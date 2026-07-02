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

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SpojeNet\DeBlocker;
use SpojeNet\NetworkBackendInterface;

/**
 * Test DeBlocker orchestration.
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

    public function testBlockCustomersBlocksEachResolvedIp(): void
    {
        $this->mockAdapter->method('getCustomerIPs')->willReturnMap([
            ['CUST1', ['192.168.1.10', '192.168.1.20']],
            ['CUST2', ['192.168.1.30']],
        ]);
        $this->mockAdapter->expects($this->exactly(3))
            ->method('blockIp')
            ->willReturn(true);

        $results = $this->deblocker->blockCustomers(['CUST1', 'CUST2']);

        $this->assertSame(
            [
                'CUST1' => ['ips' => ['192.168.1.10', '192.168.1.20'], 'blocked' => 2, 'failed' => 0],
                'CUST2' => ['ips' => ['192.168.1.30'], 'blocked' => 1, 'failed' => 0],
            ],
            $results,
        );
    }

    public function testBlockCustomersCountsFailures(): void
    {
        $this->mockAdapter->method('getCustomerIPs')->willReturn(['192.168.1.10', '192.168.1.20']);
        $this->mockAdapter->expects($this->exactly(2))
            ->method('blockIp')
            ->willReturnOnConsecutiveCalls(true, false);

        $results = $this->deblocker->blockCustomers(['CUST1']);

        $this->assertSame(1, $results['CUST1']['blocked']);
        $this->assertSame(1, $results['CUST1']['failed']);
    }

    public function testBlockCustomersWithoutIpsDoesNotBlock(): void
    {
        $this->mockAdapter->method('getCustomerIPs')->willReturn([]);
        $this->mockAdapter->expects($this->never())->method('blockIp');

        $results = $this->deblocker->blockCustomers(['CUST1']);

        $this->assertSame(['ips' => [], 'blocked' => 0, 'failed' => 0], $results['CUST1']);
    }

    public function testUnblockCustomersUsesFallbackSpeed(): void
    {
        $this->mockAdapter->method('getCustomerIPs')->willReturn(['192.168.1.10']);
        $this->mockAdapter->expects($this->once())
            ->method('unblockIp')
            ->with('192.168.1.10', 42)
            ->willReturn(true);

        $results = $this->deblocker->unblockCustomers(['CUST1'], 42);

        $this->assertSame(['ips' => ['192.168.1.10'], 'unblocked' => 1, 'failed' => 0], $results['CUST1']);
    }

    public function testUnblockCustomersCountsFailures(): void
    {
        $this->mockAdapter->method('getCustomerIPs')->willReturn(['192.168.1.10', '192.168.1.20']);
        $this->mockAdapter->expects($this->exactly(2))
            ->method('unblockIp')
            ->willReturnOnConsecutiveCalls(true, false);

        $results = $this->deblocker->unblockCustomers(['CUST1']);

        $this->assertSame(1, $results['CUST1']['unblocked']);
        $this->assertSame(1, $results['CUST1']['failed']);
    }

    public function testBlockCustomersWithEmptyArray(): void
    {
        $this->assertSame([], $this->deblocker->blockCustomers([]));
    }

    public function testUnblockCustomersWithEmptyArray(): void
    {
        $this->assertSame([], $this->deblocker->unblockCustomers([]));
    }
}
