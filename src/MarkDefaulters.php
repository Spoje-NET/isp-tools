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

\define('EASE_APPNAME', 'MarkDefaulters');

require_once is_readable('/usr/share/php/isp-tools/autoload.php') ? '/usr/share/php/isp-tools/autoload.php' : \dirname(__DIR__).'/vendor/autoload.php';

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
    \array_key_exists('environment', $options) ? $options['environment'] : (\array_key_exists('e', $options) ? $options['e'] : (\is_readable(\dirname(__DIR__).'/.env') ? \dirname(__DIR__).'/.env' : '')),
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
$customer->addStatusMessage(\count($candidates).' customers with label '.$labelThirdReminder, 'info');

if (empty($candidates)) {
    $report['message'] = 'No customers with '.$labelThirdReminder.' label found.';
    $customer->addStatusMessage($report['message'], 'success');
    file_put_contents($destination, json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

    exit(0);
}

// ------------------------------------------------------------------
// 2. Customers with active INTERNET contracts
// INET_CONTRACT_TYPE filters smlouva by its type code (typSml, e.g. "INTERNET").
// Leave empty to match ALL active contracts (less precise).
// ------------------------------------------------------------------
$smlouvaEvidence = new \AbraFlexi\Smlouva();

$allContracts = $smlouvaEvidence->getColumnsFromAbraFlexi(
    ['firma', 'kod', 'stavSml', 'typSml'],
    \SpojeNet\DeBlocker::activeContractConditions((string) Shared::cfg('INET_CONTRACT_TYPE', '')),
);

$customersWithContracts = [];

foreach ($allContracts as $contract) {
    if (!empty($contract['firma'])) {
        $firmCode = \is_array($contract['firma']) ? ($contract['firma']['kod'] ?? '') : \AbraFlexi\Functions::uncode((string) $contract['firma']);
        $customersWithContracts[$firmCode] = true;
    }
}

$smlouvaEvidence->addStatusMessage(\count($customersWithContracts).' customers with active contracts', 'info');

// ------------------------------------------------------------------
// 3. Mark eligible customers
// ------------------------------------------------------------------
$addresser = new \AbraFlexi\Adresar();

foreach ($candidates as $kod => $address) {
    ++$report['metrics']['checked'];
    $labels = \AbraFlexi\Stitek::listToArray((string) ($address['stitky'] ?? ''));

    // Skip if already marked, VIP, or explicitly excluded
    if (\array_key_exists($labelDisconnected, $labels)) {
        ++$report['metrics']['skipped'];
        $addresser->addStatusMessage($kod.' '.($address['nazev'] ?? '').' already marked', 'warning');
        $report['customers'][] = ['kod' => $kod, 'action' => 'already_marked'];

        continue;
    }

    if (\array_key_exists($labelVip, $labels)) {
        ++$report['metrics']['skipped'];
        $addresser->addStatusMessage($kod.' '.($address['nazev'] ?? '').' VIP', 'warning');
        $report['customers'][] = ['kod' => $kod, 'action' => 'vip_skipped'];

        continue;
    }

    if (\array_key_exists($labelNoDisconnect, $labels)) {
        ++$report['metrics']['skipped'];
        $addresser->addStatusMessage($kod.' '.($address['nazev'] ?? '').' NODISCONNECT', 'warning');
        $report['customers'][] = ['kod' => $kod, 'action' => 'nodisconnect_skipped'];

        continue;
    }

    // Skip if no internet service contract
    if (!isset($customersWithContracts[$kod])) {
        ++$report['metrics']['skipped'];
        $addresser->addStatusMessage($kod.' '.($address['nazev'] ?? '').' no active contract', 'warning');
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
        ++$report['metrics']['marked'];
        $addresser->addStatusMessage($kod.' '.($address['nazev'] ?? '').' marked '.$labelDisconnected, 'success');
        $report['customers'][] = ['kod' => $kod, 'nazev' => $address['nazev'] ?? '', 'action' => 'marked_for_disconnection'];
    } else {
        $report['exitcode'] = 1;
        ++$report['metrics']['errors'];
        $addresser->addStatusMessage($kod.' '.($address['nazev'] ?? '').' error setting label', 'error');
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

$addresser->addStatusMessage($report['message'], $report['exitcode'] === 0 ? 'success' : 'warning');

$written = file_put_contents($destination, json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
$addresser->addStatusMessage(sprintf('Report saved to %s', $destination), $written ? 'success' : 'error');

exit($report['exitcode']);
