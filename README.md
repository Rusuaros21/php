# Monitor de Rede em Tempo Real

Dashboard em PHP puro que varre a rede local e mostra, ao vivo, os
dispositivos conectados: nome (hostname), IP, endereço MAC e portas TCP
abertas. Também verifica seu IP público na aba dedicada.

## Como funciona

- **Reconhecimento de ambiente** (`src/Support/Environment.php` +
  `src/Support/Cidr.php`): a cada carregamento, o sistema detecta o sistema
  operacional, quais ferramentas de rede estão disponíveis (`nmap`, `ping`,
  `ip`, `arp`, `ifconfig`) e todas as sub-redes IPv4 locais alcançáveis —
  tentando, em ordem, `ip` (Linux/iproute2), `ifconfig` (macOS/BSD/Linux
  legado) e, por último, resolução de hostname via PHP puro (sem depender de
  nenhum comando externo). O resultado alimenta o campo "Sub-rede" com
  sugestões e avisa na tela quando alguma ferramenta necessária está
  faltando, em vez de simplesmente retornar uma lista vazia sem explicação.
- **Descoberta de hosts** (`src/NetworkScanner.php`): usa `nmap -sn` quando
  disponível (mais rápido, traz fabricante via OUI do MAC) e cai para um
  fallback 100% PHP — varredura de ping em paralelo + leitura da tabela ARP
  — quando o `nmap` não está instalado. Tanto o comando de ping quanto o
  parsing da tabela ARP se adaptam ao sistema operacional (Linux via
  `ip neigh`/`arp -n`, macOS/BSD via `arp -a`; o timeout do `ping` também é
  ajustado, já que a flag `-W` tem unidades diferentes em cada plataforma).
- **Varredura de portas** (`src/PortScanner.php`): usa `nmap -p` quando
  disponível, ou sockets não bloqueantes em paralelo (`stream_socket_client`
  + `stream_select`) como fallback, testando todas as portas de um host
  simultaneamente.
- **Riscos por dispositivo** (`src/Security/PortRiskAdvisor.php`): cada
  porta aberta é confrontada com uma tabela de riscos conhecidos (ex.:
  Telnet/FTP em texto puro, SMB/RDP/VNC expostos) e o resultado aparece na
  coluna "Riscos" de cada host, separado por severidade (alto/médio/baixo),
  com um botão para expandir os detalhes de cada achado.
- **IP público** (`src/Support/PublicIp.php`): aba separada no dashboard que
  detecta o IP público da rede (usando o IP da própria interface, se já for
  público, ou consultando um serviço externo de eco como ipify.org) e roda a
  mesma varredura de portas/riscos contra ele, sob demanda.
