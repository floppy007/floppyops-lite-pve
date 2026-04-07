/**
 * FloppyOps Lite — WireGuard
 * WireGuard — VPN Tunnels, Traffic-Graph, Wizard
 */

// ── WireGuard Live-Traffic Chart ─────────────────────
const WG_MAX_POINTS = 60;
let wgChart = null;
let wgLastBytes = null;
let wgGraphTimer = null;
let wgPollCount = 0;
let wgStatusTimer = null;

function fmtSpeed(b) {
    if (b < 1024) return b.toFixed(0) + ' B/s';
    if (b < 1048576) return (b / 1024).toFixed(1) + ' KB/s';
    return (b / 1048576).toFixed(2) + ' MB/s';
}

function initWgChart() {
    if (wgChart) return;
    const canvas = document.getElementById('wgCanvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

    const rxGrad = ctx.createLinearGradient(0, 0, 0, 100);
    rxGrad.addColorStop(0, 'rgba(0,230,118,.25)');
    rxGrad.addColorStop(1, 'rgba(0,230,118,0)');
    const txGrad = ctx.createLinearGradient(0, 0, 0, 100);
    txGrad.addColorStop(0, 'rgba(64,196,255,.2)');
    txGrad.addColorStop(1, 'rgba(64,196,255,0)');

    wgChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: Array(WG_MAX_POINTS).fill(''),
            datasets: [
                {
                    label: 'RX',
                    data: Array(WG_MAX_POINTS).fill(0),
                    borderColor: '#00e676',
                    backgroundColor: rxGrad,
                    borderWidth: 1.5,
                    fill: true,
                    tension: .35,
                    pointRadius: 0,
                    pointHitRadius: 0,
                },
                {
                    label: 'TX',
                    data: Array(WG_MAX_POINTS).fill(0),
                    borderColor: '#40c4ff',
                    backgroundColor: txGrad,
                    borderWidth: 1.5,
                    fill: true,
                    tension: .35,
                    pointRadius: 0,
                    pointHitRadius: 0,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 400, easing: 'easeOutQuart' },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(17,24,39,.9)',
                    titleColor: '#9aa0a6',
                    bodyColor: '#e8eaed',
                    borderColor: 'rgba(255,255,255,.06)',
                    borderWidth: 1,
                    titleFont: { family: 'JetBrains Mono', size: 10 },
                    bodyFont: { family: 'JetBrains Mono', size: 11 },
                    padding: 8,
                    displayColors: true,
                    callbacks: {
                        title: () => '',
                        label: (c) => c.dataset.label + ': ' + fmtSpeed(c.raw)
                    }
                }
            },
            scales: {
                x: { display: false },
                y: {
                    display: true,
                    position: 'right',
                    beginAtZero: true,
                    grid: { color: 'rgba(255,255,255,.03)', drawBorder: false },
                    border: { display: false },
                    ticks: {
                        color: 'rgba(255,255,255,.2)',
                        font: { family: 'JetBrains Mono', size: 9 },
                        maxTicksLimit: 4,
                        callback: (v) => fmtSpeed(v)
                    }
                }
            }
        }
    });
}

async function pollWgTraffic() {
    try {
        const data = await api('wg-status');
        if (!data.length) return;

        let totalRx = 0, totalTx = 0;
        data.forEach(iface => {
            iface.peers.forEach(p => { totalRx += p.rx_bytes; totalTx += p.tx_bytes; });
        });

        if (wgLastBytes !== null) {
            const elapsed = wgPollCount <= 1 ? 2 : 5;
            const dRx = Math.max(0, totalRx - wgLastBytes.rx) / elapsed;
            const dTx = Math.max(0, totalTx - wgLastBytes.tx) / elapsed;

            document.getElementById('wgGraphRx').textContent = fmtSpeed(dRx);
            document.getElementById('wgGraphTx').textContent = fmtSpeed(dTx);

            if (wgChart) {
                wgChart.data.datasets[0].data.push(dRx);
                wgChart.data.datasets[1].data.push(dTx);
                wgChart.data.labels.push('');
                if (wgChart.data.labels.length > WG_MAX_POINTS) {
                    wgChart.data.labels.shift();
                    wgChart.data.datasets[0].data.shift();
                    wgChart.data.datasets[1].data.shift();
                }
                wgChart.update('none');
                // Re-enable animation after first fast polls
                if (wgPollCount > 2) wgChart.options.animation.duration = 400;
            }
        }
        wgLastBytes = { rx: totalRx, tx: totalTx };
        wgPollCount++;
    } catch (e) {
    }
}

function startWgGraph() {
    if (wgGraphTimer) return;
    initWgChart();
    wgPollCount = 0;
    wgLastBytes = null;
    // Fast start: poll immediately, then at 2s, then every 5s
    pollWgTraffic();
    setTimeout(() => {
        pollWgTraffic();
        wgGraphTimer = setInterval(pollWgTraffic, 5000);
    }, 2000);
    // Auto-refresh peer status every 10s
    if (!wgStatusTimer) {
        wgStatusTimer = setInterval(loadWg, 10000);
    }
}

function stopWgGraph() {
    if (wgGraphTimer) { clearInterval(wgGraphTimer); wgGraphTimer = null; }
    if (wgStatusTimer) { clearInterval(wgStatusTimer); wgStatusTimer = null; }
}

