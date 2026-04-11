/**
 * FloppyOps Lite — Updates
 * Updates — App-Update, Repositories, System-Updates
 */

// ── Init: App-Start ─────────────────────────────────
loadStats();
loadDashboardVms();
setInterval(loadStats, 4000);
setInterval(loadDashboardVms, 30000);
// Restore tab from URL hash (after all functions are defined)
if (location.hash && location.hash.length > 1) {
    const h = location.hash.substring(1);
    // Map old tab names to new grouped tabs + sub-tabs
    const tabMap = { fail2ban: ['security','fail2ban'], firewall: ['security','firewall'], portscan: ['security','portscan'],
        nginx: ['network','nginx'], wireguard: ['network','wireguard'], updates: 'system' };
    if (tabMap[h] && Array.isArray(tabMap[h])) { switchTab(tabMap[h][0]); switchSubTab(tabMap[h][0], tabMap[h][1]); }
    else if (tabMap[h]) { switchTab(tabMap[h]); }
    else if (document.querySelector('.nav-tab[data-tab="' + h + '"]')) { switchTab(h); }
    // Ensure sub-tab data loads for grouped tabs opened via hash
    if (h === 'network') switchSubTab('network', 'wireguard');
}

// ═══ Updates Tab ════════════════════════════════════
async function loadUpdates() {
    // Load all checks in parallel
    const [repo, app, sys] = await Promise.all([
        api('repo-check').catch(() => null),
        api('update-check').catch(() => null),
        api('apt-check').catch(() => null),
    ]);

    // Repo check
    try {
        const banner = document.getElementById('updRepoBanner');
        if (repo && repo.warning) banner.style.display = 'block';
        else banner.style.display = 'none';
    } catch(e) {}

    // App update check
    try {
        const el = document.getElementById('appUpdateInfo');
        if (app.ok) {
            let html = '<div style="display:flex;flex-direction:column;gap:6px">';
            html += '<div style="display:flex;justify-content:space-between"><span style="color:var(--text3)">Installiert:</span><span style="font-family:var(--mono);font-weight:600">v' + app.local_version + '</span></div>';
            html += '<div style="display:flex;justify-content:space-between"><span style="color:var(--text3)">Verfügbar:</span><span style="font-family:var(--mono)">v' + app.remote_version + '</span></div>';
            html += '<div style="display:flex;justify-content:space-between"><span style="color:var(--text3)">Update-Methode:</span><span>' + (app.is_git ? 'Git (git pull)' : 'Download (GitHub)') + '</span></div>';
            if (app.update_available) {
                html += '<div style="margin-top:6px;padding:8px 12px;background:rgba(64,196,255,.06);border:1px solid rgba(64,196,255,.15);border-radius:6px;display:flex;align-items:center;gap:8px">';
                html += '<span style="color:var(--blue);font-weight:600">v' + app.remote_version + ' verfügbar</span>';
                html += '<button class="btn btn-sm btn-accent" onclick="appUpdate()" style="margin-left:auto">Update</button></div>';
            } else {
                html += '<div style="margin-top:4px;display:flex;align-items:center;gap:6px;color:var(--green);font-size:.72rem"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Aktuell</div>';
            }
            html += '</div>';
            el.innerHTML = html;
        } else { el.innerHTML = '<span style="color:var(--text3)">Prüfung fehlgeschlagen</span>'; }
    } catch(e) { document.getElementById('appUpdateInfo').innerHTML = '<span style="color:var(--red)">Fehler</span>'; }

    // System updates — simple status
    try {
        if (!sys) throw new Error('no data');
        const countEl = document.getElementById('updCount');
        const iconEl = document.getElementById('updStatusIcon');
        const textEl = document.getElementById('updStatusText');
        const subEl = document.getElementById('updStatusSub');
        const rebootEl = document.getElementById('updRebootBanner');
        if (sys.reboot_required) rebootEl.style.display = '';
        else rebootEl.style.display = 'none';
        countEl.textContent = sys.count;
        countEl.style.background = sys.count > 0 ? 'rgba(255,89,0,.15)' : 'rgba(40,167,69,.1)';
        countEl.style.color = sys.count > 0 ? 'var(--accent)' : 'var(--green)';
        if (sys.count === 0) {
            iconEl.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
            iconEl.style.background = 'rgba(40,167,69,.1)';
            textEl.textContent = 'System ist aktuell'; textEl.style.color = 'var(--green)';
            subEl.textContent = ''; document.getElementById('btnAptUpgrade').style.display = 'none';
        } else {
            iconEl.innerHTML = '<span style="font-size:1.1rem;font-weight:700;color:var(--accent)">' + sys.count + '</span>';
            iconEl.style.background = 'rgba(255,89,0,.1)';
            textEl.textContent = sys.count + ' Updates verfügbar'; textEl.style.color = 'var(--accent)';
            const pve = sys.updates.filter(u => u.name.startsWith('pve-') || u.name.startsWith('proxmox-') || u.name.startsWith('qemu'));
            subEl.textContent = (pve.length ? pve.length + ' PVE, ' : '') + (sys.count - pve.length) + ' System';
            document.getElementById('btnAptUpgrade').style.display = '';
        }
        // Dashboard update card
        const dashUpd = document.getElementById('sUpdates');
        if (dashUpd) { dashUpd.textContent = sys.count; dashUpd.style.color = sys.count > 0 ? 'var(--accent)' : 'var(--green)'; }
    } catch(e) { const t = document.getElementById('updStatusText'); if(t) t.textContent = 'Fehler'; }

    // Repos
    try {
        const repo = await api('repo-check');
        const el = document.getElementById('repoList');
        const warnEl = document.getElementById('repoWarning');
        const warnText = document.getElementById('repoWarningText');
        const subBadge = document.getElementById('repoSubBadge');
        const addBtn = document.getElementById('btnRepoAddNoSub');

        // Subscription badge
        if (repo.has_subscription) {
            subBadge.style.display = ''; subBadge.textContent = 'Subscription aktiv';
            subBadge.style.background = 'rgba(40,167,69,.1)'; subBadge.style.color = 'var(--green)';
        } else {
            subBadge.style.display = ''; subBadge.textContent = 'Keine Subscription';
            subBadge.style.background = 'rgba(255,193,7,.1)'; subBadge.style.color = 'var(--yellow)';
        }

        // Warnings
        if (repo.enterprise_active && repo.no_sub_active) {
            warnEl.style.display = 'flex';
            warnText.textContent = 'Enterprise und No-Subscription gleichzeitig aktiv - kann zu Konflikten führen. Nur eins aktivieren!';
        } else if (repo.enterprise_active && !repo.has_subscription) {
            warnEl.style.display = 'flex';
            warnText.textContent = 'Enterprise-Repo aktiv ohne Subscription — Updates werden fehlschlagen!';
        } else if (repo.has_subscription && !repo.enterprise_active) {
            warnEl.style.display = 'flex';
            warnText.textContent = 'Subscription vorhanden aber Enterprise-Repo deaktiviert — kein Zugang zu Enterprise-Updates.';
        } else if (!repo.no_sub_active && !repo.enterprise_active) {
            warnEl.style.display = 'flex';
            warnText.textContent = 'Kein PVE-Repository aktiv - keine Proxmox-Updates möglich!';
        } else {
            warnEl.style.display = 'none';
        }

        addBtn.style.display = 'none'; // not needed anymore, standard repos always shown

        function repoRow(r, hasSub) {
            const isEnt = r.components.includes('enterprise');
            const isTest = r.components.includes('pvetest');
            const label = r._label || r.components;
            const desc = r._desc || '';
            const missing = r._missing;

            let html = '<div style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border-subtle)">';
            // Toggle
            html += '<input type="checkbox" ' + (r.active ? 'checked' : '') + ' onchange="repoToggle(\'' + (r.file||'') + '\',this.checked,\'' + r.components + '\')" style="width:16px;height:16px;accent-color:var(--accent);cursor:pointer;flex-shrink:0">';
            // Info
            html += '<div style="flex:1;min-width:0">';
            html += '<div style="font-size:.76rem;font-weight:600;display:flex;align-items:center;gap:6px">' + label;
            if (r._standard) html += ' <span style="font-size:.5rem;padding:1px 5px;border-radius:3px;background:rgba(255,89,0,.08);color:var(--accent)">PVE</span>';
            if (isEnt && !hasSub && r.active) html += ' <span style="font-size:.5rem;padding:1px 5px;border-radius:3px;background:rgba(220,53,69,.1);color:var(--red)">keine Lizenz</span>';
            if (isTest && r.active) html += ' <span style="font-size:.5rem;padding:1px 5px;border-radius:3px;background:rgba(255,193,7,.1);color:var(--yellow)">Vorsicht</span>';
            if (missing) html += ' <span style="font-size:.5rem;padding:1px 5px;border-radius:3px;background:var(--surface-solid);color:var(--text3)">nicht eingerichtet</span>';
            html += '</div>';
            if (desc) html += '<div style="font-size:.64rem;color:var(--text3)">' + desc + '</div>';
            if (r.url && !missing) html += '<div style="font-family:var(--mono);font-size:.58rem;color:var(--text3);margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + r.url + ' ' + r.suite + '</div>';
            html += '</div>';
            if (r.file) html += '<span style="font-size:.54rem;color:var(--text3)">' + r.file + '</span>';
            html += '</div>';
            return html;
        }

        let html = '';
        // PVE Standard Repos
        (repo.pve_repos || []).forEach(r => html += repoRow(r, repo.has_subscription));
        // Other repos (separator)
        const others = repo.other_repos || [];
        if (others.length) {
            html += '<div style="padding:6px 16px;font-size:.64rem;font-weight:600;color:var(--text3);background:rgba(0,0,0,.1)">Weitere Repositories</div>';
            others.forEach(r => { r._label = r.components; r._desc = ''; html += repoRow(r, repo.has_subscription); });
        }
        el.innerHTML = html;
    } catch(e) {}

    // App auto-update status
    try {
        const aau = await api('app-auto-update-status');
        document.getElementById('appAutoUpdateToggle').checked = aau.enabled;
        document.getElementById('appAutoSchedule').style.opacity = aau.enabled ? '1' : '.4';
        document.getElementById('appAutoSchedule').style.pointerEvents = aau.enabled ? '' : 'none';
        if (aau.enabled) {
            document.getElementById('appAutoDay').value = aau.day;
            document.getElementById('appAutoHour').value = aau.hour;
        }
    } catch(e) {}

    // System auto-update status
    try {
        const au = await api('auto-update-status');
        document.getElementById('autoUpdateToggle').checked = au.enabled;
        document.getElementById('autoUpdateSchedule').style.opacity = au.enabled ? '1' : '.4';
        document.getElementById('autoUpdateSchedule').style.pointerEvents = au.enabled ? '' : 'none';
        if (au.enabled) {
            document.getElementById('autoUpdateDay').value = au.day;
            document.getElementById('autoUpdateHour').value = au.hour;
        }
        document.getElementById('autoUpdateTz').textContent = au.timezone || '';
        document.getElementById('autoUpdateStatus').textContent = au.enabled ? (au.day === 0 ? 'täglich' : ['','Mo','Di','Mi','Do','Fr','Sa','So'][au.day]) + ' ' + String(au.hour).padStart(2,'0') + ':00' : '';
    } catch(e) {}
}

