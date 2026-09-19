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
</main>

<script src="assets/js/app.js"></script>
</body>
</html>
