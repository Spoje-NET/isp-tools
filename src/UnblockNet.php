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

\define('EASE_APPNAME', 'UnblockNet');

require_once \dirname(__DIR__).'/vendor/autoload.php';

use Ease\Shared;

/**
 * Restores internet access for disconnected customers who no longer owe.
 *
 * Flow:
 *   1. Find customers with the LABEL_DISCONNECTED label (default: ODPOJENO).
 *   2. Check AbraFlexi for unpaid overdue issued invoices per customer.
 *   3. Customers without debt get all their IPs unblocked (original speed is
 *      restored by the network backend, DEFAULT_SPEED is the fallback).
 *   4. After a successful unblock the LABEL_DISCONNECTED label is removed
 *      from the customer's address record.
 */
$options = getopt('o::e::', ['output::', 'environment::']);
Shared::init(
    ['ABRAFLEXI_URL', 'ABRAFLEXI_LOGIN', 'ABRAFLEXI_PASSWORD', 'ABRAFLEXI_COMPANY', 'SVNUSER', 'SVNPASS', 'SVNURL', 'SVNBIN', 'LOGFILE'],
    \array_key_exists('environment', $options) ? $options['environment'] : (\array_key_exists('e', $options) ? $options['e'] : \dirname(__DIR__).'/.env'),
);

$destination = \array_key_exists('output', $options) ? $options['output'] : Shared::cfg('RESULT_FILE', 'php://stdout');

$report = [
    'exitcode' => 0,
    'status' => 'success',
    'timestamp' => date(\DateTimeInterface::ATOM),
    'message' => '',
    'metrics' => ['disconnected_total' => 0, 'still_owing' => 0, 'unblocked' => 0, 'labels_cleared' => 0, 'no_ip' => 0, 'errors' => 0],
    'customers' => [],
];

$deblocker = new \SpojeNet\DeBlocker();

if (Shared::cfg('APP_DEBUG', false)) {
    $deblocker->logBanner();
}

$disconnected = $deblocker->getBlockedCustomers();
$report['metrics']['disconnected_total'] = \count($disconnected);

if (empty($disconnected)) {
    $report['message'] = 'No disconnected customers found.';
    file_put_contents($destination, json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

    exit(0);
}

$debtors = $deblocker->getInvoicesStatus();

$eligible = [];

foreach ($disconnected as $kod => $address) {
    if (\array_key_exists($kod, $debtors)) {
        ++$report['metrics']['still_owing'];
        $report['customers'][] = [
            'kod' => $kod,
            'action' => 'still_owes',
            'unpaid_invoices' => $debtors[$kod]['count'],
            'due' => $debtors[$kod]['due'],
        ];

        continue;
    }

    $eligible[] = (string) $kod;
}

$results = $deblocker->unblockCustomers($eligible, (int) Shared::cfg('DEFAULT_SPEED', 0));

foreach ($results as $kod => $result) {
    if ($result['failed'] > 0) {
        $report['exitcode'] = 1;
        ++$report['metrics']['errors'];
        $report['customers'][] = ['kod' => $kod, 'action' => 'error_unblocking', 'ips' => $result['ips']];

        continue;
    }

    if ($result['ips'] === []) {
        ++$report['metrics']['no_ip'];
        $action = 'no_ip_label_cleared';
    } else {
        ++$report['metrics']['unblocked'];
        $action = 'unblocked';
    }

    if ($deblocker->removeDisconnectedLabel((string) $kod)) {
        ++$report['metrics']['labels_cleared'];
        $report['customers'][] = ['kod' => $kod, 'action' => $action, 'ips' => $result['ips']];
    } else {
        $report['exitcode'] = 1;
        ++$report['metrics']['errors'];
        $report['customers'][] = ['kod' => $kod, 'action' => 'error_removing_label', 'ips' => $result['ips']];
    }
}

$m = $report['metrics'];
$report['message'] = sprintf(
    'Unblocked %d of %d disconnected customers. Still owing: %d. Labels cleared: %d. No IP: %d. Errors: %d.',
    $m['unblocked'],
    $m['disconnected_total'],
    $m['still_owing'],
    $m['labels_cleared'],
    $m['no_ip'],
    $m['errors'],
);

if ($report['exitcode'] !== 0) {
    $report['status'] = 'error';
} elseif ($m['no_ip'] > 0) {
    $report['status'] = 'warning';
}

$written = file_put_contents($destination, json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
$deblocker->addStatusMessage(sprintf('Report saved to %s', $destination), $written ? 'success' : 'error');

exit($report['exitcode']);
