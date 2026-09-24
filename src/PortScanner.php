<?php

declare(strict_types=1);

namespace App;

use App\Support\Environment;

/**
 * Scans a host's TCP ports. Uses `nmap` when available, otherwise falls
 * back to concurrent non-blocking socket connects (all ports are probed
 * in parallel so the scan takes roughly one timeout window, not
 * one-timeout-per-port).
 */
final class PortScanner
{
    private ?bool $nmapAvailable = null;

    public function __construct(
        private readonly array $defaultPorts,
        private readonly float $timeout = 0.6
    ) {
    }

    public function scan(string $ip, ?array $ports = null, bool $detectVersions = false): array
    {
        $ports = $ports ?: $this->defaultPorts;

        return $this->hasNmap()
            ? $this->scanWithNmap($ip, $ports, $detectVersions)
            : $this->scanWithSockets($ip, $ports, $detectVersions);
    }

    public function hasNmap(): bool
    {
        if ($this->nmapAvailable === null) {
            $this->nmapAvailable = Environment::commandExists('nmap');
        }

        return $this->nmapAvailable;
    }

    private function scanWithNmap(string $ip, array $ports, bool $detectVersions = false): array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [];
        }

        $portList = implode(',', array_map('intval', $ports));
        $cmd = sprintf(
            'nmap -Pn -T4 %s-p %s --open -oX - %s 2>/dev/null',
            $detectVersions ? '-sV ' : '',
            escapeshellarg($portList),
            escapeshellarg($ip)
        );
        $xml = @shell_exec($cmd);
        if (!$xml) {
            return $this->scanWithSockets($ip, $ports, $detectVersions);
        }

        $sx = @simplexml_load_string($xml);
        if ($sx === false || !isset($sx->host->ports->port)) {
            return [];
        }

        $open = [];
        foreach ($sx->host->ports->port as $port) {
            if ((string) $port->state['state'] === 'open') {
                $product = (string) ($port->service['product'] ?? '');
                $version = (string) ($port->service['version'] ?? '');
                $open[] = [
                    'port' => (int) $port['portid'],
                    'service' => (string) ($port->service['name'] ?? ''),
                    'product' => $product !== '' ? $product : null,
                    'version' => $version !== '' ? $version : null,
                ];
            }
        }

        return $open;
    }

    private function scanWithSockets(string $ip, array $ports, bool $detectVersions = false): array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [];
        }

        $sockets = [];
        foreach ($ports as $port) {
            $port = (int) $port;
            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client(
                "tcp://{$ip}:{$port}",
                $errno,
                $errstr,
                0,
                STREAM_CLIENT_ASYNC_CONNECT
            );
            if ($socket !== false) {
                $sockets[$port] = $socket;
            }
        }

        $open = [];
        $deadline = microtime(true) + $this->timeout;

        while ($sockets && microtime(true) < $deadline) {
            $write = array_values($sockets);
            $read = [];
            $except = [];
            $changed = @stream_select($read, $write, $except, 0, 100000);
            if ($changed === false) {
                break;
            }

            foreach ($write as $socket) {
                $port = array_search($socket, $sockets, true);
                if ($port === false) {
                    continue;
                }
                if (@stream_socket_get_name($socket, true) !== false) {
                    $open[] = ['port' => $port, 'service' => $this->guessService($port), 'product' => null, 'version' => null];
                }
                fclose($socket);
                unset($sockets[$port]);
            }
        }

        foreach ($sockets as $socket) {
            fclose($socket);
        }

        usort($open, fn ($a, $b) => $a['port'] <=> $b['port']);

        if ($detectVersions) {
            foreach ($open as &$entry) {
                $entry['product'] = $this->grabBanner($ip, $entry['port']);
            }
            unset($entry);
        }

        return $open;
    }

    /**
     * Best-effort version hint without nmap: connect and read whatever the
     * service sends unprompted (SSH/FTP/SMTP/etc. banners), or send a
     * minimal HTTP request and read the `Server:` header for web ports.
     */
    private function grabBanner(string $ip, int $port): ?string
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client("tcp://{$ip}:{$port}", $errno, $errstr, 1.5);
        if ($socket === false) {
            return null;
        }
        stream_set_timeout($socket, 1);

        if (in_array($port, [80, 8080, 8000, 8888], true)) {
            @fwrite($socket, "HEAD / HTTP/1.0\r\nHost: {$ip}\r\nConnection: close\r\n\r\n");
        }

        $data = @fread($socket, 512);
        fclose($socket);

        if (!$data) {
            return null;
        }

        if (preg_match('/^Server:\s*(.+)$/mi', $data, $m)) {
            return trim($m[1]);
        }

        $firstLine = trim(strtok($data, "\r\n"));

        return $firstLine !== '' ? $firstLine : null;
    }

    private function guessService(int $port): string
    {
        static $known = [
            21 => 'ftp', 22 => 'ssh', 23 => 'telnet', 25 => 'smtp', 53 => 'dns',
            80 => 'http', 110 => 'pop3', 111 => 'rpcbind', 135 => 'msrpc',
            139 => 'netbios-ssn', 143 => 'imap', 443 => 'https', 445 => 'microsoft-ds',
            993 => 'imaps', 995 => 'pop3s', 1723 => 'pptp', 3306 => 'mysql',
            3389 => 'rdp', 5900 => 'vnc', 8080 => 'http-proxy', 8443 => 'https-alt',
        ];

        return $known[$port] ?? '';
    }
}
