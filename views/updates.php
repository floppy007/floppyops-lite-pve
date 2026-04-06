<?php
/**
 * FloppyOps Lite PVE — Updates View
 * Updates tab — system packages (apt), app self-update, repo check, auto-update settings
 * 
 * Included by index.php — do not call directly.
 * Variables available: $lang (string), $L (translations)
 */
?>
        <div class="tab-panel" id="panel-updates">
            <div class="section-head">
                <div class="section-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                    Updates
                    <span id="updCount" class="count" style="font-size:.58rem">—</span>
                </div>
                <div style="display:flex;gap:6px">
                    <button class="btn btn-sm" onclick="aptRefresh()" id="btnAptRefresh"><?= $lang === 'en' ? 'Check for updates' : 'Nach Updates suchen' ?></button>
                </div>
            </div>

            <!-- Banners -->
            <div id="updRepoBanner" style="display:none;background:rgba(220,53,69,.05);border:1px solid rgba(220,53,69,.15);border-radius:var(--radius);padding:12px 16px;margin-bottom:10px">
                <div style="display:flex;align-items:center;gap:12px;font-size:.76rem">
                    <div style="width:32px;height:32px;border-radius:8px;background:rgba(220,53,69,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </div>
                    <div style="flex:1">
                        <div style="font-weight:600;color:var(--red)"><?= $lang === 'en' ? 'Enterprise repository active without subscription' : 'Enterprise-Repository aktiv ohne Subscription' ?></div>
                        <div style="font-size:.68rem;color:var(--text3);margin-top:2px"><?= $lang === 'en' ? 'Updates will fail. We can switch to the free community repository.' : 'Updates werden fehlschlagen. Wir können auf das kostenlose Community-Repository wechseln.' ?></div>
                    </div>
                    <button class="btn btn-sm btn-accent" onclick="repoFix()" id="btnRepoFix"><?= $lang === 'en' ? 'Fix now' : 'Jetzt fixen' ?></button>
                </div>
            </div>

            <div id="updRebootBanner" style="display:none;background:rgba(255,193,7,.05);border:1px solid rgba(255,193,7,.15);border-radius:var(--radius);padding:12px 16px;margin-bottom:10px">
                <div style="display:flex;align-items:center;gap:12px;font-size:.76rem">
                    <div style="width:32px;height:32px;border-radius:8px;background:rgba(255,193,7,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--yellow)" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    </div>
                    <div>
                        <div style="font-weight:600;color:var(--yellow)"><?= $lang === 'en' ? 'Reboot required' : 'Neustart erforderlich' ?></div>
                        <div style="font-size:.68rem;color:var(--text3);margin-top:2px"><?= $lang === 'en' ? 'A system update requires a server reboot to take effect.' : 'Ein System-Update erfordert einen Neustart des Servers.' ?></div>
                    </div>
                </div>
            </div>

            <!-- System Updates -->
            <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);overflow:hidden;margin-bottom:12px">
                <div style="padding:12px 16px;border-bottom:1px solid var(--border-subtle);display:flex;align-items:center;gap:10px">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    <span style="font-size:.8rem;font-weight:600"><?= $lang === 'en' ? 'System Packages' : 'System-Pakete' ?></span>
                    <span id="updCountBadge" style="font-size:.58rem;padding:2px 8px;border-radius:10px;background:var(--surface-solid);color:var(--text3);font-weight:600">—</span>
                    <div style="margin-left:auto;display:flex;gap:6px" id="updActions">
                        <button class="btn btn-sm btn-accent" onclick="aptUpgrade()" id="btnAptUpgrade" style="display:none"><?= $lang === 'en' ? 'Install all' : 'Alle installieren' ?></button>
                    </div>
                </div>
                <div id="updList" style="padding:0;max-height:320px;overflow-y:auto">
                    <div style="color:var(--text3);text-align:center;padding:24px;font-size:.76rem">
                        <div class="spinner-small" style="margin:0 auto 8px"></div>
                        <?= $lang === 'en' ? 'Checking for updates...' : 'Suche nach Updates...' ?>
                    </div>
                </div>
                <div id="updOutput" style="display:none;border-top:1px solid var(--border-subtle);padding:10px 16px;font-family:var(--mono);font-size:.62rem;max-height:180px;overflow-y:auto;white-space:pre-wrap;color:var(--text3);background:rgba(0,0,0,.15)"></div>
            </div>

            <!-- App + Settings -->
            <div style="display:flex;gap:12px;flex-wrap:wrap">
                <!-- FloppyOps Lite Version -->
                <div style="flex:1;min-width:300px;background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:14px 16px">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <span style="font-size:.78rem;font-weight:600">FloppyOps Lite</span>
                    </div>
                    <div id="appUpdateInfo" style="font-size:.74rem">
                        <div style="color:var(--text3);display:flex;align-items:center;gap:6px"><div class="spinner-small"></div> <?= $lang === 'en' ? 'Checking...' : 'Prüfe...' ?></div>
                    </div>
                    <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border-subtle)">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.72rem">
                            <input type="checkbox" id="appAutoUpdateToggle" onchange="appAutoUpdateChanged()" style="width:14px;height:14px;accent-color:var(--accent);cursor:pointer">
                            <span style="color:var(--text2)"><?= $lang === 'en' ? 'Auto-update app' : 'App automatisch aktualisieren' ?></span>
                        </label>
                        <div id="appAutoSchedule" style="margin-top:6px;display:flex;gap:6px;align-items:center;font-size:.68rem;flex-wrap:wrap;opacity:.4;pointer-events:none">
                            <select id="appAutoDay" onchange="appAutoUpdateChanged()" style="background:var(--surface-solid);border:1px solid var(--border-subtle);border-radius:4px;padding:2px 5px;font-size:.66rem;color:var(--text)">
                                <option value="0"><?= $lang === 'en' ? 'Daily' : 'Täglich' ?></option>
                                <option value="1"><?= $lang === 'en' ? 'Mon' : 'Mo' ?></option>
                                <option value="2"><?= $lang === 'en' ? 'Tue' : 'Di' ?></option>
                                <option value="3"><?= $lang === 'en' ? 'Wed' : 'Mi' ?></option>
                                <option value="4"><?= $lang === 'en' ? 'Thu' : 'Do' ?></option>
                                <option value="5"><?= $lang === 'en' ? 'Fri' : 'Fr' ?></option>
                                <option value="6"><?= $lang === 'en' ? 'Sat' : 'Sa' ?></option>
                                <option value="7"><?= $lang === 'en' ? 'Sun' : 'So' ?></option>
                            </select>
                            <select id="appAutoHour" onchange="appAutoUpdateChanged()" style="background:var(--surface-solid);border:1px solid var(--border-subtle);border-radius:4px;padding:2px 5px;font-size:.66rem;color:var(--text)">
                                <?php for ($h = 0; $h < 24; $h++): ?><option value="<?= $h ?>"<?= $h === 4 ? ' selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option><?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Auto-Update -->
                <div style="flex:1;min-width:300px;background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:14px 16px">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><path d="M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48l2.83-2.83"/></svg>
                        <span style="font-size:.78rem;font-weight:600">Auto-Update</span>
                        <span id="autoUpdateStatus" style="font-size:.58rem;color:var(--text3);margin-left:auto"></span>
                    </div>
