<?php

declare(strict_types=1);

use App\Alerting\AlertDispatcher;
use App\Diagnostics\NetworkAnomalyDetector;
use App\NetworkScanner;
use App\PortScanner;
use App\Security\PortRiskAdvisor;
use App\Storage\DeviceStore;
use App\Support\Cidr;
use App\Support\Environment;

$config = require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$subnet = trim((string) ($_GET['subnet'] ?? '')) ?: ($config['subnet'] ?? Cidr::detectLocalSubnet());

if (!$subnet) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Não foi possível detectar a sub-rede local automaticamente. Informe ?subnet=192.168.1.0/24',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!preg_match('#^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}(/\d{1,2})?$#', $subnet)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Formato de sub-rede inválido. Use algo como 192.168.1.0/24',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$scanPorts = filter_var(
    $_GET['ports'] ?? ($config['port_scan_enabled_by_default'] ? '1' : '0'),
    FILTER_VALIDATE_BOOLEAN
);

$scanner = new NetworkScanner($config['ping_timeout'], $config['ping_batch_size'], $config['max_hosts']);
$devices = $scanner->discoverHosts($subnet);

if ($scanPorts) {
    $portScanner = new PortScanner($config['ports'], $config['port_scan_timeout']);
    foreach ($devices as &$device) {
        $device['ports'] = $portScanner->scan($device['ip']);
        $device['risks'] = PortRiskAdvisor::assess($device['ports']);
    }
    unset($device);
}

$networkDiagnostics = NetworkAnomalyDetector::duplicateMacs($devices);
$networkDiagnostics = array_merge(
    $networkDiagnostics,
    NetworkAnomalyDetector::detectLoops($scanner, $devices, $subnet)
);

if (!empty($config['storage']['enabled'])) {
    $store = new DeviceStore($config['storage']['sqlite_path']);
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
        (new AlertDispatcher($config))->notifyNewDevices($newDevices);
    }
}

echo json_encode([
    'subnet' => $subnet,
    'scanned_at' => date(DATE_ATOM),
    'engine' => $scanner->hasNmap() ? 'nmap' : 'php',
    'devices' => $devices,
    'network_diagnostics' => $networkDiagnostics,
    'warnings' => Environment::detect()['warnings'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
