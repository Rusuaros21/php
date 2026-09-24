<?php

declare(strict_types=1);

namespace App;

use App\Support\Environment;

/**
 * Best-effort OS detection via nmap's TCP/IP stack fingerprinting (`-O`).
 * There is no meaningful pure-PHP fallback for this — it requires crafting
 * and analysing raw packets — so this returns null whenever nmap isn't
 * installed. `-O` also usually needs administrator/root privileges; when
 * run unprivileged, nmap silently skips OS detection rather than failing,
 * so an empty result here can mean either "inconclusive" or "no
 * permission" — both surfaced the same way to the caller.
 */
final class OsFingerprinter
{
    public function isAvailable(): bool
    {
        return Environment::commandExists('nmap');
    }

    /**
     * @return array<int, array{name: string, accuracy: int}>
     */
    public function detect(string $ip): array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !$this->isAvailable()) {
            return [];
        }

        $cmd = sprintf('nmap -O -Pn -oX - %s 2>/dev/null', escapeshellarg($ip));
        $xml = @shell_exec($cmd);
        if (!$xml) {
            return [];
        }

        $sx = @simplexml_load_string($xml);
        if ($sx === false || !isset($sx->host->os->osmatch)) {
            return [];
        }

        $matches = [];
        foreach ($sx->host->os->osmatch as $match) {
            $matches[] = [
                'name' => (string) $match['name'],
                'accuracy' => (int) $match['accuracy'],
            ];
        }

        usort($matches, static fn (array $a, array $b) => $b['accuracy'] <=> $a['accuracy']);

        return array_slice($matches, 0, 3);
    }
}
