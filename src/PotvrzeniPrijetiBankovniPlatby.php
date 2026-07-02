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

\define('EASE_APPNAME', 'PotvrzeniPrijetiBankovniPlatby');

require_once \dirname(__DIR__).'/vendor/autoload.php';

use Ease\Shared;

/**
 * Notifies the customer that their bank payment was received but awaits
 * manual matching by accounting.
 *
 * Environment:
 *   DOCID             - bank record code or numeric id (required)
 *   PAYMENT_EVIDENCE  - banka|pokladna|auto (default: auto)
 *   EASE_FROM         - sender address
 *   MUTE              - true = do not actually send (dry run)
 *
 * Exit codes: 0 - notified or payer unknown, 1 - error (unknown document, send failure)
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

if ($docId === '') {
    $report['exitcode'] = 1;
    $report['status'] = 'error';
    $report['reason'] = 'no_docid';
    $report['message'] = 'No DOCID provided.';
    file_put_contents($destination, json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

    exit($report['exitcode']);
}

$payment = \SpojeNet\PaymentMatcher::loadPayment($docId, (string) Shared::cfg('PAYMENT_EVIDENCE', 'auto'));

if ($payment === null) {
    $report['exitcode'] = 1;
    $report['status'] = 'error';
    $report['reason'] = 'document_not_found';
    $report['message'] = sprintf('Payment document %s not found.', $docId);
    file_put_contents($destination, json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

    exit($report['exitcode']);
}

if (Shared::cfg('APP_DEBUG', false)) {
    $payment->logBanner();
}

$report['document'] = \AbraFlexi\Functions::uncode((string) $payment->getRecordCode()) ?: $docId;

$notifier = new \SpojeNet\ReceivedPaymentNotifier($payment);
$recipient = $notifier->recipient();
$report['recipient'] = $recipient;

if ($recipient === null) {
    $report['status'] = 'warning';
    $report['reason'] = 'no_recipient';
    $report['message'] = sprintf('Payment %s has no known payer email - manual matching proceeds without notification.', $report['document']);
} elseif (Shared::cfg('MUTE', false)) {
    $report['reason'] = 'would_send';
    $report['message'] = sprintf('MUTE enabled - notification about payment %s to %s not sent.', $report['document'], $recipient);
} elseif ($notifier->send(null, $recipient)) {
    $report['reason'] = 'sent';
    $report['message'] = sprintf('Notification about payment %s sent to %s.', $report['document'], $recipient);
} else {
    $report['exitcode'] = 1;
    $report['status'] = 'error';
    $report['reason'] = 'send_failed';
    $report['message'] = sprintf('Sending notification about payment %s to %s failed.', $report['document'], $recipient);
}

$written = file_put_contents($destination, json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
$notifier->addStatusMessage(sprintf('Report saved to %s', $destination), $written ? 'success' : 'error');

exit($report['exitcode']);
