<?php

declare(strict_types=1);

namespace SpojeNet\tests;

use PHPUnit\Framework\TestCase;

/**
 * Basic integration test
 */
class IntegrationTest extends TestCase
{
    public function testClassesCanBeInstantiated(): void
    {
        // Test that all main classes can be loaded
        $this->assertTrue(class_exists(\SpojeNet\DeBlocker::class));
        $this->assertTrue(class_exists(\SpojeNet\SubVersioner::class));
        $this->assertTrue(class_exists(\SpojeNet\NetBoxer::class));
        $this->assertTrue(interface_exists(\SpojeNet\NetworkBackendInterface::class));
    }

    public function testNamespaceStructure(): void
    {
        // Test that classes are in the correct namespace
        $this->assertTrue(class_exists('SpojeNet\\DeBlocker'));
        $this->assertTrue(class_exists('SpojeNet\\SubVersioner'));
        $this->assertTrue(class_exists('SpojeNet\\NetBoxer'));
        $this->assertTrue(interface_exists('SpojeNet\\NetworkBackendInterface'));
    }

    public function testAutoloadingWorks(): void
    {
        // Test that autoloading works for our classes
        $reflection = new \ReflectionClass(\SpojeNet\DeBlocker::class);
        $this->assertStringContainsString('src/SpojeNet', $reflection->getFileName());
    }
}