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

namespace SpojeNet;

/**
 * Description of Deblocker.
 *
 * @author Vitex <info@vitexsoftware.cz>
 */
class DeBlocker extends \Ease\Sand
{
    private \AbraFlexi\Bricks\Customer $customer;
    private NetworkBackendInterface $adapter;

    public function __construct(?NetworkBackendInterface $adapter = null)
    {
        $this->setObjectName('Deblocker');
        $this->customer = new \AbraFlexi\Bricks\Customer();
        $this->adapter = $adapter ?? new SubVersioner();
    }

    public function getBlocked(): array
    {
        $diconnectedLabel = \Ease\Shared::cfg('LABEL_DISCONNECTED');
        $adresses = $this->customer->getCustomerList(['stitky' => $diconnectedLabel, 'limit' => 0]);
        $this->addStatusMessage(\count($adresses).' '.sprintf(_('customers with label %s'), $diconnectedLabel));

        return $adresses;
    }

    /**
     * Block customers by setting their IP speed to 0.
     */
    public function blockCustomers(array $customers): bool
    {
        $allSuccessful = true;

        foreach ($customers as $customer) {
            $this->adapter->getCustomerIPs($customer);

            if (isset($customer['ip'])) {
                $result = $this->adapter->blockIp($customer['ip']);

                if (!$result) {
                    $allSuccessful = false;
                }
            }
        }

        return $allSuccessful;
    }

    /**
     * Unblock customers by setting their IP speed to contract value.
     */
    public function unblockCustomers(array $customers): bool
    {
        $allSuccessful = true;

        foreach ($customers as $customer) {
            if (isset($customer['ip'], $customer['speed'])) {
                $result = $this->adapter->unblockIp($customer['ip'], (int) $customer['speed']);

                if (!$result) {
                    $allSuccessful = false;
                }
            }
        }

        return $allSuccessful;
    }
}
