<?php

declare(strict_types=1);

use App\Alerting\AlertDispatcher;
use App\NetworkScanner;
use App\PortScanner;
use App\Security\PortRiskAdvisor;
use App\Storage\DeviceStore;
use App\Support\Cidr;
use App\Support\Environment;

$config = require __DIR__ . '/../bootstrap.php';

set_time_limit(0);
ignore_user_abort(true);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

while (ob_get_level() > 0) {
    ob_end_flush();
}

$subnet = trim((string) ($_GET['subnet'] ?? '')) ?: ($config['subnet'] ?? Cidr::detectLocalSubnet());

if (!$subnet || !preg_match('#^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}(/\d{1,2})?$#', $subnet)) {
    echo "event: error\n";
    echo 'data: ' . json_encode(['message' => 'Sub-rede inválida ou não detectada. Informe ?subnet=192.168.1.0/24'], JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
    exit;
}

$scanPorts = filter_var(
    $_GET['ports'] ?? ($config['port_scan_enabled_by_default'] ? '1' : '0'),
    FILTER_VALIDATE_BOOLEAN
);
$interval = max(5, (int) ($_GET['interval'] ?? $config['scan_interval']));

$scanner = new NetworkScanner($config['ping_timeout'], $config['ping_batch_size'], $config['max_hosts']);
$portScanner = $scanPorts ? new PortScanner($config['ports'], $config['port_scan_timeout']) : null;
$store = !empty($config['storage']['enabled']) ? new DeviceStore($config['storage']['sqlite_path']) : null;
$alertDispatcher = new AlertDispatcher($config);

echo "retry: 3000\n\n";
flush();

while (!connection_aborted()) {
    $devices = $scanner->discoverHosts($subnet);

    if ($portScanner !== null) {
        foreach ($devices as &$device) {
            $device['ports'] = $portScanner->scan($device['ip']);
            $device['risks'] = PortRiskAdvisor::assess($device['ports']);
        }
        unset($device);
    }

    if ($store !== null) {
        $newIdentities = $store->recordScan($devices);

        $newDevices = [];
        foreach ($devices as &$device) {
            $identity = $device['mac'] ?: $device['ip'];
            $device['is_new'] = isset($newIdentities[$identity]);
            if ($device['is_new']) {
                $newDevices[] = $device;
            }
        }
        unset($device);

        if ($newDevices) {
            $alertDispatcher->notifyNewDevices($newDevices);
        }
    }

    $payload = [
        'subnet' => $subnet,
        'scanned_at' => date(DATE_ATOM),
        'engine' => $scanner->hasNmap() ? 'nmap' : 'php',
        'devices' => $devices,
        'warnings' => Environment::detect()['warnings'],
    ];

    echo "event: devices\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    flush();

    if (connection_aborted()) {
        break;
    }

    sleep($interval);
}
