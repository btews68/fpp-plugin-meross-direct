<?php
$pluginName = 'fpp-plugin-meross-direct';
?>

<h2>Meross Direct Configuration</h2>
<p>Set your Meross account credentials, default device, and friendly name aliases.</p>

<div class='container-fluid' style='max-width: 1000px; margin-left: 0;'>
  <div class='row mb-2'>
    <div class='col-md-4'><label for='merossEmail'><b>Username / Email</b></label></div>
    <div class='col-md-8'><input id='merossEmail' class='form-control' type='text' placeholder='your@email.com'></div>
  </div>

  <div class='row mb-2'>
    <div class='col-md-4'><label for='merossPassword'><b>Password</b></label></div>
    <div class='col-md-8'><input id='merossPassword' class='form-control' type='password'></div>
  </div>

  <div class='row mb-2'>
    <div class='col-md-4'><label for='merossRegion'><b>API Region</b></label></div>
    <div class='col-md-8'>
      <select id='merossRegion' class='form-control'>
        <option value='us'>United States (us)</option>
        <option value='eu'>Europe (eu)</option>
        <option value='ap'>Asia Pacific (ap)</option>
      </select>
      <small class='form-text text-muted'>Match the region where your Meross account was created.</small>
    </div>
  </div>

  <div class='row mb-2'>
    <div class='col-md-4'><label for='quickSelectDevice'><b>Quick Select Device</b></label></div>
    <div class='col-md-8'>
      <select id='quickSelectDevice' class='form-control'>
        <option value=''>-- Discover Devices first --</option>
      </select>
      <small class='form-text text-muted'>After discovery, select a device to auto-fill Default Device UUID.</small>
    </div>
  </div>

  <div class='row mb-2'>
    <div class='col-md-4'><label for='aliasName'><b>Friendly Name</b></label></div>
    <div class='col-md-8'>
      <div style='display: flex; gap: 8px;'>
        <input id='aliasName' class='form-control' type='text' placeholder='Example: Porch, Window_Left, TreeLeft'>
        <button id='saveAliasBtn' class='buttons btn-outline-primary' type='button'>Save Alias</button>
      </div>
      <small class='form-text text-muted'>Use this name in scripts/commands instead of UUID.</small>
    </div>
  </div>

  <div class='row mb-2'>
    <div class='col-md-4'><label for='defaultDevice'><b>Default Device UUID</b></label></div>
    <div class='col-md-8'><input id='defaultDevice' class='form-control' type='text' placeholder='Optional: UUID used when no device is passed to a command'></div>
  </div>

  <div class='row mb-2'>
    <div class='col-md-4'><label for='defaultChannel'><b>Default Channel</b></label></div>
    <div class='col-md-8'>
      <input id='defaultChannel' class='form-control' type='number' min='0' max='7' value='0'>
      <small class='form-text text-muted'>0 for single-plug devices; power strips use channels 0–3 (or more).</small>
    </div>
  </div>

  <div class='row mb-4'>
    <div class='col-md-12'>
      <button id='saveBtn' class='buttons btn-success'>Save Settings</button>
      <button id='discoverBtn' class='buttons btn-outline-primary'>Discover Devices</button>
      <button id='testOnBtn'  class='buttons btn-outline-secondary'>Test ON (Default)</button>
      <button id='testOffBtn' class='buttons btn-outline-secondary'>Test OFF (Default)</button>
      <input id='testLevelValue' type='number' min='0' max='100' value='50' style='width: 90px; margin-left: 8px;'>
      <button id='testLevelBtn' class='buttons btn-outline-secondary'>Test LEVEL</button>
    </div>
  </div>

  <h3>Discovered Devices</h3>
  <div id='devicesOutput' style='min-height: 180px; background: #111; color: #ddd; padding: 12px; border-radius: 6px;'>No device data yet.</div>

  <h3>Saved Friendly Names</h3>
  <div id='aliasesOutput' style='min-height: 80px; background: #111; color: #ddd; padding: 12px; border-radius: 6px;'>No aliases saved.</div>

  <h3>Status</h3>
  <pre id='statusOutput' style='min-height: 80px; background: #111; color: #ddd; padding: 12px; border-radius: 6px;'>Ready.</pre>
</div>

