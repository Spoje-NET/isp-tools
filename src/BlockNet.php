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

\define('EASE_APPNAME', 'BlockNet');

require_once \dirname(__DIR__).'/vendor/autoload.php';

use Ease\Shared;

/**
 * Blocks internet access of all customers carrying the LABEL_DISCONNECTED
 * label (default: ODPOJENO), except customers labelled LABEL_VIP or
 * LABEL_NODISCONNECT.
 *
 * Customer IP addresses are resolved and blocked through the configured
 * network backend (SubVersioner by default).
 */
$options = getopt('o::e::', ['output::', 'environment::']);
Shared::init(
    ['ABRAFLEXI_URL', 'ABRAFLEXI_LOGIN', 'ABRAFLEXI_PASSWORD', 'ABRAFLEXI_COMPANY', 'SVNUSER', 'SVNPASS', 'SVNURL', 'SVNBIN', 'LOGFILE'],
    \array_key_exists('environment', $options) ? $options['environment'] : (\array_key_exists('e', $options) ? $options['e'] : \dirname(__DIR__).'/.env'),
);

$destination = \array_key_exists('output', $options) ? $options['output'] : Shared::cfg('RESULT_FILE', 'php://stdout');

$labelVip = Shared::cfg('LABEL_VIP', 'VIP');
$labelNoDisconnect = Shared::cfg('LABEL_NODISCONNECT', 'NEODPOJOVAT');

$report = [
    'exitcode' => 0,
    'status' => 'success',
    'timestamp' => date(\DateTimeInterface::ATOM),
    'message' => '',
    'metrics' => ['disconnected_total' => 0, 'blocked' => 0, 'vip_skipped' => 0, 'nodisconnect_skipped' => 0, 'no_ip' => 0, 'errors' => 0],
    'customers' => [],
];

$deblocker = new \SpojeNet\DeBlocker();

if (Shared::cfg('APP_DEBUG', false)) {
    $deblocker->logBanner();
}

$addresses = $deblocker->getBlockedCustomers();
$report['metrics']['disconnected_total'] = \count($addresses);

$toBlock = [];

foreach ($addresses as $kod => $address) {
    $labels = \AbraFlexi\Stitek::listToArray((string) ($address['stitky'] ?? ''));

    if (\array_key_exists($labelVip, $labels)) {
        $deblocker->addStatusMessage($kod.' '.($address['nazev'] ?? '').' '._('VIP'), 'warning');
        ++$report['metrics']['vip_skipped'];
        $report['customers'][] = ['kod' => $kod, 'action' => 'vip_skipped'];

        continue;
    }

    if (\array_key_exists($labelNoDisconnect, $labels)) {
        $deblocker->addStatusMessage($kod.' '.($address['nazev'] ?? '').' '._('NODISCONNECT'), 'warning');
        ++$report['metrics']['nodisconnect_skipped'];
        $report['customers'][] = ['kod' => $kod, 'action' => 'nodisconnect_skipped'];

        continue;
    }

    $toBlock[] = (string) $kod;
}

foreach ($deblocker->blockCustomers($toBlock) as $kod => $result) {
    if ($result['ips'] === []) {
        ++$report['metrics']['no_ip'];
        $report['customers'][] = ['kod' => $kod, 'action' => 'no_ip_found'];
    } elseif ($result['failed'] > 0) {
        $report['exitcode'] = 1;
        ++$report['metrics']['errors'];
        $report['customers'][] = ['kod' => $kod, 'action' => 'error_blocking', 'ips' => $result['ips']];
    } else {
        ++$report['metrics']['blocked'];
        $report['customers'][] = ['kod' => $kod, 'action' => 'blocked', 'ips' => $result['ips']];
    }
}

$m = $report['metrics'];
$report['message'] = sprintf(
    'Blocked %d of %d disconnected customers. Skipped: %d VIP, %d NODISCONNECT. No IP: %d. Errors: %d.',
    $m['blocked'],
    $m['disconnected_total'],
    $m['vip_skipped'],
    $m['nodisconnect_skipped'],
    $m['no_ip'],
    $m['errors'],
);

if ($report['exitcode'] !== 0) {
    $report['status'] = 'error';
} elseif ($m['vip_skipped'] + $m['nodisconnect_skipped'] + $m['no_ip'] > 0) {
    $report['status'] = 'warning';
}

$written = file_put_contents($destination, json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
$deblocker->addStatusMessage(sprintf('Report saved to %s', $destination), $written ? 'success' : 'error');

exit($report['exitcode']);
