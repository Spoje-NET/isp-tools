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
use SpojeNet\PaidInvoiceConfirmer;

/**
 * Test PaidInvoiceConfirmer message composition and sending.
 */
class PaidInvoiceConfirmerTest extends TestCase
{
    private const INVOICE_DATA = [
        'kod' => 'code:FAK123',
        'sumCelkem' => '1210.0',
        'mena' => 'code:CZK',
        'varSym' => '123456',
    ];

    public function testComposeSubjectContainsInvoiceCode(): void
    {
        $subject = PaidInvoiceConfirmer::composeSubject(self::INVOICE_DATA);

        $this->assertStringContainsString('FAK123', $subject);
        $this->assertStringNotContainsString('code:', $subject);
    }

    public function testComposeBodyContainsInvoiceFacts(): void
    {
        $body = PaidInvoiceConfirmer::composeBody(self::INVOICE_DATA);

        $this->assertStringContainsString('FAK123', $body);
        $this->assertStringContainsString('1210.0', $body);
        $this->assertStringContainsString('CZK', $body);
        $this->assertStringContainsString('123456', $body);
    }

    public function testSendAttachesPdfAndCleansUp(): void
    {
        $pdf = (string) tempnam(sys_get_temp_dir(), 'isp-tools-test-pdf');

        $invoice = $this->createMock(\AbraFlexi\FakturaVydana::class);
        $invoice->method('getData')->willReturn(self::INVOICE_DATA);
        $invoice->method('getRecordIdent')->willReturn('code:FAK123');
        $invoice->method('downloadInFormat')->willReturn($pdf);

        $mailer = $this->createMock(\Ease\Mailer::class);
        $mailer->expects($this->once())->method('addFile')->with($pdf, 'application/pdf');
        $mailer->expects($this->once())->method('send')->willReturn(true);

        $confirmer = new PaidInvoiceConfirmer($invoice);
        $sent = $confirmer->send(static fn (string $to, string $subject, string $body) => $mailer, 'customer@example.com');

        $this->assertTrue($sent);
        $this->assertFileDoesNotExist($pdf);
    }

    public function testSendWithoutRecipientFails(): void
    {
        $invoice = $this->createMock(\AbraFlexi\FakturaVydana::class);
        $invoice->method('getRecordIdent')->willReturn('code:FAK123');
        $invoice->expects($this->never())->method('downloadInFormat');

        $confirmer = new PaidInvoiceConfirmer($invoice);

        $this->assertFalse($confirmer->send(null, ''));
    }
}
