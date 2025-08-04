<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter;
use Symfony\Component\Yaml\Yaml;
class FunctionCollector extends NodeVisitorAbstract
{
    public $classMethods = [];
    public $functions = [];
    public $functionMappings;
    public $globalClassMethods = [];
    public $polyfills = [];
    private $currentFile;
    public function __construct($currentFile, $functionMappings, $polyfills)
    {
        $this->currentFile = $currentFile;
        $this->functionMappings = $functionMappings;
        $this->polyfills = $polyfills;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Function_) {
            $functionName = $node->name->toString();
            if (in_array($functionName, $this->polyfills)) {
                return;
                // Skip polyfill functions
            }

            // Avoid collecting duplicate functions
            if (!isset($this->functions[$functionName])) {
                // Store the function node along with its file path
                $this->functions[$functionName] = [
                    'node' => $node,
                    'file' => $this->currentFile
                ];
            }
            
            // Check if the function is in the mappings
            if (!isset($this->functionMappings[$functionName])) {
                // Create default mapping
                $this->functionMappings[$functionName] = [
                    'namespace' => 'Minnow',
                    'class' => 'Misc',
                    'method' => $functionName,
                ];
            }

            $mapping = $this->functionMappings[$functionName];
            // Create the method node once, as it's the same for both cases
            $methodNode = new Node\Stmt\ClassMethod(
                $mapping['method'],
                [
                    'flags' => Node\Stmt\Class_::MODIFIER_PUBLIC | Node\Stmt\Class_::MODIFIER_STATIC,
                    'params' => $node->getParams(),
                    'stmts' => $node->getStmts(),
                    'returnType' => $node->getReturnType(),
                    'attrGroups' => $node->attrGroups,
                ]
            );
            // NEW: Check if this should be a global class method (no 'class' key)
            if (isset($mapping['namespace']) && !isset($mapping['class'])) {
                $className = $mapping['namespace'];
                if (!isset($this->globalClassMethods[$className])) {
                    $this->globalClassMethods[$className] = [];
                }
                $this->globalClassMethods[$className][] = $methodNode;
            } else {
                // EXISTING: Organize method under class and namespace
                $nsKey = $mapping['namespace'];
                $classKey = $mapping['class'];

                if (!isset($this->classMethods[$nsKey])) {
                    $this->classMethods[$nsKey] = [];
                }
                if (!isset($this->classMethods[$nsKey][$classKey])) {
                    $this->classMethods[$nsKey][$classKey] = [];
                }

                $this->classMethods[$nsKey][$classKey][] = $methodNode;
            }
        }
    }
}

class ClassCollector extends NodeVisitorAbstract
{
    public $classes = [];
    public $classMappings;
    public $namespacedClasses = [];
    private $currentFile;

    public function __construct($currentFile, $classMappings)
    {
        $this->currentFile = $currentFile;
        $this->classMappings = $classMappings;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Class_) {
            $className = $node->name->toString();
            // Avoid collecting duplicate classes
            if (!isset($this->classes[$className])) {
                // Store the class node along with its file path
                $this->classes[$className] = [
                    'node' => $node,
                    'file' => $this->currentFile
                ];
            }

            // Check if the class is in the mappings
            if (!isset($this->classMappings[$className])) {
                // Create default mapping
                $this->classMappings[$className] = [
                    'namespace' => 'Minnow',
                    'class' => $className,
                ];
            }

            $mapping = $this->classMappings[$className];
            // Update class name
            $node->name = new Node\Identifier($mapping['class']);
            // **Update extended class name if needed**
            if ($node->extends !== null) {
                $extendedClassName = $node->extends->toString();
                // Check if extended class is in the mappings
                if (isset($this->classMappings[$extendedClassName])) {
                    $extendedClassMapping = $this->classMappings[$extendedClassName];
                    // Build fully qualified name for the new extended class
                    $newExtendedClassName = $extendedClassMapping['namespace'] .
'\\' . $extendedClassMapping['class'];

                    // Update the node
                    $node->extends = new Node\Name\FullyQualified($newExtendedClassName);
                }
            }

            // Organize class under namespace
            $nsKey = $mapping['namespace'];
            if (!isset($this->namespacedClasses[$nsKey])) {
                $this->namespacedClasses[$nsKey] = [];
            }
            $this->namespacedClasses[$nsKey][] = $node;
        }
    }
}

