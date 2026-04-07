/**
 * FloppyOps Lite — Nginx
 * Nginx — Sites, System-Checks, SSL Health
 */

// ── Nginx System-Checks (IP-Forwarding, NAT, Certbot) ──
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
    } catch (e) {
    }
}

async function nginxApplyFix(fixId, extra) {
    toast('Wende Fix an...');
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

// ── Nginx Sites Verwaltung ──────────────────────────
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
                        <button class="btn btn-sm" onclick="editSite(${i})" title="${T.edit}">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        <button class="btn btn-sm btn-red" onclick="deleteSite('${s.file}')" title="${T.delete}">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </div>
                </div>`;
        });
    } catch (e) {
    }
}

function showAddSite() {
    document.getElementById('newDomain').value = '';
    document.getElementById('newTargetIp').value = '10.10.10.';
    document.getElementById('newTargetPort').value = '80';
    document.getElementById('newSsl').checked = true;
    document.getElementById('newForceSsl').checked = true;
    document.getElementById('newWs').checked = false;
    document.getElementById('newMaxUpload').value = '100';
    document.getElementById('newTimeout').value = '60';
    openModal('addSiteModal');
}

async function addSite() {
    const domain = document.getElementById('newDomain').value.trim();
    const ip = document.getElementById('newTargetIp').value.trim();
    const port = document.getElementById('newTargetPort').value.trim() || '80';
    const ssl = document.getElementById('newSsl').checked ? '1' : '0';
    const forceSsl = document.getElementById('newForceSsl').checked ? '1' : '0';
    const ws = document.getElementById('newWs').checked ? '1' : '0';
    const maxUpload = document.getElementById('newMaxUpload').value;
    const timeout = document.getElementById('newTimeout').value;
    const target = 'http://' + ip + ':' + port;

    if (!domain || !ip) { toast('Domain und IP erforderlich', 'error'); return; }

    try {
        const res = await api('nginx-add', 'POST', { domain, target, ssl, ws, force_ssl: forceSsl, max_upload: maxUpload, timeout });
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
    document.getElementById('editSiteTitle').textContent = s.domains.join(', ') || s.file;
    document.getElementById('editDomains').value = s.domains.join(', ');
    const m = (s.target || '').match(/https?:\/\/([\d.]+):?(\d*)/);
    document.getElementById('editTargetIp').value = m ? m[1] : '';
    document.getElementById('editTargetPort').value = m && m[2] ? m[2] : '80';
    document.getElementById('editWs').checked = /proxy_set_header Upgrade/.test(s.content);
    document.getElementById('editForceSsl').checked = /return 301 https/.test(s.content);
    // Parse max_upload from content
    const uploadM = s.content.match(/client_max_body_size\s+(\d+)m/i);
    document.getElementById('editMaxUpload').value = uploadM ? uploadM[1] : '';
    // Parse timeout from content
    const timeoutM = s.content.match(/proxy_read_timeout\s+(\d+)/);
    document.getElementById('editTimeout').value = timeoutM ? timeoutM[1] : '';
    document.getElementById('editSiteContent').value = s.content;
    document.getElementById('editConfigWrap').style.display = 'none';
    openModal('editSiteModal');
}

async function saveSite() {
    const file = document.getElementById('editSiteFile').value;
    const configWrap = document.getElementById('editConfigWrap');

    if (configWrap.style.display !== 'none') {
        const content = document.getElementById('editSiteContent').value;
        try {
            const res = await api('nginx-save', 'POST', { file, content });
            if (res.ok) { toast('OK'); closeModal('editSiteModal'); loadNginx(); }
            else { toast(res.error || 'Fehler', 'error'); }
        } catch (e) { toast('Fehler: ' + e.message, 'error'); }
        return;
    }

    const domains = document.getElementById('editDomains').value.trim();
    const ip = document.getElementById('editTargetIp').value.trim();
    const port = document.getElementById('editTargetPort').value.trim() || '80';
    const ws = document.getElementById('editWs').checked ? '1' : '0';
    const forceSsl = document.getElementById('editForceSsl').checked ? '1' : '0';
    const maxUpload = document.getElementById('editMaxUpload').value;
    const timeout = document.getElementById('editTimeout').value;

    if (!domains || !ip) { toast('Domain und IP erforderlich', 'error'); return; }

    try {
        const res = await api('nginx-update', 'POST', { file, domains, ip, port, ws, force_ssl: forceSsl, max_upload: maxUpload, timeout });
        if (res.ok) { toast('OK'); closeModal('editSiteModal'); loadNginx(); }
        else { toast(res.error || 'Fehler', 'error'); }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function deleteSite(file) {
    if (!await appConfirm('Site löschen', 'Site <strong>' + file + '</strong> wirklich löschen?')) return;
    try {
        const res = await api('nginx-delete', 'POST', { file });
        if (res.ok) {
            toast('Site gelöscht');
            loadNginx();
            loadStats();
        } else {
            toast(res.error || 'Fehler', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function renewCert(domain) {
    toast('SSL-Zertifikat wird erneuert...', 'success');
    try {
        const res = await api('nginx-renew', 'POST', { domain });
        if (res.ok) {
            toast('Zertifikat für ' + domain + ' erneuert');
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

// ── SSL Health Check ────────────────────────────────
async function loadSslHealth() {
    const el = document.getElementById('sslHealthResult');
    const badge = document.getElementById('sslIssueCount');
    el.innerHTML = `<div style="display:flex;align-items:center;gap:8px;padding:4px 0"><span style="width:14px;height:14px;border:2px solid var(--border-subtle);border-top-color:var(--accent);border-radius:50%;animation:spin .6s linear infinite;flex-shrink:0"></span>${T.ssl_scanning}</div>`;
    badge.style.display = 'none';

    const d = await api('ssl-health');
    if (!d.ok) { el.innerHTML = T.error; return; }

    const results = d.results;
    let issueCount = 0;
    results.forEach(r => {
        if (!r.dns_a || (r.has_aaaa && !r.dns_aaaa) || !r.ssl_valid || !r.cert_match || !r.v4v6_match) issueCount++;
        if (r.issues?.length) issueCount += r.issues.length;
    });

    if (issueCount > 0) {
        badge.textContent = issueCount;
        badge.style.display = '';
    }

    if (results.length === 0) {
        el.innerHTML = `<div style="text-align:center;color:var(--text3)">${T.ssl_all_ok}</div>`;
        return;
    }

    const ok = (v) => v ? `<span style="color:var(--green);font-weight:600">✓</span>` : `<span style="color:var(--red);font-weight:600">✗</span>`;
    const na = `<span style="color:var(--text3)">—</span>`;

    let html = '<table style="width:100%;border-collapse:collapse;font-size:.72rem"><thead><tr style="border-bottom:1px solid var(--border-subtle)">'
        + `<th style="padding:6px 8px;text-align:left;color:var(--text3);font-weight:600">${T.ssl_domain}</th>`
        + `<th style="padding:6px 8px;text-align:center;color:var(--text3);font-weight:600">${T.ssl_dns_v4}</th>`
        + `<th style="padding:6px 8px;text-align:center;color:var(--text3);font-weight:600">${T.ssl_dns_v6}</th>`
        + `<th style="padding:6px 8px;text-align:center;color:var(--text3);font-weight:600">${T.ssl_cert}</th>`
        + `<th style="padding:6px 8px;text-align:center;color:var(--text3);font-weight:600">${T.ssl_cert_match}</th>`
        + `<th style="padding:6px 8px;text-align:center;color:var(--text3);font-weight:600">${T.ssl_v4v6}</th>`
        + `<th style="padding:6px 8px;text-align:center;color:var(--text3);font-weight:600">${T.ssl_expiry}</th>`
        + `<th style="padding:6px 8px;text-align:right;color:var(--text3);font-weight:600">${T.ssl_fix}</th>`
        + '</tr></thead><tbody>';

    results.forEach(r => {
        const hasIssue = !r.dns_a || (r.has_aaaa && !r.dns_aaaa) || !r.ssl_valid || !r.cert_match || !r.v4v6_match || r.issues?.length;
        const rowBg = hasIssue ? 'background:rgba(239,68,68,.04)' : '';

        // DNS tooltips
        const dnsATitle = r.dns_a_ip ? `title="${r.dns_a_ip}"` : '';
        const dnsAAAATitle = r.dns_aaaa_ip ? `title="${r.dns_aaaa_ip}"` : '';

        // Expiry
        let expiryHtml = na;
        if (r.ssl_days !== null) {
            const color = r.ssl_days < 7 ? 'var(--red)' : r.ssl_days < 30 ? 'var(--yellow)' : 'var(--green)';
            expiryHtml = `<span style="color:${color};font-family:var(--mono)">${T.ssl_days.replace('%d', r.ssl_days)}</span>`;
        }

        // Fix buttons
        let fixHtml = '';
        if (r.issues?.includes('ipv6only_on')) {
            fixHtml = `<button class="btn btn-sm btn-yellow" onclick="sslFixIpv6only('${r.file}')" style="padding:1px 6px;font-size:.55rem">${T.ssl_fix_ipv6only}</button>`;
        }

        html += `<tr style="border-bottom:1px solid var(--border-subtle);${rowBg}">
            <td style="padding:5px 8px;font-size:.68rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${r.domain}">${r.domain}</td>
            <td style="padding:5px 8px;text-align:center" ${dnsATitle}>${ok(r.dns_a)}</td>
            <td style="padding:5px 8px;text-align:center" ${dnsAAAATitle}>${r.has_aaaa ? ok(r.dns_aaaa) : na}</td>
            <td style="padding:5px 8px;text-align:center">${ok(r.ssl_valid)}</td>
            <td style="padding:5px 8px;text-align:center">${ok(r.cert_match)}</td>
            <td style="padding:5px 8px;text-align:center">${r.has_aaaa ? ok(r.v4v6_match) : na}</td>
            <td style="padding:5px 8px;text-align:center">${expiryHtml}</td>
            <td style="padding:5px 8px;text-align:right">${fixHtml}</td>
        </tr>`;
    });
    html += '</tbody></table>';

    if (issueCount === 0) {
        html = `<div style="text-align:center;color:var(--green);padding:8px;font-size:.78rem;margin-bottom:8px">✓ ${T.ssl_all_ok}</div>` + html;
    }

    el.innerHTML = html;
}

async function sslFixIpv6only(file) {
    if (!await appConfirm(T.ssl_fix_ipv6only, T.ssl_fix_ipv6only_desc, 'warning')) return;
    const d = await api('ssl-fix-ipv6only', 'POST', { file });
    if (d.ok) loadSslHealth();
}