<script>
(function () {
  const plugin = '<?php echo $pluginName; ?>';
  const REQUEST_TIMEOUT_MS = 240000;
  let discoveredDevices = [];
  const discoveredDevicesKey = 'MEROSS_DISCOVERED_DEVICES';
  const aliasesKey = 'MEROSS_DEVICE_ALIASES';
  let deviceAliases = {};

  const fields = {
    MEROSS_EMAIL:               'merossEmail',
    MEROSS_PASSWORD:            'merossPassword',
    MEROSS_API_REGION:          'merossRegion',
    MEROSS_DEFAULT_DEVICE_UUID: 'defaultDevice',
    MEROSS_DEFAULT_CHANNEL:     'defaultChannel',
  };

  // ── FPP settings API helpers ──────────────────────────────────────────────
  async function getSetting(key) {
    const resp = await fetch(`api/plugin/${plugin}/settings/${encodeURIComponent(key)}`);
    const json = await resp.json();
    return json[key] || '';
  }

  async function setSetting(key, value) {
    const resp = await fetch(`api/plugin/${plugin}/settings/${encodeURIComponent(key)}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'text/plain' },
      body: value
    });
    return await resp.json();
  }

  async function fetchJsonWithTimeout(url, options = {}, timeoutMs = REQUEST_TIMEOUT_MS) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    try {
      const resp = await fetch(url, { ...options, signal: controller.signal });
      const text = await resp.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch (_) {
        throw new Error(`Invalid response (HTTP ${resp.status}): ${text.slice(0, 200)}`);
      }
      if (!resp.ok) {
        throw new Error(data.error || `HTTP ${resp.status}`);
      }
      return data;
    } finally {
      clearTimeout(timer);
    }
  }

  function showStatus(obj) {
    document.getElementById('statusOutput').textContent =
      typeof obj === 'string' ? obj : JSON.stringify(obj, null, 2);
  }

  // ── Load / save settings ──────────────────────────────────────────────────
  async function loadSettings() {
    for (const [key, id] of Object.entries(fields)) {
      try {
        const value = await getSetting(key);
        document.getElementById(id).value = value;
      } catch (err) {
        showStatus(`Failed to load ${key}: ${err}`);
      }
    }
  }

  async function saveSettings() {
    const results = {};
    for (const [key, id] of Object.entries(fields)) {
      const value = document.getElementById(id).value;
      results[key] = await setSetting(key, value);
    }
    showStatus({ ok: true, message: 'Settings saved', results });
  }

  // ── Alias management ──────────────────────────────────────────────────────
  function renderAliases() {
    const container = document.getElementById('aliasesOutput');
    const entries = Object.entries(deviceAliases || {});
    if (entries.length === 0) {
      container.innerHTML = 'No aliases saved.';
      return;
    }
    entries.sort((a, b) => a[0].toLowerCase().localeCompare(b[0].toLowerCase()));
    let html = '<table style="width:100%; border-collapse:collapse; color:#ddd;">';
    html += '<tr style="border-bottom:1px solid #444;"><th style="text-align:left;padding:8px;">Friendly Name</th><th style="text-align:left;padding:8px;">UUID</th><th style="text-align:left;padding:8px;">Device Name</th><th style="text-align:center;padding:8px;">Ch</th><th style="text-align:center;padding:8px;">Actions</th></tr>';
    entries.forEach(([alias, data]) => {
      const uuid    = String(data?.uuid || '');
      const dname   = String(data?.name || '');
      const channel = String(data?.channel ?? 0);
      html += `<tr style="border-bottom:1px solid #333;">`;
      html += `<td style="padding:8px;">${alias}</td>`;
      html += `<td style="padding:8px;"><code>${uuid}</code></td>`;
      html += `<td style="padding:8px;">${dname}</td>`;
      html += `<td style="padding:8px;text-align:center;">${channel}</td>`;
      html += `<td style="padding:8px;text-align:center;">`;
      html += `<button class="buttons btn-outline-secondary" style="padding:4px 8px;font-size:12px;margin-right:6px;" onclick="selectAlias('${alias.replace(/'/g,"\\'")}')">Use</button>`;
      html += `<button class="buttons btn-outline-danger" style="padding:4px 8px;font-size:12px;" onclick="removeAlias('${alias.replace(/'/g,"\\'")}')">Delete</button>`;
      html += `</td></tr>`;
    });
    html += '</table>';
    container.innerHTML = html;
  }

  async function saveAliasesToSettings() {
    await setSetting(aliasesKey, JSON.stringify(deviceAliases));
  }

  async function loadAliasesFromSettings() {
    try {
      const raw = await getSetting(aliasesKey);
      if (!raw) { deviceAliases = {}; renderAliases(); return; }
      const parsed = JSON.parse(raw);
      deviceAliases = (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) ? parsed : {};
      renderAliases();
    } catch (err) {
      deviceAliases = {};
      renderAliases();
      showStatus(`Warning: unable to load aliases: ${err}`);
    }
  }

  async function saveAliasFromSelection() {
    const alias      = document.getElementById('aliasName').value.trim();
    const selectedId = document.getElementById('quickSelectDevice').value.trim();
    if (!alias)      { showStatus('Enter a Friendly Name first.'); return; }
    if (!selectedId) { showStatus('Select a device first.');       return; }

    // quickSelectDevice value is "uuid|channel"
    const [uuid, channelStr] = selectedId.split('|');
    const channel = parseInt(channelStr || '0', 10);

    const device = discoveredDevices.find(d => String(d.uuid || '').trim() === uuid);
    if (!device) { showStatus('Selected device not found in discovered list. Click Discover Devices again.'); return; }

    deviceAliases[alias] = {
      uuid:    uuid,
      channel: channel,
      name:    String(device.name || ''),
      type:    String(device.deviceType || ''),
    };
    await saveAliasesToSettings();
    renderAliases();
    showStatus({ ok: true, message: `Saved alias '${alias}' -> ${uuid} ch${channel}` });
  }

  async function removeAlias(alias) {
    if (!deviceAliases[alias]) return;
    delete deviceAliases[alias];
    await saveAliasesToSettings();
    renderAliases();
    showStatus({ ok: true, message: `Deleted alias '${alias}'.` });
  }

  function selectAlias(alias) {
    const data = deviceAliases[alias];
    if (!data || !data.uuid) { showStatus({ ok: false, message: `Alias '${alias}' is missing UUID.` }); return; }
    document.getElementById('defaultDevice').value  = String(data.uuid);
    document.getElementById('defaultChannel').value = String(data.channel ?? 0);
    const selValue = `${data.uuid}|${data.channel ?? 0}`;
    const sel = document.getElementById('quickSelectDevice');
    if ([...sel.options].some(o => o.value === selValue)) sel.value = selValue;
    showStatus({ ok: true, message: `Using alias '${alias}' (${data.uuid} ch${data.channel ?? 0}).` });
  }

  window.selectAlias = selectAlias;
  window.removeAlias = removeAlias;

  // ── Device discovery ──────────────────────────────────────────────────────
  function renderDevices(devices) {
    const container = document.getElementById('devicesOutput');
    if (!devices || devices.length === 0) { container.innerHTML = 'No devices found.'; return; }

    let html = '<table style="width:100%; border-collapse:collapse; color:#ddd;">';
    html += '<tr style="border-bottom:1px solid #444;"><th style="text-align:left;padding:8px;">Name</th><th style="text-align:left;padding:8px;">Type</th><th style="text-align:left;padding:8px;">UUID</th><th style="text-align:center;padding:8px;">Online</th><th style="text-align:left;padding:8px;">Channels</th><th style="text-align:center;padding:8px;">Select</th></tr>';

    const sorted = [...devices].sort((a, b) => String(a.name || '').toLowerCase().localeCompare(String(b.name || '').toLowerCase()));
    sorted.forEach(device => {
      const channels = device.channels || [{ index: 0, name: 'main' }];
      const onlineColor = String(device.online || '').toUpperCase() === 'ONLINE' ? '#4caf50' : '#f44336';
      html += `<tr style="border-bottom:1px solid #333;">`;
      html += `<td style="padding:8px;">${device.name || ''}</td>`;
      html += `<td style="padding:8px;">${device.deviceType || ''}</td>`;
      html += `<td style="padding:8px;"><code>${device.uuid || ''}</code></td>`;
      html += `<td style="padding:8px;text-align:center;color:${onlineColor};">${device.online || 'unknown'}</td>`;
      html += `<td style="padding:8px;">${channels.map(c => `ch${c.index}: ${c.name}`).join(', ')}</td>`;
      html += `<td style="padding:8px;text-align:center;">`;
      channels.forEach(ch => {
        const optVal = `${device.uuid}|${ch.index}`;
        html += `<button class="buttons btn-outline-secondary" style="padding:4px 8px;font-size:11px;margin:2px;" onclick="useDevice('${device.uuid}','${ch.index}','${String(device.name||'').replace(/'/g,"\\'")}')">ch${ch.index}</button>`;
      });
      html += `</td></tr>`;
    });
    html += '</table>';
    container.innerHTML = html;
  }

  function useDevice(uuid, channel, name) {
    document.getElementById('defaultDevice').value  = uuid;
    document.getElementById('defaultChannel').value = channel;
    const sel = document.getElementById('quickSelectDevice');
    const optVal = `${uuid}|${channel}`;
    if ([...sel.options].some(o => o.value === optVal)) sel.value = optVal;
    showStatus({ ok: true, message: `Selected device '${name}' (${uuid}) ch${channel}.` });
  }
  window.useDevice = useDevice;

  function populateQuickSelect(devices) {
    const sel = document.getElementById('quickSelectDevice');
    const prev = sel.value;
    // Remove all options except first placeholder
    while (sel.options.length > 1) sel.remove(1);
    const sorted = [...(devices || [])].sort((a,b) => String(a.name||'').toLowerCase().localeCompare(String(b.name||'').toLowerCase()));
    sorted.forEach(device => {
      const channels = device.channels || [{ index: 0, name: 'main' }];
      channels.forEach(ch => {
        const opt = document.createElement('option');
        opt.value = `${device.uuid}|${ch.index}`;
        opt.textContent = `${device.name || device.uuid} — ch${ch.index}${ch.name ? ' (' + ch.name + ')' : ''}`;
        sel.appendChild(opt);
      });
    });
    if ([...sel.options].some(o => o.value === prev)) sel.value = prev;
  }

  async function discoverDevices() {
    const discoverBtn = document.getElementById('discoverBtn');
    const originalText = discoverBtn.textContent;
    discoverBtn.disabled = true;
    discoverBtn.textContent = 'Discovering...';
    showStatus('Discovering devices... (timeout: up to 240s on first run while dependencies install)');
    try {
      const data = await fetchJsonWithTimeout(`api/plugin/${plugin}/devices`);
      if (!data.ok) { showStatus({ ok: false, error: data.error || 'Discovery failed', details: data }); return; }
      discoveredDevices = data.devices || [];
      await setSetting(discoveredDevicesKey, JSON.stringify(discoveredDevices));
      renderDevices(discoveredDevices);
      populateQuickSelect(discoveredDevices);
      showStatus({ ok: true, message: `Found ${discoveredDevices.length} device(s).` });
    } catch (err) {
      if (err && err.name === 'AbortError') {
        try {
          const diag = await fetchJsonWithTimeout(`api/plugin/${plugin}/diagnostics`, {}, 30000);
          showStatus({
            ok: false,
            error: `Discovery timed out after ${Math.round(REQUEST_TIMEOUT_MS / 1000)} seconds`,
            diagnostics: diag,
          });
        } catch (diagErr) {
          showStatus(`Discovery timed out and diagnostics failed: ${diagErr}`);
        }
      } else {
        showStatus(`Discovery error: ${err}`);
      }
    } finally {
      discoverBtn.disabled = false;
      discoverBtn.textContent = originalText;
    }
  }

  async function loadCachedDevices() {
    try {
      const raw = await getSetting(discoveredDevicesKey);
      if (!raw) return;
      const parsed = JSON.parse(raw);
      if (Array.isArray(parsed) && parsed.length > 0) {
        discoveredDevices = parsed;
        renderDevices(discoveredDevices);
        populateQuickSelect(discoveredDevices);
      }
    } catch (_) {}
  }

  // ── Test buttons ──────────────────────────────────────────────────────────
  async function runAction(action, value) {
    let deviceId = document.getElementById('defaultDevice').value.trim();
    if (!deviceId && discoveredDevices.length > 0) {
      const online = discoveredDevices.find(d => String(d.online || '').toUpperCase() === 'ONLINE') || discoveredDevices[0];
      deviceId = online.uuid;
      showStatus({ ok: true, message: `No default device set — using first discovered: ${online.name} (${deviceId})` });
    }
    if (!deviceId) {
      showStatus({ ok: false, message: 'No device selected. Run Discovery first or enter a Device UUID above.' });
      return;
    }
    const channel = parseInt(document.getElementById('defaultChannel').value, 10);
    const body = { action, deviceId, channel: isNaN(channel) ? 0 : channel };
    if (value !== undefined && value !== '') body.value = String(value);
    showStatus(`Sending ${action}${value !== undefined ? ' ' + value : ''}...`);
    try {
      const data = await fetchJsonWithTimeout(`api/plugin/${plugin}/run`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      });
      showStatus(data);
    } catch (err) {
      if (err && err.name === 'AbortError') {
        showStatus(`Error: ${action} request timed out after ${Math.round(REQUEST_TIMEOUT_MS / 1000)} seconds.`);
      } else {
        showStatus(`Error: ${err}`);
      }
    }
  }

  // ── Bootstrap ─────────────────────────────────────────────────────────────
  document.getElementById('quickSelectDevice').addEventListener('change', function() {
    const val = this.value;
    if (!val) return;
    const [uuid, channel] = val.split('|');
    document.getElementById('defaultDevice').value  = uuid || '';
    document.getElementById('defaultChannel').value = channel || '0';
    const opt = this.options[this.selectedIndex];
    showStatus({ ok: true, message: `Selected: ${opt.textContent}` });
  });

  document.getElementById('saveBtn').addEventListener('click', saveSettings);
  document.getElementById('discoverBtn').addEventListener('click', discoverDevices);
  document.getElementById('testOnBtn').addEventListener('click', () => runAction('on'));
  document.getElementById('testOffBtn').addEventListener('click', () => runAction('off'));
  document.getElementById('testLevelBtn').addEventListener('click', () => {
    const level = document.getElementById('testLevelValue').value;
    runAction('level', level);
  });
  document.getElementById('saveAliasBtn').addEventListener('click', saveAliasFromSelection);

  loadSettings();
  loadCachedDevices();
  loadAliasesFromSettings();
})();
</script>
