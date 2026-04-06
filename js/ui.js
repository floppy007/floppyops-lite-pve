/**
 * FloppyOps Lite PVE — Ui
 * UI Helpers — confirm dialog, prompt dialog, modal open/close
 *
 * @requires app.js (api, toast, fmtBytes, pct)
 */

function appConfirm(title, message, type = 'danger') {
    return new Promise(resolve => {
        let modal = document.getElementById('appConfirmModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'appConfirmModal';
            modal.className = 'modal-overlay';
            modal.innerHTML = '<div class="modal" style="max-width:400px"><div class="modal-head"><div class="modal-title" id="appConfirmTitle"></div><button class="modal-close" id="appConfirmClose">&times;</button></div><div class="modal-body" id="appConfirmBody" style="font-size:.82rem"></div><div class="modal-foot"><button class="btn" id="appConfirmNo">Abbrechen</button><button class="btn" id="appConfirmYes">OK</button></div></div>';
            document.body.appendChild(modal);
        }
        document.getElementById('appConfirmTitle').textContent = title;
        document.getElementById('appConfirmBody').innerHTML = message;
        const yesBtn = document.getElementById('appConfirmYes');
        yesBtn.className = type === 'danger' ? 'btn btn-red' : 'btn btn-accent';
        yesBtn.textContent = type === 'danger' ? 'Ja, fortfahren' : 'OK';
        modal.classList.add('active');
        const cleanup = (val) => { modal.classList.remove('active'); resolve(val); };
        document.getElementById('appConfirmYes').onclick = () => cleanup(true);
        document.getElementById('appConfirmNo').onclick = () => cleanup(false);
        document.getElementById('appConfirmClose').onclick = () => cleanup(false);
    });
}

function appPrompt(title, label, defaultVal = '') {
    return new Promise(resolve => {
        let modal = document.getElementById('appPromptModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'appPromptModal';
            modal.className = 'modal-overlay';
            modal.innerHTML = '<div class="modal" style="max-width:420px"><div class="modal-head"><div class="modal-title" id="appPromptTitle"></div><button class="modal-close" id="appPromptClose">&times;</button></div><div class="modal-body"><div style="font-size:.78rem;margin-bottom:8px" id="appPromptLabel"></div><input class="form-input" id="appPromptInput" style="font-family:var(--mono);font-size:.78rem"></div><div class="modal-foot"><button class="btn" id="appPromptNo">Abbrechen</button><button class="btn btn-accent" id="appPromptYes">OK</button></div></div>';
            document.body.appendChild(modal);
        }
        document.getElementById('appPromptTitle').textContent = title;
        document.getElementById('appPromptLabel').textContent = label;
        const input = document.getElementById('appPromptInput');
        input.value = defaultVal;
        modal.classList.add('active');
        setTimeout(() => input.focus(), 100);
        const cleanup = (val) => { modal.classList.remove('active'); resolve(val); };
        document.getElementById('appPromptYes').onclick = () => cleanup(input.value.trim());
        document.getElementById('appPromptNo').onclick = () => cleanup(null);
        document.getElementById('appPromptClose').onclick = () => cleanup(null);
        input.onkeydown = (e) => { if (e.key === 'Enter') cleanup(input.value.trim()); };
    });
}

// ── Modals ───────────────────────────────────────────
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

document.querySelectorAll('.modal-overlay').forEach(m => {
