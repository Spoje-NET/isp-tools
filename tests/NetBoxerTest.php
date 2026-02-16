<?php

declare(strict_types=1);

namespace SpojeNet\tests;

use PHPUnit\Framework\TestCase;
use SpojeNet\NetBoxer;

/**
 * Test NetBoxer functionality
 */
class NetBoxerTest extends TestCase
{
    private array $originalEnv;

    protected function setUp(): void
    {
        // Backup original environment
        $this->originalEnv = [
            'NETBOXURL' => getenv('NETBOXURL'),
            'NETBOXTOKEN' => getenv('NETBOXTOKEN'),
        ];

        // Set test environment
        putenv('NETBOXURL=https://test-netbox.example.com');
        putenv('NETBOXTOKEN=test-token-123');
    }

    protected function tearDown(): void
    {
        // Restore original environment
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
    }

    public function testConstructorWithValidConfig(): void
    {
        $this->assertInstanceOf(NetBoxer::class, new NetBoxer());
    }

    public function testConstructorThrowsExceptionWithMissingUrl(): void
    {
        putenv('NETBOXURL'); // Remove required config

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Missing required NetBox configuration');

        new NetBoxer();
    }

    public function testConstructorThrowsExceptionWithMissingToken(): void
    {
        putenv('NETBOXTOKEN'); // Remove required config

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