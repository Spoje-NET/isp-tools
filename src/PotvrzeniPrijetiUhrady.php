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

\define('EASE_APPNAME', 'PotvrzeniPrijetiUhrady');

require_once \dirname(__DIR__).'/vendor/autoload.php';

use Ease\Shared;

/**
 * Sends the customer a payment-received confirmation email with the tax
 * document (invoice PDF) attached.
 *
 * Environment:
 *   DOCID      - faktura-vydana record code (required)
 *   EASE_FROM  - sender address
 *   MUTE       - true = do not actually send (dry run)
 *
 * Exit codes: 0 - confirmation sent, 1 - error (unknown document, no email, send failure)
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
    'recipient' => null,
    'reason' => '',
];

$docId = (string) Shared::cfg('DOCID', '');
$report['document'] = $docId;

$invoice = new \AbraFlexi\FakturaVydana();

if (Shared::cfg('APP_DEBUG', false)) {
    $invoice->logBanner();
}

if ($docId === '') {
    $report['exitcode'] = 1;
    $report['status'] = 'error';
    $report['reason'] = 'no_docid';
    $report['message'] = 'No DOCID provided.';
} elseif (!$invoice->recordExists(\AbraFlexi\Functions::code($docId))) {
    $report['exitcode'] = 1;
    $report['status'] = 'error';
    $report['reason'] = 'document_not_found';
    $report['message'] = sprintf('Invoice %s not found.', $docId);
} else {
    $invoice->loadFromAbraFlexi(\AbraFlexi\Functions::code($docId));
    $confirmer = new \SpojeNet\PaidInvoiceConfirmer($invoice);
    $recipient = $confirmer->recipient();
    $report['recipient'] = $recipient !== '' ? $recipient : null;
    $paymentState = (string) $invoice->getDataValue('stavUhrK');

    if ($paymentState === '') {
        // The invoice.matched wiring fires on any faktura-vydana update;
        // never confirm a payment that did not happen.
        $report['status'] = 'warning';
        $report['reason'] = 'not_paid';
        $report['message'] = sprintf('Invoice %s is not paid - no confirmation sent.', $docId);
    } elseif ($recipient === '') {
        $report['exitcode'] = 1;
        $report['status'] = 'error';
        $report['reason'] = 'no_email';
        $report['message'] = sprintf('Invoice %s has no email recipient.', $docId);
    } elseif (Shared::cfg('MUTE', false)) {
        $report['reason'] = 'would_send';
        $report['message'] = sprintf('MUTE enabled - confirmation for invoice %s to %s not sent.', $docId, $recipient);
    } elseif ($confirmer->send()) {
        $report['reason'] = 'sent';
        $report['message'] = sprintf('Confirmation for invoice %s sent to %s.', $docId, $recipient);
    } else {
        $report['exitcode'] = 1;
        $report['status'] = 'error';
        $report['reason'] = 'send_failed';
        $report['message'] = sprintf('Sending confirmation for invoice %s to %s failed.', $docId, $recipient);
    }
}

$written = file_put_contents($destination, json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
$invoice->addStatusMessage(sprintf('Report saved to %s', $destination), $written ? 'success' : 'error');

exit($report['exitcode']);
