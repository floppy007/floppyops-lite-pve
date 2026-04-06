<?php
/**
 * FloppyOps Lite PVE — Modals View
 * Shared modals — clone VM, fail2ban config, wireguard config/wizard, nginx add/edit site
 * 
 * Included by index.php — do not call directly.
 * Variables available: $lang (string), $L (translations)
 */
?>
                        <input type="checkbox" id="autoUpdateToggle" onchange="autoUpdateChanged()" style="width:16px;height:16px;accent-color:var(--accent);cursor:pointer">
                        <span style="color:var(--text2)"><?= $lang === 'en' ? 'Automatic system updates' : 'Automatische System-Updates' ?></span>
                    </label>
                    <div id="autoUpdateSchedule" style="margin-top:8px;display:flex;gap:8px;align-items:center;font-size:.72rem;flex-wrap:wrap;opacity:.4;pointer-events:none">
                        <select id="autoUpdateDay" onchange="autoUpdateChanged()" style="background:var(--surface-solid);border:1px solid var(--border-subtle);border-radius:4px;padding:3px 6px;font-size:.68rem;color:var(--text)">
                            <option value="0"><?= $lang === 'en' ? 'Daily' : 'Täglich' ?></option>
                            <option value="1"><?= $lang === 'en' ? 'Monday' : 'Montag' ?></option>
                            <option value="2"><?= $lang === 'en' ? 'Tuesday' : 'Dienstag' ?></option>
                            <option value="3"><?= $lang === 'en' ? 'Wednesday' : 'Mittwoch' ?></option>
                            <option value="4"><?= $lang === 'en' ? 'Thursday' : 'Donnerstag' ?></option>
                            <option value="5"><?= $lang === 'en' ? 'Friday' : 'Freitag' ?></option>
                            <option value="6"><?= $lang === 'en' ? 'Saturday' : 'Samstag' ?></option>
                            <option value="7"><?= $lang === 'en' ? 'Sunday' : 'Sonntag' ?></option>
                        </select>
                        <span style="color:var(--text3)"><?= $lang === 'en' ? 'at' : 'um' ?></span>
                        <select id="autoUpdateHour" onchange="autoUpdateChanged()" style="background:var(--surface-solid);border:1px solid var(--border-subtle);border-radius:4px;padding:3px 6px;font-size:.68rem;color:var(--text)">
                            <?php for ($h = 0; $h < 24; $h++): ?><option value="<?= $h ?>"<?= $h === 3 ? ' selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option><?php endfor; ?>
                        </select>
                        <span id="autoUpdateTz" style="font-size:.6rem;color:var(--text3)"></span>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ─ Fail2ban Config Modal ─────────────────────────────── -->
<div class="modal-overlay" id="f2bConfigModal">
    <div class="modal">
        <div class="modal-head">
            <div class="modal-title" id="f2bConfigTitle">jail.local</div>
            <button class="modal-close" onclick="closeModal('f2bConfigModal')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="f2bConfigFile">
            <div class="form-group">
                <textarea class="form-textarea" id="f2bConfigContent" style="min-height:300px"></textarea>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn" onclick="closeModal('f2bConfigModal')">Abbrechen</button>
            <button class="btn btn-accent" onclick="saveF2bConfig()">Speichern & Restart</button>
        </div>
    </div>
</div>

<!-- ─ WireGuard Config Modal ──────────────────────────── -->
<div class="modal-overlay" id="wgConfigModal">
    <div class="modal">
        <div class="modal-head">
            <div class="modal-title" id="wgConfigTitle">WireGuard Config</div>
            <button class="modal-close" onclick="closeModal('wgConfigModal')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="wgConfigIface">
            <div class="form-group">
                <label class="form-label">Konfiguration</label>
                <textarea class="form-textarea" id="wgConfigContent" style="min-height:260px"></textarea>
                <div class="form-hint">Private/Preshared Keys werden beim Laden maskiert. Zum Speichern den echten Key eintragen.</div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn" onclick="closeModal('wgConfigModal')">Abbrechen</button>
            <button class="btn btn-accent" onclick="saveWgConfig()">Speichern</button>
        </div>
    </div>
</div>

<!-- ─ WireGuard Wizard Modal ───────────────────────────── -->
<div class="modal-overlay" id="wgWizardModal">
    <div class="modal" style="max-width:600px">
        <div class="modal-head">
            <div class="modal-title" id="wgWizardTitle">Neuer VPN-Tunnel</div>
            <button class="modal-close" onclick="closeModal('wgWizardModal')">&times;</button>
        </div>
        <div class="modal-body" id="wgWizardBody"></div>
        <div class="modal-foot" id="wgWizardFoot"></div>
    </div>
</div>

<!-- ─ Add Site Modal ──────────────────────────────────── -->
<div class="modal-overlay" id="addSiteModal">
    <div class="modal">
        <div class="modal-head">
            <div class="modal-title"><?= __('new_site') ?></div>
            <button class="modal-close" onclick="closeModal('addSiteModal')">&times;</button>
        </div>
        <div class="modal-body">
            <!-- Info -->
            <div style="background:rgba(64,196,255,.04);border:1px solid rgba(64,196,255,.1);border-radius:6px;padding:8px 12px;margin-bottom:14px;font-size:.68rem;color:var(--text2);line-height:1.5">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2" style="margin-right:4px;vertical-align:middle"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <?= $lang === 'de' ? 'Erstellt einen Nginx Reverse Proxy. Der DNS A-Record der Domain muss auf die IP dieses Servers zeigen.' : 'Creates an Nginx reverse proxy. The domain DNS A record must point to this server\'s IP.' ?>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label"><?= __('domains') ?></label>
                    <input class="form-input" id="newDomain" placeholder="example.com, www.example.com">
                    <div class="form-hint"><?= __('multi_domain_hint') ?></div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= __('target_ip') ?></label>
                    <input class="form-input" id="newTarget" placeholder="http://10.10.10.100:80">
                </div>
            </div>
            <div class="form-group">
                <label class="form-check">
                    <input type="checkbox" id="newSsl">
                    <?= __('enable_ssl') ?>
                </label>
                <div class="form-hint"><?= __('dns_hint') ?></div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn" onclick="closeModal('addSiteModal')"><?= __('cancel') ?></button>
            <button class="btn btn-accent" onclick="addSite()"><?= __('create_site') ?></button>
        </div>
    </div>
</div>

<!-- ─ Edit Site Modal ─────────────────────────────────── -->
<div class="modal-overlay" id="editSiteModal">
    <div class="modal">
        <div class="modal-head">
            <div class="modal-title" id="editSiteTitle"><?= __('edit') ?></div>
            <button class="modal-close" onclick="closeModal('editSiteModal')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editSiteFile">
            <div class="form-group">
                <label class="form-label"><?= __('nginx_config') ?></label>
                <textarea class="form-textarea" id="editSiteContent"></textarea>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn" onclick="closeModal('editSiteModal')"><?= __('cancel') ?></button>
            <button class="btn btn-accent" onclick="saveSite()"><?= __('save_reload') ?></button>
        </div>
    </div>
</div>

<!-- ─ Toast Container ─────────────────────────────────── -->
<div class="toast-container" id="toasts"></div>
