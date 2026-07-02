#!/usr/bin/php
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

\define('EASE_APPNAME', 'MatchReceivedPayment');

require_once \dirname(__DIR__).'/vendor/autoload.php';

use Ease\Shared;

/**
 * Matches a received payment (bank or cash record) to an unpaid issued
 * invoice by variable symbol.
 *
 * Environment:
 *   DOCUMENTID          - bank/cash record code or numeric id (required)
 *   PAYMENT_EVIDENCE    - banka|pokladna|auto (default: auto)
 *   MATCH_OVERPAY_MODE  - settle|manual (default: settle)
 *
 * Exit codes:
 *   0 - payment matched and linked to an invoice
 *   1 - error (missing/unknown document, settle failure)
 *   2 - payment received but cannot be auto-matched (emits payment.unmatched)
 */
$options = getopt('o::e::', ['output::', 'environment::']);
Shared::init(
    ['ABRAFLEXI_URL', 'ABRAFLEXI_LOGIN', 'ABRAFLEXI_PASSWORD', 'ABRAFLEXI_COMPANY'],
    \array_key_exists('environment', $options) ? $options['environment'] : (\array_key_exists('e', $options) ? $options['e'] : \dirname(__DIR__).'/.env'),
);

$destination = \array_key_exists('output', $options) ? $options['output'] : Shared::cfg('RESULT_FILE', 'php://stdout');

$report = [
    'exitcode' => 0,
    'status' => 'success',
    'timestamp' => date(\DateTimeInterface::ATOM),
    'message' => '',
    'document' => '',
    'invoice' => null,
    'varSym' => null,
    'reason' => '',
];

$documentId = (string) Shared::cfg('DOCUMENTID', '');

if ($documentId === '') {
    $report['exitcode'] = \SpojeNet\PaymentMatcher::EXIT_ERROR;
    $report['status'] = 'error';
    $report['reason'] = 'no_documentid';
    $report['message'] = 'No DOCUMENTID provided.';
    file_put_contents($destination, json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

    exit($report['exitcode']);
}

$report['document'] = $documentId;

$parovac = new \AbraFlexi\Bricks\ParovacFaktur([
    'LABEL_OVERPAY' => Shared::cfg('LABEL_OVERPAY', 'PREPLATEK'),
    'LABEL_INVOICE_MISSING' => Shared::cfg('LABEL_INVOICE_MISSING', 'CHYBIFAKTURA'),
    'LABEL_UNIDENTIFIED' => Shared::cfg('LABEL_UNIDENTIFIED', 'NEIDENTIFIKOVANO'),
]);
$matcher = new \SpojeNet\PaymentMatcher($parovac);

if (Shared::cfg('APP_DEBUG', false)) {
    $matcher->logBanner();
}

$payment = \SpojeNet\PaymentMatcher::loadPayment($documentId, (string) Shared::cfg('PAYMENT_EVIDENCE', 'auto'));

if ($payment === null) {
    $report['exitcode'] = \SpojeNet\PaymentMatcher::EXIT_ERROR;
    $report['status'] = 'error';
    $report['reason'] = 'document_not_found';
    $report['message'] = sprintf('Payment document %s not found.', $documentId);
} else {
    $report['document'] = \AbraFlexi\Functions::uncode((string) $payment->getRecordCode()) ?: $documentId;

    $result = $matcher->match($payment, (string) Shared::cfg('MATCH_OVERPAY_MODE', 'settle'));

    $report['exitcode'] = $result['exitcode'];
    $report['invoice'] = $result['invoice'];
    $report['varSym'] = $result['varSym'];
    $report['reason'] = $result['reason'];

    switch ($result['exitcode']) {
        case \SpojeNet\PaymentMatcher::EXIT_MATCHED:
            $report['status'] = 'success';
            $report['message'] = sprintf('Payment %s matched with invoice %s (%s).', $report['document'], (string) $result['invoice'], $result['reason']);

            break;
        case \SpojeNet\PaymentMatcher::EXIT_UNMATCHED:
            $report['status'] = 'warning';
            $report['message'] = sprintf('Payment %s received but not auto-matched: %s.', $report['document'], $result['reason']);

            break;

        default:
            $report['status'] = 'error';
            $report['message'] = sprintf('Payment %s matching failed: %s.', $report['document'], $result['reason']);

            break;
    }
}

$written = file_put_contents($destination, json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
$matcher->addStatusMessage(sprintf('Report saved to %s', $destination), $written ? 'success' : 'error');

exit($report['exitcode']);