async function aptRefresh() {
    const btn = document.getElementById('btnAptRefresh');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-small"></span> Prüfe...';
    try {
        await api('apt-refresh', 'POST');
        await loadUpdates();
    } catch(e) { toast('Fehler: ' + e.message, 'error'); }
    btn.disabled = false; btn.innerHTML = 'Prüfen';
}

async function aptUpgrade() {
    if (!confirm('Alle System-Updates jetzt installieren?')) return;
    const btn = document.getElementById('btnAptUpgrade');
    const outEl = document.getElementById('updOutput');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-small"></span> Installiere...';
    outEl.style.display = 'block'; outEl.textContent = 'apt dist-upgrade läuft...';
    try {
        const res = await api('apt-upgrade', 'POST');
        outEl.textContent = res.output + (res.autoremove ? '\n\nautoremove:\n' + res.autoremove : '');
        if (res.ok) toast('Updates installiert');
        else toast('Update fehlgeschlagen', 'error');
        await loadUpdates();
    } catch(e) { toast('Fehler: ' + e.message, 'error'); outEl.textContent = e.message; }
    btn.disabled = false; btn.innerHTML = 'Alle Updates installieren';
}

async function appUpdate() {
    try {
        const res = await api('update-pull', 'POST');
        if (res.ok) { toast('Update erfolgreich — Seite wird neu geladen'); setTimeout(() => location.reload(), 1500); }
        else toast('Update fehlgeschlagen: ' + (res.output || ''), 'error');
    } catch(e) { toast('Fehler: ' + e.message, 'error'); }
}

