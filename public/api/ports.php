<?php

declare(strict_types=1);

use App\Discovery\SnmpDiscovery;
use App\OsFingerprinter;
use App\PortScanner;
use App\Security\PortRiskAdvisor;

$config = require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ip = (string) ($_GET['ip'] ?? '');

if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    http_response_code(400);
    echo json_encode(['error' => 'IP inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$full = filter_var($_GET['full'] ?? '0', FILTER_VALIDATE_BOOLEAN);
$fingerprint = filter_var($_GET['fingerprint'] ?? '0', FILTER_VALIDATE_BOOLEAN);
$ports = $full ? range(1, 1024) : $config['ports'];
$timeout = $full ? $config['port_scan_timeout'] * 3 : $config['port_scan_timeout'];
if ($fingerprint) {
    // Version detection (-sV) / banner grabbing takes longer per port than a plain connect scan.
    $timeout *= 2;
}

$scanner = new PortScanner($ports, $timeout);
$open = $scanner->scan($ip, $ports, $fingerprint);

$os = $fingerprint ? (new OsFingerprinter())->detect($ip) : [];

$snmp = null;
if ($fingerprint) {
    $snmpDiscovery = new SnmpDiscovery($config['snmp']['community']);
    if ($snmpDiscovery->isAvailable()) {
        $snmp = $snmpDiscovery->query($ip);
    }
}

echo json_encode([
    'ip' => $ip,
    'ports' => $open,
    'risks' => PortRiskAdvisor::assess($open),
    'os' => $os,
    'snmp' => $snmp,
    'scanned_at' => date(DATE_ATOM),
], JSON_UNESCAPED_UNICODE);
