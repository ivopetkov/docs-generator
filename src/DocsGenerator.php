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
     * @param string $projectDir
     * @param array $sourceDirs
     * @throws \InvalidArgumentException
     */
    public function __construct(string $projectDir, array $sourceDirs)
    {
        if (!is_dir($projectDir)) {
            throw new \InvalidArgumentException('The projectDir specified (' . $projectDir . ') is not a valid dir!');
        }
        $this->projectDir = realpath($projectDir);
        foreach ($sourceDirs as $sourceDir) {
            $sourceDir = DIRECTORY_SEPARATOR . trim($sourceDir, '/\\');
            if (!is_dir($this->projectDir . $sourceDir)) {
                throw new \InvalidArgumentException('The sourceDir specified (' . $this->projectDir . $sourceDir . ') is not a valid dir!');
            }
            $this->sourceDirs[] = $sourceDir;
        }
    }

    public function generateMarkdown(string $outputDir)
    {
        $this->generate($outputDir, 'markdown');
    }

    private function generate(string $outputDir, string $type)
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }
        $outputDir = rtrim($outputDir, '\/');
        $classNames = [];
        foreach ($this->sourceDirs as $i => $sourceDir) {
            $files = $this->getFiles($this->projectDir . $sourceDir);
            foreach ($files as $file) {
                $content = file_get_contents($file);
                if (preg_match('/class [a-zA-Z]*/', $content)) {
                    $declaredClasses = get_declared_classes();
                    require_once $file;
                    $newClasses = array_values(array_diff(get_declared_classes(), $declaredClasses));
                    foreach ($newClasses as $newClassName) {
                        $classNames[$newClassName] = str_replace($this->projectDir, '', $file);
                    }
                }
            }
        }

        asort($classNames);

        $writeFile = function(string $filename, string $content) use ($outputDir) {
            $filename = $outputDir . DIRECTORY_SEPARATOR . $filename;
            file_put_contents($filename, $content);
        };

        $indexOutput = '';
        $indexOutput .= '## Classes' . "\n\n";
        foreach ($classNames as $className => $classSourceFile) {
            $classData = ClassParser::parse($className);

            $classOutput = '';

            $classOutput .= '# ' . $className . "\n\n";

            if (!empty($classData['extends'])) {
                $classOutput .= "extends " . $this->getType((string) $classData['extends']) . "\n\n";
            }

            if (!empty($classData['implements'])) {
                $implements = array_map(function($value) {
                    return $this->getType((string) $value);
                }, $classData['implements']);
                $classOutput .= "implements " . implode(', ', $implements) . "\n\n";
            }

            if (!empty($classData['description'])) {
                $classOutput .= $classData['description'] . "\n\n";
            }

            if (!empty($classData['constants'])) {
                usort($classData['constants'], function($data1, $data2) {
                    return strcmp($data1['name'], $data2['name']);
                });
                $classOutput .= '## Constants' . "\n\n";
                foreach ($classData['constants'] as $constantData) {
                    $classOutput .= "##### const " . $this->getType((string) $constantData['type']) . ' ' . $constantData['name'] . "\n\n";
                    if (!empty($constantData['description'])) {
                        $classOutput .= "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;" . $constantData['description'] . "\n\n";
                    }
                }
            }

            if (!empty($classData['properties'])) {
                usort($classData['properties'], function($data1, $data2) {
                    return strcmp($data1['name'], $data2['name']);
                });
                $propertiesOutput = '';
                $inheritedProperties = [];
                foreach ($classData['properties'] as $propertyData) {
                    if ($propertyData['isPrivate']) {
                        continue;
                    }
                    $keywords = [];
                    if ($propertyData['isStatic']) {
                        $keywords[] = 'static';
                    }
                    if ($propertyData['isPublic']) {
                        $keywords[] = 'public';
                    }
                    if ($propertyData['isProtected']) {
                        $keywords[] = 'protected';
                    }
                    if ($propertyData['isPrivate']) {
                        $keywords[] = 'private';
                    }
                    if ($propertyData['isReadOnly']) {
                        $keywords[] = 'readonly';
                    }
                    $propertyOutput = "##### " . implode(' ', $keywords) . ' ' . $this->getType((string) $propertyData['type']) . ' $' . $propertyData['name'] . "\n\n";
                    if ($propertyData['class'] !== $className) {
                        if (!isset($inheritedProperties[$propertyData['class']])) {
                            $inheritedProperties[$propertyData['class']] = [];
                        }
                        $inheritedProperties[$propertyData['class']][] = $propertyOutput;
                        continue;
                    }
                    $propertiesOutput .= $propertyOutput;
                    if (!empty($propertyData['description'])) {
                        $propertiesOutput .= "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;" . $propertyData['description'] . "\n\n";
                    }
                }
                ksort($inheritedProperties);
                foreach ($inheritedProperties as $inheritedClassName => $inheritedPropertiesOutput) {
                    $propertiesOutput .= '### Inherited from ' . $this->getType($inheritedClassName) . ":\n\n";
                    $propertiesOutput .= implode('', $inheritedPropertiesOutput);
                }
                if (!empty($propertiesOutput)) {
                    $classOutput .= '## Properties' . "\n\n";
                    $classOutput .= $propertiesOutput;
                }
            }

            if (!empty($classData['methods'])) {
                usort($classData['methods'], function($data1, $data2) {
                    return strcmp($data1['name'], $data2['name']);
                });
                $methodsOutput = '';
                $inheritedMethods = [];
                foreach ($classData['methods'] as $methodData) {
                    if ($methodData['isPrivate'] || (substr($methodData['name'], 0, 2) === '__' && $methodData['name'] !== '__construct')) {
                        continue;
                    }
                    if ($methodData['class'] !== $className) {
                        if (!isset($inheritedMethods[$methodData['class']])) {
                            $inheritedMethods[$methodData['class']] = [];
                        }
                        $inheritedMethods[$methodData['class']][] = "##### " . $this->getMethod($methodData) . "\n\n";
                        continue;
                    }
                    $methodsOutput .= "##### " . $this->getMethod($methodData) . "\n\n";
                    if (!empty($methodData['description'])) {
                        $methodsOutput .= "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;" . $methodData['description'] . "\n\n";
                    }

                    $methodOutput = '';
                    $methodOutput .= '# ' . $methodData['class'] . '::' . $methodData['name'] . "\n\n";
                    if (!empty($methodData['description'])) {
                        $methodOutput .= $methodData['description'] . "\n\n";
                    }
                    $methodOutput .= "```php\n" . $this->getMethod($methodData, false) . "\n```\n\n";
                    if (!empty($methodData['parameters'])) {
                        $methodOutput .= '## Parameters' . "\n\n";
                        foreach ($methodData['parameters'] as $i => $parameter) {
                            $methodOutput .= "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`$" . $parameter['name'] . "`\n\n";
                            if (!empty($parameter['description'])) {
                                $methodOutput .= "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;" . $parameter['description'] . "\n\n";
                            }
                        }
                    }

                    if ($methodData['name'] !== '__construct') {
                        $returnDescription = is_array($methodData['return']) ? $methodData['return']['description'] : '';
                        if (!empty($returnDescription)) {
                            $methodOutput .= '## Returns' . "\n\n";
                            $methodOutput .= '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' . $returnDescription . "\n\n";
                        }
                    }

                    $methodOutput .= '## Details' . "\n\n";
                    $methodOutput .= "Class: [" . $className . "](" . $this->getClassOutputFilename($className) . ")\n\n";
                    $methodOutput .= "File: " . str_replace('\\', '/', $classSourceFile) . "\n\n";
                    $methodOutput .= '---' . "\n\n" . '[back to index](index.md)' . "\n\n";

                    $writeFile($this->getMethodOutputFilename($className, $methodData['name']), $methodOutput);
                }
                ksort($inheritedMethods);
                foreach ($inheritedMethods as $inheritedClassName => $inheritedMethodsOutput) {
                    $methodsOutput .= '### Inherited from ' . $this->getType($inheritedClassName) . ":\n\n";
                    $methodsOutput .= implode('', $inheritedMethodsOutput);
                }
                if (!empty($methodsOutput)) {
                    $classOutput .= '## Methods' . "\n\n";
                    $classOutput .= $methodsOutput;
                }
            }

            $classOutput .= '## Details' . "\n\n";
            $classOutput .= "File: " . str_replace('\\', '/', $classSourceFile) . "\n\n";
            $classOutput .= '---' . "\n\n" . '[back to index](index.md)' . "\n\n";

            $writeFile($this->getClassOutputFilename($className), $classOutput);

            $indexOutput .= '### [' . $className . '](' . $this->getClassOutputFilename($className) . ')' . "\n\n";
            if (!empty($classData['description'])) {
                $indexOutput .= "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;" . $classData['description'] . "\n\n";
            }
        }

        $writeFile('index.md', $indexOutput);
    }

    /**
     * 
     * @param string $class
     * @param string $method
     * @return string
     */
    private function getMethodOutputFilename(string $class, string $method): string
    {
        return str_replace('\\', '.', strtolower($class . '.' . $method)) . '.method.md';
    }

    /**
     * 
     * @param string $class
     * @return string
     */
    private function getClassOutputFilename(string $class): string
    {
        return str_replace('\\', '.', strtolower($class)) . '.class.md';
    }

    /**
     * 
     * @param mixed $value
     * @return string
     */
    private function getValue($value): string
    {
        if (is_string($value)) {
            return '\'' . str_replace('\'', '\\\'', $value) . '\'';
        }
        return json_encode($value);
    }

    /**
     * 
     * @param string $type
     * @param bool $richOutput
     * @return string
     */
    private function getType(string $type, bool $richOutput = true): string
    {
        $parts = explode('|', $type);
        foreach ($parts as $i => $part) {
            $part = trim(trim($part), '\\');
            if ($richOutput) {
                if ($part !== 'void' && $part !== 'string' && $part !== 'int' && $part !== 'boolean' && $part !== 'array') {
                    $class = $part;
                    if (substr($class, -2) === '[]') {
                        $class = substr($class, 0, -2);
                    }
                    $classData = ClassParser::parse($class);
                    if (is_array($classData)) {
                        if (strlen($classData['extension']) > 0) {
                            $part = '[' . $part . '](http://php.net/manual/en/class.' . strtolower($class) . '.php)';
                        } else {
                            $part = '[' . $part . '](' . $this->getClassOutputFilename($class) . ')';
                        }
                    }
                }
            }
            $parts[$i] = $part;
        }
        return implode('|', $parts);
    }

    /**
     * 
     * @param array $method
     * @param bool $richOutput
     * @return string
     */
    private function getMethod(array $method, bool $richOutput = true): string
    {
        $result = '';
        $keywords = [];
        if ($method['isStatic']) {
            $keywords[] = 'static';
        }
        if ($method['isPublic']) {
            $keywords[] = 'public';
        }
        if ($method['isProtected']) {
            $keywords[] = 'protected';
        }
        if ($method['isPrivate']) {
            $keywords[] = 'private';
        }
        if ($method['isAbstract']) {
            $keywords[] = 'abstract';
        }
        if ($method['isFinal']) {
            $keywords[] = 'final';
        }

        $classData = ClassParser::parse($method['class']);

        if (empty($method['parameters'])) {
            $parameters = 'void';
        } else {
            $parameters = '';
            $bracketsToAddInTheEnd = 0;
            foreach ($method['parameters'] as $parameter) {
                if ($parameter['isOptional']) {
                    $parameters .= ' [, ';
                    $bracketsToAddInTheEnd++;
                } else {
                    $parameters .= ' , ';
                }
                $parameters .= $this->getType((string) $parameter['type'], $richOutput) . ' $' . $parameter['name'] . ($parameter['value'] !== null ? ' = ' . $this->getValue($parameter['value']) : '');
            }
            if ($bracketsToAddInTheEnd > 0) {
                $parameters .= ' ' . str_repeat(']', $bracketsToAddInTheEnd) . ' ';
            }
            $parameters = trim($parameters, ' ,');
            if (substr($parameters, 0, 2) === '[,') {
                $parameters = '[' . substr($parameters, 2);
            }
        }
        $returnType = isset($method['return']['type']) ? $method['return']['type'] : 'void';
        $name = $method['name'];
        $url = strlen($classData['extension']) > 0 ? 'http://php.net/manual/en/' . strtolower($method['class'] . '.' . $name) . '.php' : $this->getMethodOutputFilename($method['class'], $name);
        $result .= implode(' ', $keywords) . ($method['isConstructor'] || $method['isDestructor'] ? '' : ' ' . $this->getType((string) $returnType, $richOutput)) . ' ' . ($richOutput ? '[' . $name . '](' . $url . ')' : $name) . ' ( ' . $parameters . ' )' . "\n";
        return trim($result);
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
