<?php

declare(strict_types=1);

namespace App\Support;

final class Cidr
{
    /**
     * Try to detect the local IPv4 subnet in CIDR notation (e.g. "192.168.1.0/24").
     */
    public static function detectLocalSubnet(): ?string
    {
        $output = @shell_exec('ip -o -4 addr show scope global 2>/dev/null');
        if ($output) {
            foreach (explode("\n", trim($output)) as $line) {
                if (preg_match('#^\d+:\s+(\S+)\s+inet\s+(\d+\.\d+\.\d+\.\d+/\d+)#', $line, $m)) {
                    [$iface, $cidr] = [$m[1], $m[2]];
                    if ($iface === 'lo' || str_starts_with($cidr, '127.')) {
                        continue;
                    }
                    return self::toNetworkCidr($cidr);
                }
            }
        }

        $ipList = trim((string) @shell_exec('hostname -I 2>/dev/null'));
        if ($ipList !== '') {
            $first = explode(' ', $ipList)[0];
            if (filter_var($first, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return self::toNetworkCidr($first . '/24');
            }
        }

        return null;
    }

    /**
     * Normalise a host/prefix pair (e.g. "192.168.1.5/24") to its network address ("192.168.1.0/24").
     */
    public static function toNetworkCidr(string $cidr): string
    {
        [$base, $prefix] = array_pad(explode('/', $cidr, 2), 2, '24');
        $prefix = (int) $prefix;
        $baseLong = ip2long($base);
        if ($baseLong === false) {
            return $cidr;
        }
        $mask = $prefix > 0 ? (-1 << (32 - $prefix)) : 0;
        $network = $baseLong & $mask;

        return long2ip($network) . '/' . $prefix;
    }

    /**
     * List usable host addresses inside a CIDR block, capped at $limit for safety.
     * Prefixes smaller than /22 (i.e. more than ~1024 hosts) are clamped to /22.
     */
    public static function listHosts(string $cidr, int $limit = 512): array
    {
        [$base, $prefix] = array_pad(explode('/', $cidr, 2), 2, '24');
        $prefix = (int) $prefix;
        if ($prefix < 22) {
            $prefix = 22;
        }

        $baseLong = ip2long($base);
        if ($baseLong === false) {
            return [];
        }

        $mask = -1 << (32 - $prefix);
        $network = $baseLong & $mask;
        $broadcast = $network | ~$mask;

        $hosts = [];
        for ($i = $network + 1; $i < $broadcast && count($hosts) < $limit; $i++) {
            $hosts[] = long2ip($i);
        }

        return $hosts;
    }
}
