<?php
/**
 * FloppyOps Lite PVE — Wireguard View
 * WireGuard VPN tab — tunnel status, traffic graph, config editor, new tunnel wizard
 * 
 * Included by index.php — do not call directly.
 * Variables available: $lang (string), $L (translations)
 */
?>
        <div class="tab-panel" id="panel-wireguard">
            <div class="section-head">
                <div class="section-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M16.24 7.76a6 6 0 0 1 0 8.49m-8.48-.01a6 6 0 0 1 0-8.49"/></svg>
                    VPN Tunnels
                    <span class="count" id="wgCount">0</span>
                </div>
                <div style="display:flex;gap:6px">
                    <button class="btn btn-sm btn-green" onclick="wgWizardOpen()">+ Neuer Tunnel</button>
                    <button class="btn btn-sm" onclick="loadWg()">Aktualisieren</button>
                </div>
            </div>
            <!-- Info Box -->
            <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);margin-bottom:14px;overflow:hidden">
                <div style="padding:10px 14px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--border-subtle);cursor:pointer" onclick="var g=document.getElementById('wgGuide');g.style.display=g.style.display==='none'?'':'none'">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <span style="font-size:.75rem;font-weight:600;flex:1"><?= $lang === 'de' ? 'Warum WireGuard VPN?' : 'Why WireGuard VPN?' ?></span>
                    <span style="font-size:.6rem;color:var(--text3)">&#9660;</span>
                </div>
                <div id="wgGuide" style="display:none;padding:14px;font-size:.72rem;color:var(--text2);line-height:1.8">
                    <?php if ($lang === 'de'): ?>
                    <strong style="color:var(--text)">Sichere Verbindung zu deinem Server</strong><br>
                    WireGuard erstellt einen verschlüsselten Tunnel zwischen deinem lokalen Netzwerk und dem Dedicated Server.
                    So erreichst du alle internen Dienste (CTs, VMs, PVE WebUI) sicher über das Internet — ohne Ports öffentlich freizugeben.

                    <div style="margin:10px 0;padding:8px 12px;background:rgba(0,0,0,.2);border-radius:6px;font-family:var(--mono);font-size:.65rem;color:var(--text3)">
                        Büro/Zuhause (10.10.20.2) → <span style="color:var(--accent)">WireGuard Tunnel</span> → Dedicated Server (10.10.20.1) → <span style="color:var(--green)">CTs (10.10.10.x)</span>
                    </div>

                    <strong style="color:var(--text)">Typische Einsatzszenarien:</strong>
                    <div style="display:flex;flex-direction:column;gap:4px;margin-top:6px">
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> PVE WebUI sicher erreichbar ohne öffentlichen Port 8006</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> Zugriff auf interne CTs/VMs von überall (Homeoffice, Mobil)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> Site-to-Site VPN zwischen Standorten (z.B. Büro ↔ Rechenzentrum)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> Backup-Traffic über verschlüsselte Verbindung (PBS, ZFS Replikation)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> Monitoring & Management ohne öffentliche Angriffsfläche</div>
                    </div>

                    <div style="margin-top:10px;padding:8px 12px;background:rgba(255,89,0,.04);border:1px solid rgba(255,89,0,.1);border-radius:6px;font-size:.65rem">
                        <strong style="color:var(--accent)">Tipp:</strong> Auf der Gegenstelle (Router/Gateway) muss ebenfalls WireGuard installiert und der Peer konfiguriert sein.
                        Der Wizard oben generiert die passende Remote-Config zum Kopieren.
                    </div>
                    <?php else: ?>
                    <strong style="color:var(--text)">Secure connection to your server</strong><br>
                    WireGuard creates an encrypted tunnel between your local network and the dedicated server.
                    Access all internal services (CTs, VMs, PVE WebUI) securely over the internet — without exposing ports publicly.

                    <div style="margin:10px 0;padding:8px 12px;background:rgba(0,0,0,.2);border-radius:6px;font-family:var(--mono);font-size:.65rem;color:var(--text3)">
                        Office/Home (10.10.20.2) → <span style="color:var(--accent)">WireGuard Tunnel</span> → Dedicated Server (10.10.20.1) → <span style="color:var(--green)">CTs (10.10.10.x)</span>
                    </div>

                    <strong style="color:var(--text)">Typical use cases:</strong>
                    <div style="display:flex;flex-direction:column;gap:4px;margin-top:6px">
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> Secure PVE WebUI access without public port 8006</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> Access internal CTs/VMs from anywhere (home office, mobile)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> Site-to-site VPN between locations (office ↔ datacenter)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> Backup traffic over encrypted connection (PBS, ZFS replication)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> Monitoring & management without public attack surface</div>
                    </div>

                    <div style="margin-top:10px;padding:8px 12px;background:rgba(255,89,0,.04);border:1px solid rgba(255,89,0,.1);border-radius:6px;font-size:.65rem">
                        <strong style="color:var(--accent)">Tip:</strong> The remote side (router/gateway) also needs WireGuard installed and the peer configured.
                        The wizard above generates the matching remote config for copying.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Traffic Graph -->
            <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:14px 16px 8px;margin-bottom:16px">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
                    <div style="display:flex;align-items:center;gap:6px;font-size:.7rem;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.06em">
                        <span class="indicator"></span> Live Traffic
                    </div>
                    <div style="display:flex;gap:16px;font-family:var(--mono);font-size:.68rem">
                        <span style="color:#00e676">&#9660; <span id="wgGraphRx">---</span></span>
                        <span style="color:#40c4ff">&#9650; <span id="wgGraphTx">---</span></span>
                    </div>
                </div>
                <div style="height:100px"><canvas id="wgCanvas"></canvas></div>
            </div>

            <div id="wgGrid" class="jail-grid"></div>
        </div>