/**
 * A NodeVisitor to traverse the Abstract Syntax Tree (AST) and collect all method names for each class.
 */
class ClassMethodCollector extends NodeVisitorAbstract
{
    public $classMethods = [];
    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Class_) {
            $className = $node->name ? $node->name->toString() : '[anonymous]';
            if ('[anonymous]' === $className) {
                return;
            }

            if (!isset($this->classMethods[$className])) {
                $this->classMethods[$className] = [];
            }

            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\ClassMethod) {
                    $this->classMethods[$className][] = $stmt->name->toString();
                }
            }
            $this->classMethods[$className] = array_unique($this->classMethods[$className]);
            sort($this->classMethods[$className]);
        }
    }
}


// Directory containing PHP files
$directory = 'wordpress';
$mappings = [];
// Read both function and class mappings
if ( file_exists('mappings.yaml')) {
    $mappings = Yaml::parseFile('mappings.yaml');
}
if ( empty( $mappings ) ) {
    $mappings = [
        'functions' => [],
        'classes' => [],
    ];
}
$functionMappings = $mappings['functions'] ?? [];
$classMappings = $mappings['classes'] ?? [];
$polyfills = [];
foreach ($functionMappings as $functionName => $mapping) {
    if ($mapping === 'polyfill') {
        $polyfills[] = $functionName;
    }
}
$polyfillCode = "<?php\n\n// Polyfills extracted by Minnow Transmuter\n\n";
// Generate PHP code for the bindings.php file using string interpolation
$bindingsCode = "<?php\n\n";
// Initialize parser and traverser
$parser = (new ParserFactory)->createForVersion(PhpVersion::fromString('7.4'));
$prettyPrinter = new PrettyPrinter\Standard();

$allFunctions = [];
$functionNames = [];
$classMethods = [];
$allGlobalClassMethods = []; // Accumulator for new global class methods
$allClasses = [];
$classNames = [];
$namespacedClasses = [];
// Collect all PHP files in the directory recursively
$directoryIterator = new RecursiveDirectoryIterator($directory);
$iterator = new RecursiveIteratorIterator($directoryIterator);
$phpFiles = new RegexIterator($iterator, '/\.php$/i');

// Define an array of files to exclude
$excludeFiles = [
    'noop.php',
    'wp-admin/install-helper.php',
    'wp-includes/sodium_compat',
    'wp-includes/ID3',
    'wp-includes/Requests',
    'wp-includes/class-requests.php',
    'wp-includes/PHPMailer',
    'wp-includes/class-phpmailer.php',
    'wp-includes/SimplePie',
    'wp-includes/class-simplepie.php',
    'wp-includes/cache-compat.php',
    'wp-content/',
];

// --- Preliminary pass to collect all original class methods ---
echo "Running preliminary scan for class methods...\n";
$preliminaryParser = (new ParserFactory)->createForVersion(PhpVersion::fromString('7.4'));
$preliminaryTraverser = new NodeTraverser();
$methodCollector = new ClassMethodCollector();
$preliminaryTraverser->addVisitor($methodCollector);

// Re-iterate over the same files just for this purpose
$directoryIteratorPrelim = new RecursiveDirectoryIterator($directory);
$iteratorPrelim = new RecursiveIteratorIterator($directoryIteratorPrelim);
$phpFilesPrelim = new RegexIterator($iteratorPrelim, '/\.php$/i');

foreach ($phpFilesPrelim as $file) {
    $filePath = $file->getRealPath();
    $excluded = false;
    foreach ($excludeFiles as $excludeFile) {
        if (strpos($filePath, $excludeFile) !== false) {
            $excluded = true;
            continue;
        }
    }
    if ($excluded) {
        continue;
    }
    try {
        $code = file_get_contents($filePath);
        $stmts = $preliminaryParser->parse($code);
        if ($stmts) {
            $preliminaryTraverser->traverse($stmts);
        }
    } catch (Error $e) {
        // Silently ignore parse errors in this preliminary pass
    }
}
$originalClassMethods = $methodCollector->classMethods;
echo "Preliminary scan complete.\n\n";

