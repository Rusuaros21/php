<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Introspects the host this scanner is running on — OS family, which
 * scanning tools are installed, and which local subnets are reachable —
 * so the scan can adapt itself instead of assuming a fixed environment.
 */
final class Environment
{
    private static ?array $cache = null;

    public static function detect(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $isWindows = PHP_OS_FAMILY === 'Windows';

        $hasNmap = self::commandExists('nmap');
        $hasPing = self::commandExists('ping');
        $hasIp = self::commandExists('ip');
        $hasArp = self::commandExists('arp');
        $hasIfconfig = self::commandExists('ifconfig');
        $hasIpconfig = $isWindows && self::commandExists('ipconfig');

        $warnings = [];
        if (!$hasNmap && !$hasPing) {
            $warnings[] = 'Nem "nmap" nem "ping" foram encontrados neste servidor — a descoberta de dispositivos não vai funcionar. Instale um dos dois (recomendado: nmap).';
        }
        if (!$hasNmap && !$hasArp) {
            $warnings[] = 'O "arp" não foi encontrado — endereços MAC não poderão ser lidos sem o nmap.';
        }

        $subnets = Cidr::detectLocalSubnets();
        if (!$subnets) {
            $warnings[] = 'Não foi possível detectar automaticamente nenhuma sub-rede local. Informe manualmente (ex.: 192.168.1.0/24).';
        }

        self::$cache = [
            'os' => PHP_OS_FAMILY,
            'engine' => $hasNmap ? 'nmap' : 'php',
            'tools' => [
                'nmap' => $hasNmap,
                'ping' => $hasPing,
                'ip' => $hasIp,
                'arp' => $hasArp,
                'ifconfig' => $hasIfconfig,
                'ipconfig' => $hasIpconfig,
            ],
            'subnets' => $subnets,
            'recommended_subnet' => $subnets[0]['cidr'] ?? null,
            'warnings' => $warnings,
        ];

        return self::$cache;
    }

    public static function commandExists(string $bin): bool
    {
        $checker = PHP_OS_FAMILY === 'Windows'
            ? 'where ' . escapeshellarg($bin) . ' 2>NUL'
            : 'command -v ' . escapeshellarg($bin) . ' 2>/dev/null';

        $path = trim((string) @shell_exec($checker));

        return $path !== '';
    }
}
