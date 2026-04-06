<?php
/**
 * FloppyOps Lite PVE — Vms View
 * VMs & Containers tab — list all VMs/CTs with clone functionality
 * 
 * Included by index.php — do not call directly.
 * Variables available: $lang (string), $L (translations)
 */
?>
        <div class="tab-panel" id="panel-vms">
            <div class="section-head">
                <div class="section-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                    VMs & Container
                    <span class="count" id="pveVmCount">0</span>
                </div>
                <button class="btn btn-sm" onclick="loadPveVms()">Aktualisieren</button>
            </div>
            <div id="pveVmList"></div>
        </div>

        <!-- Clone Modal -->
        <div class="modal-overlay" id="pveCloneModal">
            <div class="modal" style="max-width:500px">
                <div class="modal-head">
                    <div class="modal-title" id="pveCloneTitle">Clone</div>
                    <button class="modal-close" onclick="closeModal('pveCloneModal')">&times;</button>
                </div>
                <div class="modal-body" id="pveCloneBody"></div>
                <div class="modal-foot">
                    <button class="btn" onclick="closeModal('pveCloneModal')">Abbrechen</button>
                    <button class="btn btn-accent" id="pveCloneBtn" onclick="pveDoClone()"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>Clone starten</button>
                </div>
            </div>
        </div>

