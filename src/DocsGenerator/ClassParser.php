<?php

/*
 * Docs Generator
 * https://github.com/ivopetkov/docs-generator
 * Copyright (c) Ivo Petkov
 * Free to use under the MIT license.
 */

namespace IvoPetkov\DocsGenerator;

/**
 * 
 */
class ClassParser
{

    static private $cache = [];

    /**
     * 
     * @param string $class
     * @return array|null
     */
    static function parse(string $class)
    {
        if (!isset(self::$cache[$class])) {
            $result = [];
            if (!class_exists($class) && !interface_exists($class)) {
                return null;
            }
            $reflectionClass = new \ReflectionClass($class);

            $result['name'] = $reflectionClass->name;
            $result['namespace'] = $reflectionClass->getNamespaceName();
            $result['filename'] = $reflectionClass->getFileName();

            $classComments = self::parseDocComment($reflectionClass->getDocComment());

            $parentClass = $reflectionClass->getParentClass();
            $result['extends'] = $parentClass instanceof \ReflectionClass ? $parentClass->name : null;
            $result['implements'] = $reflectionClass->getInterfaceNames();
            $result['keywords'] = [];
            if ($classComments['internal']) {
                $result['keywords'][] = 'internal';
            }
            if ($reflectionClass->isFinal()) {
                $result['keywords'][] = 'final';
            }
            if ($reflectionClass->isAbstract()) {
                $result['keywords'][] = 'abstract';
            }
            if ($reflectionClass->isInterface()) {
                $result['keywords'][] = 'interface';
            }
            if ($reflectionClass->isTrait()) {
                $result['keywords'][] = 'trait';
            }

            $methodsToSkip = [];
            if (array_search('ArrayAccess', $result['implements']) !== false) {
                $methodsToSkip = array_merge($methodsToSkip, ['offsetGet', 'offsetExists', 'offsetSet', 'offsetUnset']);
            }
            if (array_search('Iterator', $result['implements']) !== false) {
                $methodsToSkip = array_merge($methodsToSkip, ['current', 'next', 'key', 'valid', 'rewind']);
            }

            $result['description'] = isset($classComments['description']) ? $classComments['description'] : '';

            $result['constants'] = [];
            $constants = $reflectionClass->getConstants();
            foreach ($constants as $name => $value) {
                $constant = $reflectionClass->getReflectionConstant($name);
                $constantComments = self::parseDocComment($constant->getDocComment());
                $result['constants'][] = [
                    'name' => $name,
                    'class' => $constant->class,
                    'value' => $value,
                    'type' => $value !== null ? self::updateType(gettype($value)) : null,
                    'description' => isset($constantComments['description']) ? $constantComments['description'] : '',
                ];
            }
            usort($result['constants'], function ($constant1, $constant2) {
                return strcmp($constant1['name'], $constant2['name']);
            });

            $result['properties'] = [];
            $properties = $reflectionClass->getProperties();
            $defaultProperties = $reflectionClass->getDefaultProperties();
            foreach ($properties as $property) {
                $value = isset($defaultProperties[$property->name]) ? $defaultProperties[$property->name] : null;
                $propertyComments = self::parseDocComment($property->getDocComment());
                $keywords = [];
                if ($property->isPrivate()) {
                    $keywords[] = 'private';
                }
                if ($property->isProtected()) {
                    $keywords[] = 'protected';
                }
                if ($property->isPublic()) {
                    $keywords[] = 'public';
                }
                if ($property->isStatic()) {
                    $keywords[] = 'static';
                }
                $result['properties'][] = [
                    'name' => $property->name,
                    'class' => $property->class,
                    'value' => $value,
                    'type' => self::updateType($propertyComments['type'] !== null ? $propertyComments['type'] : ($value !== null ? gettype($value) : null)),
                    'keywords' => $keywords,
                    'description' => isset($propertyComments['description']) ? $propertyComments['description'] : '',
                ];
            }

            if (!empty($classComments['properties'])) {
                foreach ($classComments['properties'] as $propertyComments) {
                    $keywords = [];
                    $keywords[] = 'public';
                    if (isset($propertyComments['readonly']) && $propertyComments['readonly']) {
                        $keywords[] = 'readonly';
                    }
                    $result['properties'][] = [
                        'name' => $propertyComments['name'],
                        'class' => $class,
                        'value' => null,
                        'type' => $propertyComments['type'],
                        'keywords' => $keywords,
                        'description' => isset($propertyComments['description']) ? $propertyComments['description'] : '',
                    ];
                }
            }
            usort($result['properties'], function ($property1, $property2) {
                return strcmp($property1['name'], $property2['name']);
            });

            $result['methods'] = [];
            $methods = $reflectionClass->getMethods();
            foreach ($methods as $method) {
                if (array_search($method->name, $methodsToSkip) !== false) {
                    continue;
                }
                $parameters = $method->getParameters();
                $parametersData = [];
                $methodComments = self::parseDocComment($method->getDocComment());
                foreach ($parameters as $i => $parameter) {
                    $value = null;
                    $type = null;
                    if ($parameter->hasType()) {
                        $type = (string) $parameter->getType();
                    }
                    if ($parameter->isOptional()) {
                        if ($parameter->isDefaultValueAvailable()) {
                            $value = $parameter->getDefaultValue();
                        }
                        if ($type === null && $value !== null) {
                            $type = gettype($value);
                        }
                    }
                    $description = '';
                    if (isset($methodComments['parameters'][$i]) && $methodComments['parameters'][$i]['name'] === $parameter->name) {
                        $type = $methodComments['parameters'][$i]['type'];
                        $description = $methodComments['parameters'][$i]['description'];
                    }
                    $keywords = [];
                    if ($parameter->isOptional()) {
                        $keywords[] = 'optional';
                    }
                    $parametersData[] = [
                        'name' => $parameter->name,
                        'value' => $value,
                        'type' => self::updateType($type),
                        'keywords' => $keywords,
                        'description' => $description,
                    ];
                }

                $keywords = [];
                if ($method->isPrivate()) {
                    $keywords[] = 'private';
                }
                if ($method->isProtected()) {
                    $keywords[] = 'protected';
                }
                if ($method->isPublic()) {
                    $keywords[] = 'public';
                }
                if ($method->isStatic()) {
                    $keywords[] = 'static';
                }
                if ($method->isAbstract()) {
                    $keywords[] = 'abstract';
                }
                if ($method->isFinal()) {
                    $keywords[] = 'final';
                }
                if ($method->isConstructor()) {
                    $keywords[] = 'constructor';
                }
                if ($method->isDestructor()) {
                    $keywords[] = 'destructor';
                }
                $result['methods'][] = [
                    'name' => $method->name,
                    'class' => $method->class,
                    'parameters' => $parametersData,
                    'keywords' => $keywords,
                    'description' => isset($methodComments['description']) ? $methodComments['description'] : '',
                    'return' => isset($methodComments['return']) ? $methodComments['return'] : ['type' => 'void', 'description' => ''],
                    'examples' => isset($methodComments['examples']) ? $methodComments['examples'] : [],
                    'see' => isset($methodComments['see']) ? $methodComments['see'] : [],
                ];
            }
            usort($result['methods'], function ($method1, $method2) {
                $method1IsPublic = array_search('public', $method1['keywords']);
                $method1IsProtected = array_search('protected', $method1['keywords']);
                $method1IsPrivate = array_search('private', $method1['keywords']);
                $method2IsPublic = array_search('public', $method2['keywords']);
                $method2IsProtected = array_search('protected', $method2['keywords']);
                $method2IsPrivate = array_search('private', $method2['keywords']);
                if ((int) $method1IsPublic . (int) $method1IsProtected . (int) $method1IsPrivate !== (int) $method2IsPublic . (int) $method2IsProtected . (int) $method2IsPrivate) {
                    if ($method1IsPublic) {
                        return -1;
                    }
                    if ($method1IsPrivate) {
                        return 1;
                    }
                    return 1;
                }
                return strcmp($method1['name'], $method2['name']);
            });

            $result['extension'] = $reflectionClass->getExtensionName();

            $result['events'] = [];
            foreach ($classComments['events'] as $eventComments) {
                $result['events'][] = [
                    'name' => $eventComments['name'],
                    'type' => $eventComments['type'],
                    'description' => $eventComments['description']
                ];
            }
            usort($result['events'], function ($event1, $event2) {
                return strcmp($event1['name'], $event2['name']);
            });

            $result['examples'] = isset($classComments['examples']) ? $classComments['examples'] : [];
            $result['see'] = isset($classComments['see']) ? $classComments['see'] : [];

            self::$cache[$class] = $result;
        }
        return self::$cache[$class];
    }

