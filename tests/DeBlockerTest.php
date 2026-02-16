<?php

declare(strict_types=1);

namespace SpojeNet\tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use SpojeNet\DeBlocker;
use SpojeNet\NetworkBackendInterface;

/**
 * Test DeBlocker orchestration
 */
class DeBlockerTest extends TestCase
{
    private MockObject&NetworkBackendInterface $mockAdapter;
    private DeBlocker $deblocker;

    protected function setUp(): void
    {
        $this->mockAdapter = $this->createMock(NetworkBackendInterface::class);
        $this->deblocker = new DeBlocker($this->mockAdapter);
    }

    public function testBlockCustomersCallsAdapterForEachIp(): void
    {
        $customerIps = [
            ['ip' => '192.168.1.10'],
            ['ip' => '192.168.1.20'],
            ['ip' => '192.168.1.30']
        ];

        $this->mockAdapter->expects($this->exactly(3))
            ->method('blockIp')
            ->willReturn(true);

        $result = $this->deblocker->blockCustomers($customerIps);

        $this->assertTrue($result);
    }

    public function testUnblockCustomersCallsAdapterForEachIp(): void
    {
        $customerIps = [
            ['ip' => '192.168.1.10', 'speed' => 100],
            ['ip' => '192.168.1.20', 'speed' => 50]
        ];

        $this->mockAdapter->expects($this->exactly(2))
            ->method('unblockIp')
            ->willReturn(true);

        $result = $this->deblocker->unblockCustomers($customerIps);

        $this->assertTrue($result);
    }

    public function testBlockCustomersReturnsFalseWhenAdapterFails(): void
    {
        $customerIps = [
            ['ip' => '192.168.1.10'],
            ['ip' => '192.168.1.20']
        ];

        $this->mockAdapter->expects($this->exactly(2))
            ->method('blockIp')
            ->willReturnOnConsecutiveCalls(true, false);

        $result = $this->deblocker->blockCustomers($customerIps);

        $this->assertFalse($result);
    }

    public function testUnblockCustomersReturnsFalseWhenAdapterFails(): void
    {
        $customerIps = [
            ['ip' => '192.168.1.10', 'speed' => 100],
            ['ip' => '192.168.1.20', 'speed' => 50]
        ];

        $this->mockAdapter->expects($this->exactly(2))
            ->method('unblockIp')
            ->willReturnOnConsecutiveCalls(true, false);

        $result = $this->deblocker->unblockCustomers($customerIps);

        $this->assertFalse($result);
    }

    public function testBlockCustomersWithEmptyArray(): void
    {
        $result = $this->deblocker->blockCustomers([]);

        $this->assertTrue($result);
    }

    public function testUnblockCustomersWithEmptyArray(): void
    {
        $result = $this->deblocker->unblockCustomers([]);

        $this->assertTrue($result);
    }
}