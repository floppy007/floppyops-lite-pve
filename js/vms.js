/**
 * FloppyOps Lite — VMs & Container
 * VMs & Container — Liste, Clone, Start/Stop
 */

let _pveVms = [];
let _pveNode = '';

async function loadDashboardVms() {
    try {
        const d = await api('pve-vms');
        if (!d.ok) return;
        document.getElementById('pveVmCount').textContent = d.vms.length;
        const el = document.getElementById('dashVmList');
        if (!d.vms.length) { el.innerHTML = '<span style="color:var(--text3)">Keine VMs/CTs</span>'; return; }

        const running = d.vms.filter(v => v.status === 'running').length;
        const stopped = d.vms.length - running;

        let html = '<div style="display:flex;gap:12px;margin-bottom:8px;font-size:.68rem">';
        html += '<span style="color:var(--green)">' + running + ' running</span>';
        html += '<span style="color:var(--text3)">' + stopped + ' stopped</span>';
        html += '</div>';
        html += '<table style="width:100%;border-collapse:collapse;font-size:.72rem">';
        html += '<thead><tr style="border-bottom:1px solid var(--border-subtle)">';
        html += '<th style="text-align:left;padding:4px 6px;font-size:.6rem;color:var(--text3);font-weight:600">Status</th>';
        html += '<th style="text-align:left;padding:4px 6px;font-size:.6rem;color:var(--text3);font-weight:600">VMID</th>';
        html += '<th style="text-align:left;padding:4px 6px;font-size:.6rem;color:var(--text3);font-weight:600">Name</th>';
        html += '<th style="text-align:left;padding:4px 6px;font-size:.6rem;color:var(--text3);font-weight:600">Typ</th>';
        html += '<th style="text-align:left;padding:4px 6px;font-size:.6rem;color:var(--text3);font-weight:600">vCPU</th>';
        html += '<th style="text-align:left;padding:4px 6px;font-size:.6rem;color:var(--text3);font-weight:600">RAM</th>';
        html += '<th style="text-align:right;padding:4px 6px;font-size:.6rem;color:var(--text3);font-weight:600"></th>';
        html += '</tr></thead><tbody>';
        d.vms.sort((a, b) => a.vmid - b.vmid).forEach(v => {
            const isUp = v.status === 'running';
            const dot = isUp ? 'var(--green)' : 'var(--text3)';
            const typeC = v.type === 'qemu' ? '#a855f7' : 'var(--blue)';
            const typeL = v.type === 'qemu' ? 'VM' : 'CT';
            const memPct = v.mem > 0 ? Math.round(v.mem_used / v.mem * 100) : 0;
            html += '<tr style="border-bottom:1px solid var(--border-subtle)">';
            html += '<td style="padding:5px 6px"><span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:' + dot + '"></span></td>';
            html += '<td style="padding:5px 6px;font-family:var(--mono);font-weight:600">' + v.vmid + '</td>';
            html += '<td style="padding:5px 6px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + (v.name || '—') + '</td>';
            html += '<td style="padding:5px 6px"><span style="font-size:.58rem;padding:1px 5px;border-radius:3px;background:rgba(' + (v.type==='qemu'?'168,85,247':'64,196,255') + ',.08);color:' + typeC + '">' + typeL + '</span></td>';
            html += '<td style="padding:5px 6px;font-family:var(--mono);font-size:.68rem;color:var(--text2)">' + v.cpus + '</td>';
            html += '<td style="padding:5px 6px;font-family:var(--mono);font-size:.68rem;color:var(--text2)">' + (isUp ? fmtBytes(v.mem_used) + ' <span style="color:var(--text3)">/ ' + fmtBytes(v.mem) + '</span>' : fmtBytes(v.mem)) + '</td>';
            html += '<td style="padding:5px 6px;text-align:right;white-space:nowrap">';
            if (isUp) {
                html += '<button class="btn btn-sm btn-red" onclick="pveVmAction(' + v.vmid + ',\'' + v.type + '\',\'stop\')" style="padding:1px 5px;font-size:.5rem" title="Stop">Stop</button> ';
                html += '<button class="btn btn-sm" onclick="pveVmAction(' + v.vmid + ',\'' + v.type + '\',\'restart\')" style="padding:1px 5px;font-size:.5rem" title="Restart">Restart</button>';
            } else {
                html += '<button class="btn btn-sm btn-green" onclick="pveVmAction(' + v.vmid + ',\'' + v.type + '\',\'start\')" style="padding:1px 5px;font-size:.5rem" title="Start">Start</button>';
            }
            html += '</td></tr>';
        });
        html += '</tbody></table>';
        el.innerHTML = html;
    } catch (e) { }
}

