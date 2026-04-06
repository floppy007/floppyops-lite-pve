/**
 * FloppyOps Lite PVE — Fail2ban
 * Fail2ban — load jails, unban IPs, config editor, log viewer
 *
 * @requires app.js (api, toast, fmtBytes, pct)
 */

async function loadF2b() {
    try {
        const [jails, log] = await Promise.all([api('f2b-jails'), api('f2b-log')]);

        document.getElementById('jailCount').textContent = jails.length;
        const grid = document.getElementById('jailGrid');
        grid.innerHTML = '';

        jails.forEach(j => {
            const bannedHtml = j.banned_ips.length > 0
                ? j.banned_ips.map(ip => `<div class="banned-ip"><span>${ip}</span><button class="unban-btn" title="Entbannen" onclick="unban('${j.name}','${ip}')">&#10005;</button></div>`).join('')
                : '<span style="color:var(--text3);font-size:.78rem">Keine gebannten IPs</span>';

            grid.innerHTML += `
                <div class="jail-card">
                    <div class="jail-header">
                        <div class="jail-name">
                            <span class="tag ${j.banned_current > 0 ? 'tag-red' : 'tag-green'}">${j.banned_current > 0 ? 'AKTIV' : 'OK'}</span>
                            ${j.name}
                        </div>
                        <div class="jail-stats">
                            <span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg> ${j.banned_current} gebannt</span>
                            <span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--yellow)" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg> ${j.failed_current} fehlgeschlagen</span>
                            <span style="color:var(--text3)">Gesamt: ${j.banned_total} Bans / ${j.failed_total} Fails</span>
                        </div>
                    </div>
                    <div class="jail-body">
                        <div class="banned-list">${bannedHtml}</div>
                    </div>
                </div>`;
        });

        // Log
        const logEl = document.getElementById('f2bLog');
        logEl.innerHTML = '';
        log.forEach(line => {
            let hl = line.replace(/&/g, '&amp;').replace(/</g, '&lt;');
            if (hl.includes(' Ban ')) { hl = hl.replace(/( Ban )/, '<span class="log-ban">$1</span>'); }
            else if (hl.includes(' Unban ')) { hl = hl.replace(/( Unban )/, '<span class="log-unban">$1</span>'); }
            else if (hl.includes(' Found ')) { hl = hl.replace(/( Found )/, '<span class="log-found">$1</span>'); }
            // Timestamp
            hl = hl.replace(/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/, '<span class="log-ts">$1</span>');
            logEl.innerHTML += `<div class="log-line">${hl}</div>`;
        });
    } catch (e) { /* load error */ }
}

async function unban(jail, ip) {
    try {
        const res = await api('f2b-unban', 'POST', { jail, ip });
        if (res.ok) {
            toast(`${ip} aus ${jail} entbannt`);
            loadF2b();
            loadStats();
        } else {
            toast(res.error || 'Fehler', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function showF2bConfig(file) {
    try {
        const res = await api('f2b-config&file=' + encodeURIComponent(file));
        if (res.ok) {
            document.getElementById('f2bConfigFile').value = res.file;
            document.getElementById('f2bConfigTitle').textContent = res.file;
            document.getElementById('f2bConfigContent').value = res.content;
            openModal('f2bConfigModal');
        } else {
            toast(res.error || 'Config nicht gefunden', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function saveF2bConfig() {
    const file = document.getElementById('f2bConfigFile').value;
    const content = document.getElementById('f2bConfigContent').value;
    try {
        const res = await api('f2b-save', 'POST', { file, content });
        if (res.ok) {
            toast('Config gespeichert, Fail2ban: ' + res.status);
            closeModal('f2bConfigModal');
            loadF2b();
        } else {
            toast('Fail2ban Restart fehlgeschlagen: ' + (res.output || res.status), 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

// ── Nginx Checks ─────────────────────────────────────
