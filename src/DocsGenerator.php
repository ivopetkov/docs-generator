<?php

/*
 * Docs Generator
 * https://github.com/ivopetkov/docs-generator
 * Copyright (c) Ivo Petkov
 * Free to use under the MIT license.
 */

namespace IvoPetkov;

use IvoPetkov\DocsGenerator\ClassParser;

/**
 * 
 */
class DocsGenerator
{

    /**
     *
     * @var string 
     */
    private $projectDir = null;

    /**
     *
     * @var array 
     */
    private $sourceDirs = [];

    /**
     *
     * @var array 
     */
    private $sourceDirsClasses = [];

    /**
     *
     * @var array 
     */
    private $examplesDirs = [];

    /**
     * 
     * @param string $projectDir
     * @throws \InvalidArgumentException
     */
    public function __construct(string $projectDir)
    {
        if (!is_dir($projectDir)) {
            throw new \InvalidArgumentException('The projectDir specified (' . $projectDir . ') is not a valid dir!');
        }
        $this->projectDir = str_replace('\\', '/', realpath($projectDir));
    }

    public function addSourceDir(string $dir)
    {
        $dir = '/' . trim($dir, '/\\');
        if (!is_dir($this->projectDir . $dir)) {
            throw new \InvalidArgumentException('The source dir specified (' . $this->projectDir . $dir . ') is not a valid dir!');
        }
        $this->sourceDirs[] = $dir;
    }

    public function addExamplesDir(string $dir)
    {
        $dir = '/' . trim($dir, '/\\');
        if (!is_dir($this->projectDir . $dir)) {
            throw new \InvalidArgumentException('The examples dir specified (' . $this->projectDir . $dir . ') is not a valid dir!');
        }
        $this->examplesDirs[] = $dir;
    }

    public function generateMarkdown(string $outputDir, array $options = [])
    {
        $this->generate($outputDir, 'md', $options);
    }

//    public function generateJSON(string $outputDir, array $options = [])
//    {
//        $this->generate($outputDir, 'json', $options);
//    }

    public function generateHTML(string $outputDir, array $options = [])
    {
        $this->generate($outputDir, 'html', $options);
    }

