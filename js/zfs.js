/**
 * FloppyOps Lite PVE — Zfs
 * ZFS — pools, datasets, snapshots (create/delete/rollback/clone), auto-snapshot config
 *
 * @requires app.js (api, toast, fmtBytes, pct)
 */

function zfsSwitchTab(tab, btn) {
    document.querySelectorAll('#panel-zfs [id^="zfsTab"]').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.zfs-sub').forEach(b => { b.style.background = ''; b.style.color = ''; b.classList.remove('active'); });
    const panel = document.getElementById('zfsTab' + tab.charAt(0).toUpperCase() + tab.slice(1));
    if (panel) panel.style.display = '';
    if (btn) { btn.style.background = 'var(--accent)'; btn.style.color = '#fff'; btn.classList.add('active'); }
}

async function loadZfs() {
    try {
        // Load ZFS + VM names in parallel
        const [d, vmData] = await Promise.all([
            api('zfs-status'),
            _pveVms.length ? Promise.resolve(null) : api('pve-vms')
        ]);
        if (!d.ok) return;
        _zfsData = d;
        if (vmData && vmData.ok) _pveVms = vmData.vms;

        // Pools
        const poolsEl = document.getElementById('zfsPools');
        if (d.pools.length) {
            poolsEl.innerHTML = '<div style="display:flex;gap:10px;flex-wrap:wrap">' + d.pools.map(p => {
                const cap = parseInt(p.cap) || 0;
                const barClass = cap > 85 ? 'red' : cap > 70 ? '' : 'green';
                const hc = p.health === 'ONLINE' ? 'var(--green)' : 'var(--red)';
                return '<div class="stat-card" style="flex:1;min-width:200px">' +
                    '<div class="stat-label"><span class="indicator" style="background:' + hc + ';box-shadow:0 0 6px ' + hc + '"></span>' + p.name + '</div>' +
                    '<div class="stat-value">' + cap + '%</div>' +
                    '<div class="stat-sub">' + fmtBytes(p.alloc) + ' / ' + fmtBytes(p.size) + ' &middot; ' + p.health + (p.frag !== '0' ? ' &middot; Frag: ' + p.frag + '%' : '') + '</div>' +
                    '<div class="progress-wrap"><div class="progress-bar ' + barClass + '" style="width:' + cap + '%"></div></div>' +
                '</div>';
            }).join('') + '</div>';
        } else {
            poolsEl.innerHTML = '';
        }

        // Datasets
        document.getElementById('zfsDsBody').innerHTML = d.datasets.map(ds => {
            const p = pct(ds.used, ds.total);
            const barClass = p > 85 ? 'red' : p > 70 ? '' : 'green';
            const isSubvol = ds.name.includes('subvol-');
            const vmMatch = ds.name.match(/subvol-(\d+)-disk/);
            const vmLabel = vmMatch ? ' <span style="font-size:.58rem;padding:1px 5px;border-radius:3px;background:rgba(64,196,255,.08);color:var(--blue)">CT ' + vmMatch[1] + '</span>' : '';
            return '<tr><td style="font-family:var(--mono);font-size:.75rem">' + ds.name + vmLabel + '</td>' +
                '<td style="font-size:.75rem">' + fmtBytes(ds.used) + '</td>' +
                '<td style="font-size:.75rem">' + fmtBytes(ds.avail) + '</td>' +
                '<td style="font-size:.7rem;color:var(--text3)">' + (ds.mount || '-') + '</td>' +
                '<td><div style="display:flex;align-items:center;gap:6px"><span style="font-family:var(--mono);font-size:.7rem;min-width:28px">' + p + '%</span><div class="progress-wrap" style="flex:1;margin:0"><div class="progress-bar ' + barClass + '" style="width:' + p + '%"></div></div></div></td></tr>';
        }).join('');

        // Auto-Snapshots
        const autoStatus = document.getElementById('zfsAutoStatus');
        const autoBody = document.getElementById('zfsAutoBody');
        if (!d.auto_installed) {
            autoStatus.innerHTML = '<span class="tag tag-red">Nicht installiert</span>';
            autoBody.innerHTML = '<div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:16px;text-align:center">' +
                '<div style="font-size:.8rem;color:var(--text2);margin-bottom:10px">zfs-auto-snapshot ist nicht installiert</div>' +
                '<button class="btn btn-accent" onclick="zfsInstallAuto()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Jetzt installieren</button></div>';
        } else {
            autoStatus.innerHTML = '<span class="tag tag-green">Installiert</span>';
            let autoHtml = '<table class="data-table" style="margin-bottom:10px"><thead><tr><th>Intervall</th><th>Status</th><th>Aufbewahren</th><th>Zeitraum</th></tr></thead><tbody>';
            const intervals = {frequent:'alle 15 Min', hourly:'Stündlich', daily:'Taeglich', weekly:'Woechentlich', monthly:'Monatlich'};
            d.auto_crons.forEach(c => {
                const desc = intervals[c.label] || c.label;
                let timespan = '';
                if (c.label === 'frequent') timespan = c.keep * 15 + ' Min';
                else if (c.label === 'hourly') timespan = c.keep + ' Std';
                else if (c.label === 'daily') timespan = c.keep + ' Tage';
                else if (c.label === 'weekly') timespan = c.keep + ' Wochen';
                else if (c.label === 'monthly') timespan = c.keep + ' Monate';
                autoHtml += '<tr>' +
                    '<td style="font-size:.75rem;font-weight:500">' + desc + ' <span style="font-size:.58rem;color:var(--text3)">(' + c.label + ')</span></td>' +
                    '<td>' + (c.exists ? '<span class="tag tag-green" style="font-size:.46rem">Aktiv</span>' : '<span class="tag tag-muted" style="font-size:.46rem">Aus</span>') + '</td>' +
                    '<td><input type="number" min="1" max="999" value="' + c.keep + '" style="width:60px;font-family:var(--mono);font-size:.72rem;padding:1px 4px;background:var(--surface);border:1px solid var(--border-subtle);border-radius:4px;color:var(--text);text-align:center" onchange="zfsSetRetention(\'' + c.label + '\',this.value)" data-orig="' + c.keep + '"></td>' +
                    '<td style="font-size:.72rem;color:var(--text3)">' + timespan + '</td>' +
                '</tr>';
            });
            autoHtml += '</tbody></table>';

            // Per-dataset toggles
            autoHtml += '<div style="font-size:.65rem;color:var(--text3);margin-bottom:4px">Pro Dataset ein-/ausschalten:</div>';
            autoHtml += '<div style="display:flex;flex-wrap:wrap;gap:4px">';
            d.datasets.forEach(ds => {
                const short = ds.name.split('/').pop();
                const vmMatch = ds.name.match(/subvol-(\d+)/);
                const label = vmMatch ? 'CT ' + vmMatch[1] : short;
                autoHtml += '<label style="display:flex;align-items:center;gap:5px;padding:4px 8px;background:var(--surface);border:1px solid var(--border-subtle);border-radius:4px;cursor:pointer;font-size:.68rem">' +
                    '<input type="checkbox" onchange="zfsToggleAuto(\'' + ds.name + '\',this.checked)" checked style="accent-color:var(--accent);width:13px;height:13px">' +
                    '<span>' + label + '</span></label>';
            });
            autoHtml += '</div>';
            autoBody.innerHTML = autoHtml;
        }

        // Populate filter dropdown
        const filterSel = document.getElementById('zfsSnapFilter');
        const curFilter = filterSel.value;
        filterSel.innerHTML = '<option value="">Alle Datasets</option>';
        const dsNames = [...new Set(d.snapshots.map(s => s.dataset))];
        dsNames.forEach(n => {
            const short = n.includes('subvol-') ? n.match(/subvol-(\d+)/)?.[0] || n : n.split('/').pop();
            filterSel.innerHTML += '<option value="' + n + '"' + (curFilter === n ? ' selected' : '') + '>' + short + '</option>';
        });

        zfsRenderSnaps();
    } catch (e) { /* load error */ }
}

