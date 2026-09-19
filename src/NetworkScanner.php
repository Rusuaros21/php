<?php

declare(strict_types=1);

namespace App;

use App\Support\Cidr;
use App\Support\Environment;
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
            $this->nmapAvailable = Environment::commandExists('nmap');
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
            $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
            foreach ($batch as $ip) {
                $cmd = $this->pingCommand($ip);
                $descriptors = [
                    1 => ['file', $nullDevice, 'w'],
                    2 => ['file', $nullDevice, 'w'],
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

    /**
     * `ping`'s flags are not portable: iputils (Linux) takes `-c` (count)
     * and `-W` (timeout in seconds); macOS/BSD ping interprets `-W` as
     * milliseconds and instead offers `-t` as an overall deadline in
     * seconds; Windows ping uses `-n` (count) and `-w` (timeout in ms).
     */
    private function pingCommand(string $ip): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return sprintf('ping -n 1 -w %d %s', $this->pingTimeout * 1000, escapeshellarg($ip));
        }

        if (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'BSD') {
            return sprintf('ping -c 1 -t %d %s', $this->pingTimeout, escapeshellarg($ip));
        }

        return sprintf('ping -c 1 -W %d %s', $this->pingTimeout, escapeshellarg($ip));
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
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->readArpTableWindows();
        }

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

        if (!$table) {
            // macOS / BSD: "? (192.168.1.5) at aa:bb:cc:dd:ee:ff on en0 ifscope [ethernet]"
            $output = @shell_exec('arp -a -n 2>/dev/null');
            if ($output) {
                foreach (explode("\n", trim($output)) as $line) {
                    if (preg_match('#\((\d+\.\d+\.\d+\.\d+)\)\s+at\s+([0-9a-fA-F:]{17})#', $line, $m)) {
                        $table[$m[1]] = strtoupper($m[2]);
                    }
                }
            }
        }

        return $table;
    }

    /**
     * Windows `arp -a` output looks like:
     *   Interface: 192.168.1.5 --- 0xb
     *     Internet Address      Physical Address      Type
     *     192.168.1.1           aa-bb-cc-dd-ee-ff     dynamic
     */
    private function readArpTableWindows(): array
    {
        $table = [];

        $output = @shell_exec('arp -a 2>NUL');
        if ($output) {
            foreach (preg_split('/\r?\n/', $output) as $line) {
                if (preg_match('#^\s*(\d+\.\d+\.\d+\.\d+)\s+([0-9a-fA-F-]{17})\s+\w+#', $line, $m)) {
                    $table[$m[1]] = strtoupper(str_replace('-', ':', $m[2]));
                }
            }
        }

        return $table;
    }
}
