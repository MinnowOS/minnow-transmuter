<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use Symfony\Component\Yaml\Yaml;

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


// --- File Parsing for Accurate Method Counts ---

echo "Parsing source files to collect methods...\n";
$directory = 'wordpress';
$parser = (new ParserFactory)->createForVersion(PhpVersion::fromString('7.4'));
$traverser = new NodeTraverser();
$methodCollector = new ClassMethodCollector();
$traverser->addVisitor($methodCollector);

$directoryIterator = new RecursiveDirectoryIterator($directory);
$iterator = new RecursiveIteratorIterator($directoryIterator);
$phpFiles = new RegexIterator($iterator, '/\.php$/i');

$excludeFiles = [ 'wp-content/' ];

foreach ($phpFiles as $file) {
    $filePath = $file->getRealPath();
    $excluded = false;
    foreach ($excludeFiles as $excludeFile) {
        if (strpos($filePath, $excludeFile) !== false) {
            $excluded = true;
            break;
        }
    }
    if ($excluded) continue;
    try {
        $code = file_get_contents($filePath);
        $stmts = $parser->parse($code);
        if ($stmts) {
            $traverser->traverse($stmts);
        }
    } catch (Error $e) {
        // Silently ignore parse errors
    }
}

$originalClassMethods = $methodCollector->classMethods;
echo "Finished parsing source files.\n\n";


// --- YAML Processing ---

$mappingsFile = 'mappings.yaml';
if (!file_exists($mappingsFile)) {
    die("Error: mappings.yaml file not found.\n");
}

$mappings = Yaml::parseFile($mappingsFile);
$functionMappings = $mappings['functions'] ?? [];
$classMappings = $mappings['classes'] ?? [];

// --- Data Processing ---

$totalFunctions = count($functionMappings);
$totalClasses = count($classMappings);
$totalPolyfillFunctions = 0; // Initialize polyfill counter

// Count polyfills directly from the mappings
foreach ($functionMappings as $mapping) {
    if ($mapping === 'polyfill') {
        $totalPolyfillFunctions++;
    }
}


$structuredData = [];  // For namespaced classes
$globalClassData = []; // For global classes like Minnow

// Create a reverse map from new class name to original class name
$newToOriginalClassMap = [];
foreach ($classMappings as $originalClass => $mapping) {
    if (isset($mapping['namespace']) && isset($mapping['class'])) {
        $fullNewClassName = $mapping['namespace'] . '\\' . $mapping['class'];
        $newToOriginalClassMap[$fullNewClassName] = $originalClass;
    }
}

// Process function mappings, separating global from namespaced
foreach ($functionMappings as $functionName => $mapping) {
    // Skip polyfills so they aren't processed as class methods
    if (!is_array($mapping)) {
        continue;
    }
    if (isset($mapping['namespace'])) {
        $method = $mapping['method'];
        if (isset($mapping['class'])) {
            // It's a namespaced class
            $namespace = $mapping['namespace'];
            $class = $mapping['class'];
            if (!isset($structuredData[$namespace][$class]['mapped'])) $structuredData[$namespace][$class]['mapped'] = [];
            $structuredData[$namespace][$class]['mapped'][] = $method;
        } else {
            // It's a global class
            $className = $mapping['namespace'];
            if (!isset($globalClassData[$className]['mapped'])) $globalClassData[$className]['mapped'] = [];
            $globalClassData[$className]['mapped'][] = $method;
        }
    }
}

// Process class mappings to add unmapped methods (only affects namespaced classes)
foreach ($classMappings as $originalClassName => $mapping) {
     if (isset($mapping['namespace']) && isset($mapping['class'])) {
        $namespace = $mapping['namespace'];
        $class = $mapping['class'];
        if (!isset($structuredData[$namespace])) $structuredData[$namespace] = [];
        if (!isset($structuredData[$namespace][$class])) $structuredData[$namespace][$class] = ['mapped' => [], 'unmapped' => []];
        if (isset($originalClassMethods[$originalClassName])) {
            $allOriginalMethods = $originalClassMethods[$originalClassName];
            $mappedMethods = $structuredData[$namespace][$class]['mapped'] ?? [];
            $unmappedMethods = array_diff($allOriginalMethods, $mappedMethods);
            sort($unmappedMethods);
            $structuredData[$namespace][$class]['unmapped'] = $unmappedMethods;
        }
    }
}

// Sort data for consistent and readable output
ksort($globalClassData);
foreach ($globalClassData as &$methods) {
    sort($methods['mapped']);
}
ksort($structuredData);
foreach ($structuredData as &$classes) {
    ksort($classes);
    foreach ($classes as &$methods) {
        if (isset($methods['mapped'])) {
            sort($methods['mapped']);
        }
    }
}
unset($classes, $methods);


// --- Console Outputting (omitted for brevity) ---


// --- HTML File Generation ---

