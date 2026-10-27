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
use SpojeNet\NetBoxer;

/**
 * Test NetBoxer functionality.
 */
class NetBoxerTest extends TestCase
{
    private array $originalEnv;

    protected function setUp(): void
    {
        // Backup original environment
        $this->originalEnv = [
            'NETBOX_URL' => getenv('NETBOX_URL'),
            'NETBOX_TOKEN' => getenv('NETBOX_TOKEN'),
        ];

        // Set test environment
        putenv('NETBOX_URL=https://test-netbox.example.com');
        putenv('NETBOX_TOKEN=test-token-123');
    }

    protected function tearDown(): void
    {
        // Restore original environment
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key.'='.$value);
            }
        }
    }

    public function testConstructorWithValidConfig(): void
    {
        $this->assertInstanceOf(NetBoxer::class, new NetBoxer());
    }

    public function testConstructorThrowsExceptionWithMissingUrl(): void
    {
        putenv('NETBOX_URL'); // Remove required config

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Missing required NetBox configuration');

        new NetBoxer();
    }

    public function testConstructorThrowsExceptionWithMissingToken(): void
    {
        putenv('NETBOX_TOKEN'); // Remove required config

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Missing required NetBox configuration');

        new NetBoxer();
    }

    public function testImplementsNetworkBackendInterface(): void
    {
        $netboxer = new NetBoxer();
        $this->assertInstanceOf(\SpojeNet\NetworkBackendInterface::class, $netboxer);
    }

    public function testBlockIpReturnsBoolean(): void
    {
        $netboxer = new NetBoxer();
        $result = $netboxer->blockIp('192.168.1.10');

        $this->assertIsBool($result);
    }

    public function testUnblockIpReturnsBoolean(): void
    {
        $netboxer = new NetBoxer();
        $result = $netboxer->unblockIp('192.168.1.10', 100);

        $this->assertIsBool($result);
    }

    public function testBlockIpWithInvalidIp(): void
    {
        $netboxer = new NetBoxer();
        $result = $netboxer->blockIp('invalid-ip');

        // Should handle gracefully
        $this->assertIsBool($result);
    }

    public function testUnblockIpWithInvalidIp(): void
    {
        $netboxer = new NetBoxer();
        $result = $netboxer->unblockIp('invalid-ip', 50);

        // Should handle gracefully
        $this->assertIsBool($result);
    }
}