foreach ($phpFiles as $file) {
    $filePath = $file->getRealPath();

    $excluded = false;
    // Optionally exclude certain files
    foreach ($excludeFiles as $excludeFile) {
        if (strpos($filePath, $excludeFile) !== false) {
            $excluded = true;
            continue;
        }
    }

    if ($excluded) {
        continue;
    }

    try {
        $code = file_get_contents($filePath);
        // remove code from class-wp-filesystem-ftpsockets.php
        if (str_contains($filePath, 'class-wp-filesystem-ftpsockets.php')) {
            $code = str_replace("		// Check if possible to use ftp functions.\n		if ( ! require_once ABSPATH . 'wp-admin/includes/class-ftp.php' ) {\n			return;\n		}", "", $code);
        }
        // remove code from blocks.php
        if (str_contains($filePath, 'blocks.php')) {
            $code = str_replace("	if ( ! \$core_blocks_meta ) {\n		\$core_blocks_meta = require ABSPATH . WPINC . '/blocks/blocks-json.php';\n	}", "", $code);
        }   
        // Perform the replacement for "require ABSPATH" with "// require ABSPATH"
        $code = preg_replace('/\brequire(?:_once)?\s+ABSPATH\b/', '// $0', $code);
        // Perform various text replacements
        $code = str_replace('WordPress', 'Minnow', $code);
        $code = str_replace('https://wordpress.org', 'https://minn.xyz/legacy', $code);
        $code = str_replace('Howdy', 'Hey', $code);
        $code = str_replace('wp-login.php', 'admin/', $code);
        if (str_contains($filePath, 'load.php')) {
            $code = str_replace('/wp-admin/install.php', '/admin/install/', $code);
        }

        $stmts = $parser->parse($code);
        if ($stmts) {
            $nonPolyfillStmts = [];
            foreach ($stmts as $stmt) {
                $isPolyfillBlock = false;
                if ($stmt instanceof Node\Stmt\If_) {
                    $condition = $stmt->cond;
                    if ($condition instanceof Node\Expr\BooleanNot &&
                        $condition->expr instanceof Node\Expr\FuncCall &&
                        $condition->expr->name instanceof Node\Name &&
                        $condition->expr->name->toString() === 'function_exists' && !empty($condition->expr->getArgs())
                    ) {
                        $arg = $condition->expr->getArgs()[0]->value;
                        if ($arg instanceof Node\Scalar\String_ && in_array($arg->value, $polyfills)) {
                            // This is a polyfill if block, extract it
                            $polyfillCode .= $prettyPrinter->prettyPrint([$stmt]) . "\n\n";
                            $isPolyfillBlock = true;
                        }
                    }
                }

                if (!$isPolyfillBlock) {
                    $nonPolyfillStmts[] = $stmt;
                }
            }
            // Use only the non-polyfill statements for transmutation
            $stmts = $nonPolyfillStmts;
        }
        if ($stmts === null) {
            continue;
        }

        $traverser = new NodeTraverser();
        $functionCollector = new FunctionCollector($filePath, $functionMappings, $polyfills);
        $traverser->addVisitor(new PhpParser\NodeVisitor\NameResolver());
        $traverser->addVisitor($functionCollector);
        $traverser->traverse($stmts);

        $classTraverser = new NodeTraverser();
        $classCollector = new ClassCollector($filePath, $classMappings);
        $classTraverser->addVisitor(new PhpParser\NodeVisitor\NameResolver());
        $classTraverser->addVisitor($classCollector);
        $classTraverser->traverse($stmts);
        foreach ($functionCollector->functions as $functionName => $data) {
            if (!isset($functionNames[$functionName])) {
                $allFunctions[$functionName] = $data['node'];
                $functionNames[$functionName] = $data['file'];
            } else {
                // Optionally log or handle duplicate functions
                // For example, you can compare the file paths or function bodies
            }
        }
        // Accumulate class methods
        foreach ($functionCollector->classMethods as $nsKey => $classes) {
            if (!isset($classMethods[$nsKey])) {
                $classMethods[$nsKey] = [];
            }
            foreach ($classes as $classKey => $methods) {
                if (!isset($classMethods[$nsKey][$classKey])) {
                    $classMethods[$nsKey][$classKey] = [];
                }
                $classMethods[$nsKey][$classKey] = array_merge($classMethods[$nsKey][$classKey], $methods);
            }
        }
        // NEW: Accumulate global class methods
        foreach ($functionCollector->globalClassMethods as $className => $methods) {
            if (!isset($allGlobalClassMethods[$className])) {
                $allGlobalClassMethods[$className] = [];
            }
            $allGlobalClassMethods[$className] = array_merge($allGlobalClassMethods[$className], $methods);
        }
         // Accumulate classes
        foreach ($classCollector->classes as $className => $data) {
            if (!isset($classNames[$className])) {
                $allClasses[$className] = $data['node'];
                $classNames[$className] = $data['file'];
            }
        }
        // Accumulate namespaced classes
        foreach ($classCollector->namespacedClasses as $nsKey => $classes) {
            if (!isset($namespacedClasses[$nsKey])) {
                $namespacedClasses[$nsKey] = [];
            }
            foreach ($classes as $classNode) {
                $namespacedClasses[$nsKey][] = $classNode;
            }
        }
        // Update the main functionMappings with new mappings
        $functionMappings = array_merge($functionMappings, $functionCollector->functionMappings);
        $classMappings = array_merge($classMappings, $classCollector->classMappings);
    } catch (Error $e) {
        echo 'Parse Error in file ', $filePath, ': ', $e->getMessage(), "\n";
    }
}

