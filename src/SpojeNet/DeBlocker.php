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

namespace SpojeNet;

/**
 * Orchestrates blocking/unblocking of customer internet access.
 *
 * Resolves customers to IP addresses through a NetworkBackendInterface
 * adapter and answers AbraFlexi questions (blocked customers, debtors,
 * internet contracts) needed by the blocknet/unblocknet tools.
 *
 * @author Vitex <info@vitexsoftware.cz>
 */
class DeBlocker extends \Ease\Sand
{
    private \AbraFlexi\Bricks\Customer $customer;
    private NetworkBackendInterface $adapter;

    public function __construct(?NetworkBackendInterface $adapter = null, ?\AbraFlexi\Bricks\Customer $customer = null)
    {
        $this->setObjectName('Deblocker');
        $this->customer = $customer ?? new \AbraFlexi\Bricks\Customer();
        $this->adapter = $adapter ?? new SubVersioner();
    }

    /**
     * Customers holding an active internet service contract.
     *
     * INET_CONTRACT_TYPE filters smlouva by typSmlouvyK code
     * (e.g. "typSmlouvy.INTERNET"). Leave empty to match ALL active contracts.
     *
     * @return array<string, bool> customer (firma) code => true
     */
    public function getInetCustomers(): array
    {
        $contract = new \AbraFlexi\Smlouva();
        $conditions = ['stavK eq "stav.platna"', 'limit' => 0];
        $inetContractType = (string) \Ease\Shared::cfg('INET_CONTRACT_TYPE', '');

        if ($inetContractType !== '') {
            $conditions[] = 'typSmlouvyK eq "'.addslashes($inetContractType).'"';
        }

        $customersWithContracts = [];

        foreach ((array) $contract->getColumnsFromAbraFlexi(['firma', 'kod', 'stavK', 'typSmlouvyK'], $conditions) as $contractData) {
            if (!empty($contractData['firma'])) {
                $firmCode = \is_array($contractData['firma']) ? ($contractData['firma']['kod'] ?? '') : \AbraFlexi\Functions::uncode((string) $contractData['firma']);
                $customersWithContracts[$firmCode] = true;
            }
        }

        return $customersWithContracts;
    }

    /**
     * Customers currently marked for disconnection.
     *
     * @return array<string, array<string, mixed>> address records keyed by customer code
     */
    public function getBlockedCustomers(): array
    {
        $diconnectedLabel = \Ease\Shared::cfg('LABEL_DISCONNECTED', 'ODPOJENO');
        $adresses = $this->customer->getCustomerList(['stitky' => $diconnectedLabel, 'limit' => 0]);
        $this->addStatusMessage(\count($adresses).' '.sprintf(_('customers with label %s'), $diconnectedLabel));

        return $adresses;
    }

    /**
     * Customers with unpaid overdue issued invoices.
     *
     * @return array<string, array{count: int, due: float}> keyed by customer (firma) code
     */
    public function getInvoicesStatus(): array
    {
        $invoicer = new \AbraFlexi\FakturaVydana();
        $unpaid = $invoicer->getColumnsFromAbraFlexi(
            ['firma', 'kod', 'zbyvaUhradit', 'datSplat'],
            [
                "(stavUhrK is null OR stavUhrK eq 'stavUhr.castUhr')",
                'storno eq false',
                "datSplat lt '".date('Y-m-d')."'",
                "(typDokl eq 'code:FAKTURA' OR typDokl eq 'code:ZALOHA')",
                'limit' => 0,
            ],
        );

        $debtors = [];

        foreach ((array) $unpaid as $invoice) {
            if (empty($invoice['firma'])) {
                continue;
            }

            $firmCode = \is_array($invoice['firma']) ? ($invoice['firma']['kod'] ?? '') : \AbraFlexi\Functions::uncode((string) $invoice['firma']);

            if ($firmCode === '') {
                continue;
            }

            if (!\array_key_exists($firmCode, $debtors)) {
                $debtors[$firmCode] = ['count' => 0, 'due' => 0.0];
            }

            ++$debtors[$firmCode]['count'];
            $debtors[$firmCode]['due'] += (float) $invoice['zbyvaUhradit'];
        }

        $this->addStatusMessage(\count($debtors).' '._('customers with unpaid overdue invoices'));

        return $debtors;
    }

