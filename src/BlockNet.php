<?php

declare(strict_types=1);

/**
 * This file is part of the AbraFlexi Reminder package
 *
 * https://github.com/SpojeNET/isp-tools
 *
 * (c) Spoje.Net <https://spoje.net/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SpojeNet\System;

use Ease\Shared;

/**
 * System.spoje.net - zablokuje internet všem klientům kteří mají štítek ODPOJEN.
 *
 * @author     Vítězslav Dvořák <info@vitexsofware.cz>
 * @copyright  (G) 2019-2026 Spoje.Net s.r.o.
 */
\define('EASE_APPNAME', 'BlockNet');

require_once '../vendor/autoload.php';

$options = getopt('o::e::', ['output::', 'environment::']);
Shared::init(
    ['ABRAFLEXI_URL', 'ABRAFLEXI_LOGIN', 'ABRAFLEXI_PASSWORD', 'ABRAFLEXI_COMPANY'],
    \array_key_exists('environment', $options) ? $options['environment'] : (\array_key_exists('e', $options) ? $options['e'] : '../.env'),
);
$exitcode = 0;

$destination = \array_key_exists('output', $options) ? $options['output'] : \Ease\Shared::cfg('RESULT_FILE', 'php://stdout');

$deblocker = new \SpojeNet\DeBlocker();
$deblocker->logBanner();

$deblocker->getBlocked();

$clientCount = 0;
$vipSkipped = 0;
$noDisconnectSkipped = 0;
$blockedCount = 0;

foreach ($adresses as $kod => $address) {
    $lables = \AbraFlexi\Stitek::listToArray($address['stitky']);

    if (\array_key_exists('VIP', $lables)) {
        $deblocker->addStatusMessage($kod.' '.$address['nazev'].' '._('VIP'), 'warning');
        ++$vipSkipped;

        continue;
    }

    if (\array_key_exists('NODISCONNECT', $lables)) {
        $deblocker->addStatusMessage($kod.' '.$address['nazev'].' '._('NODISCONNECT'), 'warning');
        ++$noDisconnectSkipped;

        continue;
    }

    ++$clientCount;
    $deblocker->customer->adresar->setData($address);
    $lmsID = $deblocker->customer->adresar->getRecordCode();

    if (!empty($lmsID)) {
        $lmsIDs[$lmsID] = $lmsID;
        $deblocker->customer->adresar->addStatusMessage($kod.' '.$address['nazev'].' '._(' to disconnect'));
        ++$blockedCount;
    }
}

if (!empty($lmsIDs)) {
    $deblocker->setDisconnect($lmsIDs);
}

// Generate MultiFlexi-compliant report
$hasErrors = false;
$hasWarnings = ($vipSkipped > 0 || $noDisconnectSkipped > 0);

foreach ($deblocker->getStatusMessages() as $message) {
    if (isset($message['type']) && $message['type'] === 'error') {
        $hasErrors = true;
        $exitcode = 1;

        break;
    }
}

if ($hasErrors) {
    $status = 'error';
    $reportMessage = 'Internet blocking completed with errors';
} elseif ($hasWarnings) {
    $status = 'warning';
    $reportMessage = sprintf(
        'Blocked %d clients with ODPOJEN label. Skipped %d VIP and %d NODISCONNECT clients.',
        $blockedCount,
        $vipSkipped,
        $noDisconnectSkipped,
    );
} else {
    $status = 'success';
    $reportMessage = sprintf('Successfully blocked internet for %d clients with ODPOJEN label', $blockedCount);
}

$report = [
    'producer' => 'BlockNet',
    'status' => $status,
    'timestamp' => date('c'),
    'message' => $reportMessage,
    'metrics' => [
        'total_disconnected_customers' => \count($adresses),
        'clients_blocked' => $blockedCount,
        'vip_skipped' => $vipSkipped,
        'no_disconnect_skipped' => $noDisconnectSkipped,
        'exit_code' => $exitcode,
    ],
];

$written = file_put_contents($destination, json_encode($report, \JSON_PRETTY_PRINT));
$deblocker->addStatusMessage(sprintf('Report saved to %s', $destination), $written ? 'success' : 'error');

exit($exitcode);