// --- CONFLICT DETECTION LOGIC ---
echo "Checking for mapping conflicts...\n";
// 1. Build a set of all Fully Qualified Class Names (FQCNs) generated from functions.
$functionGeneratedFqcns = [];
foreach ($classMethods as $namespace => $classes) {
    foreach (array_keys($classes) as $className) {
        $fqcn = str_replace('/', '\\', $namespace) . '\\' . $className;
        $functionGeneratedFqcns[$fqcn] = true; // Use as a set for fast lookups
    }
}

// 2. Create a reverse map for renamed classes to easily find the original name.
$renamedClassReverseMap = [];
foreach ($classMappings as $originalClassName => $mapping) {
    if (isset($mapping['namespace']) && isset($mapping['class'])) {
        $fqcn = str_replace('/', '\\', $mapping['namespace']) . '\\' . $mapping['class'];
        // Ensure we don't overwrite with outdated mapping info
        if (isset($allClasses[$originalClassName])) {
             $renamedClassReverseMap[$fqcn] = $originalClassName;
        }
    }
}

// 3. Iterate through renamed classes and check if they exist in the function-generated set.
$conflictsFound = false;
foreach ($renamedClassReverseMap as $fqcn => $originalClassName) {
    if (isset($functionGeneratedFqcns[$fqcn])) {
        echo "----------------------------------------------------------------\n";
        echo "FATAL MAPPING CONFLICT DETECTED:\n\n";
        echo "The class '{$originalClassName}' is mapped to '{$fqcn}', but this name is also\n";
        echo "being used by a class generated from one or more functions.\n\n";
        echo "This will cause methods to be overwritten or a fatal error.\n";
        echo "To fix, please rename the class in the 'classes' section of mappings.yaml\n";
        echo "or remap the conflicting functions to a different class.\n";
        echo "----------------------------------------------------------------\n\n";
        $conflictsFound = true;
    }
}

// --- CONFLICT DETECTION LOGIC (CLASS & METHOD) ---
echo "Checking for method mapping conflicts...\n";
$methodConflictMap = [];

