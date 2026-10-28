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

use mkevenaar\NetBox\Client;

/**
 * Network backend that records block/unblock in NetBox IPAM.
 *
 * Customer IPs are resolved via custom field `flags.code` (hostsbrevnov import).
 * Disconnect is recorded by adding the `dashboard-odpojen` token to `flags.raw`
 * / `flags.tokens` (same marker SubVersioner uses in the hosts file comment).
 */
class NetBoxer implements NetworkBackendInterface
{
    private const DISCONNECTED_TOKEN = 'dashboard-odpojen';

    private Client $netbox;

    public function __construct()
    {
        $url = \Ease\Shared::cfg('NETBOX_URL') ?: \Ease\Shared::cfg('NETBOXURL');
        $token = \Ease\Shared::cfg('NETBOX_TOKEN') ?: \Ease\Shared::cfg('NETBOXTOKEN');

        if (!$url || !$token) {
            throw new \Exception('Missing required NetBox configuration. Please check NETBOX_URL and NETBOX_TOKEN in configuration.');
        }

        // mkevenaar/netbox Client reads these environment variables.
        // HttpClient concatenates baseUri + "/ipam/...", so base must include /api/.
        $apiBase = rtrim((string) $url, '/');
        if (!str_ends_with($apiBase, '/api')) {
            $apiBase .= '/api';
        }
        putenv('NETBOX_API='.$apiBase.'/');
        putenv('NETBOX_API_KEY='.$token);

        $this->netbox = new Client();
    }

    /**
     * Block IP by adding dashboard-odpojen to NetBox flags.
     */
    public function blockIp(string $ip): bool
    {
        $item = $this->findIpAddress($ip);

        if ($item === null) {
            return false;
        }

        $flags = $this->normalizeFlags($item['custom_fields']['flags'] ?? null);

        if (\in_array(self::DISCONNECTED_TOKEN, $flags['tokens'], true)) {
            return true;
        }

        array_unshift($flags['tokens'], self::DISCONNECTED_TOKEN);
        $flags['tokens'] = array_values(array_unique($flags['tokens']));
        $flags['raw'] = trim(self::DISCONNECTED_TOKEN.' '.ltrim(str_replace(self::DISCONNECTED_TOKEN, '', (string) $flags['raw'])));

        return $this->persistFlags((int) $item['id'], $flags, (string) ($item['description'] ?? ''));
    }

    /**
     * Unblock IP by removing dashboard-odpojen from NetBox flags.
     */
    public function unblockIp(string $ip, int $speed): bool
    {
        unset($speed); // NetBox hosts model has no speed field; token removal is the unblock signal.
        $item = $this->findIpAddress($ip);

        if ($item === null) {
            return false;
        }

        $flags = $this->normalizeFlags($item['custom_fields']['flags'] ?? null);

        if (!\in_array(self::DISCONNECTED_TOKEN, $flags['tokens'], true)
            && !str_contains((string) $flags['raw'], self::DISCONNECTED_TOKEN)) {
            return true;
        }

        $flags['tokens'] = array_values(array_filter(
            $flags['tokens'],
            static fn (string $t): bool => $t !== self::DISCONNECTED_TOKEN,
        ));
        $flags['raw'] = trim(preg_replace('/\s+/', ' ', str_replace(self::DISCONNECTED_TOKEN, '', (string) $flags['raw'])) ?? '');

        return $this->persistFlags((int) $item['id'], $flags, (string) ($item['description'] ?? ''));
    }

    /**
     * Obtain list of customer's IP addresses from NetBox (flags.code).
     *
     * @return list<string>
     */
    public function getCustomerIPs(string $code): array
    {
        $ips = [];
        $code = trim($code);

        if ($code === '') {
            return [];
        }

        try {
            $offset = 0;
            $limit = 200;

            do {
                $response = $this->netbox->ipAddresses()->list([
                    'limit' => $limit,
                    'offset' => $offset,
                ]);
                $items = $this->extractResults($response);

                foreach ($items as $item) {
                    if (!$this->itemMatchesCustomerCode($item, $code)) {
                        continue;
                    }

                    $address = (string) ($item['address'] ?? '');
                    $ip = explode('/', $address)[0];

                    if (filter_var($ip, \FILTER_VALIDATE_IP)) {
                        $ips[] = $ip;
                    }
                }

                $offset += $limit;
                $count = (int) ($response['count'] ?? \count($items));
            } while ($offset < $count && $items !== []);
        } catch (\Exception $e) {
            error_log('NetBox lookup failed: '.$e->getMessage());
        }

        return array_values(array_unique($ips));
    }

