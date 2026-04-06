<?php
/**
 * FloppyOps Lite PVE — Dashboard View
 * Dashboard tab — server stats (hostname, uptime, CPU, RAM, disk, fail2ban, nginx, ZFS)
 * 
 * Included by index.php — do not call directly.
 * Variables available: $lang (string), $L (translations)
 */
?>
        <div class="tab-panel active" id="panel-dashboard">
            <div class="stat-grid" id="statsGrid">
                <div class="stat-card">
                    <div class="stat-label"><span class="indicator"></span> <?= __('hostname') ?></div>
                    <div class="stat-value" id="sHostname" style="font-size:1.1rem">---</div>
                    <div class="stat-sub" id="sKernel">---</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><span class="indicator"></span> <?= __('uptime') ?></div>
                    <div class="stat-value" id="sUptime" style="font-size:1rem">---</div>
                    <div class="stat-sub" id="sUptimeSince">---</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><?= __('cpu_load') ?></div>
                    <div class="stat-value" id="sLoad">---</div>
                    <div class="stat-sub" id="sLoadSub">---</div>
                    <div class="progress-wrap"><div class="progress-bar blue" id="sLoadBar" style="width:0"></div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><?= __('memory') ?></div>
                    <div class="stat-value" id="sMem">---</div>
                    <div class="stat-sub" id="sMemSub">---</div>
                    <div class="progress-wrap"><div class="progress-bar" id="sMemBar" style="width:0"></div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><?= __('disk') ?> /</div>
                    <div class="stat-value" id="sDisk">---</div>
                    <div class="stat-sub" id="sDiskSub">---</div>
                    <div class="progress-wrap"><div class="progress-bar green" id="sDiskBar" style="width:0"></div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Fail2ban <?= __('jails') ?></div>
                    <div class="stat-value" id="sF2bJails">---</div>
                    <div class="stat-sub"><?= __('active') ?> <?= __('jails') ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><?= __('banned_ips') ?></div>
                    <div class="stat-value" id="sF2bBanned" style="color:var(--red)">---</div>
                    <div class="stat-sub"><?= __('blocked') ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><?= __('nginx_sites') ?></div>
                    <div class="stat-value" id="sNginxSites">---</div>
                    <div class="stat-sub"><?= __('active_sites') ?></div>
                </div>
            </div>
            <div id="zfsSection" style="display:none">
                <div class="section-head" style="margin-top:8px">
                    <div class="section-title">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
                        ZFS Datasets
                    </div>
                </div>
                <table class="data-table" id="zfsTable">
                    <thead><tr><th>Dataset</th><th>Belegt</th><th>Verfügbar</th><th>Auslastung</th></tr></thead>
                    <tbody id="zfsBody"></tbody>
                </table>
            </div>
        </div>
