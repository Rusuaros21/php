(() => {
  const statusEl = document.getElementById('status');
  const deviceListEl = document.getElementById('deviceList');
  const deviceCountEl = document.getElementById('deviceCount');
  const engineEl = document.getElementById('engine');
  const osFamilyEl = document.getElementById('osFamily');
  const currentSubnetEl = document.getElementById('currentSubnet');
  const scannedAtEl = document.getElementById('scannedAt');
  const subnetInput = document.getElementById('subnet');
  const subnetSuggestionsEl = document.getElementById('subnetSuggestions');
  const scanPortsCheckbox = document.getElementById('scanPorts');
  const form = document.getElementById('controlsForm');
  const envWarningsEl = document.getElementById('envWarnings');
  const tabButtons = document.querySelectorAll('.tab-btn');
  const tabPanels = {
    local: document.getElementById('tab-local'),
    diagnostics: document.getElementById('tab-diagnostics'),
    public: document.getElementById('tab-public'),
  };
  const checkPublicIpBtn = document.getElementById('checkPublicIpBtn');
  const publicIpEl = document.getElementById('publicIp');
  const publicIpCheckedAtEl = document.getElementById('publicIpCheckedAt');
  const publicIpContentEl = document.getElementById('publicIpContent');
  const diagnosticsContentEl = document.getElementById('diagnosticsContent');
  const diagnosticsBadgeEl = document.getElementById('diagnosticsBadge');
  const checkUpnpBtn = document.getElementById('checkUpnpBtn');
  const upnpContentEl = document.getElementById('upnpContent');

  const SEVERITY_LABEL = { high: 'ALTO', medium: 'MÉDIO', low: 'BAIXO' };
  const SEVERITY_RANK = { high: 3, medium: 2, low: 1 };

  let source = null;

  function setStatus(text, cls) {
    statusEl.textContent = text;
    statusEl.className = 'status status--' + cls;
  }

  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;',
    }[c]));
  }

  function renderWarnings(warnings) {
    if (!warnings || warnings.length === 0) {
      envWarningsEl.hidden = true;
      envWarningsEl.innerHTML = '';
      return;
    }
    envWarningsEl.hidden = false;
    envWarningsEl.innerHTML = warnings
      .map((w) => `<div class="warning-item">⚠ ${escapeHtml(w)}</div>`)
      .join('');
  }

  async function loadEnvironment() {
    try {
      const res = await fetch('api/environment.php');
      const env = await res.json();

      osFamilyEl.textContent = env.os || '-';
      if (env.engine) {
        engineEl.textContent = env.engine;
      }

      subnetSuggestionsEl.innerHTML = '';
      (env.subnets || []).forEach((s) => {
        const option = document.createElement('option');
        option.value = s.cidr;
        option.label = `${s.iface} (${s.cidr})`;
        subnetSuggestionsEl.appendChild(option);
      });

      // Pre-fill (not just suggest) the detected subnet, visibly and
      // editable, so it's clear what will be scanned before the user does
      // anything — they can still overwrite it with a different range.
      if (!subnetInput.value.trim() && env.recommended_subnet) {
        subnetInput.value = env.recommended_subnet;
      }

      renderWarnings(env.warnings);
    } catch (err) {
      console.error('Falha ao detectar o ambiente de rede', err);
    }
  }

  function renderPorts(ports) {
    if (!Array.isArray(ports)) {
      return '<span class="muted">—</span>';
    }
    if (ports.length === 0) {
      return '<span class="muted">nenhuma</span>';
    }
    return ports
      .map((p) => {
        const versionLabel = [p.product, p.version].filter(Boolean).join(' ');
        const label = versionLabel ? `${p.port} · ${versionLabel}` : String(p.port);
        return `<span class="badge" title="${escapeHtml(p.service || '')}">${escapeHtml(label)}</span>`;
      })
      .join(' ');
  }

  function renderOsGuess(os) {
    if (!Array.isArray(os) || os.length === 0) {
      return '';
    }
    const items = os
      .map((m) => `<li>${escapeHtml(m.name)} <span class="muted">(${escapeHtml(String(m.accuracy))}% de confiança)</span></li>`)
      .join('');
    return `<div class="os-guess"><strong>Sistema operacional provável:</strong><ul>${items}</ul></div>`;
  }

  function renderRiskSummary(risks) {
    if (!Array.isArray(risks) || risks.length === 0) {
      return '<span class="muted">—</span>';
    }
    const top = risks.reduce((a, b) => (SEVERITY_RANK[b.severity] > SEVERITY_RANK[a.severity] ? b : a));
    const label = risks.length === 1 ? '1 risco' : `${risks.length} riscos`;
    return `<button type="button" class="risk-badge risk-badge--${escapeHtml(top.severity)}" data-toggle-risks>${label}</button>`;
  }

  function renderRiskDetails(risks) {
    if (!Array.isArray(risks) || risks.length === 0) {
      return '';
    }
    const items = risks
      .map((r) => `
        <li class="risk-item risk-item--${escapeHtml(r.severity)}">
          <span class="risk-item__severity">${SEVERITY_LABEL[r.severity] || escapeHtml(r.severity).toUpperCase()}</span>
          <strong>${escapeHtml(r.title)}</strong> — porta ${escapeHtml(String(r.port))}${r.service ? ` (${escapeHtml(r.service)})` : ''}
          <p>${escapeHtml(r.description)}</p>
        </li>
      `)
      .join('');
    return `<ul class="risk-list">${items}</ul>`;
  }

  function renderDiagnostics(diagnostics) {
    const list = Array.isArray(diagnostics) ? diagnostics : [];

    diagnosticsBadgeEl.hidden = list.length === 0;
    diagnosticsBadgeEl.textContent = String(list.length);

    if (list.length === 0) {
      diagnosticsContentEl.innerHTML = '<div class="empty">Nenhum problema detectado na rede até agora.</div>';
      return;
    }

    const items = list
      .map((d) => `
        <li class="risk-item risk-item--${escapeHtml(d.severity)}">
          <span class="risk-item__severity">${SEVERITY_LABEL[d.severity] || escapeHtml(d.severity).toUpperCase()}</span>
          <strong>${escapeHtml(d.title)}</strong>
          <p>${escapeHtml(d.description)}</p>
        </li>
      `)
      .join('');
    diagnosticsContentEl.innerHTML = `<ul class="risk-list">${items}</ul>`;
  }

  function renderDevices(payload) {
    const devices = payload.devices || [];
    deviceCountEl.textContent = String(devices.length);
    engineEl.textContent = payload.engine || '-';
    currentSubnetEl.textContent = payload.subnet || '-';
    scannedAtEl.textContent = payload.scanned_at
      ? new Date(payload.scanned_at).toLocaleTimeString('pt-BR')
      : '-';

    if (Array.isArray(payload.warnings)) {
      renderWarnings(payload.warnings);
    }

    renderDiagnostics(payload.network_diagnostics);

    if (devices.length === 0) {
      deviceListEl.innerHTML = '<tr><td colspan="8" class="empty">Nenhum dispositivo encontrado.</td></tr>';
      return;
    }

    deviceListEl.innerHTML = '';

    devices.forEach((device) => {
      const tr = document.createElement('tr');
      if (device.is_new) {
        tr.classList.add('is-new');
      }

      const nameLabel = escapeHtml(device.hostname || '(desconhecido)')
        + (device.is_new ? ' <span class="badge badge--new">NOVO</span>' : '');
      const hasRisks = Array.isArray(device.risks) && device.risks.length > 0;

      tr.innerHTML = `
        <td><span class="dot dot--online" title="online"></span></td>
        <td>${nameLabel}</td>
        <td>${escapeHtml(device.ip)}</td>
        <td>${escapeHtml(device.mac || '—')}</td>
        <td>${escapeHtml(device.vendor || '—')}</td>
        <td class="ports-cell">${renderPorts(device.ports)}</td>
        <td class="risks-cell">${renderRiskSummary(device.risks)}</td>
        <td><button type="button" class="link-btn" data-ip="${escapeHtml(device.ip)}">Escanear portas/SO</button></td>
      `;
      deviceListEl.appendChild(tr);

      const detailTr = document.createElement('tr');
      detailTr.className = 'risk-details-row';
      detailTr.hidden = true;
      detailTr.innerHTML = `<td colspan="8">${renderRiskDetails(device.risks)}</td>`;
      deviceListEl.appendChild(detailTr);
      if (!hasRisks) {
        detailTr.style.display = 'none';
      }
    });
  }

  deviceListEl.addEventListener('click', async (event) => {
    const toggleBtn = event.target.closest('[data-toggle-risks]');
    if (toggleBtn) {
      const row = toggleBtn.closest('tr');
      const detailRow = row.nextElementSibling;
      if (detailRow && detailRow.classList.contains('risk-details-row')) {
        detailRow.hidden = !detailRow.hidden;
      }
      return;
    }

    const scanBtn = event.target.closest('button[data-ip]');
    if (!scanBtn) {
      return;
    }

    const ip = scanBtn.dataset.ip;
    const originalLabel = scanBtn.textContent;
    scanBtn.disabled = true;
    scanBtn.textContent = 'Escaneando…';

    try {
      const res = await fetch(`api/ports.php?ip=${encodeURIComponent(ip)}&full=1&fingerprint=1`);
      const data = await res.json();
      const row = scanBtn.closest('tr');
      row.querySelector('.ports-cell').innerHTML = renderPorts(data.ports);
      row.querySelector('.risks-cell').innerHTML = renderRiskSummary(data.risks);

      const detailRow = row.nextElementSibling;
      if (detailRow && detailRow.classList.contains('risk-details-row')) {
        const hasRisks = Array.isArray(data.risks) && data.risks.length > 0;
        const hasOs = Array.isArray(data.os) && data.os.length > 0;
        detailRow.querySelector('td').innerHTML = renderOsGuess(data.os) + renderRiskDetails(data.risks);
        detailRow.style.display = (hasRisks || hasOs) ? '' : 'none';
        // Auto-expand: the user just explicitly asked for this deep scan,
        // so show the result immediately instead of requiring another click.
        detailRow.hidden = !(hasRisks || hasOs);
      }
    } catch (err) {
      console.error('Falha ao escanear portas', err);
    } finally {
      scanBtn.disabled = false;
      scanBtn.textContent = originalLabel;
    }
  });

  function connect() {
    if (source) {
      source.close();
    }

    const params = new URLSearchParams();
    const subnetValue = subnetInput.value.trim();
    if (subnetValue) {
      params.set('subnet', subnetValue);
    }
    params.set('ports', scanPortsCheckbox.checked ? '1' : '0');

    setStatus('Conectando…', 'connecting');

    source = new EventSource(`api/stream.php?${params.toString()}`);

    source.addEventListener('devices', (event) => {
      setStatus('Ao vivo', 'live');
      renderDevices(JSON.parse(event.data));
    });

    source.addEventListener('error', (event) => {
      let message = 'Reconectando…';
      if (event.data) {
        try {
          message = JSON.parse(event.data).message || message;
        } catch (e) {
          // ignore malformed payload
        }
      }
      setStatus(message, 'error');
    });

    source.onerror = () => {
      setStatus('Reconectando…', 'error');
    };
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    connect();
  });

  tabButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      tabButtons.forEach((b) => b.classList.toggle('is-active', b === btn));
      Object.entries(tabPanels).forEach(([key, panel]) => {
        panel.hidden = key !== btn.dataset.tab;
      });
    });
  });

  async function checkPublicIp() {
    const originalLabel = checkPublicIpBtn.textContent;
    checkPublicIpBtn.disabled = true;
    checkPublicIpBtn.textContent = 'Verificando…';
    publicIpContentEl.innerHTML = '<div class="empty">Verificando portas no seu IP público…</div>';

    try {
      const res = await fetch('api/public-ip.php');
      const data = await res.json();

      if (data.error) {
        publicIpEl.textContent = '—';
        publicIpContentEl.innerHTML = `<div class="empty">${escapeHtml(data.error)}</div>`;
        return;
      }

      publicIpEl.textContent = data.ip;
      publicIpCheckedAtEl.textContent = data.checked_at
        ? new Date(data.checked_at).toLocaleString('pt-BR')
        : '—';

      const risksHtml = renderRiskDetails(data.risks)
        || '<p class="muted">Nenhum risco conhecido identificado nas portas verificadas.</p>';

      publicIpContentEl.innerHTML = `
        <div class="public-ip-ports">${renderPorts(data.ports)}</div>
        ${risksHtml}
      `;
    } catch (err) {
      console.error('Falha ao verificar IP público', err);
      publicIpContentEl.innerHTML = '<div class="empty">Falha ao verificar. Tente novamente.</div>';
    } finally {
      checkPublicIpBtn.disabled = false;
      checkPublicIpBtn.textContent = originalLabel;
    }
  }

  checkPublicIpBtn.addEventListener('click', checkPublicIp);

  function renderUpnpDevices(devices) {
    const list = Array.isArray(devices) ? devices : [];
    if (list.length === 0) {
      upnpContentEl.innerHTML = '<div class="empty">Nenhum dispositivo UPnP respondeu.</div>';
      return;
    }

    const rows = list
      .map((d) => {
        const name = d.friendly_name || d.server || '(sem nome anunciado)';
        const model = [d.manufacturer, d.model_name].filter(Boolean).join(' ');
        return `
          <tr>
            <td>${escapeHtml(d.ip)}</td>
            <td>${escapeHtml(name)}</td>
            <td>${model ? escapeHtml(model) : '<span class="muted">—</span>'}</td>
          </tr>
        `;
      })
      .join('');

    upnpContentEl.innerHTML = `
      <table class="devices devices--compact">
        <thead><tr><th>IP</th><th>Nome</th><th>Fabricante/Modelo</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    `;
  }

  async function checkUpnp() {
    const originalLabel = checkUpnpBtn.textContent;
    checkUpnpBtn.disabled = true;
    checkUpnpBtn.textContent = 'Procurando…';
    upnpContentEl.innerHTML = '<div class="empty">Procurando dispositivos (alguns segundos)…</div>';

    try {
      const res = await fetch('api/upnp.php');
      const data = await res.json();

      if (data.error) {
        upnpContentEl.innerHTML = `<div class="empty">${escapeHtml(data.error)}</div>`;
        return;
      }

      renderUpnpDevices(data.devices);
    } catch (err) {
      console.error('Falha ao detectar dispositivos UPnP', err);
      upnpContentEl.innerHTML = '<div class="empty">Falha ao detectar. Tente novamente.</div>';
    } finally {
      checkUpnpBtn.disabled = false;
      checkUpnpBtn.textContent = originalLabel;
    }
  }

  checkUpnpBtn.addEventListener('click', checkUpnp);

  loadEnvironment();
  connect();
})();
