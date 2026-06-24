#!/usr/bin/php
<?php

declare(strict_types=1);

/**
 * This file is part of the ISP Tools package
 *
 * https://github.com/SpojeNET/isp-tools
 *
 * (c) Spoje.Net <https://spoje.net/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

\define('EASE_APPNAME', 'MarkDefaulters');

require_once \dirname(__DIR__).'/vendor/autoload.php';

use Ease\Shared;

/**
 * Marks overdue internet customers for disconnection.
 *
 * Finds customers that:
 *   1. Have the UPOMINKA3 label (third reminder sent by abraflexi-reminder)
 *   2. Have at least one active internet service contract (smlouva)
 *   3. Do NOT yet have the LABEL_DISCONNECTED label (default: ODPOJENO)
 *   4. Do NOT have the LABEL_NODISCONNECT label (default: NEODPOJOVAT)
 *   5. Do NOT have the LABEL_VIP label (default: VIP)
 *
 * When such a customer is found, LABEL_DISCONNECTED is added to their
 * AbraFlexi address record so that the `blocknet` application can act on it.
 *
 * Intended to be triggered by multiflexi-event-processor after the
 * `invoice.reminder.sent` event (score 3) is emitted by abraflexi-reminder.
 */
$options = getopt('o::e::', ['output::', 'environment::']);
Shared::init(
    ['ABRAFLEXI_URL', 'ABRAFLEXI_LOGIN', 'ABRAFLEXI_PASSWORD', 'ABRAFLEXI_COMPANY'],
    \array_key_exists('environment', $options) ? $options['environment'] : (\array_key_exists('e', $options) ? $options['e'] : \dirname(__DIR__).'/.env'),
);

$destination = \array_key_exists('output', $options) ? $options['output'] : Shared::cfg('RESULT_FILE', 'php://stdout');

$labelDisconnected = Shared::cfg('LABEL_DISCONNECTED', 'ODPOJENO');
$labelNoDisconnect = Shared::cfg('LABEL_NODISCONNECT', 'NEODPOJOVAT');
$labelVip = Shared::cfg('LABEL_VIP', 'VIP');
$labelThirdReminder = Shared::cfg('LABEL_THIRD_REMINDER', 'UPOMINKA3');

$report = [
    'exitcode' => 0,
    'status' => 'success',
    'timestamp' => date(\DateTimeInterface::ATOM),
    'message' => '',
    'metrics' => ['checked' => 0, 'marked' => 0, 'skipped' => 0, 'errors' => 0],
    'customers' => [],
];

// ------------------------------------------------------------------
// 1. Customers with 3rd reminder label
// ------------------------------------------------------------------
$customer = new \AbraFlexi\Bricks\Customer();
$candidates = $customer->getCustomerList(['stitky' => $labelThirdReminder, 'limit' => 0]);

if (empty($candidates)) {
    $report['message'] = 'No customers with '.$labelThirdReminder.' label found.';
    file_put_contents($destination, json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
    exit(0);
}

// ------------------------------------------------------------------
// 2. Customers with active INTERNET contracts
// INET_CONTRACT_TYPE filters smlouva by typSmlouvyK code (e.g. "INET").
// Leave empty to match ALL active contracts (less precise).
// ------------------------------------------------------------------
$inetContractType = Shared::cfg('INET_CONTRACT_TYPE', '');
$smlouvaEvidence = new \AbraFlexi\Smlouva();

$contractConditions = ['stavK eq "stav.platna"', 'limit' => 0];

if ($inetContractType !== '') {
    $contractConditions[] = 'typSmlouvyK eq "'.addslashes($inetContractType).'"';
}

$allContracts = $smlouvaEvidence->getColumnsFromAbraFlexi(
    ['firma', 'kod', 'stavK', 'typSmlouvyK'],
    $contractConditions,
);

$customersWithContracts = [];

foreach ($allContracts as $contract) {
    if (!empty($contract['firma'])) {
        $firmCode = \is_array($contract['firma']) ? ($contract['firma']['kod'] ?? '') : (string) $contract['firma'];
        $customersWithContracts[$firmCode] = true;
    }
}

// ------------------------------------------------------------------
// 3. Mark eligible customers
// ------------------------------------------------------------------
$addresser = new \AbraFlexi\Adresar();

foreach ($candidates as $kod => $address) {
    $report['metrics']['checked']++;
    $labels = \AbraFlexi\Stitek::listToArray((string) ($address['stitky'] ?? ''));

    // Skip if already marked, VIP, or explicitly excluded
    if (\array_key_exists($labelDisconnected, $labels)) {
        $report['metrics']['skipped']++;
        $report['customers'][] = ['kod' => $kod, 'action' => 'already_marked'];

        continue;
    }

    if (\array_key_exists($labelVip, $labels)) {
        $report['metrics']['skipped']++;
        $report['customers'][] = ['kod' => $kod, 'action' => 'vip_skipped'];

        continue;
    }

    if (\array_key_exists($labelNoDisconnect, $labels)) {
        $report['metrics']['skipped']++;
        $report['customers'][] = ['kod' => $kod, 'action' => 'nodisconnect_skipped'];

        continue;
    }

    // Skip if no internet service contract
    if (!isset($customersWithContracts[$kod])) {
        $report['metrics']['skipped']++;
        $report['customers'][] = ['kod' => $kod, 'action' => 'no_contract_skipped'];

        continue;
    }

    // Set ODPOJENO label
    $addresser->dataReset();
    $addresser->setData([
        'id' => $address['id'],
        'stitky' => $labelDisconnected,
    ], true);
    $saved = $addresser->sync();

    if ($saved) {
        $report['metrics']['marked']++;
        $report['customers'][] = ['kod' => $kod, 'nazev' => $address['nazev'] ?? '', 'action' => 'marked_for_disconnection'];
    } else {
        $report['exitcode'] = 1;
        $report['metrics']['errors']++;
        $report['customers'][] = ['kod' => $kod, 'action' => 'error_setting_label'];
    }
}

// ------------------------------------------------------------------
// 4. Report
// ------------------------------------------------------------------
$m = $report['metrics'];
$report['message'] = "Checked: {$m['checked']}, Marked: {$m['marked']}, Skipped: {$m['skipped']}, Errors: {$m['errors']}";

if ($report['exitcode'] !== 0) {
    $report['status'] = 'warning';
}

file_put_contents($destination, json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

exit($report['exitcode']);
