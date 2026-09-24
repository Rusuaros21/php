<?php

declare(strict_types=1);

use App\PortScanner;
use App\Security\PortRiskAdvisor;
use App\Support\PublicIp;

$config = require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($config['public_ip']['enabled'])) {
    http_response_code(403);
    echo json_encode([
        'error' => 'A verificação de IP público está desativada nesta instância (SCANNER_PUBLIC_IP_ENABLED=0).',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$ip = PublicIp::detect();

if ($ip === null) {
    echo json_encode([
        'ip' => null,
        'error' => 'Não foi possível detectar um IP público. Verifique se este servidor tem acesso à internet.',
        'checked_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_UNICODE);
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
    'checked_at' => date(DATE_ATOM),
    'note' => 'Este teste foi feito de dentro da sua própria rede. Muitos roteadores não permitem alcançar o IP público a partir da rede interna (NAT hairpin) — a ausência de portas abertas aqui não garante que a rede esteja protegida vista de fora. Para um resultado mais confiável, repita a verificação a partir de outra rede (ex.: dados móveis).',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
