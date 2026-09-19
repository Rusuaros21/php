<?php

declare(strict_types=1);

namespace App\Support;

final class Cidr
{
    /**
     * Best local IPv4 subnet guess (first usable interface found), in CIDR
     * notation (e.g. "192.168.1.0/24"). Null if nothing could be detected.
     */
    public static function detectLocalSubnet(): ?string
    {
        $subnets = self::detectLocalSubnets();

        return $subnets[0]['cidr'] ?? null;
    }

    /**
     * Detects every local, non-loopback, non-link-local IPv4 subnet this
     * host is attached to, trying (in order) Linux iproute2 (`ip`),
     * macOS/BSD/legacy `ifconfig`, and finally a pure-PHP hostname lookup
     * that needs no external command at all.
     *
     * @return array<int, array{iface: string, ip: string, cidr: string}>
     */
    public static function detectLocalSubnets(): array
    {
        $subnets = [];

        $output = @shell_exec('ip -o -4 addr show scope global 2>/dev/null');
        if ($output) {
            foreach (explode("\n", trim($output)) as $line) {
                if (preg_match('#^\d+:\s+(\S+)\s+inet\s+(\d+\.\d+\.\d+\.\d+/\d+)#', $line, $m)) {
                    self::addSubnet($subnets, $m[1], $m[2]);
                }
            }
        }

        if (!$subnets) {
            $output = @shell_exec('ifconfig 2>/dev/null');
            if ($output) {
                $iface = null;
                foreach (explode("\n", $output) as $line) {
                    if ($line === '') {
                        continue;
                    }

                    // A new interface block starts at column 0 (no leading
                    // whitespace). Its name is the first token, with or
                    // without a trailing colon depending on ifconfig flavor
                    // (macOS/BSD: "en0: flags=…", legacy net-tools: "eth0      Link encap:…").
                    if (!preg_match('#^\s#', $line)) {
                        if (preg_match('#^(\S+?):?\s#', $line, $m)) {
                            $iface = $m[1];
                        }
                        continue;
                    }
                    if ($iface === null) {
                        continue;
                    }

                    // macOS / BSD: "inet 192.168.1.5 netmask 0xffffff00 broadcast ..."
                    if (preg_match('#inet\s+(\d+\.\d+\.\d+\.\d+)\s+netmask\s+(0x[0-9a-fA-F]{8})#', $line, $m)) {
                        self::addSubnet($subnets, $iface, $m[1] . '/' . self::hexMaskToPrefix($m[2]));
                        continue;
                    }

                    // Legacy net-tools Linux: "inet addr:192.168.1.5  Mask:255.255.255.0"
                    if (preg_match('#inet addr:(\d+\.\d+\.\d+\.\d+).*Mask:(\d+\.\d+\.\d+\.\d+)#', $line, $m)) {
                        self::addSubnet($subnets, $iface, $m[1] . '/' . self::dottedMaskToPrefix($m[2]));
                    }
                }
            }
        }

        if (!$subnets) {
            $host = gethostname();
            if ($host !== false) {
                $ip = gethostbyname($host);
                if ($ip !== $host) {
                    self::addSubnet($subnets, 'hostname', $ip . '/24');
                }
            }
        }

        return $subnets;
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

    private static function addSubnet(array &$subnets, string $iface, string $cidr): void
    {
        [$ip] = explode('/', $cidr, 2);

        if ($iface === 'lo' || $iface === 'lo0' || str_starts_with($ip, '127.') || str_starts_with($ip, '169.254.')) {
            return;
        }

        $normalized = self::toNetworkCidr($cidr);
        foreach ($subnets as $existing) {
            if ($existing['cidr'] === $normalized) {
                return;
            }
        }

        $subnets[] = ['iface' => $iface, 'ip' => $ip, 'cidr' => $normalized];
    }

    private static function hexMaskToPrefix(string $hexMask): int
    {
        $value = (int) hexdec(substr($hexMask, 2));

        return substr_count(decbin($value), '1');
    }

    private static function dottedMaskToPrefix(string $mask): int
    {
        $long = ip2long($mask);
        if ($long === false) {
            return 24;
        }

        return substr_count(decbin($long), '1');
    }
}
