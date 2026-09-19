# Contexto para continuar localmente

## O que é
Dashboard PHP puro (sem frameworks/composer) que roda **localmente na sua
máquina**, conecta na rede em que você está (ex.: rede de um cliente) e
mostra em tempo real: dispositivos conectados (nome, IP, MAC, fabricante,
portas abertas), riscos por host, diagnóstico da própria rede (MAC
duplicado, loop, instabilidade) e uma verificação do IP público.

## Repositório
- GitHub: `Rusuaros21/php`
- Branch: `claude/network-scan-realtime-4fqvo5`
- PR aberta: #1 (ainda não mesclada)

## Como pegar o código
```
git clone -b claude/network-scan-realtime-4fqvo5 https://github.com/Rusuaros21/php.git
cd php
```
(ou baixar ZIP em https://github.com/Rusuaros21/php/tree/claude/network-scan-realtime-4fqvo5)

## Como rodar
- Windows: duplo-clique em `start.bat`
- macOS/Linux: `./start.sh`
- Manual: `php -S 127.0.0.1:8080 -t public` e abrir `http://localhost:8080`

**Recomendado:** instalar `nmap` também (resultados mais rápidos/completos;
sem ele, cai num fallback 100% PHP com ping/arp nativos do SO).

## O que já foi construído (histórico de commits, mais recente primeiro)
1. `Add one-click launcher scripts for field use` — start.bat/start.sh
2. `Add network diagnostics tab (duplicate MAC, loop, flapping)`
3. `Add native Windows support` — corrige detecção de nmap, ping, arp e
   sub-rede no Windows
4. `Add public IP tab with port/risk check`
5. `Add environment auto-detection and per-host risk findings`
6. `Add auth, device history/alerts, and MAC vendor lookup`
7. `Add real-time network scanner dashboard` — commit inicial

O **README.md** do projeto documenta tudo em detalhe: cada funcionalidade,
variáveis de ambiente de configuração, e as limitações conhecidas (ex.: a
verificação de riscos é heurística, não é scanner de CVEs; o teste de IP
público sofre de NAT hairpin). Peça para a nova sessão ler o README.md
primeiro.

## O que AINDA NÃO foi validado (importante!)
Todo o desenvolvimento foi feito num sandbox Linux na nuvem, sem:
- `ping`, `arp`, `ip`, `nmap` instalados (não dá pra testar descoberta real
  de dispositivos)
- Acesso à internet para domínios externos (não dá pra testar a aba "IP
  Público" de verdade, que depende de ipify.org/ifconfig.me)
- Ambiente Windows (não dá pra rodar/testar o `start.bat` de verdade)

Tudo foi validado com testes indiretos (mocks de `ping`/`arp`/`ip`
simulando respostas reais, servidor local simulando os serviços de eco de
IP), mas o teste real na sua máquina/rede ainda está pendente. **Esse é o
próximo passo**: rodar `start.bat`, testar contra a rede real, e reportar
qualquer erro ou comportamento estranho.

## Como pedir pra próxima sessão continuar
Algo como: "Continue o projeto do repositório Rusuaros21/php, branch
claude/network-scan-realtime-4fqvo5. Leia o README.md e o HANDOFF.md na
raiz do projeto para contexto completo. Acabei de testar rodando
localmente no Windows e encontrei/quero: [...]"