async function repoToggle(file, enable, component) {
    try {
        const data = { enable: enable ? '1' : '0' };
        if (file) data.file = file; else data.component = component;
        const res = await api('repo-toggle', 'POST', data);
        if (res.ok) { toast(res.output || (enable ? 'Aktiviert' : 'Deaktiviert')); loadUpdates(); }
        else toast(res.error || 'Fehler', 'error');
    } catch(e) { toast('Fehler: ' + e.message, 'error'); }
}

// Alias fuer HTML onclick="repoFix()"
const repoFix = () => repoAddNoSub();

async function repoAddNoSub() {
    try {
        const res = await api('repo-add-nosub', 'POST');
        if (res.ok) { toast('No-Subscription Repository hinzugefügt'); loadUpdates(); }
        else toast(res.error || 'Fehler', 'error');
    } catch(e) { toast('Fehler: ' + e.message, 'error'); }
}

let _appAutoTimer = null;
function appAutoUpdateChanged() {
    const enabled = document.getElementById('appAutoUpdateToggle').checked;
    document.getElementById('appAutoSchedule').style.opacity = enabled ? '1' : '.4';
    document.getElementById('appAutoSchedule').style.pointerEvents = enabled ? '' : 'none';
    clearTimeout(_appAutoTimer);
    _appAutoTimer = setTimeout(() => saveAppAutoUpdate(), 500);
}

