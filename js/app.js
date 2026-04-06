/**
 * FloppyOps Lite PVE — App
 * Core — tab switching, toast notifications, API fetch helper, byte/percent formatters
 */

<script>
const CSRF = '<?= $csrf ?>';
const LANG = '<?= $lang ?>';
const T = <?= json_encode($t, JSON_UNESCAPED_UNICODE) ?>;
// ── Tabs ─────────────────────────────────────────────
function switchTab(tabName) {
    document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    const tab = document.querySelector('.nav-tab[data-tab="' + tabName + '"]');
    const panel = document.getElementById('panel-' + tabName);
    if (tab) tab.classList.add('active');
    if (panel) panel.classList.add('active');
    location.hash = tabName;

    if (tabName === 'vms') loadPveVms();
    if (tabName === 'fail2ban') loadF2b();
    if (tabName === 'nginx') { loadNginx(); loadNginxChecks(); }
    if (tabName === 'zfs') loadZfs();
    if (tabName === 'wireguard') { loadWg(); startWgGraph(); }
    else { stopWgGraph(); }
    if (tabName === 'updates') loadUpdates();
}

document.querySelectorAll('.nav-tab').forEach(tab => {
    tab.addEventListener('click', () => switchTab(tab.dataset.tab));
});


// ── Toast ────────────────────────────────────────────
function toast(msg, type = 'success') {
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.innerHTML = (type === 'success' ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>' : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>') + '<span>' + msg + '</span>';
    document.getElementById('toasts').appendChild(el);
    setTimeout(() => el.remove(), 4000);
}

// ── API Helper ───────────────────────────────────────
async function api(endpoint, method = 'GET', data = null) {
    const opts = { method };
    if (data) {
        const fd = new FormData();
        fd.append('_csrf', CSRF);
        Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        opts.body = fd;
    }
    const res = await fetch('?api=' + endpoint, opts);
    return res.json();
}

// ── Format Helpers ───────────────────────────────────
function fmtBytes(b) {
    if (b < 1073741824) return (b / 1048576).toFixed(0) + ' MB';
    return (b / 1073741824).toFixed(1) + ' GB';
}
function pct(used, total) {
    return total > 0 ? Math.round(used / total * 100) : 0;
}

// ── Dashboard ────────────────────────────────────────