    private function isInSourcesDirs(string $filename)
    {
        foreach ($this->sourceDirs as $sourceDir) {
            if (strpos(str_replace('\\', '/', $filename), $this->projectDir . $sourceDir . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    private function prepareClasses()
    {
        $classNames = [];
        foreach ($this->sourceDirs as $sourceDir) {
            if (!isset($this->sourceDirsClasses[$sourceDir])) {
                $sourceDirClassNames = [];
                $files = $this->getFiles($this->projectDir . $sourceDir);
                foreach ($files as $file) {
                    $content = file_get_contents($file);
                    if (preg_match('/class [a-zA-Z]*/', $content) === 1 || preg_match('/interface [a-zA-Z]*/', $content) === 1) {
                        $declaredClasses = get_declared_classes();
                        $declaredInterfaces = get_declared_interfaces();
                        require_once $file;
                        $newClasses = array_values(array_diff(get_declared_classes(), $declaredClasses));
                        $newInterfaces = array_values(array_diff(get_declared_interfaces(), $declaredInterfaces));
                        $newClasses = array_merge($newClasses, $newInterfaces);
                        foreach ($newClasses as $newClassName) {
                            if (strpos($newClassName, 'class@anonymous') === 0) {
                                continue;
                            }
                            $sourceDirClassNames[$newClassName] = str_replace($this->projectDir, '', str_replace('\\', '/', $file));
                        }
                    }
                }
                $this->sourceDirsClasses[$sourceDir] = $sourceDirClassNames;
            }
            $classNames = array_merge($classNames, $this->sourceDirsClasses[$sourceDir]);
        }
        ksort($classNames);
        return $classNames;
    }

    private function generate(string $outputDir, string $outputType, array $options = [])
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }
        $outputDir = rtrim($outputDir, '\/');

        $showPrivate = isset($options['showPrivate']) ? (int) $options['showPrivate'] : false;
        $showProtected = isset($options['showProtected']) ? (int) $options['showProtected'] : false;

        $classNames = $this->prepareClasses();

        $writeFile = function(string $filename, string $content) use ($outputDir) {
            $filename = $outputDir . DIRECTORY_SEPARATOR . $filename;
            file_put_contents($filename, $content);
        };

        $getType = function($type, bool $richOutput = true) use ($outputType) {
            if (!$richOutput) {
                return $type;
            }
            $update = function($type) use ($outputType) {
                $parts = explode('|', $type);
                foreach ($parts as $i => $part) {
                    if ($part !== 'void' && $part !== 'string' && $part !== 'int' && $part !== 'bool' && $part !== 'array') {
                        $class = $part;
                        if (substr($class, -2) === '[]') {
                            $class = substr($class, 0, -2);
                        }
                        $classData = ClassParser::parse($class);
                        if (is_array($classData)) {
                            if (strlen($classData['extension']) > 0) {
                                $url = 'http://php.net/manual/en/class.' . strtolower($class) . '.php';
                            } else {
                                if (array_search('internal', $classData['keywords']) !== false) {
                                    continue;
                                }
                                if (!$this->isInSourcesDirs($classData['filename'])) {
                                    continue;
                                }
                                $url = $this->getClassOutputFilename($class, $outputType);
                            }
                            if ($outputType === 'md') {
                                $part = '[' . $part . '](' . $url . ')';
                            } else {
                                $part = '<a href="' . $url . '">' . $part . '</a>';
                            }
                        }
                    }
                    $parts[$i] = $part;
                }
                return implode('|', $parts);
            };
            if (is_array($type)) {
                foreach ($type as $i => $_type) {
                    $type[$i] = $update($_type);
                }
                return $type;
            }
            return $update($type);
        };

        $filterMethods = function(array $methods) use ($showPrivate, $showProtected) {
            return array_filter($methods, function($methodData) use ($showPrivate, $showProtected) {
                if (substr($methodData['name'], 0, 2) === '__' && $methodData['name'] !== '__construct') {
                    return false;
                }
                if (!$showPrivate && array_search('private', $methodData['keywords']) !== false) {
                    return false;
                }
                if (!$showProtected && array_search('protected', $methodData['keywords']) !== false) {
                    return false;
                }
                return true;
            });
        };

        $filterProperties = function(array $properties)use ($showPrivate, $showProtected) {
            return array_filter($properties, function($propertyData)use ($showPrivate, $showProtected) {
                if (!$showPrivate && array_search('private', $propertyData['keywords']) !== false) {
                    return false;
                }
                if (!$showProtected && array_search('protected', $propertyData['keywords']) !== false) {
                    return false;
                }
                return true;
            });
        };

        $getOutputListItem = function($name, $description, $htmlClassPrefix = null) use ($outputType) {
            $output = '';
            if ($outputType === 'md') {
                $output = '##### ' . $name . "\n\n";
                if (!empty($description)) {
                    $output .= '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' . $description . "\n\n";
                }
            } else {
                $output = '<div class="' . $htmlClassPrefix . '">';
                $output .= '<div class="' . $htmlClassPrefix . '-name">' . $name . '</div>';
                if (!empty($description)) {
                    $output .= '<div class="' . $htmlClassPrefix . '-description">' . $description . '</div>';
                }
                $output .= '</div>';
            }
            return $output;
        };

        $getOutputList = function($content, $title, $htmlClassPrefix, $level = 0) use ($outputType) {
            $output = '';
            if (!empty($content)) {
                if ($outputType === 'md') {
                    $output .= str_repeat('#', $level) . '## ' . $title . "\n\n" . $content;
                } else {
                    $output .= '<div class="' . $htmlClassPrefix . '">';
                    $output .= '<div class="' . $htmlClassPrefix . '-title">' . $title . '</div>';
                    $output .= '<div class="' . $htmlClassPrefix . '-content">' . $content . '</div>';
                    $output .= '</div>';
                }
            }
            return $output;
        };

        $getExamplesOutput = function($examples, $htmlContainerPrefix, $htmlClassPrefix) use ($outputType, $getOutputList) {
            $output = '';
            $validExamples = [];
            if (!empty($examples)) {
                foreach ($examples as $example) {
                    $locationsToCheck = [];
                    foreach ($this->examplesDirs as $examplesDir) {
                        $locationsToCheck[] = $this->projectDir . $examplesDir . '/' . $example['location'];
                    }
                    foreach ($locationsToCheck as $locationToCheck) {
                        if (is_file($locationToCheck)) {
                            $validExamples[] = [
                                'location' => $locationToCheck,
                                'description' => $example['description']
                            ];
                        }
                    }
                }
            }
            if (!empty($validExamples)) {
                $content = '';
                foreach ($validExamples as $validExampleIndex => $validExample) {
                    $exampleTitle = 'Example #' . ($validExampleIndex + 1) . (strlen($validExample['description']) > 0 ? ' ' . $validExample['description'] : '');
                    $exampleContent = file_get_contents($validExample['location']);
                    $exampleLocation = substr($validExample['location'], strlen($this->projectDir));
                    if ($outputType === 'md') {
                        $content .= '**' . $exampleTitle . '**' . "\n\n";
                        $content .= '```php' . "\n" . $exampleContent . "\n" . '```' . "\n\n";
                        $content .= 'Location: ~' . $exampleLocation . "\n\n";
                    } else {
                        $content .= '<div class="' . $htmlClassPrefix . '">';
                        $content .= '<div class="' . $htmlClassPrefix . '-title">' . $exampleTitle . '</div>';
                        $content .= '<div class="' . $htmlClassPrefix . '-content">' . $exampleContent . '</div>';
                        $content .= '<div class="' . $htmlClassPrefix . '-location">Location: ~' . $exampleLocation . '</div>';
                        $content .= '</div>';
                    }
                }
                $output .= $getOutputList($content, 'Examples', $htmlContainerPrefix);
            }

            return $output;
        };

        $getSeeOutput = function($sees, $htmlContainerPrefix, $htmlClassPrefix) use ($outputType, $getOutputList, $getOutputListItem) {
            $output = '';
            $validSees = [];
            if (!empty($sees)) {
                foreach ($sees as $see) {
                    $seeLocation = $see['location'];
                    $seeDescription = $see['description'];
                    $seeClass = null;
                    $seeMethod = null;
                    $seeProperty = null;
                    $matches = null;
                    if (preg_match('/^(.*?)\:\:(.*?)\(\)$/', $seeLocation, $matches) === 1) { // class::method()
                        $seeClass = $matches[1];
                        $seeMethod = $matches[2];
                    } elseif (preg_match('/^(.*?)\:\:\$(.*?)$/', $seeLocation, $matches) === 1) { // class::$property
                        $seeClass = $matches[1];
                        $seeProperty = $matches[2];
                    } elseif (preg_match('/^(.*?)\:\:(.*?)$/', $seeLocation, $matches) === 1) { // class::method
                        $seeClass = $matches[1];
                        $seeMethod = $matches[2];
                    } else {
                        $seeClass = $seeLocation;
                    }
                    $seeClassData = ClassParser::parse($seeClass);
                    if ($seeClassData !== null) {
                        if ($seeMethod !== null) {
                            foreach ($seeClassData['methods'] as $seeClassDataMethod) {
                                if ($seeClassDataMethod['name'] === $seeMethod) {
                                    $validSees[] = [$seeClassData['name'] . '::' . $seeMethod . '()', $this->getMethodOutputFilename($seeClassData['name'], $seeMethod, $outputType), (strlen($seeDescription) > 0 ? $seeDescription : $seeClassDataMethod['description'])];
                                    break;
                                }
                            }
                        } elseif ($seeProperty !== null) {
                            foreach ($seeClassData['properties'] as $seeClassDataProperty) {
                                if ($seeClassDataProperty['name'] === $seeProperty) {
                                    $validSees[] = [$seeClassData['name'] . '::$' . $seeProperty, $this->getClassOutputFilename($seeClassData['name'], $outputType), (strlen($seeDescription) > 0 ? $seeDescription : $seeClassDataProperty['description'])];
                                    break;
                                }
                            }
                        } else {
                            $validSees[] = [$seeClassData['name'], $this->getClassOutputFilename($seeClassData['name'], $outputType), (strlen($seeDescription) > 0 ? $seeDescription : $seeClassData['description'])];
                        }
                    }
                }
                if (!empty($validSees)) {
                    $content = '';
                    foreach ($validSees as $validSee) {
                        if ($outputType === 'md') {
                            $content .= $getOutputListItem('[' . $validSee[0] . '](' . $validSee[1] . ')', $validSee[2]);
                        } else {
                            $content .= $getOutputListItem('<a href="' . $validSee[1] . '">' . $validSee[0] . '</a>', $validSee[2], $htmlClassPrefix);
                        }
                    }
                    $output .= $getOutputList($content, 'See also', $htmlContainerPrefix);
                }
            }
            return $output;
        };

        $getConstantSynopsis = function(array $constantData, bool $richOutput = true) use ($getType) {
            return 'const ' . $getType((string) $constantData['type'], $richOutput) . ' ' . $constantData['name'];
        };

        $getPropertySynopsis = function(array $propertyData, bool $richOutput = true) use ($getType) {
            return implode(' ', $propertyData['keywords']) . ' ' . $getType((string) $propertyData['type'], $richOutput) . ' $' . $propertyData['name'];
        };

        $getEventSynopsis = function(array $eventData, bool $richOutput = true) use ($getType) {
            return $getType($eventData['type'], $richOutput) . ' ' . $eventData['name'];
        };

        $getMethodSynopsis = function(array $methodData, bool $richOutput = true) use ($getType, $outputType): string {
            $result = '';
            $keywords = [];
            if (array_search('abstract', $methodData['keywords']) !== false) {
                $keywords[] = 'abstract';
            }
            if (array_search('public', $methodData['keywords']) !== false) {
                $keywords[] = 'public';
            }
            if (array_search('protected', $methodData['keywords']) !== false) {
                $keywords[] = 'protected';
            }
            if (array_search('private', $methodData['keywords']) !== false) {
                $keywords[] = 'private';
            }
            if (array_search('static', $methodData['keywords']) !== false) {
                $keywords[] = 'static';
            }
            if (array_search('final', $methodData['keywords']) !== false) {
                $keywords[] = 'final';
            }

            $classData = ClassParser::parse($methodData['class']);

            if (empty($methodData['parameters'])) {
                $parameters = 'void';
            } else {
                $updateValue = function($value) {
                    if (is_string($value)) {
                        return '\'' . str_replace('\'', '\\\'', $value) . '\'';
                    }
                    return json_encode($value);
                };
                $parameters = '';
                $bracketsToAddInTheEnd = 0;
                foreach ($methodData['parameters'] as $parameter) {
                    if (array_search('optional', $parameter['keywords']) !== false) {
                        $parameters .= ' [, ';
                        $bracketsToAddInTheEnd++;
                    } else {
                        $parameters .= ' , ';
                    }
                    $parameters .= ($richOutput ? $getType($parameter['type']) : $parameter['type']) . ' $' . $parameter['name'] . ($parameter['value'] !== null ? ' = ' . $updateValue($parameter['value']) : '');
                }
                if ($bracketsToAddInTheEnd > 0) {
                    $parameters .= ' ' . str_repeat(']', $bracketsToAddInTheEnd) . ' ';
                }
                $parameters = trim($parameters, ' ,');
                if (substr($parameters, 0, 2) === '[,') {
                    $parameters = '[' . substr($parameters, 2);
                }
            }
            $name = $methodData['name'];
            $url = null;
            if (strlen($classData['extension']) > 0) {
                $url = 'http://php.net/manual/en/' . strtolower($methodData['class'] . '.' . ltrim($name, '_')) . '.php';
            } else {
                if ($this->isInSourcesDirs($classData['filename'])) {
                    $url = $this->getMethodOutputFilename($methodData['class'], $name, $outputType);
                }
            }

            $result .= implode(' ', $keywords);
            $result .= (array_search('constructor', $methodData['keywords']) !== false || array_search('destructor', $methodData['keywords']) !== false ? '' : ' ' . ($richOutput ? $getType($methodData['return']['type']) : $methodData['return']['type']));
            if ($outputType === 'md') {
                if ($url !== null) {
                    $result .= ' ' . ($richOutput ? '[' . $name . '](' . $url . ')' : $name);
                } else {
                    $result .= ' ' . $name;
                }
            } else {
                if ($url !== null) {
                    $result .= ' ' . ($richOutput ? '<a href="' . $url . '">' . $name . '</a>' : $name);
                } else {
                    $result .= ' ' . $name;
                }
            }
            $result .= ' ( ' . $parameters . ' )';
            return $result;
        };

        $getClassSynopsis = function(array $classData, bool $richOutput = true) use ($filterProperties, $filterMethods, $getConstantSynopsis, $getPropertySynopsis, $getMethodSynopsis, $getType): string {
            $className = $classData['name'];
            $result = $className;

            if (!empty($classData['extends'])) {
                $result .= ' extends ' . $getType($classData['extends'], $richOutput);
            }

            if (!empty($classData['implements'])) {
                $result .= ' implements ' . implode(', ', $getType($classData['implements'], $richOutput));
            }

            $result .= ' {' . "\n\n";

            // CONSTANTS
            $constantsResult = '';
            foreach ($classData['constants'] as $constantData) {
                if ($constantData['class'] === $className) {
                    $constantsResult .= "\t" . $getConstantSynopsis($constantData, $richOutput) . "\n";
                }
            }
            if ($constantsResult !== '') {
                $result .= "\t" . '/* Constants */' . "\n" . $constantsResult . "\n";
            }

            // PROPERTIES
            $properties = $filterProperties($classData['properties']);
            $propertiesResult = '';
            foreach ($properties as $propertyData) {
                if ($propertyData['class'] === $className) {
                    $propertiesResult .= "\t" . $getPropertySynopsis($propertyData, $richOutput) . "\n";
                }
            }
            if ($propertiesResult !== '') {
                $result .= "\t" . '/* Properties */' . "\n" . $propertiesResult . "\n";
            }

            // METHODS
            $methods = $filterMethods($classData['methods']);
            $methodsResult = '';
            foreach ($methods as $methodData) {
                if ($methodData['class'] === $className) {
                    $methodsResult .= "\t" . $getMethodSynopsis($methodData, $richOutput) . "\n";
                }
            }
            if ($methodsResult !== '') {
                $result .= "\t" . '/* Methods */' . "\n" . $methodsResult . "\n";
            }

            $result .= '}';
            return $result;
        };

        $temp = [];
        foreach ($classNames as $className => $classSourceFile) {
            $classData = ClassParser::parse($className);
            if (array_search('internal', $classData['keywords']) !== false) {
                continue;
            }
            if (!$this->isInSourcesDirs($classData['filename'])) {
                continue;
            }
            $temp[$className] = $classSourceFile;
        }
        $classNames = $temp;

        if ($outputType === 'json') {
            $indexData = [
                'classes' => []
            ];
            foreach ($classNames as $className => $classSourceFile) {
                $classData = ClassParser::parse($className);
                $classOutput = json_encode($classData, JSON_PRETTY_PRINT);
                $writeFile($this->getClassOutputFilename($className, 'json'), $classOutput);
                $indexData['classes'][] = [
                    'name' => $className,
                    'file' => $this->getClassOutputFilename($className, 'json'),
                    'description' => $classData['description'],
                ];
            }
            $writeFile('index.json', json_encode($indexData, JSON_PRETTY_PRINT));
        } elseif ($outputType === 'md' || $outputType === 'html') {
            $indexOutput = '';

            if ($outputType === 'md') {
                $indexOutput .= '## Classes' . "\n\n";
            } else {
                $indexOutput .= '<div class="page-index-classes">';
                $indexOutput .= '<div class="page-index-classes-title">Classes</div>';
            }

            foreach ($classNames as $className => $classSourceFile) {
                $classData = ClassParser::parse($className);

                $classOutput = '';
                if ($outputType === 'md') {
                    $classOutput .= '# ' . $className . "\n\n";
                } else {
                    $classOutput .= '<div class="page-class-name">' . $className . "</div>";
                }

                if (!empty($classData['description'])) {
                    if ($outputType === 'md') {
                        $classOutput .= $classData['description'] . "\n\n";
                    } else {
                        $classOutput .= '<div class="page-class-description">' . $classData['description'] . '</div>';
                    }
                }

                if ($outputType === 'md') {
                    $classOutput .= "```php\n" . $getClassSynopsis($classData, false) . "\n```\n\n";
                } else {
                    $classOutput .= '<div class="page-class-synopsis">' . $getClassSynopsis($classData, false) . '</div>';
                }

                // EXTENDS
                $extendsOutput = '';
                if (strlen($classData['extends']) > 0) {
                    $extendClass = ClassParser::parse($classData['extends']);
                    $extendsOutput .= $getOutputListItem($getType($classData['extends']), $extendClass['description'], 'page-class-extends-class');
                }
                $classOutput .= $getOutputList($extendsOutput, 'Extends', 'page-class-extends');

                // IMPLEMENTS
                $implementsOutput = '';
                foreach ($classData['implements'] as $implements) {
                    $implementClass = ClassParser::parse($implements);
                    $implementsOutput .= $getOutputListItem($getType($implements), $implementClass['description'], 'page-class-implements-interface');
                }
                $classOutput .= $getOutputList($implementsOutput, 'Implements', 'page-class-implements');

                // CONSTANTS
                $constantsOutput = '';
                foreach ($classData['constants'] as $constantData) {
                    if ($constantData['class'] === $className) {
                        $constantsOutput .= $getOutputListItem($getConstantSynopsis($constantData), $constantData['description'], 'page-class-constant');
                    }
                }
                $classOutput .= $getOutputList($constantsOutput, 'Constants', 'page-class-constants');

                // PROPERTIES
                $properties = $filterProperties($classData['properties']);
                $propertiesOutput = '';
                $inheritedProperties = [];
                foreach ($properties as $propertyData) {
                    $propertyOutput = $getOutputListItem($getPropertySynopsis($propertyData), $propertyData['description'], 'page-class-property');
                    if ($propertyData['class'] !== $className) {
                        if (!isset($inheritedProperties[$propertyData['class']])) {
                            $inheritedProperties[$propertyData['class']] = [];
                        }
                        $inheritedProperties[$propertyData['class']][] = $propertyOutput;
                        continue;
                    }
                    $propertiesOutput .= $propertyOutput;
                }
                ksort($inheritedProperties);
                foreach ($inheritedProperties as $inheritedClassName => $inheritedPropertiesOutput) {
                    $propertiesOutput .= $getOutputList(implode('', $inheritedPropertiesOutput), 'Inherited from ' . $getType($inheritedClassName), 'page-class-inherited-properties', 1);
                }
                $classOutput .= $getOutputList($propertiesOutput, 'Properties', 'page-class-properties');

                // METHODS
                $methods = $filterMethods($classData['methods']);
                $methodsOutput = '';
                $inheritedMethods = [];
                foreach ($methods as $methodData) {
                    $methodOutput = $getOutputListItem($getMethodSynopsis($methodData), $methodData['description'], 'page-class-method');
                    if ($methodData['class'] !== $className) {
                        if (!isset($inheritedMethods[$methodData['class']])) {
                            $inheritedMethods[$methodData['class']] = [];
                        }
                        $inheritedMethods[$methodData['class']][] = $methodOutput;
                        continue;
                    }
                    $methodsOutput .= $methodOutput;
                }
                ksort($inheritedMethods);
                foreach ($inheritedMethods as $inheritedClassName => $inheritedMethodsOutput) {
                    $methodsOutput .= $getOutputList(implode('', $inheritedMethodsOutput), 'Inherited from ' . $getType($inheritedClassName), 'page-class-inherited-methods', 1);
                }
                $classOutput .= $getOutputList($methodsOutput, 'Methods', 'page-class-methods');

                // EVENTS
                $eventsOutput = '';
                foreach ($classData['events'] as $eventData) {
                    $eventsOutput .= $getOutputListItem($getEventSynopsis($eventData), $eventData['description'], 'page-class-event');
                }
                $classOutput .= $getOutputList($eventsOutput, 'Events', 'page-class-events');

                // EXAMPLES
                $classOutput .= $getExamplesOutput($classData['examples'], 'page-class-examples', 'page-class-example');

                // SEE
                $classOutput .= $getSeeOutput($classData['see'], 'page-class-see-also', 'page-class-see');

                $location = str_replace('\\', '/', $classSourceFile);
                if ($outputType === 'md') {
                    $classOutput .= '## Details' . "\n\n";
                    $classOutput .= 'Location: ~' . $location . "\n\n";
                    $classOutput .= '---' . "\n\n" . '[back to index](index.md)' . "\n\n";
                } else {
                    $classOutput .= '<div class="page-class-details">';
                    $classOutput .= '<div class="page-class-details-title">Details</div>';
                    $classOutput .= '<div class="page-class-details-location">Location: ~' . $location . '</div>';
                    $classOutput .= '<div class="page-class-details-back-to-index"><a href="index.html">back to index</a></div>';
                    $classOutput .= '</div>';
                }

                $writeFile($this->getClassOutputFilename($className, $outputType), $classOutput);

                // METHODS PAGES
                $methods = $filterMethods($classData['methods']);
                foreach ($methods as $methodData) {
                    if ($methodData['class'] === $className) {
                        $methodOutput = '';
                        $methodPageName = $methodData['class'] . '::' . $methodData['name'];
                        if ($outputType === 'md') {
                            $methodOutput .= '# ' . $methodPageName . "\n\n";
                        } else {
                            $methodOutput .= '<div class="page-method-name">' . $methodPageName . "</div>";
                        }

                        if (!empty($methodData['description'])) {
                            if ($outputType === 'md') {
                                $methodOutput .= $methodData['description'] . "\n\n";
                            } else {
                                $methodOutput .= '<div class="page-method-description">' . $methodData['description'] . '</div>';
                            }
                        }

                        if ($outputType === 'md') {
                            $methodOutput .= "```php\n" . $getMethodSynopsis($methodData, false) . "\n```\n\n";
                        } else {
                            $methodOutput .= '<div class="page-method-synopsis">' . $getMethodSynopsis($methodData, false) . '</div>';
                        }

                        // PARAMETERS
                        $parametersOutput = '';
                        foreach ($methodData['parameters'] as $parameterData) {
                            $parametersOutput .= $getOutputListItem($parameterData['name'], $parameterData['description'], 'page-method-parameter');
                        }
                        $methodOutput .= $getOutputList($parametersOutput, 'Parameters', 'page-method-parameters');

                        // RETURN
                        if ($methodData['name'] !== '__construct') {
                            if (!empty($methodData['return']['description'])) {
                                $description = $methodData['return']['description'];
                                if ($outputType === 'md') {
                                    $methodOutput .= '## Returns' . "\n\n";
                                    $methodOutput .= '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' . $description . "\n\n";
                                } else {
                                    $methodOutput .= '<div class="page-method-returns">';
                                    $methodOutput .= '<div class="page-method-returns-title">Returns</div>';
                                    $methodOutput .= '<div class="page-method-returns-description">' . $description . '</div>';
                                    $methodOutput .= '</div>';
                                }
                            }
                        }

                        // EXAMPLES
                        $methodOutput .= $getExamplesOutput($methodData['examples'], 'page-method-examples', 'page-method-example');

                        // SEE
                        $methodOutput .= $getSeeOutput($methodData['see'], 'page-method-see-also', 'page-method-see');

                        // DETAILS
                        $location = str_replace('\\', '/', $classSourceFile);
                        $classLocation = $this->getClassOutputFilename($className, $outputType);
                        if ($outputType === 'md') {
                            $methodOutput .= '## Details' . "\n\n";
                            $methodOutput .= "Class: [" . $className . "](" . $classLocation . ")\n\n";
                            $methodOutput .= 'Location: ~' . $location . "\n\n";
                            $methodOutput .= '---' . "\n\n" . '[back to index](index.md)' . "\n\n";
                        } else {
                            $methodOutput .= '<div class="page-method-details">';
                            $methodOutput .= '<div class="page-method-details-title">Details</div>';
                            $methodOutput .= '<div class="page-method-details-class">Class: <a href="' . $classLocation . '">' . $className . '</a></div>';
                            $methodOutput .= '<div class="page-method-details-location">Location: ~' . $location . '</div>';
                            $methodOutput .= '<div class="page-method-details-back-to-index"><a href="index.html">back to index</a></div>';
                            $methodOutput .= '</div>';
                        }

                        $writeFile($this->getMethodOutputFilename($className, $methodData['name'], $outputType), $methodOutput);
                    }
                }

                // INDEX
                if ($outputType === 'md') {
                    $indexOutput .= '### [' . $className . '](' . $this->getClassOutputFilename($className, $outputType) . ')' . "\n\n";
                    if (!empty($classData['description'])) {
                        $indexOutput .= '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' . $classData['description'] . "\n\n";
                    }
                } else {
                    $indexOutput .= '<div class="page-index-class">';
                    $indexOutput .= '<div class="page-index-class-name"><a href="' . $this->getClassOutputFilename($className, $outputType) . '">' . $className . '</a></div>';
                    if (!empty($classData['description'])) {
                        $indexOutput .= '<div class="page-index-class-description">' . $classData['description'] . '</div>';
                    }
                    $indexOutput .= '</div>';
                }
            }

            if ($outputType === 'html') {
                $indexOutput .= '</div>'; //class="page-index-classes"
            }

            $writeFile('index.' . $outputType, $indexOutput);
        }
    }

    /**
     * 
     * @param string $class
     * @param string $method
     * @param string $outputType
     * @return string
     */
    private function getMethodOutputFilename(string $class, string $method, string $outputType): string
    {
        return str_replace('\\', '.', strtolower($class . '.' . $method)) . '.method.' . $outputType;
    }

    /**
     * 
     * @param string $class
     * @param string $outputType
     * @return string
     */
    private function getClassOutputFilename(string $class, string $outputType): string
    {
        return str_replace('\\', '.', strtolower($class)) . '.class.' . $outputType;
    }

    /**
     * 
     * @param string $dir
     * @param string $extension
     * @return array
     */
    private function getFiles(string $dir, string $extension = 'php'): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $dir = realpath($dir);
        $files = scandir($dir);
        $result = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === '.git' || substr($file, 0, 1) === '_') {
                continue;
            }
            if (is_dir($dir . DIRECTORY_SEPARATOR . $file)) {
                $result = array_merge($result, $this->getFiles($dir . DIRECTORY_SEPARATOR . $file));
            } else {
                if (pathinfo($file, PATHINFO_EXTENSION) === $extension) {
                    $result[] = $dir . DIRECTORY_SEPARATOR . $file;
                }
            }
        }
        return $result;
    }

}
