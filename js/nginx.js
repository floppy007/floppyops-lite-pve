/**
 * FloppyOps Lite PVE — Nginx
 * Nginx Proxy — setup checks, site CRUD, SSL renewal, config editor
 *
 * @requires app.js (api, toast, fmtBytes, pct)
 */

async function loadNginxChecks() {
    try {
        const d = await api('nginx-checks');
        if (!d.ok) return;
        const el = document.getElementById('nginxChecks');
        el.innerHTML = d.checks.map(c => {
            const icon = c.ok
                ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2.5" style="flex-shrink:0"><polyline points="20 6 9 17 4 12"/></svg>'
                : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2.5" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
            let fixBtn = '';
            if (!c.ok && c.fix) {
                if (c.id === 'nat') {
                    fixBtn = '<button class="btn btn-sm btn-green" onclick="nginxApplyFix(\'nat\',{subnet:\'' + (c.nat_subnet||'') + '\',iface:\'' + (c.nat_iface||'') + '\'})" style="padding:2px 8px;font-size:.6rem">Aktivieren</button>';
                } else {
                    fixBtn = '<button class="btn btn-sm btn-green" onclick="nginxApplyFix(\'' + c.id + '\')" style="padding:2px 8px;font-size:.6rem">Fix</button>';
                }
            }
            return '<div style="display:flex;align-items:center;gap:8px;padding:5px 8px;border-radius:5px;background:' + (c.ok ? 'rgba(34,197,94,.03)' : 'rgba(255,61,87,.03)') + ';border:1px solid ' + (c.ok ? 'rgba(34,197,94,.1)' : 'rgba(255,61,87,.1)') + '">' +
                icon +
                '<span style="font-size:.75rem;font-weight:500;flex:1">' + c.label + '</span>' +
                '<span style="font-size:.65rem;font-family:var(--mono);color:' + (c.ok ? 'var(--green)' : 'var(--red)') + '">' + c.value + '</span>' +
                fixBtn +
            '</div>';
        }).join('');
    } catch (e) { /* checks error */ }
}

async function nginxApplyFix(fixId, extra) {
    toast(T.applying_fix);
    try {
        const data = { fix_id: fixId };
        if (extra) Object.assign(data, extra);
        const res = await api('nginx-fix', 'POST', data);
        if (res.ok) {
            toast(res.output || 'Fix angewendet');
            loadNginxChecks();
        } else toast(res.error || 'Fehler', 'error');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

// ── Nginx ────────────────────────────────────────────
let sitesData = [];

async function loadNginx() {
    try {
        sitesData = await api('nginx-sites');
        document.getElementById('siteCount').textContent = sitesData.length;
        const grid = document.getElementById('siteGrid');
        grid.innerHTML = '';

        if (sitesData.length === 0) {
            grid.innerHTML = '<div class="empty">Keine Proxy-Sites konfiguriert</div>';
            return;
        }

        sitesData.forEach((s, i) => {
            const domainTags = s.domains.map(d => `<span class="tag tag-accent">${d}</span>`).join(' ');
            let sslTag = '<span class="tag tag-muted">HTTP</span>';
            let sslInfo = '';
            let renewBtn = '';

            if (s.ssl) {
                if (s.ssl_days_left !== null) {
                    let tagClass = 'tag-green';
                    let statusText = s.ssl_days_left + 'd';
                    if (s.ssl_days_left <= 7) { tagClass = 'tag-red'; }
                    else if (s.ssl_days_left <= 30) { tagClass = 'tag-yellow'; }
                    sslTag = '<span class="tag ' + tagClass + '">SSL ' + statusText + '</span>';
                    sslInfo = '<div style="font-size:.68rem;color:var(--text3);margin-top:2px;font-family:var(--mono)">Ablauf: ' + s.ssl_expiry + '</div>';
                } else {
                    sslTag = '<span class="tag tag-green">SSL</span>';
                }
                const mainDomain = s.domains[0] || '';
                renewBtn = `<button class="btn btn-sm btn-green" onclick="renewCert('${mainDomain}')" title="SSL erneuern"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg></button>`;
            }

            grid.innerHTML += `
                <div class="site-row">
                    <div class="site-domain">
                        ${sslTag}
                        <div>
                            <div class="domains">${domainTags}</div>
                            ${sslInfo}
                        </div>
                    </div>
                    <div class="site-target">${s.target || '<span style="color:var(--text3)">---</span>'}</div>
                    <div class="site-actions">
                        ${renewBtn}
                        <button class="btn btn-sm" onclick="editSite(${i})" title="Bearbeiten">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        <button class="btn btn-sm btn-red" onclick="deleteSite('${s.file}')" title="Löschen">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </div>
                </div>`;
        });
    } catch (e) { /* load error */ }
}

function showAddSite() {
    document.getElementById('newDomain').value = '';
    document.getElementById('newTarget').value = 'http://10.10.10.';
    document.getElementById('newSsl').checked = true;
    openModal('addSiteModal');
}

async function addSite() {
    const domain = document.getElementById('newDomain').value.trim();
    const target = document.getElementById('newTarget').value.trim();
    const ssl = document.getElementById('newSsl').checked ? '1' : '0';

    if (!domain || !target) { toast(T.domain_ip_required, 'error'); return; }

    try {
        const res = await api('nginx-add', 'POST', { domain, target, ssl });
        if (res.ok) {
            toast(res.message || 'Site erstellt');
            closeModal('addSiteModal');
            loadNginx();
            loadStats();
        } else {
            toast(res.error || 'Fehler', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

function editSite(index) {
    const s = sitesData[index];
    document.getElementById('editSiteFile').value = s.file;
    document.getElementById('editSiteTitle').textContent = s.file;
    document.getElementById('editSiteContent').value = s.content;
    openModal('editSiteModal');
}

async function saveSite() {
    const file = document.getElementById('editSiteFile').value;
    const content = document.getElementById('editSiteContent').value;
    try {
        const res = await api('nginx-save', 'POST', { file, content });
        if (res.ok) {
            toast(T.config_saved);
            closeModal('editSiteModal');
            loadNginx();
        } else {
            toast(res.error || 'Fehler', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function deleteSite(file) {
    if (!await appConfirm('Site löschen', 'Site <strong>' + file + '</strong> wirklich löschen?')) return;
    try {
        const res = await api('nginx-delete', 'POST', { file });
        if (res.ok) {
            toast(T.site_deleted);
            loadNginx();
            loadStats();
        } else {
            toast(res.error || 'Fehler', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function renewCert(domain) {
    toast(T.ssl_renewing, 'success');
    try {
        const res = await api('nginx-renew', 'POST', { domain });
        if (res.ok) {
            toast(T.ssl_renewed + ' ' + domain);
            loadNginx();
        } else {
            toast(res.error || res.output || 'Renew fehlgeschlagen', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function reloadNginx() {
    try {
        const res = await api('nginx-reload', 'POST', {});
        toast(res.ok ? 'Nginx neu geladen' : (res.error || 'Fehler'), res.ok ? 'success' : 'error');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

