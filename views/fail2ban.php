<?php
/**
 * FloppyOps Lite PVE — Fail2ban View
 * Fail2ban tab — jail overview, unban, config editor, log viewer
 * 
 * Included by index.php — do not call directly.
 * Variables available: $lang (string), $L (translations)
 */
?>
        <div class="tab-panel" id="panel-fail2ban">
            <div class="section-head">
                <div class="section-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    Jails
                    <span class="count" id="jailCount">0</span>
                </div>
                <div style="display:flex;gap:6px">
                    <button class="btn btn-sm" onclick="showF2bConfig('jail.local')" title="jail.local bearbeiten">Config</button>
                    <button class="btn btn-sm" onclick="loadF2b()">Aktualisieren</button>
                </div>
            </div>
            <div class="jail-grid" id="jailGrid"></div>

            <div style="margin-top:32px">
                <div class="section-head">
                    <div class="section-title">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--text3)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        Ban-Log
                    </div>
                </div>
                <div class="log-viewer" id="f2bLog">Laden...</div>
            </div>
        </div>

