/**
 * FloppyOps Lite — Core
 * Kernfunktionen — Navigation, API, Toast, Modals, Hilfsfunktionen
 */

// ── Navigation & Tabs ────────────────────────────────
function switchTab(tabName) {
    document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    const tab = document.querySelector('.nav-tab[data-tab="' + tabName + '"]');
    const panel = document.getElementById('panel-' + tabName);
    if (tab) tab.classList.add('active');
    if (panel) panel.classList.add('active');
    location.hash = tabName;

    stopWgGraph();
    if (tabName === 'security') { Promise.all([loadFwTemplates(), loadFwVmList()]); }
    if (tabName === 'network') { loadNginx(); loadNginxChecks(); loadWg(); startWgGraph(); }
    if (tabName === 'zfs') loadZfs();
    if (tabName === 'system') loadUpdates();
}

// Legacy hash support: map old tab names to grouped tabs
const _tabHashMap = { fail2ban: ['security','fail2ban'], firewall: ['security','firewall'], portscan: ['security','portscan'],
    nginx: ['network','nginx'], wireguard: ['network','wireguard'] };

function switchSubTab(group, sub) {
    const tabs = document.querySelectorAll('#' + group + 'SubTabs .sub-tab, [onclick*="switchSubTab(\'' + group + '\'"] ');
    // Find parent sub-tabs container
    const container = document.getElementById(group === 'security' ? 'secSubTabs' : group === 'network' ? 'netSubTabs' : 'sysSubTabs');
    if (container) container.querySelectorAll('.sub-tab').forEach(t => t.classList.remove('active'));
    // Activate clicked tab
    const clicked = document.querySelector('[onclick="switchSubTab(\'' + group + '\',\'' + sub + '\')"]');
    if (clicked) clicked.classList.add('active');
    // Show/hide sub-panels
    document.querySelectorAll('[id^="sub-' + group + '-"]').forEach(p => p.classList.remove('active'));
    const panel = document.getElementById('sub-' + group + '-' + sub);
    if (panel) panel.classList.add('active');
    // Load data on sub-tab switch
    if (group === 'security' && sub === 'portscan') { loadSecScan(); loadSecFwRules(); }
    if (group === 'security' && sub === 'fail2ban') loadF2b();
    if (group === 'security' && sub === 'firewall') { loadFwTemplates(); loadFwVmList(); }
    if (group === 'network' && sub === 'nginx') { loadNginx(); loadNginxChecks(); }
    if (group === 'network' && sub === 'wireguard') { loadWg(); startWgGraph(); }
    if (group === 'system' && sub === 'zfs') loadZfs();
    if (group === 'system' && sub === 'updates') loadUpdates();
}

document.querySelectorAll('.nav-tab').forEach(tab => {
    tab.addEventListener('click', () => switchTab(tab.dataset.tab));
});


// ── Toast Benachrichtigungen ─────────────────────────
function toast(msg, type = 'success') {
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.innerHTML = (type === 'success' ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>' : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>') + '<span>' + msg + '</span>';
    document.getElementById('toasts').appendChild(el);
    setTimeout(() => el.remove(), 4000);
}

// ── API Helper (fetch-Wrapper mit CSRF) ─────────────
async function api(endpoint, method = 'GET', data = null) {
    const opts = { method };
    if (data) {
        const fd = new FormData();
        fd.append('_csrf', CSRF);
        Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        opts.body = fd;
    }
    const res = await fetch('?api=' + endpoint, opts);
    if (res.status === 401) {
        window.location.href = window.location.pathname;
        return {};
    }
    return res.json();
}

// ── Formatierungs-Hilfsfunktionen ───────────────────
function fmtBytes(b) {
    if (b < 1073741824) return (b / 1048576).toFixed(0) + ' MB';
    return (b / 1073741824).toFixed(1) + ' GB';
}
function pct(used, total) {
    return total > 0 ? Math.round(used / total * 100) : 0;
}

// ── Hilfe-Seite + Suche ─────────────────────────────
function toggleHelp(id) {
    const sec = document.getElementById(id);
    if (!sec) return;
    const body = sec.querySelector('.help-body');
    const isOpen = body.style.display !== 'none';
    body.style.display = isOpen ? 'none' : '';
    sec.classList.toggle('open', !isOpen);
}

function filterHelp(query) {
    const q = query.toLowerCase().trim();
    const sections = document.querySelectorAll('.help-section');
    let found = 0;
    sections.forEach(sec => {
        const title = sec.querySelector('[style*="font-weight:600"]')?.textContent || '';
        const body = sec.querySelector('.help-body');
        if (!body) return;
        // Remove old highlights
        body.querySelectorAll('mark').forEach(m => { m.outerHTML = m.textContent; });
        if (!q) {
            sec.style.display = '';
            body.style.display = 'none';
            sec.classList.remove('open');
            found++;
            return;
        }
        const text = (title + ' ' + body.textContent).toLowerCase();
        if (text.includes(q)) {
            sec.style.display = '';
            body.style.display = '';
            sec.classList.add('open');
            // Highlight matches in body
            const walker = document.createTreeWalker(body, NodeFilter.SHOW_TEXT);
            const nodes = [];
            while (walker.nextNode()) nodes.push(walker.currentNode);
            nodes.forEach(node => {
                const idx = node.textContent.toLowerCase().indexOf(q);
                if (idx === -1) return;
                const span = document.createElement('span');
                span.innerHTML = node.textContent.substring(0, idx)
                    + '<mark>' + node.textContent.substring(idx, idx + q.length) + '</mark>'
                    + node.textContent.substring(idx + q.length);
                node.parentNode.replaceChild(span, node);
            });
            found++;
        } else {
            sec.style.display = 'none';
        }
    });
    const noRes = document.getElementById('helpNoResults');
    if (noRes) noRes.style.display = found === 0 && q ? '' : 'none';
}

// ── App-eigene Confirm/Prompt Dialoge (ersetzt browser-native) ──
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

// ── Modal-Management ────────────────────────────────
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => {
        if (e.target === m && m.id !== 'wgWizardModal') closeModal(m.id);
    });
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.active').forEach(m => closeModal(m.id));
});
