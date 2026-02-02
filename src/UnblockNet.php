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
 * System.spoje.net - Odblokuje internet všem kteří nedluží.
 *
 * @author     Vítězslav Dvořák <info@vitexsofware.cz>
 * @copyright  (G) 2017-2019 Vitex Software
 */
\define('EASE_APPNAME', 'UnblockNet');

require_once '../vendor/autoload.php';

$options = getopt('o::e::', ['output::', 'environment::']);
Shared::init(
    ['ABRAFLEXI_URL', 'ABRAFLEXI_LOGIN', 'ABRAFLEXI_PASSWORD', 'ABRAFLEXI_COMPANY'],
    \array_key_exists('environment', $options) ? $options['environment'] : (\array_key_exists('e', $options) ? $options['e'] : '../.env'),
);
$exitcode = 0;

$destination = \array_key_exists('output', $options) ? $options['output'] : \Ease\Shared::cfg('RESULT_FILE', 'php://stdout');

$deblocker = new \SpojeNet\System\Upominac();
$deblocker->invoicer->logBanner(\Ease\Shared::appName());
$deblocker->customer->adresar->defaultUrlParams['limit'] = 0;
$adresses = $deblocker->customer->adresar->getColumnsFromAbraFlexi('summary', [], 'kod');
$lmsIDs = [];
$deblocker->addStatusMessage(\count($adresses).' '._('known customers'));
$invcount = 0;
$unblockedCount = 0;
$customersWithoutCode = 0;

foreach ($deblocker->getInvoicesStatus(["typDokl eq 'code:FAKTURA' or typDokl eq 'code:ZALOHA'", 'limit' => 0]) as $companyCode => $companyInfo) {
    ++$invcount;

    if ($companyInfo['balance'] >= 0) {
        $company = \AbraFlexi\Functions::uncode($companyCode);

        if (\array_key_exists($company, $adresses)) {
            $deblocker->customer->adresar->setData($adresses[$company]);
            $deblocker->setConnect($company);
            ++$unblockedCount;
        } else {
            ++$customersWithoutCode;
            //            $reminder->addStatusMessage(sprintf(_('Invoice %s without CompanyCode') . ': ', implode(',', array_keys($companyInfo['invoice']))), 'warning');
        }
    }
}

$deblocker->addStatusMessage($invcount.' '._('invoices checked'));

// Generate MultiFlexi-compliant report
$hasErrors = false;
$hasWarnings = ($customersWithoutCode > 0);

foreach ($deblocker->getStatusMessages() as $message) {
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
        $customersWithoutCode,
    );
} else {
    $status = 'success';
    $reportMessage = sprintf(
        'Successfully unblocked internet for %d clients with positive balance. %d invoices checked.',
        $unblockedCount,
        $invcount,
    );
}

$report = [
    'producer' => 'UnblockNet',
    'status' => $status,
    'timestamp' => date('c'),
    'message' => $reportMessage,
    'metrics' => [
        'total_customers' => \count($adresses),
        'invoices_checked' => $invcount,
        'clients_unblocked' => $unblockedCount,
        'customers_without_code' => $customersWithoutCode,
        'exit_code' => $exitcode,
    ],
];

$written = file_put_contents($destination, json_encode($report, \JSON_PRETTY_PRINT));
$deblocker->addStatusMessage(sprintf('Report saved to %s', $destination), $written ? 'success' : 'error');

exit($exitcode);
