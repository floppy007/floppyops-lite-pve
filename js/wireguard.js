/**
 * FloppyOps Lite PVE — Wireguard
 * WireGuard — tunnel status, live traffic chart, config editor, 3-step wizard
 *
 * @requires app.js (api, toast, fmtBytes, pct)
 */

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
    } catch (e) { /* poll error */ }
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
}

function stopWgGraph() {
    if (wgGraphTimer) { clearInterval(wgGraphTimer); wgGraphTimer = null; }
}

// ── WireGuard ────────────────────────────────────────
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
                    if (p.handshake_ago !== null) {
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
                    return `
                    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:10px 0;border-bottom:1px solid var(--border-subtle)">
                        <div style="min-width:200px">
                            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">Endpoint</div>
                            <div style="font-family:var(--mono);font-size:.82rem">${p.endpoint || '<span style="color:var(--text3)">---</span>'}</div>
                        </div>
                        <div style="min-width:200px">
                            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">Allowed IPs</div>
                            <div style="font-family:var(--mono);font-size:.82rem">${p.allowed_ips}</div>
                        </div>
                        <div>
                            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">Handshake</div>
                            <span class="tag ${handshakeTag}">${handshakeText}</span>
                        </div>
                        <div>
                            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">Transfer</div>
                            <div style="font-family:var(--mono);font-size:.78rem;color:var(--text2)">
                                <span style="color:var(--green)">&darr;</span> ${fmtBytes(p.rx_bytes)}
                                &nbsp;
                                <span style="color:var(--blue)">&uarr;</span> ${fmtBytes(p.tx_bytes)}
                            </div>
                        </div>
                        <div>
                            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">Public Key</div>
                            <div style="font-family:var(--mono);font-size:.68rem;color:var(--text3);max-width:180px;overflow:hidden;text-overflow:ellipsis" title="${p.public_key}">${p.public_key.substring(0,20)}...</div>
                        </div>
                    </div>`;
                }).join('');
            } else {
                peersHtml = '<div style="color:var(--text3);font-size:.82rem;padding:8px 0">Keine Peers konfiguriert</div>';
            }

            grid.innerHTML += `
                <div class="jail-card">
                    <div class="jail-header">
                        <div class="jail-name">
                            ${statusTag}
                            <span style="font-family:var(--mono);font-size:.95rem">${iface.name}</span>
                            ${iface.listen_port ? '<span class="tag tag-muted">:' + iface.listen_port + '</span>' : ''}
                        </div>
                        <div style="display:flex;gap:6px;align-items:center">
                            <button class="btn btn-sm" onclick="showWgConfig('${iface.name}')" title="Config anzeigen">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                Config
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
                    <div class="jail-body">
                        ${peersHtml}
                    </div>
                </div>`;
        });
    } catch (e) { /* load error */ }
}

async function showWgConfig(iface) {
    try {
        const res = await api('wg-config&iface=' + iface);
        if (res.ok) {
            document.getElementById('wgConfigIface').value = iface;
            document.getElementById('wgConfigTitle').textContent = iface + '.conf';
            document.getElementById('wgConfigContent').value = res.config;
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

// ── WireGuard Wizard ─────────────────────────────────
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
    document.getElementById('wgWizardTitle').textContent = T.wg_step1_title;
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

    document.getElementById('wgWizardTitle').textContent = T.wg_step2_title;
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
    let remoteConf = '# === Add on the remote side ===\n\n';
    remoteConf += '[Peer]\n';
    remoteConf += '# ' + (_wgWizData.iface) + ' on this server\n';
    remoteConf += 'PublicKey = ' + _wgWizData.publicKey + '\n';
    if (_wgWizData.psk) remoteConf += 'PresharedKey = ' + _wgWizData.psk + '\n';
    if (_wgWizData.port) remoteConf += 'Endpoint = YOUR-SERVER-IP:' + _wgWizData.port + '\n';
    remoteConf += 'AllowedIPs = ' + localIp + '/32\n';
    remoteConf += 'PersistentKeepalive = ' + _wgWizData.keepalive + '\n';

    document.getElementById('wgWizardTitle').textContent = T.wg_step3_title;
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
                <button class="btn btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('wgRemoteConf').textContent);toast('Kopiert!')">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    Kopieren
                </button>
            </div>
            <pre id="wgRemoteConf" style="background:rgba(64,196,255,.04);border:1px solid rgba(64,196,255,.12);border-radius:8px;padding:10px 12px;font-family:var(--mono);font-size:.7rem;line-height:1.6;overflow-x:auto;margin:0;color:var(--blue)">${remoteConf}</pre>
        </div>
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
        });

        if (res.ok) {
            toast('Tunnel ' + _wgWizData.iface + ' erstellt' + (res.started ? ' und gestartet' : ''));
            closeModal('wgWizardModal');
            loadWg();
        } else {
            toast(res.error || 'Fehler', 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = 'Tunnel erstellen'; }
        }
    } catch (e) {
        toast('Fehler: ' + e.message, 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = 'Tunnel erstellen'; }
    }
}

