/**
 * FloppyOps Lite PVE — Updates
 * Updates — apt check/upgrade, app version check, repo fix, auto-update settings
 *
 * @requires app.js (api, toast, fmtBytes, pct)
 */

async function loadUpdates() {
    // Repo check
    try {
        const repo = await api('repo-check');
        const banner = document.getElementById('updRepoBanner');
        if (repo.warning) banner.style.display = 'block';
        else banner.style.display = 'none';
    } catch(e) {}

    // App update check
    try {
        const app = await api('update-check');
        const el = document.getElementById('appUpdateInfo');
        if (app.ok) {
            let html = '<div style="display:flex;flex-direction:column;gap:6px">';
            html += '<div style="display:flex;justify-content:space-between"><span style="color:var(--text3)">' + T.installed_label + ':</span><span style="font-family:var(--mono);font-weight:600">v' + app.local_version + '</span></div>';
            html += '<div style="display:flex;justify-content:space-between"><span style="color:var(--text3)">' + T.available_label + ':</span><span style="font-family:var(--mono)">v' + app.remote_version + '</span></div>';
            html += '<div style="display:flex;justify-content:space-between"><span style="color:var(--text3)">' + T.update_method + ':</span><span>' + (app.is_git ? 'Git (git pull)' : 'Download (GitHub)') + '</span></div>';
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

    // System updates
    try {
        const sys = await api('apt-check');
        const listEl = document.getElementById('updList');
        const countEl = document.getElementById('updCount');
        const actionsEl = document.getElementById('updActions');
        const rebootEl = document.getElementById('updRebootBanner');
        if (sys.reboot_required) rebootEl.style.display = 'flex';
        else rebootEl.style.display = 'none';
        const badgeEl = document.getElementById('updCountBadge');
        countEl.textContent = sys.count;
        countEl.style.background = sys.count > 0 ? 'rgba(255,89,0,.15)' : 'rgba(40,167,69,.1)';
        countEl.style.color = sys.count > 0 ? 'var(--accent)' : 'var(--green)';
        if (badgeEl) { badgeEl.textContent = sys.count; badgeEl.style.background = sys.count > 0 ? 'rgba(255,89,0,.12)' : 'rgba(40,167,69,.1)'; badgeEl.style.color = sys.count > 0 ? 'var(--accent)' : 'var(--green)'; }
        if (sys.count === 0) {
            listEl.innerHTML = '<div style="text-align:center;padding:20px;color:var(--green);font-size:.78rem"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:6px;vertical-align:middle"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>System ist aktuell</div>';
            document.getElementById('btnAptUpgrade').style.display = 'none';
        } else {
            let html = '<table class="data-table"><thead><tr><th>' + T.package + '</th><th>' + T.installed_label + '</th><th>' + T.available_label + '</th></tr></thead><tbody>';
            sys.updates.forEach(u => {
                const isPve = u.name.startsWith('proxmox-') || u.name.startsWith('pve-') || u.name.startsWith('ceph');
                html += '<tr><td style="font-family:var(--mono);font-size:.7rem">' + u.name + (isPve ? ' <span style="font-size:.5rem;padding:1px 4px;border-radius:3px;background:rgba(255,89,0,.1);color:var(--accent)">PVE</span>' : '') + '</td>';
                html += '<td style="font-family:var(--mono);font-size:.68rem;color:var(--text3)">' + u.old + '</td>';
                html += '<td style="font-family:var(--mono);font-size:.68rem;color:var(--green)">' + u.new + '</td></tr>';
            });
            html += '</tbody></table>';
            listEl.innerHTML = html;
            document.getElementById('btnAptUpgrade').style.display = '';
        }
    } catch(e) { document.getElementById('updList').innerHTML = '<span style="color:var(--red)">Fehler: ' + e.message + '</span>'; }

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
    btn.disabled = true; btn.innerHTML = '<span class="spinner-small"></span> ' + T.checking;
    try {
        await api('apt-refresh', 'POST');
        await loadUpdates();
    } catch(e) { toast(T.error + ': ' + e.message, 'error'); }
    btn.disabled = false; btn.innerHTML = T.check_btn;
}

async function aptUpgrade() {
    if (!confirm(T.confirm_install_all)) return;
    const btn = document.getElementById('btnAptUpgrade');
    const outEl = document.getElementById('updOutput');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-small"></span> Installiere...';
    outEl.style.display = 'block'; outEl.textContent = T.apt_running;
    try {
        const res = await api('apt-upgrade', 'POST');
        outEl.textContent = res.output + (res.autoremove ? '\n\nautoremove:\n' + res.autoremove : '');
        if (res.ok) toast(T.updates_installed);
        else toast(T.update_failed, 'error');
        await loadUpdates();
    } catch(e) { toast(T.error + ': ' + e.message, 'error'); outEl.textContent = e.message; }
    btn.disabled = false; btn.innerHTML = 'Alle Updates installieren';
}

async function appUpdate() {
    try {
        const res = await api('update-pull', 'POST');
        if (res.ok) { toast(T.update_success_reload); setTimeout(() => location.reload(), 1500); }
        else toast(T.update_failed + ': ' + (res.output || ''), 'error');
    } catch(e) { toast(T.error + ': ' + e.message, 'error'); }
}

async function repoFix() {
    const btn = document.getElementById('btnRepoFix');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-small"></span> Fixe...';
    try {
        const res = await api('repo-fix', 'POST');
        if (res.ok) { toast('Repos korrigiert: ' + res.output.split('\n').join(', ')); await loadUpdates(); }
        else toast(T.error, 'error');
    } catch(e) { toast(T.error + ': ' + e.message, 'error'); }
    btn.disabled = false; btn.innerHTML = 'Repos fixen';
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
        if (res.ok) toast(enabled ? T.app_autoupdate_saved : T.app_autoupdate_disabled);
    } catch(e) { toast(T.error + ': ' + e.message, 'error'); }
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
            toast(enabled ? T.autoupdate_saved : T.autoupdate_disabled);
        }
    } catch(e) { toast(T.error + ': ' + e.message, 'error'); }
}
</script>
