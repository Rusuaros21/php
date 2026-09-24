<?php

declare(strict_types=1);

namespace App\Diagnostics;

use App\NetworkScanner;
use App\Support\Cidr;

/**
 * Heuristic health checks for the network itself (as opposed to
 * PortRiskAdvisor, which looks at individual hosts). Flags conditions that
 * usually indicate a real network problem: duplicate MAC addresses, and
 * switching loops (detected via duplicate ICMP echo replies — a
 * textbook sign of a bridge loop / broadcast storm).
 */
final class NetworkAnomalyDetector
{
    /**
     * @param array<int, array{ip: string, mac: ?string}> $devices
     * @return array<int, array{type: string, severity: string, title: string, description: string}>
     */
    public static function duplicateMacs(array $devices): array
    {
        $byMac = [];
        foreach ($devices as $device) {
            if (empty($device['mac'])) {
                continue;
            }
            $byMac[$device['mac']][] = $device['ip'];
        }

        $findings = [];
        foreach ($byMac as $mac => $ips) {
            $ips = array_values(array_unique($ips));
            if (count($ips) < 2) {
                continue;
            }

            $findings[] = [
                'type' => 'duplicate_mac',
                'severity' => 'medium',
                'title' => 'Endereço MAC duplicado',
                'description' => sprintf(
                    'O MAC %s apareceu associado a mais de um IP nesta varredura (%s). Pode ser um dispositivo com múltiplas interfaces (normal em roteadores/gateways), uma VM clonada do mesmo template, ou spoofing de MAC.',
                    $mac,
                    implode(', ', $ips)
                ),
            ];
        }

        return $findings;
    }

    /**
     * A single ICMP echo request receiving more than one reply is a
     * well-known sign of a Layer 2 loop (the packet reaches the target
     * through more than one redundant path).
     */
    public static function pingHasDuplicateReplies(string $output): bool
    {
        if ($output === '') {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return substr_count($output, 'Reply from') > 1;
        }

        if (str_contains($output, 'DUP!')) {
            return true;
        }

        // macOS/BSD ping prints a "+N duplicates" summary line instead.
        if (preg_match('/\+(\d+)\s+duplicates?/', $output, $m)) {
            return (int) $m[1] > 0;
        }

        return false;
    }

    /**
     * Checks for network-loop signs. If the scanner already pinged every
     * host directly (pure-PHP fallback engine), reuses that captured
     * output for free. Otherwise (e.g. nmap did discovery) runs a small,
     * bounded supplementary probe instead of re-pinging the whole subnet:
     * the subnet's first host (commonly the gateway) plus a handful of
     * already-discovered devices.
     *
     * @param array<int, array{ip: string}> $devices
     */
    public static function detectLoops(NetworkScanner $scanner, array $devices, string $subnet): array
    {
        $findings = [];
        $pingOutputs = $scanner->getLastPingOutputs();

        if ($pingOutputs) {
            foreach ($pingOutputs as $ip => $output) {
                if (self::pingHasDuplicateReplies($output)) {
                    $findings[] = self::loopFinding($ip);
                }
            }

            return $findings;
        }

        $sampleIps = Cidr::listHosts($subnet, 1);
        foreach (array_slice(array_column($devices, 'ip'), 0, 3) as $ip) {
            $sampleIps[] = $ip;
        }

        foreach (array_unique($sampleIps) as $ip) {
            $result = $scanner->probePing($ip);
            if ($result['alive'] && self::pingHasDuplicateReplies($result['output'])) {
                $findings[] = self::loopFinding($ip);
            }
        }

        return $findings;
    }

    public static function loopFinding(string $ip): array
    {
        return [
            'type' => 'network_loop',
            'severity' => 'high',
            'title' => 'Possível loop de rede',
            'description' => sprintf(
                'Respostas de ping duplicadas para %s — indício clássico de loop de rede (um cabo criando um caminho redundante entre switches, geralmente com o Spanning Tree Protocol desligado ou mal configurado). Isso pode causar broadcast storms e lentidão em toda a rede.',
                $ip
            ),
        ];
    }

    public static function flappingFinding(string $label, int $flapCount): array
    {
        return [
            'type' => 'flapping_device',
            'severity' => 'low',
            'title' => 'Dispositivo instável',
            'description' => sprintf(
                '%s mudou de status (online/offline) %d vezes durante este monitoramento — pode indicar sinal Wi-Fi fraco, cabo com mau contato, ou economia de energia agressiva do dispositivo.',
                $label,
                $flapCount
            ),
        ];
    }
}
