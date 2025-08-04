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

// Create a reverse map for functions to easily find the original name
$reverseFunctionMap = [];
foreach ($mappings['functions'] as $functionName => $mapping) {
    if (is_array($mapping)) {
        // Normalize namespace separator for consistent key generation
        $namespace = str_replace('/', '\\', $mapping['namespace']);
        if (isset($mapping['class'])) {
            $key = $namespace . '\\' . $mapping['class'] . '::' . $mapping['method'];
            $reverseFunctionMap[$key] = $functionName;
        } else {
            $key = $namespace . '::' . $mapping['method'];
            $reverseFunctionMap[$key] = $functionName;
        }
    }
}


// Separate polyfills from other function mappings
$polyfillFunctions = [];
foreach ($functionMappings as $functionName => $mapping) {
    if ($mapping === 'polyfill') {
        $polyfillFunctions[] = $functionName;
        unset($functionMappings[$functionName]); // Remove from the main list to avoid double counting
    }
}
sort($polyfillFunctions);

$totalFunctions = count($functionMappings);
$totalClasses = count($classMappings);
$totalPolyfillFunctions = count($polyfillFunctions);


$structuredData = [];  // For namespaced classes
$globalClassData = []; // For global classes like Minnow

// Create a reverse map from new class name to original class name
$newToOriginalClassMap = [];
foreach ($classMappings as $originalClass => $mapping) {
    if (isset($mapping['namespace']) && isset($mapping['class'])) {
        // Normalize namespace separator for consistent key generation
        $namespace = str_replace('/', '\\', $mapping['namespace']);
        $fullNewClassName = $namespace . '\\' . $mapping['class'];
        $newToOriginalClassMap[$fullNewClassName] = $originalClass;
    }
}

// Process function mappings, separating global from namespaced
foreach ($functionMappings as $functionName => $mapping) {
    // This loop now only processes non-polyfill functions
    if (isset($mapping['namespace'])) {
        // Normalize namespace separator for consistent processing
        $namespace = str_replace('/', '\\', $mapping['namespace']);
        $method = $mapping['method'];
        if (isset($mapping['class'])) {
            // It's a namespaced class
            $class = $mapping['class'];
            if (!isset($structuredData[$namespace][$class]['mapped'])) $structuredData[$namespace][$class]['mapped'] = [];
            $structuredData[$namespace][$class]['mapped'][] = $method;
        } else {
            // It's a global class
            $className = $namespace;
            if (!isset($globalClassData[$className]['mapped'])) $globalClassData[$className]['mapped'] = [];
            $globalClassData[$className]['mapped'][] = $method;
        }
    }
}

