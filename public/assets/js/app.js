(() => {
  const statusEl = document.getElementById('status');
  const deviceListEl = document.getElementById('deviceList');
  const deviceCountEl = document.getElementById('deviceCount');
  const engineEl = document.getElementById('engine');
  const currentSubnetEl = document.getElementById('currentSubnet');
  const scannedAtEl = document.getElementById('scannedAt');
  const subnetInput = document.getElementById('subnet');
  const scanPortsCheckbox = document.getElementById('scanPorts');
  const form = document.getElementById('controlsForm');

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

  function renderPorts(ports) {
    if (!Array.isArray(ports)) {
      return '<span class="muted">—</span>';
    }
    if (ports.length === 0) {
      return '<span class="muted">nenhuma</span>';
    }
    return ports
      .map((p) => `<span class="badge" title="${escapeHtml(p.service || '')}">${escapeHtml(String(p.port))}</span>`)
      .join(' ');
  }

  function renderDevices(payload) {
    const devices = payload.devices || [];
    deviceCountEl.textContent = String(devices.length);
    engineEl.textContent = payload.engine || '-';
    currentSubnetEl.textContent = payload.subnet || '-';
    scannedAtEl.textContent = payload.scanned_at
      ? new Date(payload.scanned_at).toLocaleTimeString('pt-BR')
      : '-';

    if (devices.length === 0) {
      deviceListEl.innerHTML = '<tr><td colspan="7" class="empty">Nenhum dispositivo encontrado.</td></tr>';
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

      tr.innerHTML = `
        <td><span class="dot dot--online" title="online"></span></td>
        <td>${nameLabel}</td>
        <td>${escapeHtml(device.ip)}</td>
        <td>${escapeHtml(device.mac || '—')}</td>
        <td>${escapeHtml(device.vendor || '—')}</td>
        <td class="ports-cell">${renderPorts(device.ports)}</td>
        <td><button type="button" class="link-btn" data-ip="${escapeHtml(device.ip)}">Escanear portas</button></td>
      `;
      deviceListEl.appendChild(tr);
    });
  }

  deviceListEl.addEventListener('click', async (event) => {
    const btn = event.target.closest('button[data-ip]');
    if (!btn) {
      return;
    }
    const ip = btn.dataset.ip;
    const originalLabel = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Escaneando…';

    try {
      const res = await fetch(`api/ports.php?ip=${encodeURIComponent(ip)}&full=1`);
      const data = await res.json();
      const row = btn.closest('tr');
      const cell = row.querySelector('.ports-cell');
      cell.innerHTML = renderPorts(data.ports);
    } catch (err) {
      console.error('Falha ao escanear portas', err);
    } finally {
      btn.disabled = false;
      btn.textContent = originalLabel;
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

  connect();
})();
