<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Monitor de Rede em Tempo Real</title>
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<header class="topbar">
  <h1>Monitor de Rede</h1>
  <form class="controls" id="controlsForm">
    <label>
      Sub-rede
      <input type="text" id="subnet" list="subnetSuggestions" placeholder="detectar automaticamente">
      <datalist id="subnetSuggestions"></datalist>
    </label>
    <label class="checkbox">
      <input type="checkbox" id="scanPorts" checked>
      Escanear portas
    </label>
    <button type="submit" id="applyBtn">Aplicar</button>
    <span id="status" class="status status--connecting">Conectando…</span>
  </form>
</header>

<main>
  <div id="envWarnings" class="warnings" hidden></div>

  <div class="tabs">
    <button type="button" class="tab-btn is-active" data-tab="local">Rede Local</button>
    <button type="button" class="tab-btn" data-tab="diagnostics">Diagnóstico <span id="diagnosticsBadge" class="tab-badge" hidden>0</span></button>
    <button type="button" class="tab-btn" data-tab="public">IP Público</button>
  </div>

  <section id="tab-local" class="tab-panel">
    <div class="summary">
      <div class="summary__item"><strong id="deviceCount">0</strong> dispositivo(s) online</div>
      <div class="summary__item">Motor: <span id="engine">-</span></div>
      <div class="summary__item">Sistema: <span id="osFamily">-</span></div>
      <div class="summary__item">Sub-rede: <span id="currentSubnet">-</span></div>
      <div class="summary__item">Última varredura: <span id="scannedAt">-</span></div>
    </div>

    <table class="devices">
      <thead>
        <tr>
          <th></th>
          <th>Nome</th>
          <th>IP</th>
          <th>MAC</th>
          <th>Fabricante</th>
          <th>Portas abertas</th>
          <th>Riscos</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="deviceList">
        <tr><td colspan="8" class="empty">Aguardando primeira varredura…</td></tr>
      </tbody>
    </table>
  </section>

  <section id="tab-diagnostics" class="tab-panel" hidden>
    <p class="note">
      Verificações automáticas de saúde da própria rede: MAC duplicado
      (possível clonagem ou spoofing), loop de rede (respostas de ping
      duplicadas — indício clássico de loop de switch) e dispositivos
      instáveis (entrando e saindo repetidamente). A detecção de
      instabilidade só funciona no monitoramento ao vivo — precisa observar
      vários ciclos de varredura.
    </p>
    <div id="diagnosticsContent">
      <div class="empty">Aguardando primeira varredura…</div>
    </div>
  </section>

  <section id="tab-public" class="tab-panel" hidden>
    <div class="summary">
      <div class="summary__item">IP público: <strong id="publicIp">—</strong></div>
      <div class="summary__item">Verificado em: <span id="publicIpCheckedAt">—</span></div>
      <button type="button" id="checkPublicIpBtn" class="link-btn">Verificar agora</button>
    </div>

    <p class="note">
      ⚠ Este teste é feito de dentro da sua própria rede. Muitos roteadores não
      permitem alcançar o IP público a partir da rede interna (NAT hairpin) —
      a ausência de portas abertas aqui não garante que a rede esteja
      protegida do ponto de vista externo. Para um resultado mais confiável,
      repita a verificação a partir de outra rede (ex.: dados móveis).
    </p>

    <div id="publicIpContent">
      <div class="empty">Clique em "Verificar agora" para checar portas abertas no seu IP público.</div>
    </div>
  </section>
</main>

<script src="assets/js/app.js"></script>
</body>
</html>
