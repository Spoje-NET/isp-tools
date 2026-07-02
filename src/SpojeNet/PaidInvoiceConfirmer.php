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

namespace SpojeNet;

/**
 * Sends the customer a payment-received confirmation with the tax document
 * (invoice PDF) attached.
 *
 * @author Vitex <info@vitexsoftware.cz>
 */
class PaidInvoiceConfirmer extends \Ease\Sand
{
    public function __construct(private \AbraFlexi\FakturaVydana $invoice)
    {
        $this->setObjectName('PaidInvoiceConfirmer');
    }

    /**
     * @param array<string, mixed> $invoiceData invoice fields (kod, sumCelkem, mena, varSym)
     */
    public static function composeSubject(array $invoiceData): string
    {
        return sprintf(
            _('Payment confirmation for invoice %s'),
            \AbraFlexi\Functions::uncode((string) ($invoiceData['kod'] ?? '')),
        );
    }

    /**
     * @param array<string, mixed> $invoiceData invoice fields (kod, sumCelkem, mena, varSym)
     */
    public static function composeBody(array $invoiceData): string
    {
        $body = [];
        $body[] = _('Dear customer,');
        $body[] = '';
        $body[] = sprintf(
            _('we confirm that your payment of invoice %s in the amount of %s %s (variable symbol %s) has been received.'),
            \AbraFlexi\Functions::uncode((string) ($invoiceData['kod'] ?? '')),
            (string) ($invoiceData['sumCelkem'] ?? ''),
            \AbraFlexi\Functions::uncode((string) ($invoiceData['mena'] ?? '')),
            (string) ($invoiceData['varSym'] ?? ''),
        );
        $body[] = '';
        $body[] = _('The tax document is attached to this message.');
        $body[] = '';
        $body[] = _('Thank you for your payment.');

        return implode("\n", $body);
    }

    /**
     * Confirmation email recipient taken from the invoice document.
     */
    public function recipient(): string
    {
        $recipient = $this->invoice->getRecipients();

        return $recipient !== '' ? $recipient : $this->invoice->getEmail();
    }

    /**
     * Send the confirmation email with the invoice PDF attached.
     *
     * @param null|callable(string, string, string):\Ease\Mailer $mailerFactory custom mailer factory (tests)
     * @param null|string                                        $to            recipient override
     */
    public function send(?callable $mailerFactory = null, ?string $to = null): bool
    {
        $to ??= $this->recipient();

        if ($to === '') {
            $this->addStatusMessage(sprintf(_('No email recipient for invoice %s'), $this->invoice->getRecordIdent()), 'error');

            return false;
        }

        $invoiceData = $this->invoice->getData();
        $factory = $mailerFactory ?? static fn (string $to, string $subject, string $body): \Ease\Mailer => new \Ease\Mailer($to, $subject, $body);
        $mailer = $factory($to, self::composeSubject($invoiceData), self::composeBody($invoiceData));

        $pdf = $this->invoice->downloadInFormat('pdf', sys_get_temp_dir().'/');

        if (\is_string($pdf) && $pdf !== '' && file_exists($pdf)) {
            $mailer->addFile($pdf, 'application/pdf');
        } else {
            $pdf = null;
            $this->addStatusMessage(sprintf(_('Unable to obtain PDF of invoice %s'), $this->invoice->getRecordIdent()), 'warning');
        }

        $sent = (bool) $mailer->send();

        if ($pdf !== null) {
            unlink($pdf);
        }

        $this->addStatusMessage(
            sprintf(_('Payment confirmation for %s to %s'), $this->invoice->getRecordIdent(), $to),
            $sent ? 'success' : 'error',
        );

        return $sent;
    }
}
