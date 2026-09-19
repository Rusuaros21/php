<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Detects the network's public (WAN-facing) IPv4 address, so it can be
 * checked for exposed ports separately from the internal LAN scan.
 *
 * Most home/office networks sit behind NAT, so the server's own local
 * interface IP is private — the only reliable way to learn the public IP
 * without router/UPnP integration is to ask an external "what is my IP"
 * echo service. If this host's own interface already has a public IPv4
 * (e.g. a cloud VM with a directly-attached public address), that is used
 * instead and no external call is made.
 */
final class PublicIp
{
    private const ECHO_SERVICES = [
        'https://api.ipify.org',
        'https://ifconfig.me/ip',
        'https://icanhazip.com',
    ];

    public static function detect(): ?string
    {
        foreach (Cidr::detectLocalSubnets() as $subnet) {
            if (self::isPublic($subnet['ip'])) {
                return $subnet['ip'];
            }
        }

        foreach (self::ECHO_SERVICES as $url) {
            $ip = self::fetchIp($url);
            if ($ip !== null) {
                return $ip;
            }
        }

        return null;
    }

    public static function isPublic(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private static function fetchIp(string $url): ?string
    {
        $body = function_exists('curl_init') ? self::fetchViaCurl($url) : self::fetchViaStream($url);
        if ($body === null) {
            return null;
        }

        $ip = trim($body);

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : null;
    }

    /**
     * Preferred path: curl transparently honours HTTP_PROXY/HTTPS_PROXY env
     * vars and correctly tunnels HTTPS through a forward proxy via CONNECT,
     * which PHP's plain stream wrapper does not reliably do.
     */
    private static function fetchViaCurl(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT => 'network-scanner-dashboard',
        ]);
        $body = curl_exec($ch);
        $statusOk = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);

        return ($body !== false && $statusOk) ? $body : null;
    }

    /**
     * Fallback when ext-curl isn't installed. Works for direct (no forward
     * proxy) outbound access.
     */
    private static function fetchViaStream(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 3,
                'ignore_errors' => true,
                'header' => "User-Agent: network-scanner-dashboard\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        return $body === false ? null : $body;
    }
}
