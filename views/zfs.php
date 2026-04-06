<?php
/**
 * FloppyOps Lite PVE — Zfs View
 * ZFS tab — pools, datasets, snapshots (rollback/clone), auto-snapshots with retention
 * 
 * Included by index.php — do not call directly.
 * Variables available: $lang (string), $L (translations)
 */
?>
        <div class="tab-panel" id="panel-zfs">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                <div style="display:flex;gap:2px;background:rgba(255,255,255,.02);border:1px solid var(--border-subtle);border-radius:8px;padding:2px">
                    <button class="btn btn-sm zfs-sub active" data-zfstab="pools" onclick="zfsSwitchTab('pools',this)" style="border:none;border-radius:6px;font-size:.7rem;padding:5px 14px">Pools & Datasets</button>
                    <button class="btn btn-sm zfs-sub" data-zfstab="snaps" onclick="zfsSwitchTab('snaps',this)" style="border:none;border-radius:6px;font-size:.7rem;padding:5px 14px">Snapshots <span class="count" id="zfsSnapCount" style="margin-left:4px">0</span></button>
                    <button class="btn btn-sm zfs-sub" data-zfstab="auto" onclick="zfsSwitchTab('auto',this)" style="border:none;border-radius:6px;font-size:.7rem;padding:5px 14px">Auto-Snapshots <span id="zfsAutoStatus" style="margin-left:4px"></span></button>
                </div>
                <div style="display:flex;gap:6px">
                    <button class="btn btn-sm btn-accent" onclick="zfsCreateSnapModal()">+ Snapshot</button>
                    <button class="btn btn-sm" onclick="loadZfs()">Aktualisieren</button>
                </div>
            </div>

            <!-- Info Box -->
            <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);margin-bottom:14px;overflow:hidden">
                <div style="padding:10px 14px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--border-subtle);cursor:pointer" onclick="var g=document.getElementById('zfsGuide');g.style.display=g.style.display==='none'?'':'none'">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <span style="font-size:.75rem;font-weight:600;flex:1"><?= $lang === 'de' ? 'ZFS Snapshots & Auto-Backup' : 'ZFS Snapshots & Auto-Backup' ?></span>
                    <span style="font-size:.6rem;color:var(--text3)">&#9660;</span>
                </div>
                <div id="zfsGuide" style="display:none;padding:14px;font-size:.72rem;color:var(--text2);line-height:1.8">
                    <?php if ($lang === 'de'): ?>
                    <strong style="color:var(--text)">Datensicherung direkt auf dem Server</strong><br>
                    ZFS Snapshots sind sofortige, platzsparende Sicherungspunkte deiner Container und VMs. Sie ermöglichen sekundenschnelles Zurückrollen bei Problemen.

                    <div style="display:flex;flex-direction:column;gap:4px;margin-top:8px">
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Auto-Snapshots</strong> — Automatisch alle 15 Min, stündlich, täglich, wöchentlich, monatlich</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Rollback</strong> — Container auf einen früheren Zustand zurücksetzen</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Clone</strong> — Neuen CT/VM aus einem Snapshot erstellen (Test, Migration)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Platzsparend</strong> — Nur geänderte Blöcke werden gespeichert (Copy-on-Write)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Keine Downtime</strong> — Snapshots sind sofort, ohne den CT/VM zu stoppen</div>
                    </div>

                    <div style="margin-top:10px;padding:8px 12px;background:rgba(255,89,0,.04);border:1px solid rgba(255,89,0,.1);border-radius:6px;font-size:.65rem">
                        <strong style="color:var(--accent)">Empfehlung:</strong> Installiere <code style="padding:1px 4px;background:rgba(255,255,255,.04);border-radius:3px">zfs-auto-snapshot</code> im Auto-Snapshots Tab für automatische Sicherungen. Standard-Retention: 4 frequent, 24 hourly, 31 daily, 8 weekly, 12 monthly = ca. 1 Jahr Historie.
                    </div>
                    <?php else: ?>
                    <strong style="color:var(--text)">Data protection directly on the server</strong><br>
                    ZFS snapshots are instant, space-efficient backup points of your containers and VMs. Roll back in seconds when problems occur.

                    <div style="display:flex;flex-direction:column;gap:4px;margin-top:8px">
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Auto-Snapshots</strong> — Automatically every 15 min, hourly, daily, weekly, monthly</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Rollback</strong> — Restore container to a previous state</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Clone</strong> — Create new CT/VM from a snapshot (testing, migration)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Space-efficient</strong> — Only changed blocks are stored (copy-on-write)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>No downtime</strong> — Snapshots are instant, no CT/VM stop required</div>
                    </div>

                    <div style="margin-top:10px;padding:8px 12px;background:rgba(255,89,0,.04);border:1px solid rgba(255,89,0,.1);border-radius:6px;font-size:.65rem">
                        <strong style="color:var(--accent)">Recommendation:</strong> Install <code style="padding:1px 4px;background:rgba(255,255,255,.04);border-radius:3px">zfs-auto-snapshot</code> in the Auto-Snapshots tab for automatic backups. Default retention: 4 frequent, 24 hourly, 31 daily, 8 weekly, 12 monthly = ~1 year history.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sub: Pools & Datasets -->
            <div id="zfsTabPools">
                <div id="zfsPools" style="margin-bottom:14px"></div>
                <table class="data-table">
                    <thead><tr><th>Dataset</th><th>Belegt</th><th>Verfügbar</th><th>Mountpoint</th><th style="width:150px">Auslastung</th></tr></thead>
                    <tbody id="zfsDsBody"></tbody>
                </table>
            </div>

            <!-- Sub: Snapshots -->
            <div id="zfsTabSnaps" style="display:none">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
                    <div style="font-size:.72rem;font-weight:600">Snapshots</div>
                    <div style="display:flex;gap:6px;align-items:center">
                        <select id="zfsSnapSort" onchange="zfsRenderSnaps()" style="background:var(--surface-solid);border:1px solid var(--border-subtle);border-radius:4px;padding:3px 8px;font-size:.65rem;color:var(--text)">
                            <option value="date-desc">Neueste zuerst</option>
                            <option value="date-asc">Älteste zuerst</option>
                            <option value="size-desc">Größte zuerst</option>
                            <option value="name-asc">Name A-Z</option>
                        </select>
                        <select id="zfsSnapFilter" onchange="zfsRenderSnaps()" style="background:var(--surface-solid);border:1px solid var(--border-subtle);border-radius:4px;padding:3px 8px;font-size:.65rem;color:var(--text)">
                            <option value="">Alle Datasets</option>
                        </select>
                    </div>
                </div>
                <div id="zfsSnapBody"></div>
            </div>

            <!-- Sub: Auto-Snapshots -->
            <div id="zfsTabAuto" style="display:none">
                <div id="zfsAutoBody"></div>
            </div>
        </div>