// Process class mappings to add unmapped methods (only affects namespaced classes)
foreach ($classMappings as $originalClassName => $mapping) {
     if (isset($mapping['namespace']) && isset($mapping['class'])) {
        // Normalize namespace separator for consistent key access
        $namespace = str_replace('/', '\\', $mapping['namespace']);
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

// --- Build Namespace Tree ---
$namespaceTree = [];
foreach ($structuredData as $namespace => $classes) {
    // This line is now redundant due to earlier normalization, but kept for safety.
    $namespace = str_replace('/', '\\', $namespace);
    $parts = explode('\\', $namespace);
    $node = &$namespaceTree;
    for ($i = 0; $i < count($parts); $i++) {
        $part = $parts[$i];
        if (!isset($node[$part])) {
            $node[$part] = ['classes' => [], 'children' => []];
        }
        if ($i === count($parts) - 1) {
            $node[$part]['classes'] = $classes;
        } else {
            $node = &$node[$part]['children'];
        }
    }
}

// --- Recursive Helper Functions for HTML Generation ---

/**
 * Recursively counts all classes within a namespace node and its children.
 * @param array $node The namespace node from the tree.
 * @return int The total count of classes.
 */
function countClassesRecursive($node) {
    $count = count($node['classes']);
    foreach ($node['children'] as $childNode) {
        $count += countClassesRecursive($childNode);
    }
    return $count;
}

/**
 * Recursively generates the HTML for a namespace node, its classes, and its sub-namespaces.
 * @param string $name The name of the current namespace segment.
 * @param array $data The data for the current node (classes and children).
 * @param string $fullNamespace The full namespace path up to this point.
 * @param array $newToOriginalClassMap Map of new class names to original names.
 * @param array $originalClassMethods Map of original class names to their methods.
 * @param array $reverseFunctionMap Map of new methods back to original function names.
 * @return string The generated HTML.
 */
function generateNamespaceHtml($name, $data, $fullNamespace, $newToOriginalClassMap, $originalClassMethods, $reverseFunctionMap) {
    $totalClassesInNode = countClassesRecursive($data);
    if ($totalClassesInNode === 0) return '';
    $badgeText = $totalClassesInNode . ($totalClassesInNode === 1 ? ' class' : ' classes');

    $html = "<details>\n";
    $html .= "    <summary>{$name} <span class=\"badge\">{$badgeText}</span></summary>\n";
    $html .= "    <ul class=\"namespace-list\">\n";
    // Combine classes and children into a single list to be sorted
    $items = [];
    foreach ($data['classes'] as $className => $classData) {
        $items[$className] = ['type' => 'class', 'data' => $classData];
    }
    foreach ($data['children'] as $childName => $childData) {
        $items[$childName] = ['type' => 'namespace', 'data' => $childData];
    }
    ksort($items); // Sort the combined list by name

    // Iterate through the sorted list
    foreach ($items as $itemName => $item) {
        $html .= "<li>\n";
        if ($item['type'] === 'class') {
            $className = $itemName;
            $methodLists = $item['data'];
            
            $fullNewClassName = $fullNamespace . '\\' . $className;
            $originalClassName = $newToOriginalClassMap[$fullNewClassName] ?? null;
            $totalMethodCount = isset($originalClassMethods[$originalClassName]) ? count($originalClassMethods[$originalClassName]) : 0;
            $mappedMethodCount = count($methodLists['mapped'] ?? []);
            $displayCount = $totalMethodCount > 0 ? $totalMethodCount : $mappedMethodCount;
            $methodBadgeText = "{$displayCount} methods";

            $originalClassDisplay = $originalClassName ? htmlspecialchars("Original: {$originalClassName}", ENT_QUOTES, 'UTF-8') : '';
            $summaryHoverAttributes = $originalClassName ? " onmouseover=\"showDetails(event, '{$originalClassDisplay}')\" onmouseout=\"hideDetails()\"" : "";
            
            $html .= "<details>\n";
            $html .= "    <summary{$summaryHoverAttributes}>{$className} <span class=\"badge\">{$methodBadgeText}</span></summary>\n";
            $allMethods = array_merge($methodLists['mapped'] ?? [], $methodLists['unmapped'] ?? []);
            sort($allMethods);

            if (!empty($allMethods)) {
                $html .= "    <ul class=\"method-list\">\n";
                foreach ($allMethods as $method) {
                    $copyText = htmlspecialchars(str_replace('\\', '\\\\', $fullNamespace) . '\\\\' . $className . '::' . $method . '()', ENT_QUOTES, 'UTF-8');
                    $key = $fullNamespace . '\\' . $className . '::' . $method;
                    $originalFunction = $reverseFunctionMap[$key] ?? '';
                    $originalDisplay = '';
                    if ($originalFunction) {
                        $originalDisplay = "{$originalFunction}()";
                    } elseif (in_array($method, $methodLists['unmapped'] ?? [])) {
                        $originalDisplay = "{$originalClassName}::{$method}()";
                    } else {
                        $originalDisplay = 'N/A';
                    }
                    $originalCode = htmlspecialchars("Original: {$originalDisplay}", ENT_QUOTES, 'UTF-8');
                    $html .= "        <li onclick=\"copyToClipboard(this, '{$copyText}')\" onmouseover=\"showDetails(event, '{$originalCode}')\" onmouseout=\"hideDetails()\">{$method}</li>\n";
                }
                $html .= "    </ul>\n";
            }
            $html .= "</details>\n";
        } elseif ($item['type'] === 'namespace') {
            $childName = $itemName;
            $childData = $item['data'];
            $newNamespace = $fullNamespace ? "{$fullNamespace}\\{$childName}" : $childName;
            $html .= generateNamespaceHtml($childName, $childData, $newNamespace, $newToOriginalClassMap, $originalClassMethods, $reverseFunctionMap);
        }
        $html .= "</li>\n";
    }
    
    $html .= "    </ul>\n";
    $html .= "</details>\n";
    return $html;
}

// --- HTML File Generation ---

$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mapping Reference</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
 line-height: 1.6; color: #333; max-width: 900px; margin: 2rem auto; padding: 0 1rem; background-color: #f8f9fa;
 }
        h1, h2 { color: #212529; border-bottom: 1px solid #dee2e6; padding-bottom: 0.5rem;
 margin-top: 2.5rem; }
        .summary { background-color: #fff; border: 1px solid #e9ecef; border-radius: 0.5rem;
 padding: 1rem 1.5rem; margin-bottom: 2rem; }
        details { background-color: #fff;
 border: 1px solid #e9ecef; border-radius: 0.5rem; margin-bottom: 0.5rem; padding: 0.75rem 1.25rem; transition: background-color 0.2s;
 }
        details > summary { padding: 0;
 }
        details details { border: none; padding: 0; margin-left: 1rem;
 }
        details:hover { background-color: #f1f3f5;
 }
        details[open] { background-color: #fff;
 }
        summary { font-weight: bold; cursor: pointer; display: flex; justify-content: space-between; align-items: center;
 list-style: none; }
        summary::-webkit-details-marker { display: none;
 }
        summary::after { content: '\\25B6'; font-size: 0.8em; transition: transform 0.2s; color: #868e96;
 }
        details[open] > summary::after { transform: rotate(90deg);
 }
        ul.namespace-list, ul.method-list { list-style-type: none; padding-left: 1.5rem; margin-top: 0.75rem;
 border-left: 2px solid #e9ecef; }
        li { padding: 0.4rem 0;
 }
        .badge { background-color: #e9ecef; color: #495057; padding: 0.25em 0.6em; border-radius: 10rem;
 font-size: 0.85em; font-weight: 500; }
        .method-list li { font-family: monospace; font-size: 0.9em;
 border-radius: 0.25rem; padding: 0.25rem 0.5rem; transition: background-color 0.2s; color: #495057; cursor: pointer;
 }
        .method-list li:hover { background-color: #e9ecef;
 }
        #details-panel {
            display: none;
 position: fixed;
            border: 1px solid #adb5bd;
            background-color: #fff;
            padding: 0.5rem 0.75rem;
            border-radius: 0.3rem;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            z-index: 100;
 font-family: monospace;
            font-size: 0.85em;
            color: #212529;
            pointer-events: none; /* So it doesn't interfere with mouse events on other elements */
        }
    </style>
</head>
<body>
    <h1>Mapping Reference</h1>
    <div class="summary">
        <h2>Summary</h2>
        <p><strong>Total Functions Mapped:</strong> <span class="badge">{$totalFunctions}</span></p>
        <p><strong>Total Original Classes Mapped:</strong> <span class="badge">{$totalClasses}</span></p>
        <p><strong>Total Polyfill Functions:</strong> <span class="badge">{$totalPolyfillFunctions}</span></p>
    </div>
HTML;
// Generate section for Global Class and Polyfills
if (!empty($globalClassData) || !empty($polyfillFunctions)) {
    $html .= "<h2>Helpers and Polyfills</h2>\n";
    // Global Classes
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
                $key = "{$className}::{$method}";
                $originalFunction = $reverseFunctionMap[$key] ?? '';
                $originalDisplay = $originalFunction ? "{$originalFunction}()" : "N/A";
                $originalCode = htmlspecialchars("Original: {$originalDisplay}", ENT_QUOTES, 'UTF-8');
                $html .= "        <li onclick=\"copyToClipboard(this, '{$copyText}')\" onmouseover=\"showDetails(event, '{$originalCode}')\" onmouseout=\"hideDetails()\">{$method}</li>\n";
            }
            $html .= "    </ul>\n";
        }
        $html .= "</details>\n";
    }

    // Polyfills
    if (!empty($polyfillFunctions)) {
        $html .= "<details>\n";
        $html .= "    <summary>Polyfills <span class=\"badge\">{$totalPolyfillFunctions} functions</span></summary>\n";
        $html .= "    <ul class=\"method-list\">\n";
        foreach ($polyfillFunctions as $functionName) {
            $copyText = htmlspecialchars("{$functionName}()", ENT_QUOTES, 'UTF-8');
            $originalCode = htmlspecialchars("Original: {$functionName}()", ENT_QUOTES, 'UTF-8');
            $html .= "        <li onclick=\"copyToClipboard(this, '{$copyText}')\" onmouseover=\"showDetails(event, '{$originalCode}')\" onmouseout=\"hideDetails()\">{$functionName}</li>\n";
        }
        $html .= "    </ul>\n";
        $html .= "</details>\n";
    }
}


