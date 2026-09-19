# Monitor de Rede em Tempo Real

Dashboard em PHP puro que varre a rede local e mostra, ao vivo, os
dispositivos conectados: nome (hostname), IP, endereço MAC e portas TCP
abertas.

## Como funciona

- **Descoberta de hosts** (`src/NetworkScanner.php`): usa `nmap -sn` quando
  disponível (mais rápido, traz fabricante via OUI do MAC) e cai para um
  fallback 100% PHP — varredura de ping em paralelo + leitura da tabela ARP
  (`ip neigh` / `arp -n`) — quando o `nmap` não está instalado.
- **Varredura de portas** (`src/PortScanner.php`): usa `nmap -p` quando
  disponível, ou sockets não bloqueantes em paralelo (`stream_socket_client`
  + `stream_select`) como fallback, testando todas as portas de um host
  simultaneamente.
- **Tempo real**: `public/api/stream.php` mantém uma conexão
  [Server-Sent Events](https://developer.mozilla.org/pt-BR/docs/Web/API/Server-sent_events)
  aberta com o navegador e reenvia a lista de dispositivos a cada
  `scan_interval` segundos (padrão: 20s), sem precisar recarregar a página.

## Requisitos

- PHP 8.1+ com as extensões padrão (`simplexml`, `sockets`/`streams`).
- Recomendado: [`nmap`](https://nmap.org/) instalado no servidor (dá
  resultados mais rápidos e confiáveis, incluindo fabricante do dispositivo).
  Sem `nmap`, o sistema usa `ping`, `ip neigh`/`arp` — certifique-se de que
  esses utilitários existem no PATH.
- Para descoberta de MAC via ARP funcionar bem (com ou sem `nmap`), o
  processo PHP geralmente precisa rodar como usuário com permissão de rede
  local (em muitas distros, `ping`/varredura ARP só funcionam corretamente
  como root ou com as *capabilities* `cap_net_raw` configuradas).

## Como rodar

```bash
php -S 0.0.0.0:8080 -t public
```

Depois acesse `http://localhost:8080` no navegador. Por padrão, a sub-rede é
detectada automaticamente a partir da interface de rede local; se a detecção
falhar, informe manualmente no campo "Sub-rede" (ex.: `192.168.1.0/24`) e
clique em "Aplicar".

## Autenticação (recomendado antes de expor em qualquer rede)

Por padrão o dashboard fica **sem autenticação**. Para exigir usuário e senha
(HTTP Basic Auth) em todas as páginas e endpoints, defina antes de subir o
servidor:

```bash
export SCANNER_AUTH_ENABLED=1
export SCANNER_AUTH_USER=admin
export SCANNER_AUTH_PASS="uma-senha-forte"
php -S 0.0.0.0:8080 -t public
```

Sem HTTPS na frente (ex.: atrás de um reverse proxy), as credenciais Basic
Auth trafegam apenas ofuscadas em base64 — trate como texto plano na rede.

## Histórico e alerta de dispositivo novo

Cada varredura é registrada em um banco SQLite local
(`storage/devices.sqlite`, criado automaticamente e ignorado pelo git). Isso
permite diferenciar um dispositivo que está aparecendo pela primeira vez na
rede (marcado com o selo "NOVO" no dashboard) de um que só voltou a ficar
online. Para desativar esse histórico, defina `storage.enabled = false` em
`config/config.php`.

Quando um dispositivo novo é detectado, dois canais de alerta opcionais
podem ser configurados via variáveis de ambiente (ambos inativos por
padrão):

```bash
# Qualquer endpoint que aceite POST com corpo JSON (Slack, Discord, um bot
# do Telegram por trás de uma ponte HTTP, n8n, Make, etc.)
export SCANNER_ALERT_WEBHOOK_URL="https://exemplo.com/webhook"

# Requer um MTA/sendmail configurado no servidor (usa a função mail() do PHP)
export SCANNER_ALERT_EMAIL_TO="voce@exemplo.com"
```

## Identificação de fabricante (MAC OUI)

Quando o `nmap` está disponível, o fabricante vem do próprio banco de dados
dele (bem completo). Sem `nmap`, o sistema usa uma tabela pequena e
selecionada de prefixos OUI conhecidos (`src/Support/oui-table.php`) —
cobre casos comuns como Raspberry Pi, VMware/VirtualBox, dispositivos IoT
baseados em Espressif (ESP32/ESP8266) e alguns prefixos da Apple. **Não é o
banco de dados oficial da IEEE** e não cobre a maioria dos fabricantes; para
identificação completa, instale o `nmap`.

## Endpoints da API

- `GET /api/stream.php?subnet=192.168.1.0/24&ports=1&interval=20` — fluxo
  SSE contínuo com a lista de dispositivos.
- `GET /api/scan.php?subnet=192.168.1.0/24&ports=1` — uma varredura única em
  JSON (útil para scripts ou integrações).
- `GET /api/ports.php?ip=192.168.1.10&full=1` — varredura de portas sob
  demanda para um único dispositivo (`full=1` varre as portas 1–1024; sem
  esse parâmetro, usa a lista de portas comuns do `config/config.php`).

## Configuração

Ajuste `config/config.php` para mudar a sub-rede padrão, o intervalo de
varredura em tempo real, a lista de portas verificadas automaticamente,
timeouts de ping/porta e o limite máximo de hosts por varredura (proteção
contra varreduras acidentalmente enormes).

## Observações importantes

- Cada aba de navegador aberta no dashboard mantém sua própria varredura
  contínua no servidor (uma conexão SSE = um loop de varredura). A
  ferramenta foi pensada para uso pessoal ou de uma pequena equipe em uma
  rede confiável — não para múltiplos usuários simultâneos em produção.
- Só escaneie redes que você tem autorização para varrer.
- A varredura de portas por padrão cobre um conjunto de portas comuns; use o
  botão "Escanear portas" na tabela para uma varredura mais completa (portas
  1–1024) de um dispositivo específico, sob demanda.
