<?php

declare(strict_types=1);

namespace App\Discovery;

/**
 * Passive discovery of UPnP-capable devices (printers, smart TVs, media
 * servers, cameras, routers) via SSDP (Simple Service Discovery Protocol):
 * broadcasts an M-SEARCH request to the standard multicast address and
 * collects replies, then fetches each device's XML description for its
 * human-readable name, manufacturer and model.
 *
 * No credentials or special privileges needed — pure UDP. Requires the
 * `sockets` PHP extension.
 */
final class SsdpDiscovery
{
    public static function isAvailable(): bool
    {
        return function_exists('socket_create');
    }

    /**
     * @return array<int, array{ip: string, server: ?string, location: ?string}>
     */
    public static function discover(float $timeoutSeconds = 3.0): array
    {
        if (!self::isAvailable()) {
            return [];
        }

        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            return [];
        }

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => (int) $timeoutSeconds, 'usec' => 0]);

        $message = "M-SEARCH * HTTP/1.1\r\n"
            . "HOST: 239.255.255.250:1900\r\n"
            . "MAN: \"ssdp:discover\"\r\n"
            . "MX: 2\r\n"
            . "ST: ssdp:all\r\n\r\n";

        @socket_sendto($socket, $message, strlen($message), 0, '239.255.255.250', 1900);

        $results = [];
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $buffer = '';
            $from = '';
            $port = 0;
            $bytes = @socket_recvfrom($socket, $buffer, 8192, 0, $from, $port);
            if ($bytes === false || $bytes === 0) {
                break;
            }

            $headers = self::parseHeaders($buffer);
            if (!isset($results[$from])) {
                $results[$from] = [
                    'ip' => $from,
                    'server' => $headers['server'] ?? null,
                    'location' => $headers['location'] ?? null,
                ];
            }
        }

        socket_close($socket);

        return array_values($results);
    }

    /**
     * Fetches and parses a device's UPnP description XML (the URL found in
     * an SSDP reply's LOCATION header).
     *
     * @return array{friendly_name: string, manufacturer: string, model_name: string, model_number: string}|null
     */
    public static function fetchDeviceInfo(string $locationUrl): ?array
    {
        if (!preg_match('#^https?://#i', $locationUrl)) {
            return null;
        }

        $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
        $xml = @file_get_contents($locationUrl, false, $context);
        if (!$xml) {
            return null;
        }

        $sx = @simplexml_load_string($xml);
        if ($sx === false || !isset($sx->device)) {
            return null;
        }

        $device = $sx->device;

        return [
            'friendly_name' => (string) ($device->friendlyName ?? ''),
            'manufacturer' => (string) ($device->manufacturer ?? ''),
            'model_name' => (string) ($device->modelName ?? ''),
            'model_number' => (string) ($device->modelNumber ?? ''),
        ];
    }

    private static function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (explode("\r\n", $raw) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }

        return $headers;
    }
}
