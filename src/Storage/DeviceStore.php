<?php

declare(strict_types=1);

namespace App\Storage;

use PDO;

/**
 * Persists a lightweight history of seen devices in SQLite, so the
 * dashboard can tell a brand-new device apart from one that's simply
 * back online, across restarts and separate browser sessions.
 */
final class DeviceStore
{
    private PDO $pdo;

    public function __construct(string $sqlitePath)
    {
        $dir = dirname($sqlitePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $this->pdo = new PDO('sqlite:' . $sqlitePath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS devices (
                identity TEXT PRIMARY KEY,
                ip TEXT NOT NULL,
                mac TEXT,
                vendor TEXT,
                hostname TEXT,
                first_seen TEXT NOT NULL,
                last_seen TEXT NOT NULL
            )
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                identity TEXT NOT NULL,
                ip TEXT,
                mac TEXT,
                hostname TEXT,
                created_at TEXT NOT NULL
            )
        ');
    }

    /**
     * Upserts the current scan snapshot and returns the devices that are
     * being seen for the very first time (used to trigger alerts and to
     * flag them in the UI).
     */
    public function recordScan(array $devices): array
    {
        $now = date(DATE_ATOM);
        $newDevices = [];

        $select = $this->pdo->prepare('SELECT 1 FROM devices WHERE identity = :identity');
        $insert = $this->pdo->prepare('
            INSERT INTO devices (identity, ip, mac, vendor, hostname, first_seen, last_seen)
            VALUES (:identity, :ip, :mac, :vendor, :hostname, :now, :now)
        ');
        $update = $this->pdo->prepare('
            UPDATE devices
            SET ip = :ip, mac = :mac, vendor = :vendor, hostname = :hostname, last_seen = :now
            WHERE identity = :identity
        ');
        $logEvent = $this->pdo->prepare('
            INSERT INTO events (type, identity, ip, mac, hostname, created_at)
            VALUES (:type, :identity, :ip, :mac, :hostname, :now)
        ');

        foreach ($devices as $device) {
            $identity = $device['mac'] ?: $device['ip'];

            $select->execute(['identity' => $identity]);
            $exists = (bool) $select->fetchColumn();

            $params = [
                'identity' => $identity,
                'ip' => $device['ip'],
                'mac' => $device['mac'],
                'vendor' => $device['vendor'],
                'hostname' => $device['hostname'],
                'now' => $now,
            ];

            if ($exists) {
                $update->execute($params);
            } else {
                $insert->execute($params);
                $logEvent->execute([
                    'type' => 'new_device',
                    'identity' => $identity,
                    'ip' => $device['ip'],
                    'mac' => $device['mac'],
                    'hostname' => $device['hostname'],
                    'now' => $now,
                ]);
                $newDevices[$identity] = true;
            }
        }

        return $newDevices;
    }
}