// 1. Populate from function mappings
foreach ($functionMappings as $functionName => $mapping) {
    if (is_array($mapping)) { // Skip polyfills
        $namespace = str_replace('/', '\\', $mapping['namespace']);
        $methodName = $mapping['method'];
        
        $fqcn = isset($mapping['class']) ? $namespace . '\\' . $mapping['class'] : $namespace;

        $key = "{$fqcn}::{$methodName}";
        $source = "function {$functionName}()";
        if (!isset($methodConflictMap[$key])) {
            $methodConflictMap[$key] = [];
        }
        $methodConflictMap[$key][] = $source;
    }
}

// 2. Populate from class mappings
foreach ($classMappings as $originalClassName => $mapping) {
    if (isset($originalClassMethods[$originalClassName])) {
        $namespace = str_replace('/', '\\', $mapping['namespace']);
        $className = $mapping['class'];
        $fqcn = $namespace . '\\' . $className;

        foreach ($originalClassMethods[$originalClassName] as $methodName) {
            $key = "{$fqcn}::{$methodName}";
            $source = "class {$originalClassName}::{$methodName}()";
            if (!isset($methodConflictMap[$key])) {
                $methodConflictMap[$key] = [];
            }
            $methodConflictMap[$key][] = $source;
        }
    }
}

// 3. Check for and report conflicts
$methodConflictsFound = false;
foreach ($methodConflictMap as $key => $sources) {
    if (count($sources) > 1) {
        if (!$methodConflictsFound) { // Print header only on first conflict
            echo "----------------------------------------------------------------\n";
            echo "FATAL METHOD MAPPING CONFLICT DETECTED:\n\n";
            $methodConflictsFound = true;
            $conflictsFound = true; // Use existing flag to halt script
        }
        echo "The method '{$key}' is generated by multiple sources:\n";
        foreach ($sources as $source) {
            echo "  - {$source}\n";
        }
        echo "\n";
    }
}

if ($methodConflictsFound) {
    echo "This will cause methods to be overwritten or a fatal error.\n";
    echo "To fix, please remap the conflicting functions or class methods\n";
    echo "in your mappings.yaml to have unique class and method names.\n";
    echo "----------------------------------------------------------------\n\n";
}


if ($conflictsFound) {
    die("Script halted due to critical mapping conflicts.\n");
}

echo "No mapping conflicts found.\n\n";
// --- END CONFLICT DETECTION LOGIC ---

// Get the current date
$currentDate = date('M jS Y'); // e.g., "Oct 4th 2024"

// Initialize arrays to hold outdated mappings
$outdatedFunctionMappings = [];
$outdatedClassMappings = [];
// Identify and remove outdated function mappings
foreach ($functionMappings as $functionName => $mapping) {
    // ADD a check to see if the function is a polyfill
    if (!isset($functionNames[$functionName]) && !in_array($functionName, $polyfills)) {
        // Function is not found AND it's not a polyfill, so it's outdated
        unset($functionMappings[$functionName]);
        // Add to outdated functions with removal date
        $mapping['removed'] = $currentDate;
        $outdatedFunctionMappings[$functionName] = $mapping;
    }
}

// Identify and remove outdated class mappings
foreach ($classMappings as $className => $mapping) {
    if (!isset($classNames[$className])) {
        // Class is not found in the codebase, so it's outdated
        unset($classMappings[$className]);
        // Add to outdated classes with removal date
        $mapping['removed'] = $currentDate;
        $outdatedClassMappings[$className] = $mapping;
    }
}

// Read existing outdated mappings from the YAML file
$outdatedMappings = $mappings['outdated'] ?? ['functions' => [], 'classes' => []];

$existingOutdatedFunctionMappings = $outdatedMappings['functions'] ?? [];
$existingOutdatedClassMappings = $outdatedMappings['classes'] ?? [];
// Merge existing and new outdated mappings
$outdatedFunctionMappings = array_merge($existingOutdatedFunctionMappings, $outdatedFunctionMappings);
$outdatedClassMappings = array_merge($existingOutdatedClassMappings, $outdatedClassMappings);
// Purge output directory
$outputDir = __DIR__ . '/build';
if (is_dir($outputDir)) {
    foreach (scandir($outputDir) as $item) {
        if ($item !== '.' && $item !== '..') {
            $path = $outputDir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                foreach (new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                ) as $subItem) {
                
                    $subItem->isDir() ? rmdir($subItem->getPathname()) : unlink($subItem->getPathname());
                }
                rmdir($path); // Remove the now-empty directory
            } else {
                unlink($path); // Remove the file
            }
        }
    }
    echo "Successfully purged the directory: $outputDir\n";
}

