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
 * Loads a received payment (bank or cash record) from AbraFlexi.
 *
 * Invoice pairing itself is handled by abraflexi-matcher
 * (`abraflexi-match-received-payment`).
 *
 * @author Vitex <info@vitexsoftware.cz>
 */
class PaymentMatcher
{
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
}
