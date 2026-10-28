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
 * Dual-write network backend: primary (usually SVN) then secondary (NetBox).
 *
 * IP discovery unions both backends. Block/unblock succeeds if the primary
 * succeeds; secondary failures are logged but do not fail the run.
 */
class CompositeNetworkBackend implements NetworkBackendInterface
{
    public function __construct(
        private NetworkBackendInterface $primary,
        private NetworkBackendInterface $secondary,
    ) {
    }

    public function blockIp(string $ip): bool
    {
        $ok = $this->primary->blockIp($ip);

        try {
            $this->secondary->blockIp($ip);
        } catch (\Throwable $e) {
            error_log('CompositeNetworkBackend secondary blockIp failed: '.$e->getMessage());
        }

        return $ok;
    }

    public function unblockIp(string $ip, int $speed): bool
    {
        $ok = $this->primary->unblockIp($ip, $speed);

        try {
            $this->secondary->unblockIp($ip, $speed);
        } catch (\Throwable $e) {
            error_log('CompositeNetworkBackend secondary unblockIp failed: '.$e->getMessage());
        }

        return $ok;
    }

    public function getCustomerIPs(string $code): array
    {
        $ips = $this->primary->getCustomerIPs($code);

        try {
            $ips = array_merge($ips, $this->secondary->getCustomerIPs($code));
        } catch (\Throwable $e) {
            error_log('CompositeNetworkBackend secondary getCustomerIPs failed: '.$e->getMessage());
        }

        return array_values(array_unique($ips));
    }
}
