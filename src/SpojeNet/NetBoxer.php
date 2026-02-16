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

use mkevenaar\NetBox\Client;

/**
 * Description of NetBoxer.
 *
 * @author Vitex <info@vitexsoftware.cz>
 */
class NetBoxer implements NetworkBackendInterface
{
    private Client $netbox;

    public function __construct()
    {
        $url = \Ease\Shared::cfg('NETBOXURL');
        $token = \Ease\Shared::cfg('NETBOXTOKEN');

        if (!$url || !$token) {
            throw new \Exception('Missing required NetBox configuration. Please check NETBOXURL and NETBOXTOKEN in configuration.');
        }

        // Set environment variables for NetBox Client
        putenv('NETBOX_API='.$url);
        putenv('NETBOX_API_KEY='.$token);

        $this->netbox = new Client();
    }

    /**
     * Block IP by setting speed to 0.
     */
    public function blockIp(string $ip): bool
    {
        // TODO: Implement NetBox API call to find and update IP address
        // $ipAddress = $this->netbox->ipAddresses()->findByAddress($ip);
        // if ($ipAddress) {
        //     $ipAddress->custom_fields['speed'] = 0;
        //     $this->netbox->ipAddresses()->update($ipAddress);
        //     return true;
        // }
        // return false;

        // For now, return true to indicate success
        return true;
    }

    /**
     * Unblock IP by setting speed to given value.
     */
    public function unblockIp(string $ip, int $speed): bool
    {
        // TODO: Implement NetBox API call to find and update IP address
        // $ipAddress = $this->netbox->ipAddresses()->findByAddress($ip);
        // if ($ipAddress) {
        //     $ipAddress->custom_fields['speed'] = $speed;
        //     $this->netbox->ipAddresses()->update($ipAddress);
        //     return true;
        // }
        // return false;

        // For now, return true to indicate success
        return true;
    }

    /**
     * Obtain list of customer's IP addresses from NetBox.
     *
     * Current implementation acts as a safe placeholder and returns an
     * empty array. Implementers can query NetBox IPAM for IPs linked to
     * a tenant or a custom field matching the customer code.
     *
     * @param string $code Customer identifier
     *
     * @return array List of IP strings
     */
    public function getCustomerIPs(string $code): array
    {
        $ips = [];

        try {
            // Retrieve IP list (no limit) and filter in PHP for safety
            $items = $this->netbox->ipAddresses()->list(['limit' => 0]);

            foreach ($items as $item) {
                $description = $item['description'] ?? '';
                $dnsName = $item['dns_name'] ?? '';
                $customFields = $item['custom_fields'] ?? [];

                $matches = false;

                if ($description !== '' && str_contains($description, $code)) {
                    $matches = true;
                }

                if (!$matches && $dnsName !== '' && str_contains($dnsName, $code)) {
                    $matches = true;
                }

                if (!$matches && \is_array($customFields)) {
                    foreach ($customFields as $val) {
                        if (is_string($val) && str_contains($val, $code)) {
                            $matches = true;
                            break;
                        }
                    }
                }

                if ($matches && !empty($item['address'])) {
                    $address = $item['address'];
                    $ip = explode('/', $address)[0];
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        $ips[] = $ip;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->log('NetBox lookup failed: '.$e->getMessage());
        }

        return array_values(array_unique($ips));
    }
}
