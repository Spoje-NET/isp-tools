<?php

namespace SpojeNet\System;

/**
 * System.spoje.net - Odblokuje internet všem kteří nedluží
 *
 * @author     Vítězslav Dvořák <info@vitexsofware.cz>
 * @copyright  (G) 2017-2019 Vitex Software
 */

define('EASE_APPNAME', 'UnblockNet');
$inc = 'includes/Init.php';
if (!file_exists($inc)) {
    chdir('..');
}
require_once $inc;

$options = getopt('o::e::', ['output::', 'environment::']);
$exitcode = 0;

$destination = \array_key_exists('output', $options) ? $options['output'] : \Ease\Shared::cfg('RESULT_FILE', 'php://stdout');

$reminder = new \SpojeNet\System\Upominac();
$reminder->invoicer->logBanner(\Ease\Shared::appName());
$reminder->customer->adresar->defaultUrlParams['limit'] = 0;
$adresses = $reminder->customer->adresar->getColumnsFromAbraFlexi('summary', [], 'kod');
$lmsIDs = [];
$reminder->addStatusMessage(count($adresses) . ' ' . _('known customers'));
$invcount = 0;
$unblockedCount = 0;
$customersWithoutCode = 0;

foreach ($reminder->getInvoicesStatus(["typDokl eq 'code:FAKTURA' or typDokl eq 'code:ZALOHA'", 'limit' => 0]) as $companyCode => $companyInfo) {
    $invcount++;
    if ($companyInfo['balance'] >= 0) {
        $company = \AbraFlexi\Functions::uncode($companyCode);
        if (array_key_exists($company, $adresses)) {
            $reminder->customer->adresar->setData($adresses[$company]);
            $reminder->setConnect($company);
            $unblockedCount++;
        } else {
            $customersWithoutCode++;
//            $reminder->addStatusMessage(sprintf(_('Invoice %s without CompanyCode') . ': ', implode(',', array_keys($companyInfo['invoice']))), 'warning');
        }
    }
}
$reminder->addStatusMessage($invcount . ' ' . _('invoices checked'));

// Generate MultiFlexi-compliant report
$hasErrors = false;
$hasWarnings = ($customersWithoutCode > 0);

foreach ($reminder->getStatusMessages() as $message) {
    if (isset($message['type']) && $message['type'] === 'error') {
        $hasErrors = true;
        $exitcode = 1;
        break;
    }
}

if ($hasErrors) {
    $status = 'error';
    $reportMessage = 'Internet unblocking completed with errors';
} elseif ($hasWarnings) {
    $status = 'warning';
    $reportMessage = sprintf(
        'Unblocked %d clients with positive balance. %d invoices checked. %d customers without company code.',
        $unblockedCount,
        $invcount,
        $customersWithoutCode
    );
} else {
    $status = 'success';
    $reportMessage = sprintf(
        'Successfully unblocked internet for %d clients with positive balance. %d invoices checked.',
        $unblockedCount,
        $invcount
    );
}

$report = [
    'producer' => 'UnblockNet',
    'status' => $status,
    'timestamp' => date('c'),
    'message' => $reportMessage,
    'metrics' => [
        'total_customers' => count($adresses),
        'invoices_checked' => $invcount,
        'clients_unblocked' => $unblockedCount,
        'customers_without_code' => $customersWithoutCode,
        'exit_code' => $exitcode,
    ],
];

$written = file_put_contents($destination, json_encode($report, \JSON_PRETTY_PRINT));
$reminder->addStatusMessage(sprintf('Report saved to %s', $destination), $written ? 'success' : 'error');

exit($exitcode);
