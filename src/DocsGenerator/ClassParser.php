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
            if (!class_exists($class)) {
                return null;
            }
            $reflectionClass = new \ReflectionClass($class);
//        $reflectionClass->getInterfaces();
//        $reflectionClass->getTraits();

            $result['name'] = $reflectionClass->name;
            $result['namespace'] = $reflectionClass->getNamespaceName();
            $result['filename'] = $reflectionClass->getFileName();
            $classComments = self::parseDocComment($reflectionClass->getDocComment());

            $parentClass = $reflectionClass->getParentClass();
            $result['extends'] = $parentClass instanceof \ReflectionClass ? $parentClass->name : null;

            $result['constants'] = [];
            $constants = $reflectionClass->getConstants();
            foreach ($constants as $name => $value) {
                $result['constants'][] = [
                    'name' => $name,
                    'value' => $value,
                    'type' => gettype($value),
                    'description' => '',
                ];
            }

            $result['properties'] = [];
            $properties = $reflectionClass->getProperties();
            $defaultProperties = $reflectionClass->getDefaultProperties();
            foreach ($properties as $property) {
                $value = isset($defaultProperties[$property->name]) ? $defaultProperties[$property->name] : null;
                $propertyComments = self::parseDocComment($property->getDocComment());
                $result['properties'][] = [
                    'name' => $property->name,
                    'value' => $value,
                    'type' => $propertyComments['type'] !== null ? $propertyComments['type'] : gettype($value),
                    'isPrivate' => $property->isPrivate(),
                    'isProtected' => $property->isProtected(),
                    'isPublic' => $property->isPublic(),
                    'isStatic' => $property->isStatic(),
                    'description' => isset($propertyComments['description']) ? $propertyComments['description'] : '',
                ];
            }

            if (!empty($classComments['properties'])) {
                foreach ($classComments['properties'] as $property) {
                    $result['properties'][] = [
                        'name' => $property['name'],
                        'value' => null,
                        'type' => $property['type'],
                        'isPrivate' => false,
                        'isProtected' => false,
                        'isPublic' => true,
                        'isStatic' => false,
                        'description' => isset($property['description']) ? $property['description'] : '',
                    ];
                }
            }

            $result['methods'] = [];
            $methods = $reflectionClass->getMethods();
            foreach ($methods as $method) {
                $parameters = $method->getParameters();
                $parametersData = [];
                $methodComments = self::parseDocComment($method->getDocComment());
                foreach ($parameters as $i => $parameter) {
                    $value = null;
                    $type = null;
                    if (isset($parameter->hasType) && $parameter->hasType()) {
                        $type = (string) $parameter->getType();
                    }
                    if ($parameter->isOptional()) {
                        if ($parameter->isDefaultValueAvailable()) {
                            $value = $parameter->getDefaultValue();
                        }
                        if ($type === null) {
                            $type = gettype($value);
                        }
                    }
                    if (isset($methodComments['parameters'][$i]) && $methodComments['parameters'][$i]['name'] === $parameter->name) {
                        $type = $methodComments['parameters'][$i]['type'];
                    }
                    $parametersData[] = [
                        'name' => $parameter->name,
                        'value' => $value,
                        'type' => $type,
                        'isOptional' => $parameter->isOptional(),
                    ];
                }
                $result['methods'][] = [
                    'name' => $method->name,
                    'class' => $method->class,
                    'parameters' => $parametersData,
                    'isPrivate' => $method->isPrivate(),
                    'isProtected' => $method->isProtected(),
                    'isPublic' => $method->isPublic(),
                    'isStatic' => $method->isStatic(),
                    'isAbstract' => $method->isAbstract(),
                    'isFinal' => $method->isFinal(),
                    'isConstructor' => $method->isConstructor(),
                    'isDestructor' => $method->isDestructor(),
                    'description' => isset($methodComments['description']) ? $methodComments['description'] : '',
                    'return' => isset($methodComments['return']) ? $methodComments['return'] : '',
                ];
            }

            $result['extension'] = $reflectionClass->getExtensionName();

            self::$cache[$class] = $result;
        }
        return self::$cache[$class];
    }

    /**
     * 
     * @param string $comment
     * @return array
     */
    private static function parseDocComment(string$comment): array
    {
        $comment = trim($comment, "/* \n\r\t");
        $lines = explode("\n", $comment);
        $temp = [];
        foreach ($lines as $line) {
            $line = trim($line, " *");
            if (isset($line{0})) {
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
        if (isset($lines[0])) {
            $result['description'] = $lines[0][0] === '@' ? '' : $lines[0];
            foreach ($lines as $line) {
                if ($line[0] === '@') {
                    $lineParts = explode(' ', $line, 2);
                    $tag = trim($lineParts[0]);
                    $value = trim($lineParts[1]);
                    if ($tag === '@param') {
                        $valueParts = explode(' ', $value, 3);
                        $result['parameters'][] = [
                            'name' => isset($valueParts[1]) ? trim($valueParts[1], ' $') : null,
                            'type' => isset($valueParts[0]) ? trim($valueParts[0]) : null,
                            'description' => isset($valueParts[2]) ? trim($valueParts[2]) : null,
                        ];
                    } elseif ($tag === '@return') {
                        $valueParts = explode(' ', $value, 2);
                        $result['return'] = [
                            'type' => isset($valueParts[0]) ? trim($valueParts[0]) : null,
                            'description' => isset($valueParts[1]) ? trim($valueParts[1]) : null,
                        ];
                    } elseif ($tag === '@throws') {
                        $result['exceptions'][] = $value;
                    } elseif ($tag === '@var') {
                        $result['type'] = $value;
                    } elseif ($tag === '@property' || $tag === '@property-read' || $tag === '@property-write') {
                        $valueParts = explode(' ', $value, 3);
                        $result['properties'][] = [
                            'name' => isset($valueParts[1]) ? trim($valueParts[1], ' $') : null,
                            'type' => isset($valueParts[0]) ? trim($valueParts[0]) : null,
                            'description' => isset($valueParts[2]) ? trim($valueParts[2]) : null,
                        ];
                    }
                }
            }
            $result['exceptions'] = array_unique($result['exceptions']);
        }
        return $result;
    }

}