async function pveVmAction(vmid, type, action) {
    toast(action + ' ' + vmid + '...');
    try {
        const res = await api('pve-control', 'POST', { vmid, type, action });
        if (res.ok) {
            toast(vmid + ' → ' + action + ' OK');
            setTimeout(loadDashboardVms, 2000);
        } else {
            toast(res.error || 'Fehler', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function loadPveVms() {
    try {
        const d = await api('pve-vms');
        if (!d.ok) return;
        _pveVms = d.vms;
        _pveNode = d.node;
        document.getElementById('pveVmCount').textContent = d.vms.length;

        const list = document.getElementById('pveVmList');
        if (!d.vms.length) { list.innerHTML = '<div class="empty">Keine VMs oder CTs gefunden</div>'; return; }

        let html = '<table class="data-table"><thead><tr><th style="width:60px">VMID</th><th>Name</th><th style="width:50px">Typ</th><th style="width:70px">Status</th><th>CPU</th><th>RAM</th><th>Disk</th><th style="width:90px"></th></tr></thead><tbody>';
        d.vms.forEach(v => {
            const isUp = v.status === 'running';
            const statusTag = isUp ? '<span class="tag tag-green" style="font-size:.46rem">Running</span>' : '<span class="tag tag-muted" style="font-size:.46rem">Stopped</span>';
            const typeTag = v.type === 'qemu' ? '<span style="font-size:.58rem;padding:1px 5px;border-radius:3px;background:rgba(168,85,247,.08);color:#a855f7">VM</span>' : '<span style="font-size:.58rem;padding:1px 5px;border-radius:3px;background:rgba(64,196,255,.08);color:var(--blue)">CT</span>';
            const memPct = v.mem > 0 ? Math.round(v.mem_used / v.mem * 100) : 0;
            const diskPct = v.disk > 0 ? Math.round(v.disk_used / v.disk * 100) : 0;

            html += '<tr>' +
                '<td style="font-family:var(--mono);font-size:.78rem;font-weight:600">' + v.vmid + '</td>' +
                '<td style="font-size:.78rem;font-weight:500">' + (v.name || '—') + '</td>' +
                '<td>' + typeTag + '</td>' +
                '<td>' + statusTag + '</td>' +
                '<td style="font-size:.72rem;color:var(--text2)">' + v.cpus + ' vCPU</td>' +
                '<td style="font-size:.72rem"><span style="color:var(--text2)">' + fmtBytes(v.mem_used) + '</span> <span style="color:var(--text3)">/ ' + fmtBytes(v.mem) + '</span></td>' +
                '<td style="font-size:.72rem"><span style="color:var(--text2)">' + fmtBytes(v.disk_used) + '</span> <span style="color:var(--text3)">/ ' + fmtBytes(v.disk) + '</span></td>' +
                '<td style="text-align:right"><button class="btn btn-sm" onclick="pveOpenClone(' + v.vmid + ',\'' + v.type + '\',\'' + (v.name || '').replace(/'/g, "\\'") + '\')" title="Clone"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> Clone</button></td>' +
            '</tr>';
        });
        html += '</tbody></table>';
        list.innerHTML = html;
    } catch (e) {
    }
}

async function pveOpenClone(vmid, type, name) {
    const typeLabel = type === 'qemu' ? 'VM' : 'CT';
    document.getElementById('pveCloneTitle').textContent = 'Clone ' + typeLabel + ' ' + vmid + ' (' + name + ')';

    document.getElementById('pveCloneBody').innerHTML = '<div style="text-align:center;padding:24px"><span class="loading-spinner" style="width:20px;height:20px;border-width:2px"></span></div>';
    openModal('pveCloneModal');

    // Fetch all data in parallel
    const [nextId, storages, config] = await Promise.all([
        api('pve-nextid'),
        api('pve-storages'),
        api('pve-config&vmid=' + vmid + '&type=' + type)
    ]);
    const newId = nextId.ok && nextId.vmid ? nextId.vmid : '';
    const cfg = config.ok ? config.config : {};
    const cores = cfg.cores || 1;
    const memory = cfg.memory || 2048;
    const swap = cfg.swap || 0;
    const onboot = cfg.onboot || 0;

    // Parse network interfaces
    let netInfo = '';
    for (let i = 0; i < 10; i++) {
        if (cfg['net' + i]) {
            const net = cfg['net' + i];
            const bridge = (net.match(/bridge=([^,]+)/) || [])[1] || '?';
            const ip = (net.match(/ip=([^,]+)/) || [])[1] || 'DHCP';
            netInfo += '<div style="font-size:.62rem;color:var(--text3);font-family:var(--mono)">net' + i + ': ' + bridge + ' &middot; ' + ip + '</div>';
        }
    }

    let storOpts = '<option value="">Wie Quelle</option>';
    if (storages.ok) {
        storages.storages.forEach(s => {
            const free = s.avail > 0 ? ' (' + fmtBytes(s.avail) + ' frei)' : '';
            storOpts += '<option value="' + s.name + '">' + s.name + free + '</option>';
        });
    }

    document.getElementById('pveCloneBody').innerHTML = `
        <div style="padding:8px 12px;background:rgba(255,255,255,.02);border:1px solid var(--border-subtle);border-radius:6px;margin-bottom:14px;display:flex;align-items:center;gap:8px">
            <span style="font-size:.58rem;padding:2px 6px;border-radius:3px;background:${type === 'qemu' ? 'rgba(168,85,247,.08);color:#a855f7' : 'rgba(64,196,255,.08);color:var(--blue)'}">${typeLabel}</span>
            <span style="font-size:.82rem;font-weight:600">${name || 'ID ' + vmid}</span>
            <span style="font-family:var(--mono);font-size:.68rem;color:var(--text3)">${cores} vCPU &middot; ${memory} MB RAM</span>
        </div>
        <input type="hidden" id="pveCloneVmid" value="${vmid}">
        <input type="hidden" id="pveCloneType" value="${type}">

        <div style="display:flex;gap:10px;margin-bottom:12px">
            <div style="flex:1">
                <label class="form-label">Neue VMID</label>
                <input class="form-input" id="pveCloneNewId" type="number" value="${newId}" min="100">
            </div>
            <div style="flex:2">
                <label class="form-label">Name</label>
                <input class="form-input" id="pveCloneName" value="${(name || '') + '-clone'}">
            </div>
        </div>

        <div style="display:flex;gap:8px;margin-bottom:12px">
            <label style="flex:1;display:flex;align-items:center;gap:8px;padding:8px 12px;border:2px solid var(--accent);border-radius:6px;cursor:pointer" id="pveCloneFullLabel">
                <input type="radio" name="pveCloneMode" value="1" checked style="accent-color:var(--accent)" onchange="pveCloneModeChange()">
                <div><div style="font-size:.76rem;font-weight:600">Full Clone</div><div style="font-size:.58rem;color:var(--text3)">Unabhängige Kopie</div></div>
            </label>
            <label style="flex:1;display:flex;align-items:center;gap:8px;padding:8px 12px;border:2px solid var(--border-subtle);border-radius:6px;cursor:pointer" id="pveCloneLinkedLabel">
                <input type="radio" name="pveCloneMode" value="0" style="accent-color:var(--accent)" onchange="pveCloneModeChange()">
                <div><div style="font-size:.76rem;font-weight:600">Linked Clone</div><div style="font-size:.58rem;color:var(--text3)">Schnell, abhängig</div></div>
            </label>
        </div>

        <!-- Hardware Settings -->
        <div style="border:1px solid var(--border-subtle);border-radius:8px;padding:10px 12px;margin-bottom:12px">
            <div style="font-size:.68rem;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">Hardware anpassen</div>
            <div style="display:flex;gap:8px;margin-bottom:8px">
                <div style="flex:1">
                    <label style="font-size:.65rem;color:var(--text3);display:block;margin-bottom:2px">CPU Cores</label>
                    <input class="form-input" id="pveCloneCores" type="number" value="${cores}" min="1" max="128" style="padding:4px 8px;font-size:.75rem">
                </div>
                <div style="flex:1">
                    <label style="font-size:.65rem;color:var(--text3);display:block;margin-bottom:2px">RAM (MB)</label>
                    <input class="form-input" id="pveCloneMemory" type="number" value="${memory}" min="128" step="128" style="padding:4px 8px;font-size:.75rem">
                </div>
                ${type === 'lxc' ? `<div style="flex:1">
                    <label style="font-size:.65rem;color:var(--text3);display:block;margin-bottom:2px">Swap (MB)</label>
                    <input class="form-input" id="pveCloneSwap" type="number" value="${swap}" min="0" step="128" style="padding:4px 8px;font-size:.75rem">
                </div>` : ''}
            </div>
            <div style="display:flex;gap:12px;align-items:center">
                <label style="display:flex;align-items:center;gap:5px;font-size:.72rem;cursor:pointer">
                    <input type="checkbox" id="pveCloneNetDisconnect" style="accent-color:var(--accent);width:13px;height:13px">
                    Netzwerk trennen
                </label>
                <label style="display:flex;align-items:center;gap:5px;font-size:.72rem;cursor:pointer">
                    <input type="checkbox" id="pveCloneAutoStart" style="accent-color:var(--accent);width:13px;height:13px">
                    Nach Clone starten
                </label>
                <label style="display:flex;align-items:center;gap:5px;font-size:.72rem;cursor:pointer">
                    <input type="checkbox" id="pveCloneOnboot" ${onboot ? 'checked' : ''} style="accent-color:var(--accent);width:13px;height:13px">
                    Autostart (Boot)
                </label>
            </div>
            ${netInfo ? '<div style="margin-top:6px;padding-top:6px;border-top:1px solid var(--border-subtle)">' + netInfo + '</div>' : ''}
        </div>

        <div id="pveCloneStorageRow" style="margin-bottom:12px">
            <label class="form-label">Ziel-Storage</label>
            <select class="form-input" id="pveCloneStorage">${storOpts}</select>
        </div>
        <div>
            <label class="form-label">Beschreibung <span style="color:var(--text3);font-size:.55rem">(optional)</span></label>
            <input class="form-input" id="pveCloneDesc" value="Clone von ${name || typeLabel + ' ' + vmid}" style="font-size:.75rem">
        </div>
    `;

    const btn = document.getElementById('pveCloneBtn');
    btn.disabled = false;
    btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>Clone starten';
}

function pveCloneModeChange() {
    const full = document.querySelector('input[name="pveCloneMode"]:checked')?.value === '1';
    document.getElementById('pveCloneStorageRow').style.display = full ? '' : 'none';
    document.getElementById('pveCloneFullLabel').style.borderColor = full ? 'var(--accent)' : 'var(--border-subtle)';
    document.getElementById('pveCloneLinkedLabel').style.borderColor = !full ? 'var(--accent)' : 'var(--border-subtle)';
}

async function pveDoClone() {
    const vmid = document.getElementById('pveCloneVmid').value;
    const type = document.getElementById('pveCloneType').value;
    const newid = document.getElementById('pveCloneNewId').value;
    const name = document.getElementById('pveCloneName').value.trim();
    const full = document.querySelector('input[name="pveCloneMode"]:checked')?.value || '1';
    const storage = document.getElementById('pveCloneStorage')?.value || '';
    const description = document.getElementById('pveCloneDesc')?.value?.trim() || '';
    const cores = document.getElementById('pveCloneCores')?.value || '';
    const memory = document.getElementById('pveCloneMemory')?.value || '';
    const swap = document.getElementById('pveCloneSwap')?.value || '';
    const netDisconnect = document.getElementById('pveCloneNetDisconnect')?.checked ? '1' : '0';
    const autoStart = document.getElementById('pveCloneAutoStart')?.checked;
    const onboot = document.getElementById('pveCloneOnboot')?.checked ? '1' : '0';

    if (!newid || !name) { toast('VMID und Name erforderlich', 'error'); return; }

    const btn = document.getElementById('pveCloneBtn');
    btn.disabled = true;

    // Step 1: Clone
    btn.innerHTML = '<span class="loading-spinner" style="width:12px;height:12px;border-width:1.5px;margin-right:4px"></span>1/3 Cloning...';
    try {
        const res = await api('pve-clone', 'POST', { vmid, type, newid, name, full, storage, description });
        if (!res.ok) {
            toast(res.output || res.error || 'Clone fehlgeschlagen', 'error');
            btn.disabled = false; btn.innerHTML = 'Clone starten';
            return;
        }
        toast('Clone gestartet — warte auf Abschluss...');

        // Step 2: Wait for clone to finish (poll every 3s, max 120s)
        btn.innerHTML = '<span class="loading-spinner" style="width:12px;height:12px;border-width:1.5px;margin-right:4px"></span>2/3 Warte...';
        let ready = false;
        for (let i = 0; i < 40; i++) {
            await new Promise(r => setTimeout(r, 3000));
            const vms = await api('pve-vms');
            if (vms.ok && vms.vms.some(v => v.vmid == newid)) { ready = true; break; }
        }

        if (!ready) {
            toast('Clone laeuft noch im Hintergrund', 'success');
            closeModal('pveCloneModal');
            setTimeout(loadPveVms, 5000);
            return;
        }

        // Step 3: Apply hardware changes
        btn.innerHTML = '<span class="loading-spinner" style="width:12px;height:12px;border-width:1.5px;margin-right:4px"></span>3/3 Config...';
        const cfgRes = await api('pve-setconfig', 'POST', {
            vmid: newid, type, cores, memory, swap, onboot,
            net_disconnect: netDisconnect
        });
        if (cfgRes.ok) {
            toast('Hardware-Config angepasst');
        }

        // Optional: Start
        if (autoStart) {
            const node = _pveNode || 'localhost';
            toast(type === 'qemu' ? 'VM' : 'CT' + ' ' + newid + ' wird gestartet...');
            // Start via pvesh
            await api('pve-control', 'POST', { vmid: newid, type, action: 'start' });
        }

        toast(name + ' (ID ' + newid + ') erfolgreich geclont!');
        closeModal('pveCloneModal');
        setTimeout(loadPveVms, 2000);

    } catch (e) {
        toast('Fehler: ' + e.message, 'error');
        btn.disabled = false; btn.innerHTML = 'Clone starten';
    }
}