// Generate code for each class using the accumulated class methods
foreach ($classMethods as $namespace => $classes) {
    foreach ($classes as $className => $methods) {
        $classNode = new Node\Stmt\Class_($className, [
            'stmts' => $methods,
        ]);
        $namespace = str_replace('/', '\\', $namespace);
        $namespaceNode = new Node\Stmt\Namespace_(new Node\Name($namespace), [
            $classNode,
        ]);
        $code = $prettyPrinter->prettyPrintFile([$namespaceNode]);

        $parts = explode('\\', $namespace);
        array_shift($parts); // Remove "Minnow"
        $subDir = implode(DIRECTORY_SEPARATOR, $parts);
        $finalDir = $outputDir . DIRECTORY_SEPARATOR . 'app' . (empty($subDir) ? '' : DIRECTORY_SEPARATOR . $subDir);
        if (!is_dir($finalDir)) {
            mkdir($finalDir, 0777, true);
        }

        $outputFile = $finalDir . DIRECTORY_SEPARATOR . $className . '.php';
        echo "Generating $outputFile\n";
        // Save the generated code to the file
        file_put_contents($outputFile, $code);
    }
}

foreach ($namespacedClasses as $namespace => $classes) {
    foreach ($classes as $classNode) {
        $className = $classNode->name->toString();
        $namespace = str_replace('/', '\\', $namespace);
        $namespaceNode = new Node\Stmt\Namespace_(new Node\Name($namespace), [
            $classNode,
        ]);
        $code = $prettyPrinter->prettyPrintFile([$namespaceNode]);
        
        $parts = explode('\\', $namespace);
        array_shift($parts); // Remove "Minnow"
        $subDir = implode(DIRECTORY_SEPARATOR, $parts);
        $finalDir = $outputDir . DIRECTORY_SEPARATOR . 'app' . (empty($subDir) ? '' : DIRECTORY_SEPARATOR . $subDir);
        if (!is_dir($finalDir)) {
            mkdir($finalDir, 0777, true);
        }

        $outputFile = $finalDir . DIRECTORY_SEPARATOR . $className . '.php';
        echo "Generating $outputFile ($namespace)\n";
        
        file_put_contents($outputFile, $code);
    }
}


// Generate code for each global class
foreach ($allGlobalClassMethods as $className => $methods) {
    // Manually add the new get() method to the Minnow class
    if ($className === 'Minnow') {
        // Build the AST for the new method
        $getMethod = new Node\Stmt\ClassMethod('get', [
            'flags' => Node\Stmt\Class_::MODIFIER_PUBLIC | Node\Stmt\Class_::MODIFIER_STATIC,
            'params' => [new Node\Param(new Node\Expr\Variable('name'), null, new Node\Identifier('string'))],
            'stmts' => [
                new Node\Stmt\If_(
                    new Node\Expr\Isset_([
                        new Node\Expr\ArrayDimFetch(
                     
                            new Node\Expr\Variable('GLOBALS', ['name' => 'GLOBALS']),
                            new Node\Expr\Variable('name')
                        )
                    ]),
                
                    [
                        'stmts' => [
                            new Node\Stmt\Return_(
                                new Node\Expr\ArrayDimFetch(
        
                                    new Node\Expr\Variable('GLOBALS', ['name' => 'GLOBALS']),
                                    new Node\Expr\Variable('name')
                                )
                            )
                        ]
                    ]
                )
            ]
        ]);
        // Add the new method to the beginning of the methods array
        array_unshift($methods, $getMethod);
    }

    $classNode = new Node\Stmt\Class_($className, [
        'stmts' => $methods,
    ]);
    // This class should be in a namespace to be PSR-4 compliant
    $namespaceNode = new Node\Stmt\Namespace_(new Node\Name($className), [$classNode]);
    $code = $prettyPrinter->prettyPrintFile([$namespaceNode]);

    // Determine the output file path
    $outputDir = __DIR__ . '/build';
    if (!is_dir($outputDir . '/app')) {
        mkdir($outputDir . '/app', 0777, true);
    }
    $outputFile = $outputDir . '/app/' . $className . '.php';
    echo "Generating namespaced class $outputFile\n";
    // Save the generated code to the file
    file_put_contents($outputFile, $code);
}