    /**
     * 
     * @param string $comment
     * @return array
     */
    private static function parseDocComment(string $comment): array
    {
        $comment = trim($comment, "/* \n\r\t");
        $lines = explode("\n", $comment);
        $temp = [];
        foreach ($lines as $line) {
            $line = trim($line, " *");
            $line = trim($line);
            if (isset($line[0])) {
                $temp[] = $line;
            }
        }
        $lines = $temp;
        $result = [];
        $result['description'] = '';
        $result['type'] = null;
        $result['parameters'] = [];
        $result['return'] = null;
        $result['exceptions'] = [];
        $result['properties'] = [];
        $result['events'] = [];
        $result['internal'] = false;
        $result['examples'] = [];
        $result['see'] = [];

        foreach ($lines as $i => $line) {
            if ($line[0] === '@') {
                break;
            }
            $result['description'] .= $line . "\n";
            unset($lines[$i]);
        }
        $result['description'] = trim($result['description']);

        $previousTypedLineIndex = null;
        foreach ($lines as $i => $line) {
            if ($line[0] !== '@') {
                if ($previousTypedLineIndex !== null) {
                    $lines[$previousTypedLineIndex] .= "\n" . $lines[$i];
                }
                unset($lines[$i]);
                continue;
            }
            $previousTypedLineIndex = $i;
        }

        foreach ($lines as $line) {
            if ($line[0] === '@') {
                $lineParts = explode(' ', $line, 2);
                $tag = trim($lineParts[0]);
                $value = isset($lineParts[1]) ? trim($lineParts[1]) : '';
                if ($tag === '@param') {
                    $valueParts = explode(' ', $value, 3);
                    $result['parameters'][] = [
                        'name' => isset($valueParts[1]) ? trim($valueParts[1], ' $') : null,
                        'type' => isset($valueParts[0]) ? self::updateType(trim($valueParts[0])) : null,
                        'description' => isset($valueParts[2]) ? trim($valueParts[2]) : null,
                    ];
                } elseif ($tag === '@return') {
                    $valueParts = explode(' ', $value, 2);
                    $result['return'] = [
                        'type' => isset($valueParts[0]) ? self::updateType(trim($valueParts[0])) : null,
                        'description' => isset($valueParts[1]) ? trim($valueParts[1]) : null,
                    ];
                } elseif ($tag === '@throws') {
                    $result['exceptions'][] = $value;
                } elseif ($tag === '@var') {
                    $result['type'] = self::updateType($value);
                } elseif ($tag === '@property' || $tag === '@property-read' || $tag === '@property-write') {
                    $valueParts = explode(' ', $value, 3);
                    $result['properties'][] = [
                        'name' => isset($valueParts[1]) ? trim($valueParts[1], ' $') : null,
                        'type' => isset($valueParts[0]) ? self::updateType(trim($valueParts[0])) : null,
                        'description' => isset($valueParts[2]) ? trim($valueParts[2]) : null,
                        'readonly' => $tag === '@property-read'
                    ];
                } elseif ($tag === '@example') {
                    $valueParts = explode(' ', $value, 2);
                    $result['examples'][] = [
                        'location' => isset($valueParts[0]) ? trim($valueParts[0]) : null,
                        'description' => isset($valueParts[1]) ? trim($valueParts[1]) : null
                    ];
                } elseif ($tag === '@see') {
                    $valueParts = explode(' ', $value, 2);
                    $result['see'][] = [
                        'location' => isset($valueParts[0]) ? trim($valueParts[0]) : null,
                        'description' => isset($valueParts[1]) ? trim($valueParts[1]) : null
                    ];
                } elseif ($tag === '@internal') {
                    $result['internal'] = true;
                } elseif ($tag === '@event') {
                    $valueParts = explode(' ', $value, 3);
                    $result['events'][] = [
                        'name' => isset($valueParts[1]) ? trim($valueParts[1], ' ') : null,
                        'type' => isset($valueParts[0]) ? self::updateType(trim($valueParts[0])) : null,
                        'description' => isset($valueParts[2]) ? trim($valueParts[2]) : null
                    ];
                }
            }
        }
        $result['exceptions'] = array_unique($result['exceptions']);
        return $result;
    }

    /**
     * 
     * @param string|null $type
     * @return string
     */
    private static function updateType($type): ?string
    {
        if ($type === null) {
            return null;
        } elseif (is_string($type)) {
            $parts = explode('|', $type);
            $result = [];
            $isNullable = false;
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part[0] === '?') {
                    $part = substr($part, 1);
                    $isNullable = true;
                }
                $part = ltrim($part, '\\');
                if ($part === 'integer') {
                    $part = 'int';
                } elseif ($part === 'boolean') {
                    $part = 'bool';
                }
                if (isset($part[0])) {
                    $result[] = $part;
                }
            }
            if ($isNullable) {
                $result[] = 'null';
            }
            return implode('|', $result);
        } else {
            throw new \Exception('Unsupported type (' . gettype($type) . ')!');
        }
    }
}
