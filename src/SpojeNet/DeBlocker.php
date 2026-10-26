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
     * Filter conditions selecting active contracts (smlouva), optionally of one contract type.
     *
     * The contract type accepts `INTERNET`, `code:INTERNET` and the legacy
     * `typSmlouvy.INTERNET` spelling.
     *
     * @return array<int|string, mixed> conditions for AbraFlexi\Smlouva::getColumnsFromAbraFlexi()
     */
    public static function activeContractConditions(string $contractType = ''): array
    {
        $conditions = ['stavSml eq "code:AKTIVNI"', 'limit' => 0];
        $contractType = trim($contractType);

        if ($contractType !== '') {
            $contractType = preg_replace('/^(typSmlouvy\.|code:)/', '', $contractType);
            $conditions[] = 'typSml eq "code:'.addslashes((string) $contractType).'"';
        }

        return $conditions;
    }

    /**
     * Customers having an active contract of INET_CONTRACT_TYPE (any type when empty).
     *
     * @return array<string, bool> customer code => true
     */
    public function getInetCustomers(): array
    {
        $contract = new \AbraFlexi\Smlouva();
        $customersWithContracts = [];

        foreach ((array) $contract->getColumnsFromAbraFlexi(['firma', 'kod', 'stavSml', 'typSml'], self::activeContractConditions((string) \Ease\Shared::cfg('INET_CONTRACT_TYPE', ''))) as $contractData) {
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
     * @param string|null $customer Optional customer code or ID to query for a single customer
     *
     * @return array<string, array<string, mixed>> address records keyed by customer code
     */
    public function getBlockedCustomers(?string $customer = null): array
    {
        $diconnectedLabel = \Ease\Shared::cfg('LABEL_DISCONNECTED', 'ODPOJENO');
        $conditions = ['stitky' => $diconnectedLabel, 'limit' => 0];

        if (!empty($customer)) {
            $conditions['id'] = is_numeric($customer) ? (int) $customer : \AbraFlexi\Functions::code($customer);
        }

        $adresses = $this->customer->getCustomerList($conditions);
        $this->addStatusMessage(sprintf(
            'Found %d customers with label %s (AbraFlexi address records, scope: %s)',
            \count($adresses),
            $diconnectedLabel,
            empty($customer) ? 'all customers' : 'customer '.$customer,
        ));

        return $adresses;
    }

    /**
     * Customers with unpaid overdue issued invoices.
     *
     * @param string|null $customer Optional customer code or ID to query for a single customer
     *
     * @return array<string, array{count: int, due: float}> keyed by customer (firma) code
     */
    public function getInvoicesStatus(?string $customer = null): array
    {
        $invoicer = new \AbraFlexi\FakturaVydana();
        $conditions = [
            "(stavUhrK is null OR stavUhrK eq 'stavUhr.castUhr')",
            'storno eq false',
            "datSplat lt '".date('Y-m-d')."'",
            "(typDokl eq 'code:FAKTURA' OR typDokl eq 'code:ZALOHA')",
            'limit' => 0,
        ];

        if (!empty($customer)) {
            $conditions['firma'] = is_numeric($customer) ? (int) $customer : \AbraFlexi\Functions::code($customer);
        }

        $unpaid = $invoicer->getColumnsFromAbraFlexi(
            ['firma', 'kod', 'zbyvaUhradit', 'datSplat'],
            $conditions,
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

        $this->addStatusMessage(sprintf(
            'Found %d customers with unpaid overdue invoices (FAKTURA/ZALOHA, not storno, not fully paid, due before %s, scope: %s)',
            \count($debtors),
            date('Y-m-d'),
            empty($customer) ? 'all customers' : 'customer '.$customer,
        ));

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
                $this->addStatusMessage(sprintf('Customer %s: no IP addresses in the network backend, nothing to block', $code), 'warning');
            }

            foreach ($ips as $ip) {
                if ($this->adapter->blockIp($ip)) {
                    ++$result['blocked'];
                    $this->addStatusMessage(sprintf('Customer %s: blocked IP %s (speed set to 0)', $code, $ip), 'success');
                } else {
                    ++$result['failed'];
                    $this->addStatusMessage(sprintf('Customer %s: failed to block IP %s (network backend reported an error)', $code, $ip), 'error');
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
                $this->addStatusMessage(sprintf('Customer %s: no IP addresses in the network backend, nothing to unblock', $code), 'warning');
            }

            foreach ($ips as $ip) {
                if ($this->adapter->unblockIp($ip, $fallbackSpeed)) {
                    ++$result['unblocked'];
                    $this->addStatusMessage(sprintf('Customer %s: unblocked IP %s (original speed restored, fallback %d if none stored)', $code, $ip, $fallbackSpeed), 'success');
                } else {
                    ++$result['failed'];
                    $this->addStatusMessage(sprintf('Customer %s: failed to unblock IP %s (network backend reported an error)', $code, $ip), 'error');
                }
            }

            $results[$code] = $result;
        }

        return $results;
    }

    /**
     * Remove the LABEL_DISCONNECTED label from a customer address record.
     *
     * A plain update of the 'stitky' field merges into the existing label
     * set rather than replacing it, so removal must go through
     * Adresar::unsetLabel(), which sends the 'stitky@removeAll' directive
     * AbraFlexi requires to actually drop a label.
     */
    public function removeDisconnectedLabel(string $code): bool
    {
        $diconnectedLabel = (string) \Ease\Shared::cfg('LABEL_DISCONNECTED', 'ODPOJENO');
        $addresser = new \AbraFlexi\Adresar(\AbraFlexi\Functions::code($code), ['detail' => 'custom:kod,stitky']);
        $labels = \AbraFlexi\Stitek::listToArray((string) $addresser->getDataValue('stitky'));

        if (!\array_key_exists($diconnectedLabel, $labels)) {
            $this->addStatusMessage(sprintf('Customer %s: label %s already absent, nothing to remove', $code, $diconnectedLabel), 'debug');

            return true;
        }

        $removed = $addresser->unsetLabel($diconnectedLabel);
        $this->addStatusMessage(
            sprintf('Customer %s: %s label %s in AbraFlexi', $code, $removed ? 'removed' : 'failed to remove', $diconnectedLabel),
            $removed ? 'success' : 'error',
        );

        return $removed;
    }
}
