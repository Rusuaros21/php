<?php

declare(strict_types=1);

use App\Discovery\SsdpDiscovery;

$config = require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!SsdpDiscovery::isAvailable()) {
    http_response_code(500);
    echo json_encode([
        'error' => 'A extensão "sockets" do PHP não está disponível — descoberta UPnP requer ela.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$replies = SsdpDiscovery::discover(3.0);

$devices = [];
foreach ($replies as $reply) {
    $info = $reply['location'] ? SsdpDiscovery::fetchDeviceInfo($reply['location']) : null;
    $devices[] = [
        'ip' => $reply['ip'],
        'server' => $reply['server'],
        'friendly_name' => $info['friendly_name'] ?? null,
        'manufacturer' => $info['manufacturer'] ?? null,
        'model_name' => $info['model_name'] ?? null,
        'model_number' => $info['model_number'] ?? null,
    ];
}

echo json_encode([
    'devices' => $devices,
    'checked_at' => date(DATE_ATOM),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