// Separate polyfills from sortable mappings
$sortableMappings = array_filter($functionMappings, 'is_array');
$polyfillMappings = array_filter($functionMappings, function($value) {
    return !is_array($value);
});
// Sort only the array-based mappings
uasort($sortableMappings, function ($a, $b) {
    // Compare namespaces
    $namespaceComparison = strcmp($a['namespace'], $b['namespace']);
    if ($namespaceComparison !== 0) {
        return $namespaceComparison;
    }
    
    // Use isset to handle global classes that don't have a 'class' key
    $aClass = $a['class'] ?? '';
    $bClass = $b['class'] ?? '';

    // Namespaces are equal, compare classes
    $classComparison = strcmp($aClass, $bClass);
    if ($classComparison !== 0) {
        return $classComparison;
    }

    // Classes are equal, compare methods
    return strcmp($a['method'], $b['method']);
});
// Combine the sorted mappings with the polyfills
$functionMappings = $sortableMappings + $polyfillMappings;
// Write updated function mappings back to mappings.yaml
$combinedMappings = [
    'functions' => $functionMappings,
    'classes' => $classMappings,
    'outdated' => [
        'functions' => $outdatedFunctionMappings,
        'classes' => $outdatedClassMappings,
    ],
];
// Write updated mappings back to mappings.yaml
$yaml = Yaml::dump($combinedMappings, 4, 2);
file_put_contents('mappings.yaml', $yaml);

$builtInFunctions = get_defined_functions()['internal'];
$builtInFunctions = array_map('strtolower', $builtInFunctions); // Normalize to lowercase

foreach ($functionMappings as $functionName => $mapping) {
    // Add this check to skip polyfills
    if (!is_array($mapping)) {
        continue;
    }
    
    $namespace = str_replace('/', '\\', $mapping['namespace']);
    $class = $mapping['class'] ?? null;
    // Null for global classes
    $method = $mapping['method'];
    // Check if the function is a built-in function
    if (in_array(strtolower($functionName), $builtInFunctions)) {
        // Skip built-in functions
        continue;
    }
    
    if ($class) {
        // Existing logic for namespaced classes
        $fqcn = '\\' . $namespace . '\\' . $class;
        $bindingsCode .= <<<EOD
function {$functionName}(...\$args) {
    return {$fqcn}::{$method}(...\$args);
}

EOD;
    } else {
        // New logic for global classes
        $fqcn = '\\' . $namespace;
        $bindingsCode .= <<<EOD
function {$functionName}(...\$args) {
    return {$fqcn}::{$method}(...\$args);
}

EOD;
    }
    $bindingsCode .= "\n";
}

foreach ($classMappings as $originalClassName => $mapping) {
    $namespace = str_replace('/', '\\', $mapping['namespace']);
    $class = $mapping['class'];
    $newClassFullName = "\\{$namespace}\\{$class}";
    $bindingsCode .= "class_alias('{$newClassFullName}', '{$originalClassName}');\n";
}

// Determine the output file path for bindings.php
$bindingsFile = __DIR__ . '/build/bindings.php';
echo "Generating $bindingsFile\n";

// Save the generated bindings code to the file
file_put_contents($bindingsFile, $bindingsCode);

$polyfillsFile = __DIR__ . '/build/polyfills.php';
echo "Generating $polyfillsFile\n";
file_put_contents($polyfillsFile, $polyfillCode);