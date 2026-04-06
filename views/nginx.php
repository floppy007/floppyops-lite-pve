<?php
/**
 * FloppyOps Lite PVE — Nginx View
 * Nginx Proxy tab — site management, SSL, setup guide with live checks
 * 
 * Included by index.php — do not call directly.
 * Variables available: $lang (string), $L (translations)
 */
?>
        <div class="tab-panel" id="panel-nginx">
            <div class="section-head">
                <div class="section-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                    <?= __('proxy_sites') ?>
                    <span class="count" id="siteCount">0</span>
                </div>
                <div style="display:flex;gap:8px">
                    <button class="btn btn-sm btn-green" onclick="showAddSite()">+ <?= __('new_site') ?></button>
                    <button class="btn btn-sm" onclick="reloadNginx()"><?= __('reload_nginx') ?></button>
                </div>
            </div>
            <!-- Setup Guide -->
            <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);margin-bottom:14px;overflow:hidden">
                <div style="padding:10px 14px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--border-subtle);cursor:pointer" onclick="var g=document.getElementById('nginxGuide');g.style.display=g.style.display==='none'?'':'none';if(g.style.display!=='none')loadNginxChecks()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <span style="font-size:.75rem;font-weight:600;flex:1"><?= $lang === 'de' ? 'Wie funktioniert der Reverse Proxy?' : 'How does the reverse proxy work?' ?></span>
                    <span style="font-size:.6rem;color:var(--text3)">&#9660;</span>
                </div>
                <div id="nginxGuide" style="display:none;padding:14px">
                    <div style="font-size:.72rem;color:var(--text2);line-height:1.8;margin-bottom:14px">
                        <?php if ($lang === 'de'): ?>
                        Nginx auf diesem Server empfaengt alle HTTP/HTTPS-Anfragen und leitet sie an interne Container oder VMs weiter.
                        So können mehrere Webseiten/Apps auf einem Server laufen, jede mit eigener Domain und SSL-Zertifikat.
                        <div style="margin:10px 0;padding:8px 12px;background:rgba(0,0,0,.2);border-radius:6px;font-family:var(--mono);font-size:.65rem;color:var(--text3)">
                            Browser → <span style="color:var(--accent)">nginx (:443 SSL)</span> → <span style="color:var(--green)">CT/VM (10.10.10.x:80)</span>
                        </div>
                        <?php else: ?>
                        Nginx on this server receives all HTTP/HTTPS requests and forwards them to internal containers or VMs.
                        Multiple websites/apps can run on one server, each with its own domain and SSL certificate.
                        <div style="margin:10px 0;padding:8px 12px;background:rgba(0,0,0,.2);border-radius:6px;font-family:var(--mono);font-size:.65rem;color:var(--text3)">
                            Browser → <span style="color:var(--accent)">nginx (:443 SSL)</span> → <span style="color:var(--green)">CT/VM (10.10.10.x:80)</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Status Checks -->
                    <div style="font-size:.68rem;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px"><?= $lang === 'de' ? 'System-Status' : 'System Status' ?></div>
                    <div id="nginxChecks" style="display:flex;flex-direction:column;gap:4px">
                        <div style="color:var(--text3);font-size:.72rem;padding:6px"><span class="loading-spinner" style="width:10px;height:10px;border-width:1.5px;margin-right:6px"></span> <?= $lang === 'de' ? 'Prüfe...' : 'Checking...' ?></div>
                    </div>
                </div>
            </div>
            <div class="site-grid" id="siteGrid"></div>
        </div>
