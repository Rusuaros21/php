<?php

declare(strict_types=1);

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
$ports = $full ? range(1, 1024) : $config['ports'];
$timeout = $full ? $config['port_scan_timeout'] * 3 : $config['port_scan_timeout'];

$scanner = new PortScanner($ports, $timeout);
$open = $scanner->scan($ip, $ports);

echo json_encode([
    'ip' => $ip,
    'ports' => $open,
    'risks' => PortRiskAdvisor::assess($open),
    'scanned_at' => date(DATE_ATOM),
], JSON_UNESCAPED_UNICODE);