// Generate section for Namespaced Classes using the new tree structure
if (!empty($namespaceTree)) {
    $html .= "<h2>Namespaced Classes</h2>\n";
    ksort($namespaceTree);
    foreach ($namespaceTree as $topLevelName => $data) {
        $html .= generateNamespaceHtml($topLevelName, $data, $topLevelName, $newToOriginalClassMap, $originalClassMethods, $reverseFunctionMap);
    }
}

$html .= <<<HTML
    <div id="details-panel"></div>
    <script>
        const detailsPanel = document.getElementById('details-panel');
        let hoverTimeout;

        function showDetails(event, text) {
            clearTimeout(hoverTimeout);
            detailsPanel.innerHTML = text;
            
            const rect = event.target.getBoundingClientRect();
            const panelRect = detailsPanel.getBoundingClientRect();

            let y = rect.bottom + 5;
            // If it would go off-screen, place it above
            if (y + panelRect.height > window.innerHeight) {
                y = rect.top - panelRect.height - 5;
            }

            detailsPanel.style.left = rect.left + 'px';
            detailsPanel.style.top = y + 'px';
            detailsPanel.style.display = 'block';
        }

        function hideDetails() {
            // Delay hiding to prevent flickering when moving mouse
            hoverTimeout = setTimeout(() => {
                 detailsPanel.style.display = 'none';
            }, 100);
        }

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