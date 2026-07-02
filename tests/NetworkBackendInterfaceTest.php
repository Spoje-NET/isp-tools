<?php

declare(strict_types=1);

/**
 * This file is part of the ISP Tools package
 *
 * https://github.com/Spoje-NET/isp-tools
 *
 * (c) Spoje.Net <https://spoje.net/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SpojeNet\tests;

use PHPUnit\Framework\TestCase;
use SpojeNet\NetworkBackendInterface;

/**
 * Test NetworkBackendInterface contract.
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
