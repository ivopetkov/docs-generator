<?php

/*
 * Docs Generator
 * https://github.com/ivopetkov/docs-generator
 * Copyright (c) Ivo Petkov
 * Free to use under the MIT license.
 */

use IvoPetkov\DocsGenerator;

/**
 * @runTestsInSeparateProcesses
 */
class DocsGeneratorTest extends PHPUnit\Framework\TestCase
{

    /**
     *
     */
    public function testConstructor()
    {
        $tempDir = sys_get_temp_dir() . '/ivopetkov-docs-generator-unit-tests-' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        $docsGenerator = new DocsGenerator(__DIR__ . '/../');
        $docsGenerator->addSourceDir('src');
        $docsGenerator->generateMarkDown($tempDir);
        $this->assertTrue(is_file($tempDir . '/index.md'));
        $this->assertTrue(is_file($tempDir . '/ivopetkov.docsgenerator.classparser.class.md'));
        $this->assertTrue(is_file($tempDir . '/ivopetkov.docsgenerator.classparser.parse.method.md'));
    }
}