    /**
     * Block customers by setting all their IP speeds to 0.
     *
     * @param string[] $customerCodes customer codes to block
     *
     * @return array<string, array{ips: string[], blocked: int, failed: int}> per-customer results
     */
    public function blockCustomers(array $customerCodes): array
    {
        $results = [];

        foreach ($customerCodes as $code) {
            $ips = $this->adapter->getCustomerIPs($code);
            $result = ['ips' => $ips, 'blocked' => 0, 'failed' => 0];

            if ($ips === []) {
                $this->addStatusMessage(sprintf(_('No IP addresses found for customer %s'), $code), 'warning');
            }

            foreach ($ips as $ip) {
                if ($this->adapter->blockIp($ip)) {
                    ++$result['blocked'];
                    $this->addStatusMessage(sprintf(_('Blocked IP %s of customer %s'), $ip, $code), 'success');
                } else {
                    ++$result['failed'];
                    $this->addStatusMessage(sprintf(_('Failed to block IP %s of customer %s'), $ip, $code), 'error');
                }
            }

            $results[$code] = $result;
        }

        return $results;
    }

    /**
     * Unblock customers by restoring speed on all their IPs.
     *
     * The backend restores the original speed recorded at block time when
     * available; $fallbackSpeed is used otherwise.
     *
     * @param string[] $customerCodes customer codes to unblock
     * @param int      $fallbackSpeed speed used when the backend has no stored original
     *
     * @return array<string, array{ips: string[], unblocked: int, failed: int}> per-customer results
     */
    public function unblockCustomers(array $customerCodes, int $fallbackSpeed = 0): array
    {
        $results = [];

        foreach ($customerCodes as $code) {
            $ips = $this->adapter->getCustomerIPs($code);
            $result = ['ips' => $ips, 'unblocked' => 0, 'failed' => 0];

            if ($ips === []) {
                $this->addStatusMessage(sprintf(_('No IP addresses found for customer %s'), $code), 'warning');
            }

            foreach ($ips as $ip) {
                if ($this->adapter->unblockIp($ip, $fallbackSpeed)) {
                    ++$result['unblocked'];
                    $this->addStatusMessage(sprintf(_('Unblocked IP %s of customer %s'), $ip, $code), 'success');
                } else {
                    ++$result['failed'];
                    $this->addStatusMessage(sprintf(_('Failed to unblock IP %s of customer %s'), $ip, $code), 'error');
                }
            }

            $results[$code] = $result;
        }

        return $results;
    }

    /**
     * Remove the LABEL_DISCONNECTED label from a customer address record.
     *
     * AbraFlexi replaces the whole label set on update, so the full list of
     * remaining labels is written back.
     */
    public function removeDisconnectedLabel(string $code): bool
    {
        $diconnectedLabel = (string) \Ease\Shared::cfg('LABEL_DISCONNECTED', 'ODPOJENO');
        $addresser = new \AbraFlexi\Adresar(\AbraFlexi\Functions::code($code), ['detail' => 'custom:kod,stitky']);
        $labels = \AbraFlexi\Stitek::listToArray((string) $addresser->getDataValue('stitky'));

        if (!\array_key_exists($diconnectedLabel, $labels)) {
            return true;
        }

        unset($labels[$diconnectedLabel]);

        $addresser->dataReset();
        $addresser->setData([
            'id' => \AbraFlexi\Functions::code($code),
            'stitky' => implode(',', array_keys($labels)),
        ], true);

        $removed = (bool) $addresser->sync();
        $this->addStatusMessage(
            sprintf(_('Label %s removal for customer %s'), $diconnectedLabel, $code),
            $removed ? 'success' : 'error',
        );

        return $removed;
    }
}
