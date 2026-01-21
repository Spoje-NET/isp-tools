<?php

namespace SpojeNet\System;

/**
 * System.spoje.net - zablokuje internet všem klientům kteří mají štítek ODPOJEN
 *
 * @author     Vítězslav Dvořák <info@vitexsofware.cz>
 * @copyright  (G) 2019 Vitex Software
 */

define('EASE_APPNAME', 'BlockNet');
$inc = 'includes/Init.php';
if (!file_exists($inc)) {
    chdir('..');
}
require_once $inc;

$options = getopt('o::e::', ['output::', 'environment::']);
$exitcode = 0;

$destination = \array_key_exists('output', $options) ? $options['output'] : \Ease\Shared::cfg('RESULT_FILE', 'php://stdout');

$reminder = new \SpojeNet\System\Upominac();
$reminder->invoicer->logBanner(constant('EASE_APPNAME'));
$adresses = $reminder->customer->adresar->getColumnsFromAbraFlexi(
    ['kod', 'stitky', 'nazev'],
    ['stitky' => 'ODPOJENO','limit' => 0],
    'kod'
);
$lmsIDs = [];
$reminder->addStatusMessage(count($adresses) . ' ' . _('customers with label DISCONNECTED'));
$clientCount = 0;
$vipSkipped = 0;
$noDisconnectSkipped = 0;
$blockedCount = 0;

foreach ($adresses as $kod => $address) {
    $lables = \AbraFlexi\Stitek::listToArray($address['stitky']);

    if (array_key_exists('VIP', $lables)) {
        $reminder->addStatusMessage($kod . ' ' . $address['nazev'] . ' ' . _('VIP'), 'warning');
        $vipSkipped++;
        continue;
    }

    if (array_key_exists('NODISCONNECT', $lables)) {
        $reminder->addStatusMessage($kod . ' ' . $address['nazev'] . ' ' . _('NODISCONNECT'), 'warning');
        $noDisconnectSkipped++;
        continue;
    }

    $clientCount++;
    $reminder->customer->adresar->setData($address);
    $lmsID = $reminder->customer->adresar->getRecordCode();
    if (!empty($lmsID)) {
        $lmsIDs[$lmsID] = $lmsID;
        $reminder->customer->adresar->addStatusMessage($kod . ' ' . $address['nazev'] . ' ' . _(' to disconnect'));
        $blockedCount++;
    }
}

if (!empty($lmsIDs)) {
    $reminder->setDisconnect($lmsIDs);
}

// Generate MultiFlexi-compliant report
$hasErrors = false;
$hasWarnings = ($vipSkipped > 0 || $noDisconnectSkipped > 0);

foreach ($reminder->getStatusMessages() as $message) {
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
        $noDisconnectSkipped
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
        'total_disconnected_customers' => count($adresses),
        'clients_blocked' => $blockedCount,
        'vip_skipped' => $vipSkipped,
        'no_disconnect_skipped' => $noDisconnectSkipped,
        'exit_code' => $exitcode,
    ],
];

$written = file_put_contents($destination, json_encode($report, \JSON_PRETTY_PRINT));
$reminder->addStatusMessage(sprintf('Report saved to %s', $destination), $written ? 'success' : 'error');

exit($exitcode);
