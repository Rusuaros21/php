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

    // Autenticação HTTP Basic do dashboard. Desativada por padrão; ative
    // definindo as variáveis de ambiente abaixo antes de expor a ferramenta
    // em qualquer rede que não seja totalmente confiável.
    'auth' => [
        'enabled' => filter_var(getenv('SCANNER_AUTH_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN),
        'username' => getenv('SCANNER_AUTH_USER') ?: 'admin',
        'password' => getenv('SCANNER_AUTH_PASS') ?: '',
    ],

    // Histórico de dispositivos (SQLite), usado para diferenciar um
    // dispositivo realmente novo de um que só voltou a ficar online.
    'storage' => [
        'enabled' => true,
        'sqlite_path' => __DIR__ . '/../storage/devices.sqlite',
    ],

    // Alertas quando um dispositivo é visto pela primeira vez na rede.
    // Ambos são opcionais e ficam inativos se a respectiva variável não
    // for definida.
    'alerts' => [
        // Aceita qualquer endpoint que receba um POST JSON: Slack, Discord,
        // Telegram (via bot bridge), n8n, Make, etc.
        'webhook_url' => getenv('SCANNER_ALERT_WEBHOOK_URL') ?: null,
        // Requer um MTA/sendmail configurado no servidor (função mail() do PHP).
        'email_to' => getenv('SCANNER_ALERT_EMAIL_TO') ?: null,
    ],

    // Verificação do IP público (aba "IP Público" no dashboard). Precisa de
    // acesso à internet: se a rede local não tiver um IP público próprio,
    // o sistema consulta um serviço externo de eco (ipify.org e similares)
    // para descobri-lo. Desative se preferir não fazer chamadas externas.
    'public_ip' => [
        'enabled' => filter_var(getenv('SCANNER_PUBLIC_IP_ENABLED') ?: '1', FILTER_VALIDATE_BOOLEAN),
    ],

    // Diagnóstico de rede (aba "Diagnóstico"): MAC duplicado, loop de rede
    // (respostas de ping duplicadas) e dispositivos "instáveis". O limite de
    // instabilidade só se aplica ao fluxo em tempo real (precisa de vários
    // ciclos de varredura para detectar oscilação).
    'diagnostics' => [
        'flap_threshold' => 3,
    ],

    // Community string usada para consultar equipamentos de rede via SNMP
    // (roteadores, switches gerenciáveis, impressoras, APs, nobreaks) na
    // varredura profunda ("Escanear portas/SO"). "public" é o padrão de
    // fábrica mais comum para leitura; troque se a rede do cliente usar
    // outra. Não é uma credencial administrativa — é só uma chave fraca de
    // leitura, e a maioria dos computadores comuns (Windows/macOS/Linux)
    // vem com SNMP desativado por padrão, então isso enriquece
    // principalmente equipamento de rede/infraestrutura, não estações de
    // trabalho.
    'snmp' => [
        'community' => getenv('SCANNER_SNMP_COMMUNITY') ?: 'public',
    ],
];
