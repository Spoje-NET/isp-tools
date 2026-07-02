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
use SpojeNet\PaymentMatcher;

/**
 * Test PaymentMatcher decision tree.
 */
class PaymentMatcherTest extends TestCase
{
    private \AbraFlexi\Bricks\ParovacFaktur&MockObject $parovac;
    private PaymentMatcher $matcher;

    protected function setUp(): void
    {
        $this->parovac = $this->createMock(\AbraFlexi\Bricks\ParovacFaktur::class);
        $this->matcher = new PaymentMatcher($this->parovac);
    }

    public function testMissingVarSymIsUnmatched(): void
    {
        $this->parovac->expects($this->never())->method('findInvoice');

        $result = $this->matcher->match($this->payment(['varSym' => '']));

        $this->assertSame(PaymentMatcher::EXIT_UNMATCHED, $result['exitcode']);
        $this->assertSame('no_varsym', $result['reason']);
    }

    public function testUnknownVarSymIsUnmatched(): void
    {
        $this->parovac->method('findInvoice')->willReturn([]);

        $result = $this->matcher->match($this->payment(['varSym' => '123456', 'sumCelkem' => '100']));

        $this->assertSame(PaymentMatcher::EXIT_UNMATCHED, $result['exitcode']);
        $this->assertSame('unknown_varsym', $result['reason']);
        $this->assertSame('123456', $result['varSym']);
    }

    public function testAmbiguousVarSymIsUnmatched(): void
    {
        $this->parovac->method('findInvoice')->willReturn([
            1 => ['kod' => 'code:FAK1', 'zbyvaUhradit' => '100'],
            2 => ['kod' => 'code:FAK2', 'zbyvaUhradit' => '200'],
        ]);
        $this->parovac->expects($this->never())->method('settleInvoice');

        $result = $this->matcher->match($this->payment(['varSym' => '123456', 'sumCelkem' => '100']));

        $this->assertSame(PaymentMatcher::EXIT_UNMATCHED, $result['exitcode']);
        $this->assertSame('ambiguous', $result['reason']);
    }

    public function testExactPaymentIsMatched(): void
    {
        $payment = $this->payment(['varSym' => '123456', 'sumCelkem' => '100']);

        $this->parovac->method('findInvoice')->willReturn([
            1 => ['kod' => 'code:FAK1', 'zbyvaUhradit' => '100'],
        ]);
        $this->parovac->expects($this->once())
            ->method('settleInvoice')
            ->with($this->isInstanceOf(\AbraFlexi\FakturaVydana::class), $payment)
            ->willReturn(1);

        $result = $this->matcher->match($payment);

        $this->assertSame(PaymentMatcher::EXIT_MATCHED, $result['exitcode']);
        $this->assertSame('matched', $result['reason']);
        $this->assertSame('FAK1', $result['invoice']);
    }

    public function testUnderpaymentIsMatchedPartial(): void
    {
        $this->parovac->method('findInvoice')->willReturn([
            1 => ['kod' => 'code:FAK1', 'zbyvaUhradit' => '100'],
        ]);
        $this->parovac->method('settleInvoice')->willReturn(1);

        $result = $this->matcher->match($this->payment(['varSym' => '123456', 'sumCelkem' => '40']));

        $this->assertSame(PaymentMatcher::EXIT_MATCHED, $result['exitcode']);
        $this->assertSame('matched_partial', $result['reason']);
    }

    public function testOverpaymentInManualModeIsUnmatched(): void
    {
        $this->parovac->method('findInvoice')->willReturn([
            1 => ['kod' => 'code:FAK1', 'zbyvaUhradit' => '100'],
        ]);
        $this->parovac->expects($this->never())->method('settleInvoice');

        $result = $this->matcher->match($this->payment(['varSym' => '123456', 'sumCelkem' => '150']), 'manual');

        $this->assertSame(PaymentMatcher::EXIT_UNMATCHED, $result['exitcode']);
        $this->assertSame('overpayment', $result['reason']);
    }

    public function testOverpaymentInSettleModeIsMatched(): void
    {
        $this->parovac->method('findInvoice')->willReturn([
            1 => ['kod' => 'code:FAK1', 'zbyvaUhradit' => '100'],
        ]);
        $this->parovac->expects($this->once())->method('settleInvoice')->willReturn(1);

        $result = $this->matcher->match($this->payment(['varSym' => '123456', 'sumCelkem' => '150']));

        $this->assertSame(PaymentMatcher::EXIT_MATCHED, $result['exitcode']);
        $this->assertSame('matched_overpayment', $result['reason']);
    }

    public function testSettleFailureIsError(): void
    {
        $this->parovac->method('findInvoice')->willReturn([
            1 => ['kod' => 'code:FAK1', 'zbyvaUhradit' => '100'],
        ]);
        $this->parovac->method('settleInvoice')->willReturn(0);

        $result = $this->matcher->match($this->payment(['varSym' => '123456', 'sumCelkem' => '100']));

        $this->assertSame(PaymentMatcher::EXIT_ERROR, $result['exitcode']);
        $this->assertSame('settle_failed', $result['reason']);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function payment(array $values): \AbraFlexi\Banka&MockObject
    {
        $payment = $this->createMock(\AbraFlexi\Banka::class);
        $payment->method('getDataValue')->willReturnCallback(static fn (string $key) => $values[$key] ?? null);
        $payment->method('getRecordIdent')->willReturn((string) ($values['kod'] ?? 'code:TEST'));

        return $payment;
    }
}
