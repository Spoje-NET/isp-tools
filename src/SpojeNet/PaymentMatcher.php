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
 * Matches a received payment (bank or cash record) to an unpaid issued
 * invoice by variable symbol.
 *
 * @author Vitex <info@vitexsoftware.cz>
 */
class PaymentMatcher extends \Ease\Sand
{
    /**
     * Payment was matched and linked to an invoice.
     */
    public const EXIT_MATCHED = 0;

    /**
     * Processing error (document not found, settle failure).
     */
    public const EXIT_ERROR = 1;

    /**
     * Payment received but cannot be auto-matched.
     */
    public const EXIT_UNMATCHED = 2;

    public function __construct(private \AbraFlexi\Bricks\ParovacFaktur $parovac)
    {
        $this->setObjectName('PaymentMatcher');
    }

    /**
     * Load a payment record from AbraFlexi by numeric id or record code.
     *
     * @param string $documentId numeric record id or record code
     * @param string $evidence   banka|pokladna|auto (try banka first, then pokladna)
     */
    public static function loadPayment(string $documentId, string $evidence = 'auto'): ?\AbraFlexi\RO
    {
        $candidates = match ($evidence) {
            'banka' => [\AbraFlexi\Banka::class],
            'pokladna' => [\AbraFlexi\PokladniPohyb::class],
            default => [\AbraFlexi\Banka::class, \AbraFlexi\PokladniPohyb::class],
        };

        $ident = is_numeric($documentId) ? (int) $documentId : \AbraFlexi\Functions::code($documentId);

        foreach ($candidates as $class) {
            $payment = new $class();

            try {
                if ($payment->recordExists($ident)) {
                    $payment->loadFromAbraFlexi($ident);

                    if ($payment->getDataValue('id')) {
                        return $payment;
                    }
                }
            } catch (\Exception $exc) {
                $payment->addStatusMessage($exc->getMessage(), 'debug');
            }
        }

        return null;
    }

    /**
     * Try to match the payment to exactly one unpaid issued invoice by varSym.
     *
     * @param \AbraFlexi\RO $payment     loaded banka/pokladna record
     * @param string        $overpayMode settle (link and keep overpayment) | manual (leave for accounting)
     *
     * @return array{exitcode: int, reason: string, invoice: ?string, varSym: ?string}
     */
    public function match(\AbraFlexi\RO $payment, string $overpayMode = 'settle'): array
    {
        $result = ['exitcode' => self::EXIT_UNMATCHED, 'reason' => '', 'invoice' => null, 'varSym' => null];

        $varSym = trim((string) $payment->getDataValue('varSym'));
        $result['varSym'] = $varSym;

        if ($varSym === '') {
            $result['reason'] = 'no_varsym';
            $this->addStatusMessage(sprintf(_('Payment %s carries no variable symbol'), $payment->getRecordIdent()), 'warning');

            return $result;
        }

        $invoices = (array) $this->parovac->findInvoice(['varSym' => $varSym]);

        if ($invoices === []) {
            $result['reason'] = 'unknown_varsym';
            $this->addStatusMessage(sprintf(_('No unpaid invoice with variable symbol %s'), $varSym), 'warning');

            return $result;
        }

        if (\count($invoices) > 1) {
            $result['reason'] = 'ambiguous';
            $this->addStatusMessage(sprintf(_('%d unpaid invoices share variable symbol %s'), \count($invoices), $varSym), 'warning');

            return $result;
        }

        $invoiceData = current($invoices);
        $result['invoice'] = \AbraFlexi\Functions::uncode((string) ($invoiceData['kod'] ?? ''));

        $received = (float) $payment->getDataValue('sumCelkem');
        $toPay = (float) ($invoiceData['zbyvaUhradit'] ?? 0);

        if ($received > $toPay && $overpayMode === 'manual') {
            $result['reason'] = 'overpayment';
            $this->addStatusMessage(
                sprintf(_('Overpayment of invoice %s (%s received, %s expected) left for manual matching'), $result['invoice'], $received, $toPay),
                'warning',
            );

            return $result;
        }

        $invoice = new \AbraFlexi\FakturaVydana($invoiceData);

        if ($this->parovac->settleInvoice($invoice, $payment)) {
            $result['exitcode'] = self::EXIT_MATCHED;

            if ($received < $toPay) {
                $result['reason'] = 'matched_partial';
            } elseif ($received > $toPay) {
                $result['reason'] = 'matched_overpayment';
            } else {
                $result['reason'] = 'matched';
            }
        } else {
            $result['exitcode'] = self::EXIT_ERROR;
            $result['reason'] = 'settle_failed';
        }

        return $result;
    }
}