function zfsRenderSnaps() {
    if (!_zfsData) return;
    const sort = document.getElementById('zfsSnapSort')?.value || 'date-desc';
    const filter = document.getElementById('zfsSnapFilter')?.value || '';
    const SHOW_LAST = 5;

    let snaps = [..._zfsData.snapshots];
    if (filter) snaps = snaps.filter(s => s.dataset === filter);

    if (sort === 'date-desc') snaps.sort((a, b) => b.created_ts - a.created_ts);
    else if (sort === 'date-asc') snaps.sort((a, b) => a.created_ts - b.created_ts);
    else if (sort === 'size-desc') snaps.sort((a, b) => b.used - a.used);
    else if (sort === 'name-asc') snaps.sort((a, b) => a.name.localeCompare(b.name));

    document.getElementById('zfsSnapCount').textContent = snaps.length;
    const body = document.getElementById('zfsSnapBody');

    if (!snaps.length) {
        body.innerHTML = '<div class="empty" style="padding:16px">Keine Snapshots' + (filter ? ' für dieses Dataset' : '') + '</div>';
        return;
    }

    // VM/CT name lookup from cached PVE data
    const vmNames = {};
    if (_pveVms && _pveVms.length) {
        _pveVms.forEach(v => { vmNames[String(v.vmid)] = v.name; });
    }

    // Group by dataset
    const groups = {};
    snaps.forEach(s => {
        if (!groups[s.dataset]) groups[s.dataset] = [];
        groups[s.dataset].push(s);
    });

    let html = '';
    Object.entries(groups).forEach(([ds, items], gi) => {
        const vmMatch = ds.match(/subvol-(\d+)/);
        const vmid = vmMatch ? vmMatch[1] : '';
        const vmName = vmid && vmNames[vmid] ? vmNames[vmid] : '';
        const typeLabel = vmMatch ? 'CT' : '';
        const label = vmMatch ? typeLabel + ' ' + vmid : ds.split('/').pop() || ds;
        const totalUsed = items.reduce((sum, s) => sum + s.used, 0);
        const hasMore = items.length > SHOW_LAST;
        const groupId = 'zsg_' + gi;

        // Group header
        html += '<div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:8px;margin-bottom:8px;overflow:hidden">';
        html += '<div style="display:flex;align-items:center;gap:8px;padding:8px 12px;border-bottom:1px solid var(--border-subtle)">' +
            '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" style="flex-shrink:0"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>' +
            '<span style="font-size:.78rem;font-weight:600">' + label + '</span>' +
            (vmName ? '<span style="font-size:.68rem;color:var(--text2)">' + vmName + '</span>' : '') +
            '<span style="font-size:.55rem;padding:1px 6px;border-radius:10px;background:rgba(255,255,255,.04);color:var(--text3);font-family:var(--mono)">' + items.length + '</span>' +
            '<span style="flex:1"></span>' +
            '<span style="font-size:.62rem;color:var(--text3);font-family:var(--mono)">' + fmtBytes(totalUsed) + '</span>' +
        '</div>';

        // Snapshot rows
        html += '<table class="data-table" style="border:none;border-radius:0;margin:0"><tbody>';

        items.forEach((s, si) => {
            const isAuto = s.snap.startsWith('zfs-auto-snap');
            const esc = s.name.replace(/'/g, "\\'");
            const hidden = hasMore && si >= SHOW_LAST;
            const snapShort = isAuto ? s.snap.replace('zfs-auto-snap_', '') : s.snap;

            html += '<tr' + (hidden ? ' class="zsg-hidden-' + groupId + '" style="display:none"' : '') + '>' +
                '<td style="font-family:var(--mono);font-size:.65rem;padding:4px 12px;color:' + (isAuto ? 'var(--text3)' : 'var(--text2)') + '"><span style="color:var(--text3)">@</span>' + snapShort + '</td>' +
                '<td style="font-size:.62rem;color:var(--text3);width:65px">' + fmtBytes(s.used) + '</td>' +
                '<td style="font-size:.6rem;color:var(--text3);width:100px">' + s.created + '</td>' +
                '<td style="text-align:right;padding:2px 8px;width:80px"><div style="display:flex;gap:2px;justify-content:flex-end">' +
                    '<button class="btn btn-sm" onclick="zfsRollback(\'' + esc + '\')" title="Rollback" style="padding:1px 4px;font-size:.5rem"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg></button>' +
                    (s.dataset.match(/subvol-|vm-|base-/) ?
                        '<button class="btn btn-sm btn-green" onclick="zfsSnapCloneVm(\'' + esc + '\')" title="Als VM/CT clonen" style="padding:1px 4px;font-size:.5rem"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg></button>' :
                        '<button class="btn btn-sm" onclick="zfsClone(\'' + esc + '\')" title="Dataset Clone" style="padding:1px 4px;font-size:.5rem"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>') +
                    '<button class="btn btn-sm btn-red" onclick="zfsDeleteSnap(\'' + esc + '\')" title="Löschen" style="padding:1px 4px;font-size:.5rem"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg></button>' +
                '</div></td></tr>';
        });

        html += '</tbody></table>';

        // "Show more" button
        if (hasMore) {
            html += '<div style="text-align:center;padding:4px"><button class="btn btn-sm" style="font-size:.58rem;padding:2px 12px" onclick="document.querySelectorAll(\'.zsg-hidden-' + groupId + '\').forEach(r=>r.style.display=\'\');this.remove()">+ ' + (items.length - SHOW_LAST) + ' weitere anzeigen</button></div>';
        }

        html += '</div>';
    });
    body.innerHTML = html;
}

async function zfsInstallAuto() {
    toast(T.zfs_installing);
    try {
        const res = await api('zfs-install-auto', 'POST', {});
        if (res.ok) { toast('zfs-auto-snapshot installiert'); loadZfs(); }
        else toast(res.output || 'Fehler', 'error');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function zfsToggleAuto(dataset, enabled) {
    try {
        const res = await api('zfs-auto-toggle', 'POST', { dataset, enabled: enabled ? '1' : '0' });
        if (res.ok) toast('Auto-Snapshot ' + (enabled ? 'aktiviert' : 'deaktiviert') + ': ' + dataset.split('/').pop());
        else toast(res.output || 'Fehler', 'error');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function zfsSetRetention(label, value) {
    const keep = parseInt(value);
    if (!keep || keep < 1 || keep > 999) { toast(T.retention_range_error, 'error'); return; }
    try {
        const res = await api('zfs-set-retention', 'POST', { label, keep });
        if (res.ok) toast('Retention ' + label + ' → ' + keep + ' Snapshots');
        else toast(res.error || 'Fehler', 'error');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

function zfsCreateSnapModal() {
    if (!_zfsData || !_zfsData.datasets.length) { toast(T.no_datasets, 'error'); return; }
    const ds = _zfsData.datasets;
    const defaultName = 'manual-' + new Date().toISOString().slice(0,19).replace(/[T:]/g, '-');
    let body = '<div class="form-group"><label class="form-label">Dataset</label>' +
        '<select id="zfsSnapDs" class="form-input" style="font-size:.75rem">' +
        ds.map(d => '<option value="' + d.name + '">' + d.name + '</option>').join('') +
        '</select></div>' +
        '<div class="form-group"><label class="form-label">Snapshot-Name</label>' +
        '<input class="form-input" id="zfsSnapName" value="' + defaultName + '" style="font-family:var(--mono);font-size:.75rem"></div>';

    let modal = document.getElementById('zfsSnapModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'zfsSnapModal';
        modal.className = 'modal-overlay';
        modal.innerHTML = '<div class="modal" style="max-width:450px"><div class="modal-head"><div class="modal-title">Neuer Snapshot</div><button class="modal-close" onclick="closeModal(\'zfsSnapModal\')">&times;</button></div><div class="modal-body" id="zfsSnapModalBody"></div><div class="modal-foot"><button class="btn" onclick="closeModal(\'zfsSnapModal\')">Abbrechen</button><button class="btn btn-accent" onclick="zfsDoSnap()">Erstellen</button></div></div>';
        document.body.appendChild(modal);
    }
    document.getElementById('zfsSnapModalBody').innerHTML = body;
    openModal('zfsSnapModal');
}

async function zfsDoSnap() {
    const dataset = document.getElementById('zfsSnapDs')?.value;
    const name = document.getElementById('zfsSnapName')?.value?.trim();
    if (!dataset || !name) { toast(T.dataset_name_required, 'error'); return; }
    closeModal('zfsSnapModal');
    try {
        const res = await api('zfs-snapshot', 'POST', { dataset, name });
        if (res.ok) { toast('Snapshot erstellt: ' + res.snapshot); loadZfs(); }
        else toast(res.error || res.output || 'Fehler', 'error');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function zfsDeleteSnap(snap) {
    if (!await appConfirm('Snapshot löschen', 'Snapshot <strong>' + snap.split('@')[1] + '</strong> löschen?')) return;
    try {
        const res = await api('zfs-destroy-snap', 'POST', { snapshot: snap });
        if (res.ok) { toast('Snapshot gelöscht'); loadZfs(); }
        else toast(res.output || 'Fehler', 'error');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function zfsRollback(snap) {
    const parts = snap.split('@');
    if (!await appConfirm('Rollback', '<strong>ACHTUNG:</strong> Rollback auf <strong>' + parts[1] + '</strong>?<br><br>Alle neueren Snapshots werden gelöscht!<br>Dataset: <code>' + parts[0] + '</code>')) return;
    try {
        const res = await api('zfs-rollback', 'POST', { snapshot: snap });
        if (res.ok) { toast('Rollback erfolgreich auf ' + parts[1]); loadZfs(); }
        else toast(res.output || 'Fehler', 'error');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function zfsSnapCloneVm(snap) {
    const parts = snap.split('@');
    const dataset = parts[0];
    const snapName = parts[1];
    const vmMatch = dataset.match(/(subvol|vm|base)-(\d+)/);
    const sourceVmid = vmMatch ? vmMatch[2] : '?';
    const isLxc = vmMatch && (vmMatch[1] === 'subvol' || vmMatch[1] === 'base');
    const typeLabel = isLxc ? 'CT' : 'VM';

    // Get next free VMID
    const nextId = await api('pve-nextid');
    const newId = nextId.ok && nextId.vmid ? nextId.vmid : '';

    // Get source config for defaults
    const srcType = isLxc ? 'lxc' : 'qemu';
    const config = await api('pve-config&vmid=' + sourceVmid + '&type=' + srcType);
    const cfg = config.ok ? config.config : {};
    const cores = cfg.cores || 1;
    const memory = cfg.memory || 2048;
    const srcName = cfg.hostname || cfg.name || typeLabel + '-' + sourceVmid;

    let modal = document.getElementById('zfsSnapCloneModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'zfsSnapCloneModal';
        modal.className = 'modal-overlay';
        modal.innerHTML = '<div class="modal" style="max-width:500px"><div class="modal-head"><div class="modal-title" id="zfsSnapCloneTitle"></div><button class="modal-close" onclick="closeModal(\'zfsSnapCloneModal\')">&times;</button></div><div class="modal-body" id="zfsSnapCloneBody"></div><div class="modal-foot"><button class="btn" onclick="closeModal(\'zfsSnapCloneModal\')">Abbrechen</button><button class="btn btn-accent" id="zfsSnapCloneBtn" onclick="zfsSnapCloneSubmit()">Clone starten</button></div></div>';
        document.body.appendChild(modal);
    }

    // Parse network from source config
    let srcIp = '', srcGw = '', srcBridge = '', srcDns = cfg.nameserver || '';
    const net0 = cfg.net0 || '';
    if (net0) {
        srcIp = (net0.match(/ip=([^,]+)/) || [])[1] || '';
        srcGw = (net0.match(/gw=([^,]+)/) || [])[1] || '';
        srcBridge = (net0.match(/bridge=([^,]+)/) || [])[1] || '';
    }

    document.getElementById('zfsSnapCloneTitle').innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" style="margin-right:6px"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>' + typeLabel + ' ' + sourceVmid + ' aus Snapshot clonen';
    document.getElementById('zfsSnapCloneBody').innerHTML = `
        <div style="padding:10px 14px;background:rgba(255,255,255,.02);border:1px solid var(--border-subtle);border-radius:8px;margin-bottom:14px;display:flex;align-items:center;gap:10px">
            <div style="width:36px;height:36px;border-radius:8px;background:${isLxc ? 'rgba(64,196,255,.08)' : 'rgba(168,85,247,.08)'};display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="${isLxc ? 'var(--blue)' : '#a855f7'}" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
            </div>
            <div style="flex:1">
                <div style="font-size:.82rem;font-weight:600">${srcName} <span style="font-size:.62rem;font-weight:400;color:var(--text3)">${typeLabel} ${sourceVmid}</span></div>
                <div style="font-family:var(--mono);font-size:.6rem;color:var(--text3)">@${snapName}</div>
            </div>
        </div>
        <input type="hidden" id="zscSnap" value="${snap}">
        <input type="hidden" id="zscType" value="${srcType}">

        <!-- Basics -->
        <div style="display:flex;gap:10px;margin-bottom:14px">
            <div style="flex:1">
                <label class="form-label">Neue VMID</label>
                <input class="form-input" id="zscNewId" type="number" value="${newId}" min="100">
            </div>
            <div style="flex:2">
                <label class="form-label">${isLxc ? 'Hostname' : 'Name'}</label>
                <input class="form-input" id="zscName" value="${srcName}-clone">
            </div>
        </div>

        <!-- Hardware -->
        <div style="border:1px solid var(--border-subtle);border-radius:8px;padding:12px 14px;margin-bottom:14px">
            <div style="font-size:.65rem;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">Hardware</div>
            <div style="display:flex;gap:10px">
                <div style="flex:1">
                    <label style="font-size:.65rem;color:var(--text3);display:block;margin-bottom:3px">CPU Cores</label>
                    <input class="form-input" id="zscCores" type="number" value="${cores}" min="1" style="padding:5px 8px;font-size:.75rem">
                </div>
                <div style="flex:1">
                    <label style="font-size:.65rem;color:var(--text3);display:block;margin-bottom:3px">RAM (MB)</label>
                    <input class="form-input" id="zscMem" type="number" value="${memory}" min="128" step="128" style="padding:5px 8px;font-size:.75rem">
                </div>
            </div>
        </div>

        <!-- Netzwerk -->
        <div style="border:1px solid var(--border-subtle);border-radius:8px;padding:12px 14px;margin-bottom:14px">
            <div style="font-size:.65rem;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">Netzwerk</div>
            <div style="display:flex;gap:8px;margin-bottom:10px">
                <label style="flex:1;display:flex;align-items:center;gap:8px;padding:6px 10px;border:2px solid var(--accent);border-radius:6px;cursor:pointer;font-size:.72rem" id="zscNetKeepLabel">
                    <input type="radio" name="zscNetMode" value="keep" checked style="accent-color:var(--accent)" onchange="zscNetModeChange()"> Beibehalten
                </label>
                <label style="flex:1;display:flex;align-items:center;gap:8px;padding:6px 10px;border:2px solid var(--border-subtle);border-radius:6px;cursor:pointer;font-size:.72rem" id="zscNetCustomLabel">
                    <input type="radio" name="zscNetMode" value="custom" style="accent-color:var(--accent)" onchange="zscNetModeChange()"> Anpassen
                </label>
                <label style="flex:1;display:flex;align-items:center;gap:8px;padding:6px 10px;border:2px solid var(--border-subtle);border-radius:6px;cursor:pointer;font-size:.72rem" id="zscNetDiscLabel">
                    <input type="radio" name="zscNetMode" value="disconnect" style="accent-color:var(--accent)" onchange="zscNetModeChange()"> Getrennt
                </label>
            </div>
            <div id="zscNetCustomFields" style="display:none">
                <div style="display:flex;gap:8px;margin-bottom:8px">
                    <div style="flex:2">
                        <label style="font-size:.62rem;color:var(--text3);display:block;margin-bottom:2px">IP-Adresse (CIDR)</label>
                        <input class="form-input" id="zscIp" value="${srcIp}" placeholder="10.10.10.200/24" style="padding:4px 8px;font-size:.72rem;font-family:var(--mono)">
                    </div>
                    <div style="flex:1">
                        <label style="font-size:.62rem;color:var(--text3);display:block;margin-bottom:2px">Gateway</label>
                        <input class="form-input" id="zscGw" value="${srcGw}" placeholder="10.10.10.1" style="padding:4px 8px;font-size:.72rem;font-family:var(--mono)">
                    </div>
                </div>
                <div style="display:flex;gap:8px">
                    <div style="flex:1">
                        <label style="font-size:.62rem;color:var(--text3);display:block;margin-bottom:2px">Bridge</label>
                        <input class="form-input" id="zscBridge" value="${srcBridge}" placeholder="vmbr0" style="padding:4px 8px;font-size:.72rem;font-family:var(--mono)">
                    </div>
                    <div style="flex:1">
                        <label style="font-size:.62rem;color:var(--text3);display:block;margin-bottom:2px">DNS</label>
                        <input class="form-input" id="zscDns" value="${srcDns}" placeholder="1.1.1.1" style="padding:4px 8px;font-size:.72rem;font-family:var(--mono)">
                    </div>
                </div>
            </div>
            <div id="zscNetKeepInfo" style="font-family:var(--mono);font-size:.62rem;color:var(--text3)">${srcBridge ? srcBridge + ' &middot; ' + srcIp : 'Keine Netzwerk-Config'}</div>
        </div>

        <!-- Optionen -->
        <div style="display:flex;gap:16px">
            <label style="display:flex;align-items:center;gap:6px;font-size:.75rem;cursor:pointer;padding:6px 10px;background:rgba(255,255,255,.02);border:1px solid var(--border-subtle);border-radius:6px">
                <input type="checkbox" id="zscStart" style="accent-color:var(--accent);width:14px;height:14px"> Nach Clone starten
            </label>
        </div>
    `;

    // Add network mode change handler
    if (!window.zscNetModeChange) {
        window.zscNetModeChange = function() {
            const mode = document.querySelector('input[name="zscNetMode"]:checked')?.value || 'keep';
            document.getElementById('zscNetCustomFields').style.display = mode === 'custom' ? '' : 'none';
            document.getElementById('zscNetKeepInfo').style.display = mode === 'keep' ? '' : 'none';
            document.getElementById('zscNetKeepLabel').style.borderColor = mode === 'keep' ? 'var(--accent)' : 'var(--border-subtle)';
            document.getElementById('zscNetCustomLabel').style.borderColor = mode === 'custom' ? 'var(--accent)' : 'var(--border-subtle)';
            document.getElementById('zscNetDiscLabel').style.borderColor = mode === 'disconnect' ? 'var(--accent)' : 'var(--border-subtle)';
        };
    }

    const btn = document.getElementById('zfsSnapCloneBtn');
    btn.disabled = false; btn.textContent = T.clone_start;
    openModal('zfsSnapCloneModal');
}

async function zfsSnapCloneSubmit() {
    const snap = document.getElementById('zscSnap').value;
    const newVmid = document.getElementById('zscNewId').value;
    const name = document.getElementById('zscName').value.trim();
    const cores = document.getElementById('zscCores').value;
    const memory = document.getElementById('zscMem').value;
    const autoStart = document.getElementById('zscStart').checked ? '1' : '0';
    const netMode = document.querySelector('input[name="zscNetMode"]:checked')?.value || 'keep';

    if (!newVmid || !name) { toast(T.vmid_name_required, 'error'); return; }

    const data = {
        snapshot: snap, new_vmid: newVmid, new_name: name,
        cores, memory, auto_start: autoStart,
        net_disconnect: netMode === 'disconnect' ? '1' : '0',
    };

    // Custom network
    if (netMode === 'custom') {
        data.new_ip = document.getElementById('zscIp')?.value?.trim() || '';
        data.new_gw = document.getElementById('zscGw')?.value?.trim() || '';
        data.new_bridge = document.getElementById('zscBridge')?.value?.trim() || '';
        data.new_dns = document.getElementById('zscDns')?.value?.trim() || '';
    }

    const btn = document.getElementById('zfsSnapCloneBtn');
    btn.disabled = true; btn.innerHTML = '<span class="loading-spinner" style="width:12px;height:12px;border-width:1.5px;margin-right:4px"></span>Cloning...';

    try {
        const res = await api('pve-snap-clone', 'POST', data);
        if (res.ok) {
            toast(res.message || 'Clone erstellt');
            closeModal('zfsSnapCloneModal');
            loadPveVms && loadPveVms();
        } else {
            toast(res.error || T.error, 'error');
            btn.disabled = false; btn.textContent = T.clone_start;
        }
    } catch (e) {
        toast(T.error + ': ' + e.message, 'error');
        btn.disabled = false; btn.textContent = T.clone_start;
    }
}

async function zfsClone(snap) {
    const parts = snap.split('@');
    const pool = parts[0].split('/')[0];
    const defaultTarget = pool + '/clone-' + parts[1].replace(/[^a-zA-Z0-9-]/g, '');
    const target = await appPrompt('ZFS Clone', 'Ziel-Dataset:', defaultTarget);
    if (!target) return;
    try {
        const res = await api('zfs-clone', 'POST', { snapshot: snap, target });
        if (res.ok) { toast('Clone erstellt: ' + target); loadZfs(); }
        else toast(res.output || res.error || 'Fehler', 'error');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

// ── WireGuard Graph (Chart.js) ───────────────────────
const WG_MAX_POINTS = 60;
let wgChart = null;
let wgLastBytes = null;
let wgGraphTimer = null;
let wgPollCount = 0;