async function saveAppAutoUpdate() {
    const enabled = document.getElementById('appAutoUpdateToggle').checked;
    const day = document.getElementById('appAutoDay').value;
    const hour = document.getElementById('appAutoHour').value;
    try {
        const res = await api('app-auto-update-save', 'POST', { enabled: enabled ? '1' : '0', day, hour });
        if (res.ok) toast(enabled ? 'App Auto-Update gespeichert' : 'App Auto-Update deaktiviert');
    } catch(e) { toast('Fehler: ' + e.message, 'error'); }
}

let _autoUpdateTimer = null;
function autoUpdateChanged() {
    const enabled = document.getElementById('autoUpdateToggle').checked;
    document.getElementById('autoUpdateSchedule').style.opacity = enabled ? '1' : '.4';
    document.getElementById('autoUpdateSchedule').style.pointerEvents = enabled ? '' : 'none';
    // Debounce save
    clearTimeout(_autoUpdateTimer);
    _autoUpdateTimer = setTimeout(() => saveAutoUpdate(), 500);
}

async function saveAutoUpdate() {
    const enabled = document.getElementById('autoUpdateToggle').checked;
    const day = document.getElementById('autoUpdateDay').value;
    const hour = document.getElementById('autoUpdateHour').value;
    try {
        const res = await api('auto-update-save', 'POST', { enabled: enabled ? '1' : '0', day, hour });
        if (res.ok) {
            const dayNames = ['täglich','Mo','Di','Mi','Do','Fr','Sa','So'];
            document.getElementById('autoUpdateStatus').textContent = enabled ? dayNames[res.day] + ' ' + String(res.hour).padStart(2,'0') + ':00' : '';
            toast(enabled ? 'Auto-Update gespeichert' : 'Auto-Update deaktiviert');
        }
    } catch(e) { toast('Fehler: ' + e.message, 'error'); }
}
