<?php

declare(strict_types=1);

namespace App;

use App\Support\Cidr;
use App\Support\VendorLookup;

/**
 * Discovers live hosts on a local subnet, returning IP, MAC and hostname
 * for each one. Uses `nmap` when available (fast, gives vendor info too)
 * and falls back to a pure-PHP ping sweep + ARP table lookup otherwise.
 */
final class NetworkScanner
{
    private ?bool $nmapAvailable = null;

    public function __construct(
        private readonly int $pingTimeout = 1,
        private readonly int $pingBatchSize = 64,
        private readonly int $maxHosts = 512
    ) {
    }

    public function discoverHosts(string $cidr): array
    {
        return $this->hasNmap()
            ? $this->discoverWithNmap($cidr)
            : $this->discoverWithPing($cidr);
    }

    public function hasNmap(): bool
    {
        if ($this->nmapAvailable === null) {
            $path = trim((string) @shell_exec('command -v nmap 2>/dev/null'));
            $this->nmapAvailable = $path !== '';
        }

        return $this->nmapAvailable;
    }

    private function discoverWithNmap(string $cidr): array
    {
        $cmd = sprintf('nmap -sn -T4 -oX - %s 2>/dev/null', escapeshellarg($cidr));
        $xml = @shell_exec($cmd);
        if (!$xml) {
            return $this->discoverWithPing($cidr);
        }

        $sx = @simplexml_load_string($xml);
        if ($sx === false) {
            return $this->discoverWithPing($cidr);
        }

        $devices = [];
        foreach ($sx->host as $host) {
            if ((string) $host->status['state'] !== 'up') {
                continue;
            }

            $ip = null;
            $mac = null;
            $vendor = null;
            foreach ($host->address as $address) {
                $type = (string) $address['addrtype'];
                if ($type === 'ipv4') {
                    $ip = (string) $address['addr'];
                } elseif ($type === 'mac') {
                    $mac = strtoupper((string) $address['addr']);
                    $vendorAttr = (string) $address['vendor'];
                    $vendor = $vendorAttr !== '' ? $vendorAttr : VendorLookup::lookup($mac);
                }
            }

            if ($ip === null) {
                continue;
            }

            $hostname = null;
            if (isset($host->hostnames->hostname)) {
                $name = (string) $host->hostnames->hostname['name'];
                $hostname = $name !== '' ? $name : null;
            }

            $devices[] = [
                'ip' => $ip,
                'mac' => $mac,
                'vendor' => $vendor,
                'hostname' => $hostname,
            ];
        }

        usort($devices, fn ($a, $b) => ip2long($a['ip']) <=> ip2long($b['ip']));

        return $devices;
    }

    private function discoverWithPing(string $cidr): array
    {
        $ips = Cidr::listHosts($cidr, $this->maxHosts);
        $alive = [];

        foreach (array_chunk($ips, $this->pingBatchSize) as $batch) {
            $procs = [];
            foreach ($batch as $ip) {
                $cmd = sprintf('ping -c 1 -W %d %s', $this->pingTimeout, escapeshellarg($ip));
                $descriptors = [
                    1 => ['file', '/dev/null', 'w'],
                    2 => ['file', '/dev/null', 'w'],
                ];
                $pipes = [];
                $proc = @proc_open($cmd, $descriptors, $pipes);
                if (is_resource($proc)) {
                    $procs[$ip] = $proc;
                }
            }

            foreach ($procs as $ip => $proc) {
                if (proc_close($proc) === 0) {
                    $alive[] = $ip;
                }
            }
        }

        $arpTable = $this->readArpTable();

        $devices = [];
        foreach ($alive as $ip) {
            $mac = $arpTable[$ip] ?? null;
            $devices[] = [
                'ip' => $ip,
                'mac' => $mac,
                'vendor' => VendorLookup::lookup($mac),
                'hostname' => $this->resolveHostname($ip),
            ];
        }

        usort($devices, fn ($a, $b) => ip2long($a['ip']) <=> ip2long($b['ip']));

        return $devices;
    }

    private function resolveHostname(string $ip): ?string
    {
        $name = @gethostbyaddr($ip);
        if ($name === false || $name === $ip) {
            return null;
        }

        return $name;
    }

    private function readArpTable(): array
    {
        $table = [];

        $output = @shell_exec('ip neigh show 2>/dev/null');
        if ($output) {
            foreach (explode("\n", trim($output)) as $line) {
                if (preg_match('#^(\d+\.\d+\.\d+\.\d+).*lladdr\s+([0-9a-fA-F:]{17})#', $line, $m)) {
                    $table[$m[1]] = strtoupper($m[2]);
                }
            }
        }

        if (!$table) {
            $output = @shell_exec('arp -n 2>/dev/null');
            if ($output) {
                foreach (explode("\n", trim($output)) as $line) {
                    if (preg_match('#^(\d+\.\d+\.\d+\.\d+)\s+\S+\s+([0-9a-fA-F:]{17})#', $line, $m)) {
                        $table[$m[1]] = strtoupper($m[2]);
                    }
                }
            }
        }

        return $table;
    }
}
