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
use SpojeNet\ReceivedPaymentNotifier;

/**
 * Test ReceivedPaymentNotifier message composition and sending.
 */
class ReceivedPaymentNotifierTest extends TestCase
{
    private const PAYMENT_DATA = [
        'kod' => 'code:BANK123',
        'sumCelkem' => '500.0',
        'mena' => 'code:CZK',
        'varSym' => '999888',
        'datVyst' => '2026-07-01+02:00',
    ];

    public function testComposeSubjectContainsPaymentCode(): void
    {
        $subject = ReceivedPaymentNotifier::composeSubject(self::PAYMENT_DATA);

        $this->assertStringContainsString('BANK123', $subject);
        $this->assertStringNotContainsString('code:', $subject);
    }

    public function testComposeBodyContainsPaymentFacts(): void
    {
        $body = ReceivedPaymentNotifier::composeBody(self::PAYMENT_DATA);

        $this->assertStringContainsString('500.0', $body);
        $this->assertStringContainsString('CZK', $body);
        $this->assertStringContainsString('999888', $body);
        $this->assertStringContainsString('2026-07-01', $body);
        $this->assertStringNotContainsString('+02:00', $body);
    }

    public function testSendUsesInjectedMailer(): void
    {
        $payment = $this->createMock(\AbraFlexi\Banka::class);
        $payment->method('getData')->willReturn(self::PAYMENT_DATA);
        $payment->method('getRecordIdent')->willReturn('code:BANK123');

        $mailer = $this->createMock(\Ease\Mailer::class);
        $mailer->expects($this->once())->method('send')->willReturn(true);

        $notifier = new ReceivedPaymentNotifier($payment);

        $this->assertTrue($notifier->send(static fn (string $to, string $subject, string $body) => $mailer, 'payer@example.com'));
    }

    public function testSendWithoutKnownPayerReturnsFalse(): void
    {
        $payment = $this->createMock(\AbraFlexi\Banka::class);
        $payment->method('getEmail')->willReturn('');
        $payment->method('getDataValue')->willReturn('');
        $payment->method('getRecordIdent')->willReturn('code:BANK123');

        $notifier = new ReceivedPaymentNotifier($payment);

        $this->assertFalse($notifier->send());
    }
}