    /**
     * @param mixed $response
     *
     * @return list<array<string, mixed>>
     */
    private function extractResults(mixed $response): array
    {
        if (!\is_array($response)) {
            return [];
        }

        if (isset($response['results']) && \is_array($response['results'])) {
            return $response['results'];
        }

        // Non-paginated list fallback.
        if ($response !== [] && array_is_list($response)) {
            return $response;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function itemMatchesCustomerCode(array $item, string $code): bool
    {
        $flags = $item['custom_fields']['flags'] ?? null;

        if (\is_array($flags) && isset($flags['code']) && (string) $flags['code'] === $code) {
            return true;
        }

        $raw = \is_array($flags) ? (string) ($flags['raw'] ?? '') : '';
        $description = (string) ($item['description'] ?? '');
        $needle = '{code:'.$code.'}';

        return str_contains($raw, $needle) || str_contains($description, $needle);
    }

    /**
     * @return null|array<string, mixed>
     */
    private function findIpAddress(string $ip): ?array
    {
        $ip = trim($ip);

        if ($ip === '' || !filter_var($ip, \FILTER_VALIDATE_IP)) {
            return null;
        }

        try {
            foreach ([$ip.'/32', $ip] as $query) {
                $response = $this->netbox->ipAddresses()->list([
                    'address' => $query,
                    'limit' => 5,
                ]);

                foreach ($this->extractResults($response) as $item) {
                    $address = (string) ($item['address'] ?? '');

                    if (explode('/', $address)[0] === $ip) {
                        return $item;
                    }
                }
            }
        } catch (\Exception $e) {
            error_log('NetBox IP lookup failed for '.$ip.': '.$e->getMessage());
        }

        return null;
    }

    /**
     * @param mixed $flags
     *
     * @return array{raw: string, tokens: list<string>, code: null|string, kind: string, aliases: list<string>, hostname: null|string, source: null|string, device: mixed, lineno: mixed, snat_only: mixed, public_nat: mixed}
     */
    private function normalizeFlags(mixed $flags): array
    {
        if (!\is_array($flags)) {
            $flags = [];
        }

        $raw = (string) ($flags['raw'] ?? '');
        $tokens = $flags['tokens'] ?? [];

        if (!\is_array($tokens)) {
            $tokens = $raw !== '' ? preg_split('/\s+/', $raw) ?: [] : [];
        }

        $tokens = array_values(array_filter(array_map('strval', $tokens), static fn (string $t): bool => $t !== ''));

        return [
            'raw' => $raw,
            'tokens' => $tokens,
            'code' => isset($flags['code']) && $flags['code'] !== '' && $flags['code'] !== null ? (string) $flags['code'] : null,
            'kind' => (string) ($flags['kind'] ?? 'host'),
            'aliases' => array_values(array_map('strval', \is_array($flags['aliases'] ?? null) ? $flags['aliases'] : [])),
            'hostname' => isset($flags['hostname']) ? (string) $flags['hostname'] : null,
            'source' => isset($flags['source']) ? (string) $flags['source'] : 'hostsbrevnov',
            'device' => $flags['device'] ?? null,
            'lineno' => $flags['lineno'] ?? null,
            'snat_only' => $flags['snat_only'] ?? null,
            'public_nat' => $flags['public_nat'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $flags
     */
    private function persistFlags(int $id, array $flags, string $previousDescription): bool
    {
        try {
            $description = $flags['raw'] !== '' ? (string) $flags['raw'] : $previousDescription;
            $this->netbox->ipAddresses()->update($id, [
                'description' => $description,
                'custom_fields' => [
                    'flags' => $flags,
                    'hosts_kind' => $flags['kind'] ?? 'host',
                    'hosts_aliases' => $flags['aliases'] ?? [],
                ],
            ]);

            return true;
        } catch (\Exception $e) {
            error_log('NetBox IP update failed for id '.$id.': '.$e->getMessage());

            return false;
        }
    }
}
