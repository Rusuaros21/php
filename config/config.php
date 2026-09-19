<?php

declare(strict_types=1);

return [
    // Sub-rede padrão em notação CIDR (ex.: "192.168.1.0/24"). Deixe null para
    // detectar automaticamente a partir da interface de rede local.
    'subnet' => null,

    // Intervalo, em segundos, entre varreduras automáticas no fluxo em tempo real.
    'scan_interval' => 20,

    // Tempo de espera (segundos) por resposta de ping no fallback sem nmap.
    'ping_timeout' => 1,

    // Quantos hosts são "pingados" em paralelo por vez no fallback sem nmap.
    'ping_batch_size' => 64,

    // Se a varredura de portas deve rodar por padrão junto da descoberta de hosts.
    'port_scan_enabled_by_default' => true,

    // Tempo (segundos) para considerar uma porta fechada/filtrada no fallback sem nmap.
    'port_scan_timeout' => 0.6,

    // Portas comuns verificadas automaticamente para cada dispositivo encontrado.
    'ports' => [21, 22, 23, 25, 53, 80, 110, 111, 135, 139, 143, 443, 445, 993, 995, 1723, 3306, 3389, 5900, 8080, 8443],

    // Limite de segurança de hosts varridos por ciclo (evita varreduras gigantes acidentais).
    'max_hosts' => 512,
];
