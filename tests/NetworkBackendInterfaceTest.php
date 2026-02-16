<?php

declare(strict_types=1);

namespace SpojeNet\tests;

use PHPUnit\Framework\TestCase;
use SpojeNet\NetworkBackendInterface;

/**
 * Test NetworkBackendInterface contract
 */
class NetworkBackendInterfaceTest extends TestCase
{
    public function testInterfaceExists(): void
    {
        $this->assertTrue(interface_exists(NetworkBackendInterface::class));
    }

    public function testInterfaceHasRequiredMethods(): void
    {
        $reflection = new \ReflectionClass(NetworkBackendInterface::class);

        $this->assertTrue($reflection->hasMethod('blockIp'));
        $this->assertTrue($reflection->hasMethod('unblockIp'));

        // Check method signatures
        $blockIpMethod = $reflection->getMethod('blockIp');
        $unblockIpMethod = $reflection->getMethod('unblockIp');

        $this->assertEquals(1, $blockIpMethod->getNumberOfParameters());
        $this->assertEquals(2, $unblockIpMethod->getNumberOfParameters());

        // Check return types
        $this->assertEquals('bool', $blockIpMethod->getReturnType()->getName());
        $this->assertEquals('bool', $unblockIpMethod->getReturnType()->getName());
    }
}