// ── WireGuard Tunnel-Verwaltung ─────────────────────
async function loadWg() {
    try {
        const data = await api('wg-status');
        document.getElementById('wgCount').textContent = data.length;
        const grid = document.getElementById('wgGrid');
        grid.innerHTML = '';

        if (data.length === 0) {
            grid.innerHTML = '<div class="empty">Keine WireGuard-Interfaces gefunden</div>';
            return;
        }

        data.forEach(iface => {
            const statusTag = iface.active
                ? '<span class="tag tag-green">AKTIV</span>'
                : '<span class="tag tag-red">INAKTIV</span>';

            let peersHtml = '';
            if (iface.peers.length > 0) {
                peersHtml = iface.peers.map(p => {
                    let handshakeText = 'Nie';
                    let handshakeTag = 'tag-red';
                    if (p.from_config) {
                        handshakeText = 'Config';
                        handshakeTag = 'tag-muted';
                    } else if (p.handshake_ago !== null) {
                        if (p.handshake_ago < 180) {
                            handshakeText = p.handshake_ago + 's';
                            handshakeTag = 'tag-green';
                        } else if (p.handshake_ago < 600) {
                            handshakeText = Math.round(p.handshake_ago / 60) + 'min';
                            handshakeTag = 'tag-yellow';
                        } else {
                            handshakeText = Math.round(p.handshake_ago / 60) + 'min';
                            handshakeTag = 'tag-red';
                        }
                    }
                    const vpnIp = (p.allowed_ips || '').split(',').map(s => s.trim()).find(s => s.endsWith('/32'))?.replace('/32','')
                        || (p.allowed_ips || '').split(',')[0]?.trim().split('/')[0] || '';
                    return `
                    <div style="display:grid;grid-template-columns:100px 110px 140px 160px 70px 110px 160px auto;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid var(--border-subtle)">
                        <div>
                            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">Peer</div>
                            <div style="font-size:.82rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${p.name || ''}">${p.name || '<span style="color:var(--text3)">---</span>'}</div>
                        </div>
                        <div>
                            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">VPN IP</div>
                            <div style="font-family:var(--mono);font-size:.78rem;color:var(--accent)">${vpnIp || '---'}</div>
                        </div>
                        <div>
                            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">Endpoint</div>
                            <div style="font-family:var(--mono);font-size:.74rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${p.endpoint || ''}">${p.endpoint || '<span style="color:var(--text3)">---</span>'}</div>
                        </div>
                        <div>
                            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">Allowed IPs</div>
                            <div style="font-family:var(--mono);font-size:.74rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${p.allowed_ips}">${p.allowed_ips}</div>
                        </div>
                        <div>
                            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">Status</div>
                            <span class="tag ${handshakeTag}">${handshakeText}</span>
                        </div>
                        <div>
                            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">Transfer</div>
                            <div style="font-family:var(--mono);font-size:.72rem;color:var(--text2)">
                                <span style="color:var(--green)">&darr;</span>${fmtBytes(p.rx_bytes)}
                                <span style="color:var(--blue)">&uarr;</span>${fmtBytes(p.tx_bytes)}
                            </div>
                        </div>
                        <div>
                            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">Public Key</div>
                            <div style="font-family:var(--mono);font-size:.62rem;color:var(--text3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer" title="${p.public_key}" onclick="navigator.clipboard.writeText('${p.public_key}');toast('Key kopiert!')">${p.public_key.substring(0,20)}...</div>
                        </div>
                        <div style="display:flex;gap:4px;justify-content:flex-end">
                            <button class="btn btn-sm" onclick="wgEditPeerOpen('${iface.name}','${p.public_key}')" title="Peer bearbeiten" style="padding:2px 6px">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                            <button class="btn btn-sm" onclick="wgDownloadConf('${iface.name}')" title=".conf" style="padding:2px 6px;font-size:.55rem">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                .conf
                            </button>
                            <button class="btn btn-sm" onclick="wgDownloadPeerScript('${iface.name}')" title=".sh" style="padding:2px 6px;font-size:.55rem">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                .sh
                            </button>
                            <button class="btn btn-sm btn-red" onclick="wgRemovePeer('${iface.name}','${p.public_key}')" title="${T.remove_peer}" style="padding:2px 6px">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                            </button>
                        </div>
                    </div>`;
                }).join('');
            } else {
                peersHtml = '<div style="color:var(--text3);font-size:.82rem;padding:8px 0">Keine Peers konfiguriert</div>';
            }

            const addr = iface.address || '';
            const gateway = addr ? addr.split('/')[0] : '';
            const subnet = addr || '';
            const pubShort = iface.public_key ? iface.public_key.substring(0,16) + '...' : '---';

            grid.innerHTML += `
                <div class="jail-card">
                    <div class="jail-header">
                        <div class="jail-name">
                            ${statusTag}
                            <span style="font-family:var(--mono);font-size:.95rem">${iface.name}</span>
                        </div>
                        <div style="display:flex;gap:6px;align-items:center">
                            <button class="btn btn-sm btn-green" onclick="wgAddPeerOpen('${iface.name}')" title="${T.add_peer}">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                                + Peer
                            </button>
                            <button class="btn btn-sm" onclick="showWgConfig('${iface.name}')" title="${T.show_config}">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                Config
                            </button>
                            <button class="btn btn-sm" onclick="wgShowLogs('${iface.name}')" title="Logs">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                Logs
                            </button>
                            ${iface.active ? `
                                <button class="btn btn-sm btn-red" onclick="wgControl('${iface.name}','stop')">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="6" width="12" height="12" rx="1"/></svg>
                                    Stop
                                </button>
                                <button class="btn btn-sm" onclick="wgControl('${iface.name}','restart')">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                                    Restart
                                </button>
                            ` : `
                                <button class="btn btn-sm btn-green" onclick="wgControl('${iface.name}','start')">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                    Start
                                </button>
                            `}
                        </div>
                    </div>
                    <div style="display:flex;gap:20px;padding:8px 20px;background:rgba(0,0,0,.15);border-bottom:1px solid var(--border-subtle);font-size:.7rem;flex-wrap:wrap">
                        <div><span style="color:var(--text3)">VPN-Netz:</span> <span style="font-family:var(--mono);color:var(--accent)">${subnet || '---'}</span></div>
                        <div><span style="color:var(--text3)">Gateway:</span> <span style="font-family:var(--mono)">${gateway || '---'}</span></div>
                        <div><span style="color:var(--text3)">Port:</span> <span style="font-family:var(--mono)">${iface.listen_port || 'random'}</span></div>
                        <div><span style="color:var(--text3)">Public Key:</span> <span style="font-family:var(--mono);font-size:.62rem;cursor:pointer;color:var(--text3)" title="${iface.public_key || ''}" onclick="navigator.clipboard.writeText('${iface.public_key || ''}');toast('Key kopiert!')">${pubShort}</span></div>
                        <div><span style="color:var(--text3)">Peers:</span> <span style="font-family:var(--mono)">${iface.peers.length}</span></div>
                    </div>
                    <div class="jail-body">
                        ${peersHtml}
                    </div>
                </div>`;
        });

        // Show restart banner if any interface has pending config changes
        const needsRestart = data.find(i => i.needs_restart);
        if (needsRestart) {
            wgShowRestartBanner(needsRestart.name, needsRestart.name + ' — Config geändert seit letztem Start. Restart empfohlen.');
        } else {
            const banner = document.getElementById('wgRestartBanner');
            if (banner) banner.style.display = 'none';
        }
    } catch (e) {
    }
}