$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mapping Statistics</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #333; max-width: 900px; margin: 2rem auto; padding: 0 1rem; background-color: #f8f9fa; }
        h1, h2 { color: #212529; border-bottom: 1px solid #dee2e6; padding-bottom: 0.5rem; margin-top: 2.5rem; }
        .summary { background-color: #fff; border: 1px solid #e9ecef; border-radius: 0.5rem; padding: 1rem 1.5rem; margin-bottom: 2rem; }
        details { background-color: #fff; border: 1px solid #e9ecef; border-radius: 0.5rem; margin-bottom: 0.5rem; padding: 0.75rem 1.25rem; transition: background-color 0.2s; }
        details:hover { background-color: #f1f3f5; }
        details[open] { background-color: #fff; }
        summary { font-weight: bold; cursor: pointer; display: flex; justify-content: space-between; align-items: center; list-style: none; }
        summary::-webkit-details-marker { display: none; }
        summary::after { content: '\\25B6'; font-size: 0.8em; transition: transform 0.2s; color: #868e96; }
        details[open] > summary::after { transform: rotate(90deg); }
        ul { list-style-type: none; padding-left: 1.5rem; margin-top: 0.75rem; border-left: 2px solid #e9ecef; }
        li { padding: 0.25rem 0; }
        .badge { background-color: #e9ecef; color: #495057; padding: 0.25em 0.6em; border-radius: 10rem; font-size: 0.85em; font-weight: 500; }
        .method-list li { font-family: monospace; font-size: 0.9em; border-radius: 0.25rem; padding: 0.25rem 0.5rem; transition: background-color 0.2s; color: #495057; cursor: pointer; }
        .method-list li:hover { background-color: #e9ecef; }
    </style>
</head>
<body>
    <h1>Mapping Statistics</h1>
    <div class="summary">
        <h2>Summary</h2>
        <p><strong>Total Functions Mapped:</strong> <span class="badge">{$totalFunctions}</span></p>
        <p><strong>Total Original Classes Mapped:</strong> <span class="badge">{$totalClasses}</span></p>
        <p><strong>Total Polyfill Functions:</strong> <span class="badge">{$totalPolyfillFunctions}</span></p>
    </div>
HTML;

// Generate section for Global Classes
if (!empty($globalClassData)) {
    $html .= "<h2>Global Classes</h2>\n";
    foreach ($globalClassData as $className => $methodLists) {
        $mappedMethodCount = count($methodLists['mapped'] ?? []);
        $badgeText = "{$mappedMethodCount} methods";
        $html .= "<details>\n";
        $html .= "    <summary>{$className} <span class=\"badge\">{$badgeText}</span></summary>\n";
        
        $allMethods = $methodLists['mapped'] ?? [];
        if (!empty($allMethods)) {
            $html .= "    <ul class=\"method-list\">\n";
            foreach ($allMethods as $method) {
                $copyText = htmlspecialchars("{$className}::{$method}()", ENT_QUOTES, 'UTF-8');
                $html .= "        <li onclick=\"copyToClipboard(this, '{$copyText}')\">{$method}</li>\n";
            }
            $html .= "    </ul>\n";
        }
        $html .= "</details>\n";
    }
}

// Generate section for Namespaced Classes
if (!empty($structuredData)) {
    $html .= "<h2>Namespaced Classes</h2>\n";
    foreach ($structuredData as $namespace => $classes) {
        $classCount = count($classes);
        $html .= "<details>\n";
        $html .= "    <summary>{$namespace} <span class=\"badge\">{$classCount} classes</span></summary>\n";
        $html .= "    <ul>\n";
        foreach ($classes as $className => $methodLists) {
            $fullNewClassName = $namespace . '\\' . $className;
            $originalClassName = $newToOriginalClassMap[$fullNewClassName] ?? null;
            $totalMethodCount = isset($originalClassMethods[$originalClassName]) ? count($originalClassMethods[$originalClassName]) : 0;
            $mappedMethodCount = count($methodLists['mapped'] ?? []);
            
            $displayCount = $totalMethodCount > 0 ? $totalMethodCount : $mappedMethodCount;
            $badgeText = "{$displayCount} methods";

            $html .= "        <li>\n";
            $html .= "            <details>\n";
            $html .= "                <summary>{$className} <span class=\"badge\">{$badgeText}</span></summary>\n";
            $allMethods = array_merge($methodLists['mapped'] ?? [], $methodLists['unmapped'] ?? []);
            sort($allMethods);

            if (!empty($allMethods)) {
                $html .= "                <ul class=\"method-list\">\n";
                foreach ($allMethods as $method) {
                    $copyText = htmlspecialchars(str_replace('\\', '\\\\', $namespace) . '\\\\' . $className . '::' . $method . '()', ENT_QUOTES, 'UTF-8');
                    $html .= "                    <li onclick=\"copyToClipboard(this, '{$copyText}')\">{$method}</li>\n";
                }
                $html .= "                </ul>\n";
            }
            $html .= "            </details>\n";
            $html .= "        </li>\n";
        }
        $html .= "    </ul>\n";
        $html .= "</details>\n";
    }
}

$html .= <<<HTML
    <script>
        function copyToClipboard(element, text) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = 0;
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
                const originalText = element.innerText;
                element.innerText = 'Copied!';
                element.style.backgroundColor = '#d0ebff';
                setTimeout(() => {
                    element.innerText = originalText;
                    element.style.backgroundColor = '';
                }, 1500);
            } catch (err) {
                console.error('Failed to copy text: ', err);
            }
            document.body.removeChild(textarea);
        }
    </script>
</body>
</html>
HTML;

$outputFile = 'mappings.html';
file_put_contents($outputFile, $html);

echo "Successfully generated HTML report: {$outputFile}\n";