- **Tempo real**: `public/api/stream.php` mantém uma conexão
  [Server-Sent Events](https://developer.mozilla.org/pt-BR/docs/Web/API/Server-sent_events)
  aberta com o navegador e reenvia a lista de dispositivos a cada
  `scan_interval` segundos (padrão: 20s), sem precisar recarregar a página.

## Requisitos

- PHP 8.1+ com as extensões padrão (`simplexml`, `sockets`/`streams`,
  `pdo_sqlite`).
- Recomendado: [`nmap`](https://nmap.org/) instalado no servidor (dá
  resultados mais rápidos e confiáveis, incluindo fabricante do dispositivo).
  Sem `nmap`, o sistema usa os utilitários nativos do SO (`ping` +
  `ip`/`arp`/`ifconfig` no Linux/macOS, ou `ping`/`arp`/`ipconfig` no
  Windows) — certifique-se de que existem no PATH.
- Para descoberta de MAC via ARP funcionar bem (com ou sem `nmap`), o
  processo PHP geralmente precisa rodar como usuário com permissão de rede
  local (em muitas distros Linux, `ping`/varredura ARP só funcionam
  corretamente como root ou com as *capabilities* `cap_net_raw`
  configuradas).
- Se alguma dessas ferramentas estiver faltando, o dashboard mostra um aviso
  explicando o que instalar — veja `GET /api/environment.php`.

### Windows

Linux, macOS e Windows são suportados nativamente (o sistema detecta o SO e
ajusta os comandos de `ping`/ARP automaticamente). Duas formas de rodar no
Windows:

- **PHP nativo para Windows** (mais simples): baixe o PHP em
  https://windows.php.net/download/, instale o [nmap para Windows](https://nmap.org/download.html#windows)
  (recomendado — sem ele, a varredura de hosts usa `ping`/`arp` nativos do
  Windows, que funcionam, mas são mais lentos) e rode
  `php -S 0.0.0.0:8080 -t public` num terminal (cmd ou PowerShell).
- **WSL2**: funciona, mas por padrão o WSL2 usa uma rede virtual própria
  (NAT), então a sub-rede detectada de dentro do WSL não é a mesma da sua
  LAN real. Se for usar WSL2, habilite o *mirrored networking mode* (Windows
  11 recente, via `.wslconfig`) para que ele enxergue a rede física
  diretamente — caso contrário, prefira o PHP nativo para Windows acima.

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

## Riscos por host (heurística, não é scanner de CVEs)

A coluna "Riscos" de cada dispositivo mostra achados baseados unicamente nas
portas TCP abertas e nas convenções de serviço mais conhecidas (ex.: SMB,
RDP, VNC, Telnet, FTP sem criptografia). **Isto não é uma varredura de
vulnerabilidades real** (não detecta versão de software nem CVEs
específicas, ao contrário de ferramentas como Nessus/OpenVAS ou
`nmap --script vuln`) — é um alerta heurístico de "esse tipo de serviço
exposto costuma ser arriscado". Ajuste as regras em
`src/Security/PortRiskAdvisor.php` conforme a realidade da sua rede.

## IP público

A aba "IP Público" no dashboard verifica, sob demanda, se há portas abertas
no seu endereço público. **Importante:** essa verificação é feita de dentro
da sua própria rede, então está sujeita a limitações de NAT hairpin — muitos
roteadores não deixam a rede interna alcançar o próprio IP público, o que
pode mascarar portas que na prática estão expostas para a internet. O
dashboard exibe esse aviso, mas para um diagnóstico confiável do perímetro,
rode a verificação a partir de outra rede (ex.: dados móveis) ou use um
scanner externo dedicado.

Requer que o servidor tenha acesso à internet (chamada de saída para um
serviço de eco de IP como ipify.org, ifconfig.me ou icanhazip.com, com
fallback entre eles). Se o `ext-curl` estiver instalado, proxies
configurados via `HTTPS_PROXY`/`http_proxy` são respeitados automaticamente.
Desative essa aba com:

```bash
export SCANNER_PUBLIC_IP_ENABLED=0
```

## Endpoints da API

- `GET /api/environment.php` — SO detectado, ferramentas disponíveis
  (`nmap`/`ping`/`ip`/`arp`/`ifconfig`), sub-redes locais encontradas e
  avisos sobre o que falta instalar.
- `GET /api/public-ip.php?full=1` — detecta o IP público e varre portas
  (e riscos) nele, sob demanda (`full=1` varre as portas 1–1024).
- `GET /api/stream.php?subnet=192.168.1.0/24&ports=1&interval=20` — fluxo
  SSE contínuo com a lista de dispositivos (inclui `risks` por dispositivo
  quando `ports=1`).
- `GET /api/scan.php?subnet=192.168.1.0/24&ports=1` — uma varredura única em
  JSON (útil para scripts ou integrações).
- `GET /api/ports.php?ip=192.168.1.10&full=1` — varredura de portas e riscos
  sob demanda para um único dispositivo (`full=1` varre as portas 1–1024;
  sem esse parâmetro, usa a lista de portas comuns do `config/config.php`).

Se nenhuma `subnet` for informada, o sistema tenta detectar automaticamente
a partir do ambiente (veja "Reconhecimento de ambiente" acima).

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