async function showWgConfig(iface) {
    try {
        const res = await api('wg-config&iface=' + iface);
        if (!res.ok) { toast(res.error || 'Fehler', 'error'); return; }
        const conf = res.config;
        const get = (key) => { const m = conf.match(new RegExp(key + '\\s*=\\s*(.+)')); return m ? m[1].trim() : ''; };

        document.getElementById('wgEditIfaceTitle').textContent = iface + ' — Interface';
        document.getElementById('wgEditIfaceBody').innerHTML = `
            <input type="hidden" id="wgeiIface" value="${iface}">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Address (Tunnel-IP)</label>
                    <input class="form-input" id="wgeiAddr" value="${get('Address')}" placeholder="10.10.20.1/24">
                </div>
                <div class="form-group">
                    <label class="form-label">ListenPort</label>
                    <input class="form-input" id="wgeiPort" type="number" value="${get('ListenPort')}" placeholder="51820">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">PostUp</label>
                <input class="form-input" id="wgeiPostUp" value="${get('PostUp')}" style="font-size:.7rem" placeholder="iptables Regeln etc.">
            </div>
            <div class="form-group">
                <label class="form-label">PostDown</label>
                <input class="form-input" id="wgeiPostDown" value="${get('PostDown')}" style="font-size:.7rem" placeholder="iptables Cleanup etc.">
            </div>
            <div class="form-group">
                <label class="form-label">Private Key</label>
                <input class="form-input" id="wgeiPriv" value="${get('PrivateKey')}" style="font-size:.68rem" readonly>
                <div class="form-hint">Kann nicht geändert werden (Public Key hängt davon ab)</div>
            </div>
        `;
        openModal('wgEditIfaceModal');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function wgEditIfaceSave() {
    const iface = document.getElementById('wgeiIface').value;
    const addr = document.getElementById('wgeiAddr').value.trim();
    const port = document.getElementById('wgeiPort').value.trim();
    const postUp = document.getElementById('wgeiPostUp').value.trim();
    const postDown = document.getElementById('wgeiPostDown').value.trim();
    const privKey = document.getElementById('wgeiPriv').value.trim();

    if (!addr || !privKey) { toast('Address und Private Key erforderlich', 'error'); return; }

    const btn = document.getElementById('wgEditIfaceSaveBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="loading-spinner" style="width:12px;height:12px;border-width:1.5px"></span>'; }

    try {
        // Read current config, keep all [Peer] blocks, replace [Interface]
        const cur = await api('wg-config&iface=' + iface);
        if (!cur.ok) { toast(cur.error || 'Fehler', 'error'); return; }

        const peers = cur.config.split(/(?=\[Peer\])/i).slice(1).join('');
        let newIface = '[Interface]\n';
        newIface += 'PrivateKey = ' + privKey + '\n';
        newIface += 'Address = ' + addr + '\n';
        if (port) newIface += 'ListenPort = ' + port + '\n';
        if (postUp) newIface += 'PostUp = ' + postUp + '\n';
        if (postDown) newIface += 'PostDown = ' + postDown + '\n';

        const res = await api('wg-save', 'POST', { iface, content: newIface + '\n' + peers });
        if (res.ok) {
            toast('Interface gespeichert');
            closeModal('wgEditIfaceModal');
            loadWg();
            wgShowRestartBanner(iface, iface + ' — Interface geändert. Restart empfohlen.');
        } else {
            toast(res.error || 'Fehler', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
    if (btn) { btn.disabled = false; btn.textContent = 'Speichern'; }
}

async function showWgConfigFull(iface) {
    try {
        const res = await api('wg-config&iface=' + iface);
        if (res.ok) {
            _wgConfigViewIface = '';
            document.getElementById('wgConfigIface').value = iface;
            document.getElementById('wgConfigTitle').textContent = iface + '.conf';
            document.getElementById('wgConfigContent').value = res.config;
            document.getElementById('wgConfigContent').readOnly = false;
            document.getElementById('wgConfigSaveBtn').style.display = '';
            document.getElementById('wgConfigEditBtn').style.display = 'none';
            openModal('wgConfigModal');
        } else {
            toast(res.error || 'Config nicht gefunden', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function saveWgConfig() {
    const iface = document.getElementById('wgConfigIface').value;
    const content = document.getElementById('wgConfigContent').value;
    try {
        const res = await api('wg-save', 'POST', { iface, content });
        if (res.ok) {
            toast('Config gespeichert');
            closeModal('wgConfigModal');
        } else {
            toast(res.error || 'Fehler', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function wgControl(iface, cmd) {
    const labels = { start: 'Starte', stop: 'Stoppe', restart: 'Restarte' };
    toast(labels[cmd] + ' ' + iface + '...', 'success');
    try {
        const res = await api('wg-control', 'POST', { iface, cmd });
        if (res.ok) {
            toast(iface + ' → ' + res.status);
            setTimeout(loadWg, 1000);
        } else {
            toast(res.error || res.output || 'Fehler', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

// ── WireGuard Wizard (Schritt-fuer-Schritt Tunnel-Erstellung) ──
let _wgWizData = {};

async function wgWizardOpen() {
    _wgWizData = {};

    // Generate keys + find next free interface name
    const [keys, ifaces] = await Promise.all([
        api('wg-genkeys'),
        api('wg-list-ifaces')
    ]);

    _wgWizData.privateKey = keys.private_key || '';
    _wgWizData.publicKey = keys.public_key || '';
    _wgWizData.psk = keys.preshared_key || '';

    // Find next free wgN
    const existing = ifaces.interfaces || [];
    let nextNum = 0;
    while (existing.includes('wg' + nextNum)) nextNum++;
    _wgWizData.iface = 'wg' + nextNum;

    // Find next free subnet (10.10.X0.1/24)
    let subnet = 30;
    while (existing.some(e => { try { return false; } catch(x) { return false; } }) && subnet < 250) subnet++;

    wgWizStep1();
    openModal('wgWizardModal');
}

function wgWizStep1() {
    document.getElementById('wgWizardTitle').textContent = 'Schritt 1/3 — Tunnel-Grundlagen';
    document.getElementById('wgWizardBody').innerHTML = `
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Interface-Name</label>
                <input class="form-input" id="wgWizIface" value="${_wgWizData.iface || 'wg1'}" placeholder="wg1">
            </div>
            <div class="form-group">
                <label class="form-label">Listen Port</label>
                <input class="form-input" id="wgWizPort" type="number" value="${_wgWizData.port || ''}" placeholder="51820 (leer = random)">
                <div class="form-hint">Leer lassen wenn dieser Server sich zum Peer verbindet</div>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Tunnel-IP (dieses Servers)</label>
            <input class="form-input" id="wgWizAddr" value="${_wgWizData.address || '10.10.30.1/24'}" placeholder="10.10.30.1/24">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Private Key <span style="font-size:.55rem;color:var(--green)">(auto-generiert)</span></label>
                <input class="form-input" id="wgWizPriv" value="${_wgWizData.privateKey}" style="font-size:.7rem">
            </div>
            <div class="form-group">
                <label class="form-label">Public Key</label>
                <input class="form-input" id="wgWizPub" value="${_wgWizData.publicKey}" readonly style="font-size:.7rem;opacity:.7">
                <div class="form-hint">Wird aus dem Private Key abgeleitet</div>
            </div>
        </div>
        <div style="border:1px solid var(--border-subtle);border-radius:8px;padding:12px 14px">
            <div class="form-label" style="margin-bottom:10px">Firewall-Regeln (PostUp/PostDown)</div>

            <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:10px">
                <label style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:rgba(255,255,255,.02);border:1px solid var(--border-subtle);border-radius:6px;cursor:pointer">
                    <input type="checkbox" id="wgWizIpFwd" onchange="wgWizUpdatePostUp()" ${_wgWizData._ipfwd !== false ? 'checked' : ''} style="accent-color:var(--accent);width:15px;height:15px;flex-shrink:0">
                    <div style="flex:1">
                        <div style="font-size:.76rem;font-weight:500">IP-Forwarding (IPv4 + IPv6)</div>
                    </div>
                </label>

                <label style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:rgba(255,255,255,.02);border:1px solid var(--border-subtle);border-radius:6px;cursor:pointer">
                    <input type="checkbox" id="wgWizNat" onchange="wgWizUpdatePostUp()" ${_wgWizData._nat ? 'checked' : ''} style="accent-color:var(--accent);width:15px;height:15px;flex-shrink:0">
                    <div style="flex:1">
                        <div style="font-size:.76rem;font-weight:500">NAT / Masquerading</div>
                    </div>
                    <select id="wgWizNatIface" onchange="wgWizUpdatePostUp()" style="width:140px;background:var(--surface-solid);border:1px solid var(--border-subtle);border-radius:4px;padding:4px 8px;font-size:.72rem;color:var(--text);flex-shrink:0">
                        <option value="">Laden...</option>
                    </select>
                </label>

                <label style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:rgba(255,255,255,.02);border:1px solid var(--border-subtle);border-radius:6px;cursor:pointer">
                    <input type="checkbox" id="wgWizFwd" onchange="wgWizUpdatePostUp()" ${_wgWizData._fwd ? 'checked' : ''} style="accent-color:var(--accent);width:15px;height:15px;flex-shrink:0">
                    <div style="flex:1">
                        <div style="font-size:.76rem;font-weight:500">Forwarding zu Bridge</div>
                    </div>
                    <select id="wgWizFwdIface" onchange="wgWizUpdatePostUp()" style="width:140px;background:var(--surface-solid);border:1px solid var(--border-subtle);border-radius:4px;padding:4px 8px;font-size:.72rem;color:var(--text);flex-shrink:0">
                        <option value="">Laden...</option>
                    </select>
                </label>
            </div>

            <div id="wgWizPostUpPreview" style="display:none;background:rgba(0,0,0,.2);border:1px solid var(--border-subtle);border-radius:6px;padding:8px 10px">
                <div style="font-size:.6rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px">Generierte Regeln</div>
                <pre id="wgWizPostUpPre" style="font-family:var(--mono);font-size:.68rem;color:var(--text2);margin:0;white-space:pre-wrap;line-height:1.6"></pre>
            </div>
            <input type="hidden" id="wgWizPostUp" value="${_wgWizData.postUp || ''}">
        </div>
    `;
    document.getElementById('wgWizardFoot').innerHTML = `
        <button class="btn" onclick="closeModal('wgWizardModal')">Abbrechen</button>
        <button class="btn btn-accent" onclick="wgWizStep2()">Weiter &rarr;</button>
    `;

    // Load network interfaces for dropdowns
    api('wg-net-ifaces').then(d => {
        if (!d.ok) return;
        const ifaces = d.interfaces || [];
        ['wgWizNatIface', 'wgWizFwdIface'].forEach(selId => {
            const sel = document.getElementById(selId);
            if (!sel) return;
            sel.innerHTML = '';
            ifaces.forEach(i => {
                const label = i.name + (i.ip ? ' (' + i.ip + ')' : '');
                const selected = (selId === 'wgWizNatIface' && (i.name === (_wgWizData._natIface || 'vmbr0') || i.name.startsWith('vmbr0') || i.name.startsWith('eth')))
                    || (selId === 'wgWizFwdIface' && (i.name === (_wgWizData._fwdIface || 'vmbr1') || i.name === 'vmbr1'));
                sel.innerHTML += '<option value="' + i.name + '"' + (selected ? ' selected' : '') + '>' + label + '</option>';
            });
        });
        wgWizUpdatePostUp();
    });
}

function wgWizUpdatePostUp() {
    const nat = document.getElementById('wgWizNat')?.checked;
    const fwd = document.getElementById('wgWizFwd')?.checked;
    const ipfwd = document.getElementById('wgWizIpFwd')?.checked;
    const natIface = document.getElementById('wgWizNatIface')?.value || 'vmbr0';
    const fwdIface = document.getElementById('wgWizFwdIface')?.value || 'vmbr1';

    _wgWizData._nat = nat;
    _wgWizData._fwd = fwd;
    _wgWizData._ipfwd = ipfwd;
    _wgWizData._natIface = natIface;
    _wgWizData._fwdIface = fwdIface;

    let rules = [];
    if (ipfwd) {
        rules.push('echo 1 > /proc/sys/net/ipv4/ip_forward');
        rules.push('echo 1 > /proc/sys/net/ipv6/conf/all/forwarding');
    }
    if (nat) rules.push('iptables -t nat -A POSTROUTING -o ' + natIface + ' -j MASQUERADE');
    if (fwd) {
        rules.push('iptables -A FORWARD -i %i -o ' + fwdIface + ' -j ACCEPT');
        rules.push('iptables -A FORWARD -i ' + fwdIface + ' -o %i -j ACCEPT');
    }

    const joined = rules.join('; ');
    const hidden = document.getElementById('wgWizPostUp');
    if (hidden) hidden.value = joined;

    const preview = document.getElementById('wgWizPostUpPreview');
    const pre = document.getElementById('wgWizPostUpPre');
    if (preview && pre) {
        if (rules.length > 0) {
            preview.style.display = '';
            pre.textContent = rules.join('\n');
        } else {
            preview.style.display = 'none';
        }
    }
}

function wgWizStep2() {
    // Save step 1 values
    _wgWizData.iface = document.getElementById('wgWizIface').value.trim();
    _wgWizData.port = document.getElementById('wgWizPort').value.trim();
    _wgWizData.address = document.getElementById('wgWizAddr').value.trim();
    _wgWizData.privateKey = document.getElementById('wgWizPriv').value.trim();
    _wgWizData.publicKey = document.getElementById('wgWizPub').value.trim();
    _wgWizData.postUp = document.getElementById('wgWizPostUp').value.trim();

    if (!_wgWizData.iface || !_wgWizData.address || !_wgWizData.privateKey) {
        toast('Interface, IP und Private Key erforderlich', 'error');
        return;
    }

    document.getElementById('wgWizardTitle').textContent = 'Schritt 2/3 — Peer (Gegenstelle)';
    document.getElementById('wgWizardBody').innerHTML = `
        <div class="form-group">
            <label class="form-label">Peer Endpoint <span style="font-size:.55rem;color:var(--text3)">(IP:Port der Gegenstelle)</span></label>
            <input class="form-input" id="wgWizPeerEp" value="${_wgWizData.peerEndpoint || ''}" placeholder="203.0.113.1:51820">
            <div class="form-hint">Leer lassen wenn der Peer sich hierher verbindet</div>
        </div>
        <div class="form-group">
            <label class="form-label">Peer Public Key</label>
            <input class="form-input" id="wgWizPeerPub" value="${_wgWizData.peerPublicKey || ''}" placeholder="Public Key der Gegenstelle" style="font-size:.7rem">
            <div class="form-hint">Muss vom Admin der Gegenstelle mitgeteilt werden</div>
        </div>
        <div class="form-group">
            <label class="form-label">Allowed IPs</label>
            <input class="form-input" id="wgWizPeerIps" value="${_wgWizData.peerAllowedIps || '10.10.30.0/24'}" placeholder="10.10.30.0/24, 192.168.1.0/24">
            <div class="form-hint">Netzwerke die ueber den Tunnel erreichbar sein sollen</div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">PresharedKey <span style="font-size:.55rem;color:var(--green)">(auto)</span></label>
                <input class="form-input" id="wgWizPsk" value="${_wgWizData.psk}" style="font-size:.7rem">
            </div>
            <div class="form-group">
                <label class="form-label">Keepalive (Sekunden)</label>
                <input class="form-input" id="wgWizKeepalive" type="number" value="${_wgWizData.keepalive || 25}" placeholder="25">
            </div>
        </div>
        <label class="form-check" style="margin-top:8px">
            <input type="checkbox" id="wgWizAutoStart" checked>
            Tunnel nach Erstellung automatisch starten + beim Boot aktivieren
        </label>
        <label class="form-check" style="margin-top:4px">
            <input type="checkbox" id="wgWizAddFw" checked>
            UDP-Port in PVE-Firewall freigeben (ACCEPT-Regel)
        </label>
    `;
    document.getElementById('wgWizardFoot').innerHTML = `
        <button class="btn" onclick="wgWizStep1()">&larr; Zurück</button>
        <button class="btn btn-accent" onclick="wgWizStep3()">Vorschau &rarr;</button>
    `;
}

function wgWizStep3() {
    // Save step 2
    _wgWizData.peerEndpoint = document.getElementById('wgWizPeerEp').value.trim();
    _wgWizData.peerPublicKey = document.getElementById('wgWizPeerPub').value.trim();
    _wgWizData.peerAllowedIps = document.getElementById('wgWizPeerIps').value.trim();
    _wgWizData.psk = document.getElementById('wgWizPsk').value.trim();
    _wgWizData.keepalive = document.getElementById('wgWizKeepalive').value.trim() || '25';
    _wgWizData.autoStart = document.getElementById('wgWizAutoStart').checked;
    _wgWizData.addFirewall = document.getElementById('wgWizAddFw').checked;

    if (!_wgWizData.peerPublicKey) {
        toast('Peer Public Key erforderlich', 'error');
        return;
    }

    // Build local config preview
    let localConf = '[Interface]\n';
    localConf += 'PrivateKey = ' + _wgWizData.privateKey + '\n';
    localConf += 'Address = ' + _wgWizData.address + '\n';
    if (_wgWizData.port) localConf += 'ListenPort = ' + _wgWizData.port + '\n';
    if (_wgWizData.postUp) {
        localConf += 'PostUp = ' + _wgWizData.postUp + '\n';
        // PostDown: replace -A with -D, remove echo commands
        const postDown = _wgWizData.postUp.split('; ')
            .filter(r => !r.startsWith('echo '))
            .map(r => r.replace(/-A /g, '-D ').replace(/ -A /g, ' -D '))
            .join('; ');
        if (postDown) localConf += 'PostDown = ' + postDown + '\n';
    }
    localConf += '\n[Peer]\n';
    localConf += 'PublicKey = ' + _wgWizData.peerPublicKey + '\n';
    if (_wgWizData.psk) localConf += 'PresharedKey = ' + _wgWizData.psk + '\n';
    if (_wgWizData.peerEndpoint) localConf += 'Endpoint = ' + _wgWizData.peerEndpoint + '\n';
    localConf += 'AllowedIPs = ' + _wgWizData.peerAllowedIps + '\n';
    localConf += 'PersistentKeepalive = ' + _wgWizData.keepalive + '\n';

    // Build remote peer config (what the other side needs to add)
    const localIp = _wgWizData.address.split('/')[0];
    const peerSubnet = _wgWizData.address; // the peer needs to route to our address
    let remoteConf = '# === Auf der Gegenstelle hinzufügen ===\n\n';
    remoteConf += '[Peer]\n';
    remoteConf += '# ' + (_wgWizData.iface) + ' auf diesem Server\n';
    remoteConf += 'PublicKey = ' + _wgWizData.publicKey + '\n';
    if (_wgWizData.psk) remoteConf += 'PresharedKey = ' + _wgWizData.psk + '\n';
    if (_wgWizData.port) remoteConf += 'Endpoint = DEINE-SERVER-IP:' + _wgWizData.port + '\n';
    remoteConf += 'AllowedIPs = ' + localIp + '/32\n';
    remoteConf += 'PersistentKeepalive = ' + _wgWizData.keepalive + '\n';

    document.getElementById('wgWizardTitle').textContent = 'Schritt 3/3 — Vorschau';
    document.getElementById('wgWizardBody').innerHTML = `
        <div style="margin-bottom:12px">
            <div style="font-size:.72rem;font-weight:600;margin-bottom:4px;display:flex;align-items:center;gap:6px">
                <span style="color:var(--green)">&#9679;</span> Lokale Config: /etc/wireguard/${_wgWizData.iface}.conf
            </div>
            <pre style="background:rgba(0,0,0,.3);border:1px solid var(--border-subtle);border-radius:8px;padding:10px 12px;font-family:var(--mono);font-size:.7rem;line-height:1.6;overflow-x:auto;margin:0;color:var(--text2)">${localConf}</pre>
        </div>
        <div>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
                <div style="font-size:.72rem;font-weight:600;display:flex;align-items:center;gap:6px">
                    <span style="color:var(--blue)">&#9679;</span> Remote-Config (für die Gegenstelle)
                </div>
                <div style="display:flex;gap:4px">
                    <button class="btn btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('wgRemoteConf').textContent);toast('Kopiert!')">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    </button>
                    <button class="btn btn-sm btn-green" onclick="wgDownloadFile('${_wgWizData.iface}-remote.conf', document.getElementById('wgRemoteConf').textContent)">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        .conf
                    </button>
                    <button class="btn btn-sm btn-green" onclick="wgDownloadRemoteScript('${_wgWizData.iface}')">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        .sh
                    </button>
                </div>
            </div>
            <pre id="wgRemoteConf" style="background:rgba(64,196,255,.04);border:1px solid rgba(64,196,255,.12);border-radius:8px;padding:10px 12px;font-family:var(--mono);font-size:.7rem;line-height:1.6;overflow-x:auto;margin:0;color:var(--blue)">${remoteConf}</pre>
        </div>
        ${_wgWizData.addFirewall && _wgWizData.port ? `
        <div style="margin-top:12px;padding:8px 12px;background:rgba(0,230,118,.04);border:1px solid rgba(0,230,118,.12);border-radius:6px;font-size:.68rem;color:var(--text2);display:flex;align-items:center;gap:8px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <span>PVE-Firewall: <strong>UDP ${_wgWizData.port}</strong> wird automatisch freigegeben (ACCEPT-Regel)</span>
        </div>` : ''}
    `;
    document.getElementById('wgWizardFoot').innerHTML = `
        <button class="btn" onclick="wgWizStep2()">&larr; Zurück</button>
        <button class="btn btn-accent" id="wgWizCreateBtn" onclick="wgWizCreate()">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            Tunnel erstellen
        </button>
    `;
}

async function wgWizCreate() {
    const btn = document.getElementById('wgWizCreateBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="loading-spinner" style="width:12px;height:12px;border-width:1.5px"></span> Erstelle...'; }

    try {
        const res = await api('wg-create', 'POST', {
            iface: _wgWizData.iface,
            listen_port: _wgWizData.port || '0',
            address: _wgWizData.address,
            private_key: _wgWizData.privateKey,
            peer_public_key: _wgWizData.peerPublicKey,
            peer_endpoint: _wgWizData.peerEndpoint,
            peer_allowed_ips: _wgWizData.peerAllowedIps,
            peer_psk: _wgWizData.psk,
            keepalive: _wgWizData.keepalive,
            post_up: _wgWizData.postUp,
            post_down: _wgWizData.postUp ? _wgWizData.postUp.split('; ').filter(r => !r.startsWith('echo ')).map(r => r.replace(/-A /g, '-D ')).join('; ') : '',
            auto_start: _wgWizData.autoStart ? '1' : '0',
            add_firewall: _wgWizData.addFirewall ? '1' : '0',
        });

        if (res.ok) {
            toast('Tunnel ' + _wgWizData.iface + ' erstellt' + (res.started ? ' und gestartet' : '') + (res.fw_added ? ' + Firewall-Regel' : ''));
            closeModal('wgWizardModal');
            loadWg();
            if (!res.started) {
                wgShowRestartBanner(_wgWizData.iface, _wgWizData.iface + ' wurde erstellt — Tunnel starten?');
            }
        } else {
            toast(res.error || 'Fehler', 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = 'Tunnel erstellen'; }
        }
    } catch (e) {
        toast('Fehler: ' + e.message, 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = 'Tunnel erstellen'; }
    }
}

// ── WireGuard: Restart Banner ────────────────────────
function wgShowRestartBanner(iface, msg) {
    const banner = document.getElementById('wgRestartBanner');
    const msgEl = document.getElementById('wgRestartMsg');
    const btn = document.getElementById('wgRestartBtn');
    if (!banner || !msgEl || !btn) return;
    msgEl.textContent = msg;
    btn.onclick = async () => {
        btn.disabled = true;
        btn.innerHTML = '<span class="loading-spinner" style="width:12px;height:12px;border-width:1.5px"></span>';
        await wgControl(iface, 'restart');
        banner.style.display = 'none';
    };
    banner.style.display = '';
}

// ── WireGuard: Logs ──────────────────────────────────
let _wgLogsIface = '';

async function wgShowLogs(iface) {
    _wgLogsIface = iface;
    document.getElementById('wgLogsTitle').textContent = iface + ' — Logs';
    document.getElementById('wgLogsContent').textContent = 'Laden...';
    openModal('wgLogsModal');
    wgRefreshLogs();
}

async function wgRefreshLogs() {
    const lines = document.getElementById('wgLogsLines')?.value || 50;
    const btn = document.getElementById('wgLogsRefreshBtn');
    if (btn) btn.disabled = true;
    try {
        const res = await api('wg-logs&iface=' + _wgLogsIface + '&lines=' + lines);
        if (res.ok) {
            let output = res.log || 'Keine Logs vorhanden.';
            if (res.dmesg) output += '\n\n── dmesg (WireGuard) ──\n' + res.dmesg;
            const pre = document.getElementById('wgLogsContent');
            pre.textContent = output;
            pre.scrollTop = pre.scrollHeight;
        } else {
            document.getElementById('wgLogsContent').textContent = res.error || 'Fehler';
        }
    } catch (e) {
        document.getElementById('wgLogsContent').textContent = 'Fehler: ' + e.message;
    }
    if (btn) btn.disabled = false;
}

// ── WireGuard: Import Config ─────────────────────────
async function wgImportOpen() {
    // Suggest next free interface name
    const d = await api('wg-list-ifaces');
    const existing = d.interfaces || [];
    let n = 0;
    while (existing.includes('wg' + n)) n++;
    document.getElementById('wgImportIface').value = 'wg' + n;
    document.getElementById('wgImportContent').value = '';
    document.getElementById('wgImportAutoStart').checked = true;
    document.getElementById('wgImportAddFw').checked = false;
    openModal('wgImportModal');
}

function wgImportFileLoad(input) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (e) => {
        document.getElementById('wgImportContent').value = e.target.result;
        // Auto-set interface name from filename
        const name = file.name.replace(/\.conf$/i, '').replace(/[^a-zA-Z0-9]/g, '');
        if (name) document.getElementById('wgImportIface').value = name;
        // Auto-check firewall if ListenPort found
        if (/ListenPort\s*=\s*\d+/.test(e.target.result)) {
            document.getElementById('wgImportAddFw').checked = true;
        }
    };
    reader.readAsText(file);
}

async function wgImportSave() {
    const iface = document.getElementById('wgImportIface').value.trim();
    const content = document.getElementById('wgImportContent').value.trim();
    const autoStart = document.getElementById('wgImportAutoStart').checked;
    const addFw = document.getElementById('wgImportAddFw').checked;

    if (!iface || !content) { toast('Interface-Name und Config erforderlich', 'error'); return; }

    const btn = document.getElementById('wgImportBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="loading-spinner" style="width:12px;height:12px;border-width:1.5px"></span>'; }

    try {
        const res = await api('wg-import', 'POST', {
            iface,
            content,
            auto_start: autoStart ? '1' : '0',
            add_firewall: addFw ? '1' : '0',
        });
        if (res.ok) {
            let msg = 'Config ' + iface + ' importiert';
            if (res.started) msg += ' + gestartet';
            if (res.fw_added) msg += ' + Firewall';
            toast(msg);
            closeModal('wgImportModal');
            loadWg();
            if (!res.started) {
                wgShowRestartBanner(iface, iface + ' importiert — Tunnel starten?');
            }
        } else {
            toast(res.error || 'Fehler', 'error');
            if (btn) { btn.disabled = false; btn.textContent = 'Importieren'; }
        }
    } catch (e) {
        toast('Fehler: ' + e.message, 'error');
        if (btn) { btn.disabled = false; btn.textContent = 'Importieren'; }
    }
}

// ── WireGuard: Download Helper ───────────────────────
function wgDownloadFile(filename, content) {
    const blob = new Blob([content], { type: 'text/plain' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    a.click();
    URL.revokeObjectURL(a.href);
}

function wgDownloadRemoteScript(iface) {
    const conf = document.getElementById('wgRemoteConf')?.textContent
        || document.getElementById('wgPeerConfPre')?.textContent || '';
    if (!conf) { toast('Keine Config vorhanden', 'error'); return; }
    let s = '#!/bin/bash\n';
    s += '# WireGuard Peer Setup — Generated by FloppyOps Lite\n';
    s += 'set -e\n';
    s += 'IFACE="' + iface + '"\n';
    s += 'CONF="/etc/wireguard/${IFACE}.conf"\n\n';
    s += 'if [ "$(id -u)" -ne 0 ]; then echo "Bitte als root ausfuehren!"; exit 1; fi\n\n';
    s += 'EXISTING=$(ls /etc/wireguard/*.conf 2>/dev/null)\n';
    s += 'if [ -n "$EXISTING" ]; then\n';
    s += '    echo ""\n';
    s += '    echo "Bestehende WireGuard-Configs:"\n';
    s += '    for f in $EXISTING; do\n';
    s += '        NAME=$(basename "$f" .conf)\n';
    s += '        STATUS=$(systemctl is-active "wg-quick@${NAME}" 2>/dev/null || echo "inactive")\n';
    s += '        echo "   - ${NAME}.conf  [${STATUS}]"\n';
    s += '    done\n';
    s += '    echo ""\n';
    s += '    if [ -f "$CONF" ]; then\n';
    s += '        read -p "${CONF} existiert. Ueberschreiben? (j/N): " OW\n';
    s += '        if [ "$OW" != "j" ] && [ "$OW" != "J" ]; then echo "Abgebrochen."; exit 0; fi\n';
    s += '        systemctl stop "wg-quick@${IFACE}" 2>/dev/null || true\n';
    s += '    fi\n';
    s += 'fi\n\n';
    s += 'if ! command -v wg &>/dev/null; then\n';
    s += '    echo ">> WireGuard wird installiert..."\n';
    s += '    apt-get update -qq && apt-get install -y -qq wireguard >/dev/null\n';
    s += 'fi\n\n';
    s += 'cat > "$CONF" << \'WGEOF\'\n';
    s += conf + '\n';
    s += 'WGEOF\n\n';
    s += 'chmod 600 "$CONF"\n';
    s += 'systemctl enable "wg-quick@${IFACE}"\n';
    s += 'systemctl start "wg-quick@${IFACE}"\n\n';
    s += 'echo ""\n';
    s += 'echo "WireGuard ${IFACE} konfiguriert und gestartet!"\n';
    s += 'wg show "${IFACE}"\n';
    wgDownloadFile('wg-setup-' + iface + '.sh', s);
}

async function wgEditPeerOpen(iface, pubkey) {
    try {
        const res = await api('wg-config&iface=' + iface);
        if (!res.ok) { toast(res.error || 'Fehler', 'error'); return; }
        // Extract the [Peer] block matching this public key
        const blocks = res.config.split(/(?=\[Peer\])/i);
        let block = '';
        for (const b of blocks) { if (b.includes(pubkey)) { block = b; break; } }

        const get = (key) => { const m = block.match(new RegExp(key + '\\s*=\\s*(.+)')); return m ? m[1].trim() : ''; };
        const nameMatch = block.match(/^#\s*(.+)/m);

        const body = document.getElementById('wgEditPeerBody');
        body.innerHTML = `
            <input type="hidden" id="wgepIface" value="${iface}">
            <input type="hidden" id="wgepOldPub" value="${pubkey}">
            <div class="form-group">
                <label class="form-label">Peer-Name</label>
                <input class="form-input" id="wgepName" value="${nameMatch ? nameMatch[1].trim() : ''}" placeholder="z.B. Laptop, Büro-Router">
            </div>
            <div class="form-group">
                <label class="form-label">Public Key</label>
                <input class="form-input" id="wgepPub" value="${pubkey}" style="font-size:.7rem" readonly>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Endpoint</label>
                    <input class="form-input" id="wgepEndpoint" value="${get('Endpoint')}" placeholder="IP:Port (leer = kein)">
                </div>
                <div class="form-group">
                    <label class="form-label">PersistentKeepalive</label>
                    <input class="form-input" id="wgepKeepalive" type="number" value="${get('PersistentKeepalive') || '25'}">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">AllowedIPs</label>
                <input class="form-input" id="wgepAllowed" value="${get('AllowedIPs')}" placeholder="10.10.30.0/24">
            </div>
            <div class="form-group">
                <label class="form-label">PresharedKey</label>
                <input class="form-input" id="wgepPsk" value="${get('PresharedKey')}" style="font-size:.68rem" placeholder="(optional)">
            </div>
        `;
        document.getElementById('wgEditPeerTitle').textContent = (nameMatch ? nameMatch[1].trim() : 'Peer') + ' bearbeiten';
        openModal('wgEditPeerModal');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function wgEditPeerSave() {
    const iface = document.getElementById('wgepIface').value;
    const oldPub = document.getElementById('wgepOldPub').value;
    const name = document.getElementById('wgepName').value.trim();
    const endpoint = document.getElementById('wgepEndpoint').value.trim();
    const keepalive = document.getElementById('wgepKeepalive').value.trim();
    const allowed = document.getElementById('wgepAllowed').value.trim();
    const psk = document.getElementById('wgepPsk').value.trim();

    if (!allowed) { toast('AllowedIPs erforderlich', 'error'); return; }

    const btn = document.getElementById('wgEditPeerSaveBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="loading-spinner" style="width:12px;height:12px;border-width:1.5px"></span>'; }

    try {
        const res = await api('wg-update-peer', 'POST', {
            iface, public_key: oldPub, name, endpoint, keepalive, allowed_ips: allowed, psk
        });
        if (res.ok) {
            toast('Peer aktualisiert');
            closeModal('wgEditPeerModal');
            loadWg();
            wgShowRestartBanner(iface, iface + ' — Peer geändert. Restart empfohlen.');
        } else {
            toast(res.error || 'Fehler', 'error');
            if (btn) { btn.disabled = false; btn.textContent = 'Speichern'; }
        }
    } catch (e) {
        toast('Fehler: ' + e.message, 'error');
        if (btn) { btn.disabled = false; btn.textContent = 'Speichern'; }
    }
}

async function wgDownloadPeerScript(iface) {
    try {
        const res = await api('wg-config&iface=' + iface);
        if (!res.ok) { toast(res.error || 'Config nicht gefunden', 'error'); return; }
        let s = '#!/bin/bash\n';
        s += '# WireGuard Setup — Generated by FloppyOps Lite\n';
        s += 'set -e\n';
        s += 'IFACE="' + iface + '"\n';
        s += 'CONF="/etc/wireguard/${IFACE}.conf"\n\n';
        s += 'if [ "$(id -u)" -ne 0 ]; then echo "Bitte als root ausfuehren!"; exit 1; fi\n\n';
        s += 'EXISTING=$(ls /etc/wireguard/*.conf 2>/dev/null)\n';
        s += 'if [ -n "$EXISTING" ]; then\n';
        s += '    echo ""\n';
        s += '    echo "Bestehende WireGuard-Configs:"\n';
        s += '    for f in $EXISTING; do\n';
        s += '        NAME=$(basename "$f" .conf)\n';
        s += '        STATUS=$(systemctl is-active "wg-quick@${NAME}" 2>/dev/null || echo "inactive")\n';
        s += '        echo "   - ${NAME}.conf  [${STATUS}]"\n';
        s += '    done\n';
        s += '    echo ""\n';
        s += '    if [ -f "$CONF" ]; then\n';
        s += '        read -p "${CONF} existiert. Ueberschreiben? (j/N): " OW\n';
        s += '        if [ "$OW" != "j" ] && [ "$OW" != "J" ]; then echo "Abgebrochen."; exit 0; fi\n';
        s += '        systemctl stop "wg-quick@${IFACE}" 2>/dev/null || true\n';
        s += '    fi\n';
        s += 'fi\n\n';
        s += 'if ! command -v wg &>/dev/null; then\n';
        s += '    echo ">> WireGuard wird installiert..."\n';
        s += '    apt-get update -qq && apt-get install -y -qq wireguard >/dev/null\n';
        s += 'fi\n\n';
        s += 'cat > "$CONF" << \'WGEOF\'\n';
        s += res.config + '\n';
        s += 'WGEOF\n\n';
        s += 'chmod 600 "$CONF"\n';
        s += 'systemctl enable "wg-quick@${IFACE}"\n';
        s += 'systemctl start "wg-quick@${IFACE}"\n\n';
        s += 'echo ""\n';
        s += 'echo "WireGuard ${IFACE} konfiguriert und gestartet!"\n';
        s += 'wg show "${IFACE}"\n';
        wgDownloadFile('wg-setup-' + iface + '.sh', s);
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function wgDownloadConf(iface) {
    try {
        const res = await api('wg-config&iface=' + iface);
        if (res.ok) {
            wgDownloadFile(iface + '.conf', res.config);
        } else {
            toast(res.error || 'Config nicht gefunden', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

// ── WireGuard: Add Peer Wizard ───────────────────────
let _wgPeerData = {};

async function wgAddPeerOpen(iface) {
    _wgPeerData = { iface };

    // Fetch server info + generate keys in parallel
    const [info, keys] = await Promise.all([
        api('wg-server-info&iface=' + iface),
        api('wg-genkeys')
    ]);

    if (!info.ok) { toast(info.error || 'Server-Info nicht verfügbar', 'error'); return; }

    _wgPeerData.serverPubKey = info.public_key || '';
    _wgPeerData.serverPort = info.listen_port || 0;
    _wgPeerData.serverAddress = info.address || '';
    _wgPeerData.publicIp = info.public_ip || '';
    _wgPeerData.suggestedIp = info.suggested_ip || '';
    _wgPeerData.peerPrivKey = keys.private_key || '';
    _wgPeerData.peerPubKey = keys.public_key || '';
    _wgPeerData.psk = keys.preshared_key || '';

    wgAddPeerStep1();
    openModal('wgAddPeerModal');
}

function _wgSubnetFromAddr(addr) {
    return addr ? addr.replace(/\.\d+\//, '.0/') : '';
}

function wgAddPeerStep1() {
    const d = _wgPeerData;
    document.getElementById('wgAddPeerTitle').textContent = T.step1of2;
    document.getElementById('wgAddPeerBody').innerHTML = `
        <div style="background:rgba(0,230,118,.04);border:1px solid rgba(0,230,118,.12);border-radius:6px;padding:8px 12px;margin-bottom:14px;font-size:.68rem;color:var(--text2)">
            <strong>${d.iface}</strong> &mdash; Server: ${d.serverAddress || '?'} | Port: ${d.serverPort || 'random'}
        </div>
        <div class="form-group">
            <label class="form-label">${T.peer_name}</label>
            <input class="form-input" id="wgPeerName" value="${d.peerName || ''}" placeholder="z.B. Laptop, Büro-Router, Handy">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">${T.peer_tunnel_ip}</label>
                <input class="form-input" id="wgPeerIp" value="${d.peerIp || d.suggestedIp}" placeholder="10.10.30.2/24">
                <div class="form-hint">Tunnel-IP die der Peer bekommt</div>
            </div>
            <div class="form-group">
                <label class="form-label">${T.peer_dns}</label>
                <input class="form-input" id="wgPeerDns" value="${d.peerDns || '1.1.1.1, 8.8.8.8'}" placeholder="1.1.1.1">
                <div class="form-hint">DNS-Server für den Peer (optional)</div>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">${T.peer_routes}</label>
            <input class="form-input" id="wgPeerRoutes" value="${d.peerRoutes || _wgSubnetFromAddr(d.serverAddress)}" placeholder="10.10.30.0/24, 10.10.10.0/24">
            <div class="form-hint">Netzwerke die der Peer über den Tunnel erreichen soll (AllowedIPs in der Peer-Config)</div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">${T.server_endpoint}</label>
                <input class="form-input" id="wgPeerEndpoint" value="${d.peerEndpoint || (d.publicIp ? d.publicIp + ':' + (d.serverPort || 51820) : '')}" placeholder="203.0.113.1:51820">
                <div class="form-hint">Öffentliche IP:Port dieses Servers</div>
            </div>
            <div class="form-group">
                <label class="form-label">${T.keepalive}</label>
                <input class="form-input" id="wgPeerKeepalive" type="number" value="${d.keepalive || 25}" placeholder="25">
            </div>
        </div>
        <div style="border:1px solid var(--border-subtle);border-radius:6px;padding:10px 12px;margin-top:4px">
            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">Keys (auto-generiert)</div>
            <div class="form-row" style="gap:8px">
                <div class="form-group" style="flex:1">
                    <label class="form-label" style="font-size:.62rem">${T.private_key}</label>
                    <input class="form-input" id="wgPeerPrivKey" value="${d.peerPrivKey}" style="font-size:.65rem" readonly>
                </div>
                <div class="form-group" style="flex:1">
                    <label class="form-label" style="font-size:.62rem">${T.public_key}</label>
                    <input class="form-input" id="wgPeerPubKey" value="${d.peerPubKey}" style="font-size:.65rem" readonly>
                </div>
            </div>
        </div>
    `;
    document.getElementById('wgAddPeerFoot').innerHTML = `
        <button class="btn" onclick="closeModal('wgAddPeerModal')">${T.back}</button>
        <button class="btn btn-accent" onclick="wgAddPeerStep2()">${T.next} &rarr;</button>
    `;
}

function wgAddPeerStep2() {
    const d = _wgPeerData;
    d.peerName = document.getElementById('wgPeerName').value.trim();
    d.peerIp = document.getElementById('wgPeerIp').value.trim();
    d.peerDns = document.getElementById('wgPeerDns').value.trim();
    d.peerRoutes = document.getElementById('wgPeerRoutes').value.trim();
    d.peerEndpoint = document.getElementById('wgPeerEndpoint').value.trim();
    d.keepalive = document.getElementById('wgPeerKeepalive').value.trim() || '25';
    d.peerPrivKey = document.getElementById('wgPeerPrivKey').value.trim();
    d.peerPubKey = document.getElementById('wgPeerPubKey').value.trim();

    if (!d.peerIp || !d.peerPubKey) {
        toast('Tunnel-IP und Public Key erforderlich', 'error');
        return;
    }

    // Build peer WireGuard config
    let peerConf = '[Interface]\n';
    if (d.peerName) peerConf += '# ' + d.peerName + '\n';
    peerConf += 'PrivateKey = ' + d.peerPrivKey + '\n';
    peerConf += 'Address = ' + d.peerIp + '\n';
    if (d.peerDns) peerConf += 'DNS = ' + d.peerDns + '\n';
    peerConf += '\n[Peer]\n';
    peerConf += 'PublicKey = ' + d.serverPubKey + '\n';
    if (d.psk) peerConf += 'PresharedKey = ' + d.psk + '\n';
    if (d.peerEndpoint) peerConf += 'Endpoint = ' + d.peerEndpoint + '\n';
    peerConf += 'AllowedIPs = ' + d.peerRoutes + '\n';
    peerConf += 'PersistentKeepalive = ' + d.keepalive + '\n';

    // Build setup script
    const peerIfaceName = d.iface;
    let script = '#!/bin/bash\n';
    script += '# ─── WireGuard Peer Setup ───────────────────────\n';
    script += '# Generated by FloppyOps Lite\n';
    if (d.peerName) script += '# Peer: ' + d.peerName + '\n';
    script += '# Server: ' + d.iface + ' @ ' + (d.peerEndpoint || 'unknown') + '\n';
    script += '# ────────────────────────────────────────────────\n\n';
    script += 'set -e\n';
    script += 'IFACE="' + peerIfaceName + '"\n';
    script += 'CONF="/etc/wireguard/${IFACE}.conf"\n\n';
    script += '# 0. Root-Check\n';
    script += 'if [ "$(id -u)" -ne 0 ]; then echo "Bitte als root ausfuehren!"; exit 1; fi\n\n';
    script += '# 1. Bestehende WireGuard-Configs pruefen\n';
    script += 'EXISTING=$(ls /etc/wireguard/*.conf 2>/dev/null)\n';
    script += 'if [ -n "$EXISTING" ]; then\n';
    script += '    echo ""\n';
    script += '    echo "\u26a0  Bestehende WireGuard-Configs gefunden:"\n';
    script += '    for f in $EXISTING; do\n';
    script += '        NAME=$(basename "$f" .conf)\n';
    script += '        STATUS=$(systemctl is-active "wg-quick@${NAME}" 2>/dev/null || echo "inactive")\n';
    script += '        echo "   - ${NAME}.conf  [${STATUS}]"\n';
    script += '    done\n';
    script += '    echo ""\n';
    script += '    if [ -f "$CONF" ]; then\n';
    script += '        echo "ACHTUNG: ${CONF} existiert bereits!"\n';
    script += '        read -p "Ueberschreiben? (j/N): " OVERWRITE\n';
    script += '        if [ "$OVERWRITE" != "j" ] && [ "$OVERWRITE" != "J" ]; then\n';
    script += '            echo "Abgebrochen."; exit 0\n';
    script += '        fi\n';
    script += '        echo ">> Stoppe bestehenden Tunnel..."\n';
    script += '        systemctl stop "wg-quick@${IFACE}" 2>/dev/null || true\n';
    script += '    fi\n';
    script += 'fi\n\n';
    script += '# 2. WireGuard installieren\n';
    script += 'if ! command -v wg &>/dev/null; then\n';
    script += '    echo ">> WireGuard wird installiert..."\n';
    script += '    apt-get update -qq && apt-get install -y -qq wireguard >/dev/null\n';
    script += 'fi\n\n';
    script += '# 3. Config schreiben\n';
    script += 'cat > "$CONF" << \'WGEOF\'\n';
    script += peerConf;
    script += 'WGEOF\n\n';
    script += 'chmod 600 "$CONF"\n\n';
    script += '# 4. Tunnel starten + Autostart\n';
    script += 'systemctl enable "wg-quick@${IFACE}"\n';
    script += 'systemctl start "wg-quick@${IFACE}"\n\n';
    script += 'echo ""\n';
    script += 'echo "\u2713 WireGuard Peer konfiguriert und gestartet!"\n';
    script += 'echo "  Interface: ${IFACE}"\n';
    script += 'echo "  Tunnel-IP: ' + d.peerIp + '"\n';
    script += 'echo ""\n';
    script += 'wg show "${IFACE}"\n';

    // Server-side allowed IPs: just the peer's tunnel IP /32
    const peerIpOnly = d.peerIp.split('/')[0];
    d._serverAllowedIps = peerIpOnly + '/32';

    _wgPeerData._peerConf = peerConf;
    _wgPeerData._script = script;

    document.getElementById('wgAddPeerTitle').textContent = T.step2of2;
    document.getElementById('wgAddPeerBody').innerHTML = `
        <div style="margin-bottom:14px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
                <div style="font-size:.72rem;font-weight:600;display:flex;align-items:center;gap:6px">
                    <span style="color:var(--green)">&#9679;</span> ${T.peer_config}: ${peerIfaceName}.conf
                </div>
                <div style="display:flex;gap:4px">
                    <button class="btn btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('wgPeerConfPre').textContent);toast('${T.copy_config}!')">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    </button>
                    <button class="btn btn-sm btn-green" onclick="wgDownloadFile('${peerIfaceName}.conf', document.getElementById('wgPeerConfPre').textContent)">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        .conf
                    </button>
                </div>
            </div>
            <pre id="wgPeerConfPre" style="background:rgba(0,0,0,.3);border:1px solid var(--border-subtle);border-radius:8px;padding:10px 12px;font-family:var(--mono);font-size:.68rem;line-height:1.6;overflow-x:auto;margin:0;color:var(--text2);max-height:180px">${peerConf}</pre>
        </div>
        <div>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
                <div style="font-size:.72rem;font-weight:600;display:flex;align-items:center;gap:6px">
                    <span style="color:var(--blue)">&#9679;</span> ${T.setup_script}
                </div>
                <div style="display:flex;gap:4px">
                    <button class="btn btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('wgScriptPre').textContent);toast('${T.copy_script}!')">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    </button>
                    <button class="btn btn-sm btn-green" onclick="wgDownloadFile('wg-peer-setup.sh', document.getElementById('wgScriptPre').textContent)">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        .sh
                    </button>
                </div>
            </div>
            <pre id="wgScriptPre" style="background:rgba(64,196,255,.04);border:1px solid rgba(64,196,255,.12);border-radius:8px;padding:10px 12px;font-family:var(--mono);font-size:.65rem;line-height:1.5;overflow-x:auto;margin:0;color:var(--blue);max-height:220px">${script}</pre>
            <div style="font-size:.62rem;color:var(--text3);margin-top:6px">${T.setup_script_hint}</div>
        </div>
    `;
    document.getElementById('wgAddPeerFoot').innerHTML = `
        <button class="btn" onclick="wgAddPeerStep1()">&larr; ${T.back}</button>
        <button class="btn btn-accent" id="wgAddPeerBtn" onclick="wgAddPeerCreate()">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
            ${T.add_peer}
        </button>
    `;
}

async function wgAddPeerCreate() {
    const d = _wgPeerData;
    const btn = document.getElementById('wgAddPeerBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="loading-spinner" style="width:12px;height:12px;border-width:1.5px"></span>'; }

    try {
        const res = await api('wg-add-peer', 'POST', {
            iface: d.iface,
            peer_public_key: d.peerPubKey,
            peer_name: d.peerName || '',
            allowed_ips: d._serverAllowedIps,
            psk: d.psk,
            keepalive: d.keepalive,
        });

        if (res.ok) {
            toast(T.peer_added + (res.live ? ' (live)' : ''));
            closeModal('wgAddPeerModal');
            loadWg();
            wgShowRestartBanner(d.iface, d.iface + ' — Peer hinzugefügt. Restart empfohlen.');
        } else {
            toast(res.error || 'Fehler', 'error');
            if (btn) { btn.disabled = false; btn.textContent = T.add_peer; }
        }
    } catch (e) {
        toast('Fehler: ' + e.message, 'error');
        if (btn) { btn.disabled = false; btn.textContent = T.add_peer; }
    }
}

async function wgRemovePeer(iface, pubkey) {
    if (!confirm(T.confirm_remove_peer)) return;
    try {
        const res = await api('wg-remove-peer', 'POST', { iface, public_key: pubkey });
        if (res.ok) {
            toast(T.peer_removed);
            loadWg();
        } else {
            toast(res.error || 'Fehler', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}
