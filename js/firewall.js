/**
 * FloppyOps Lite — Firewall Templates
 * Firewall Templates — VM/CT Regelsaetze und Builder
 */

const FW_ICONS = {
    mail: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
    globe: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
    database: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>',
    server: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>',
    box: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>',
    zap: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
    shield: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
};

let _fwTemplates = null;
let _fwVmCache = [];

async function loadFwTemplates() {
    const grid = document.getElementById('fwTemplateGrid');
    if (grid) grid.innerHTML = `<div style="display:flex;align-items:center;gap:8px;padding:8px;grid-column:1/-1"><span style="width:14px;height:14px;border:2px solid var(--border-subtle);border-top-color:var(--accent);border-radius:50%;animation:spin .6s linear infinite;flex-shrink:0"></span><span style="color:var(--text3)">${T.loading}</span></div>`;
    const d = await api('fw-templates');
    if (!d.ok) return;
    _fwTemplates = [...d.builtin.map(t => ({...t, _type: 'builtin'})), ...d.custom.map(t => ({...t, _type: 'custom'}))];
    const assignments = d.assignments || {};
    // Build reverse map: template_id -> [vmid, ...]
    const tplVms = {};
    Object.entries(assignments).forEach(([key, a]) => {
        if (!tplVms[a.template_id]) tplVms[a.template_id] = [];
        const [type, vmid] = key.split(':');
        tplVms[a.template_id].push({ vmid, type });
    });
    if (!grid) return;
    let html = '';
    _fwTemplates.forEach(t => {
        const icon = FW_ICONS[t.icon] || FW_ICONS.shield;
        const badge = t._type === 'builtin'
            ? `<span style="font-size:.55rem;background:rgba(96,165,250,.15);color:#60a5fa;padding:1px 5px;border-radius:3px">${T.fw_builtin}</span>`
            : `<span style="font-size:.55rem;background:rgba(139,92,246,.15);color:#8b5cf6;padding:1px 5px;border-radius:3px">${T.fw_custom}</span>`;
        const ruleCount = T.fw_rules_count.replace('%d', t.rules.length);
        const assigned = tplVms[t.id] || [];
        const assignedHtml = assigned.length > 0
            ? `<div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:3px">${assigned.map(a => `<span style="font-size:.55rem;background:rgba(34,197,94,.12);color:var(--green);padding:1px 5px;border-radius:3px">${a.type === 'qemu' ? 'VM' : 'CT'} ${a.vmid}</span>`).join('')}</div>`
            : '';
        const borderColor = assigned.length > 0 ? 'rgba(34,197,94,.25)' : 'var(--border-subtle)';
        html += `<div style="background:var(--bg);border:1px solid ${borderColor};border-radius:var(--radius);padding:10px 12px;cursor:pointer;transition:border-color .15s" onmouseenter="this.style.borderColor='var(--accent)'" onmouseleave="this.style.borderColor='${borderColor}'" onclick="fwShowTemplate('${t.id}')">
            <div style="display:flex;align-items:center;gap:8px">
                <span style="color:var(--accent);flex-shrink:0">${icon}</span>
                <div style="flex:1;min-width:0">
                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px">
                        <span style="font-size:.78rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${t.name}</span>
                        ${badge}
                        <span style="font-size:.52rem;color:var(--text3);font-family:var(--mono);margin-left:auto;flex-shrink:0">${ruleCount}</span>
                    </div>
                    <div style="font-size:.64rem;color:var(--text3);line-height:1.3;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${t.description}</div>
                </div>
            </div>
            ${assignedHtml}
        </div>`;
    });
    grid.innerHTML = html;
}

async function fwShowTemplate(id) {
    const tpl = _fwTemplates?.find(t => t.id === id);
    if (!tpl) return;
    document.getElementById('fwTplTitle').textContent = tpl.name;
    document.getElementById('fwTplDesc').textContent = tpl.description;

    // Editable rules table
    const inputStyle = 'background:rgba(255,255,255,.04);border:1px solid var(--border-subtle);border-radius:3px;color:var(--text);font-family:var(--mono);font-size:.68rem;padding:2px 6px;width:100%';
    let rhtml = '<table style="width:100%;border-collapse:collapse;font-size:.72rem"><thead><tr style="border-bottom:1px solid var(--border-subtle)">'
        + '<th style="padding:4px 6px;text-align:left;color:var(--text3);width:24px"></th>'
        + '<th style="padding:4px 6px;text-align:left;color:var(--text3)">Action</th>'
        + '<th style="padding:4px 6px;text-align:left;color:var(--text3)">Port</th>'
        + '<th style="padding:4px 6px;text-align:left;color:var(--text3)">Proto</th>'
        + '<th style="padding:4px 6px;text-align:left;color:var(--text3)">Source</th>'
        + '<th style="padding:4px 6px;text-align:left;color:var(--text3)">Comment</th>'
        + '</tr></thead><tbody>';
    tpl.rules.forEach((r, i) => {
        const ac = r.action === 'ACCEPT' ? 'var(--green)' : 'var(--red)';
        const isMacro = !!r.macro;
        rhtml += `<tr class="fwTplRow" style="border-bottom:1px solid var(--border-subtle)" data-idx="${i}">
            <td style="padding:3px 6px"><input type="checkbox" checked class="fwTplCb" data-idx="${i}" style="accent-color:var(--accent)"></td>
            <td style="padding:3px 6px;color:${ac};font-weight:600;font-size:.68rem">${r.action}</td>
            <td style="padding:3px 6px">${isMacro ? '<span style="color:var(--text3);font-size:.65rem">—</span>' : `<input style="${inputStyle}" class="fwTplPort" data-idx="${i}" value="${r.dport || ''}">`}</td>
            <td style="padding:3px 6px;font-size:.68rem">${r.macro || r.proto || 'tcp'}</td>
            <td style="padding:3px 6px"><input style="${inputStyle}" class="fwTplSrc" data-idx="${i}" value="${r.source || ''}"></td>
            <td style="padding:3px 6px;color:var(--text3);font-size:.68rem">${r.comment || ''}</td>
        </tr>`;
    });
    rhtml += '</tbody></table>';
    document.getElementById('fwTplRules').innerHTML = rhtml;
    // Store original rules for apply
    document.getElementById('fwTemplateModal')._rules = JSON.parse(JSON.stringify(tpl.rules));

    // VM/CT dropdown
    if (!_fwVmCache.length) {
        const vd = await api('fw-vm-list');
        if (vd.ok) _fwVmCache = vd.guests;
    }
    const sel = document.getElementById('fwTplTarget');
    sel.innerHTML = `<option value="">${T.fw_select_vm}</option>`;
    _fwVmCache.forEach(g => {
        const label = `${g.vmid} — ${g.name} (${g.type === 'qemu' ? 'VM' : 'CT'})`;
        sel.innerHTML += `<option value="${g.vmid}:${g.type}">${label}</option>`;
    });

    document.getElementById('fwTplClear').checked = false;
    document.getElementById('fwTplClear').onchange = function() {
        document.getElementById('fwTplClearWarn').style.display = this.checked ? '' : 'none';
    };
    document.getElementById('fwTplClearWarn').style.display = 'none';

    // Delete button (custom only)
    const delBtn = document.getElementById('fwTplDeleteBtn');
    if (tpl._type === 'custom') {
        delBtn.innerHTML = `<button class="btn btn-red" onclick="fwDeleteTemplate('${tpl.id}')" style="font-size:.7rem">${T.fw_delete_template}</button>`;
    } else { delBtn.innerHTML = ''; }

    document.getElementById('fwTemplateModal').dataset.tplId = id;
    openModal('fwTemplateModal');
}

async function fwApplyTemplate() {
    const modal = document.getElementById('fwTemplateModal');
    const id = modal.dataset.tplId;
    const target = document.getElementById('fwTplTarget').value;
    if (!target) return;
    const [vmid, type] = target.split(':');
    const clear = document.getElementById('fwTplClear').checked;

    // Collect edited rules (checked rows only, with edited ports/sources)
    const rules = modal._rules;
    const editedRules = [];
    rules.forEach((r, i) => {
        const cb = document.querySelector(`.fwTplCb[data-idx="${i}"]`);
        if (!cb || !cb.checked) return;
        const rule = {...r};
        const portInput = document.querySelector(`.fwTplPort[data-idx="${i}"]`);
        const srcInput = document.querySelector(`.fwTplSrc[data-idx="${i}"]`);
        if (portInput && portInput.value.trim()) rule.dport = portInput.value.trim();
        if (srcInput) rule.source = srcInput.value.trim() || undefined;
        editedRules.push(rule);
    });
    if (editedRules.length === 0) return;

    closeModal('fwTemplateModal');
    const d = await api('fw-vm-apply-template', 'POST', { vmid, type, template_id: id, clear_existing: clear ? '1' : '', rules_override: JSON.stringify(editedRules) });
    if (d.ok) {
        _fwVmCache = [];
        loadFwTemplates();
        loadFwVmList();
    }
}

async function fwDeleteTemplate(id) {
    if (!await appConfirm(T.fw_delete_template, T.fw_delete_template_confirm)) return;
    closeModal('fwTemplateModal');
    const d = await api('fw-template-delete', 'POST', { id });
    if (d.ok) loadFwTemplates();
}

// ── VM/CT Firewall-Liste und Status ─────────────────
async function loadFwVmList() {
    const el = document.getElementById('fwVmList');
    if (!el) return;
    el.innerHTML = `<div style="display:flex;align-items:center;justify-content:center;gap:8px;padding:14px"><span style="width:14px;height:14px;border:2px solid var(--border-subtle);border-top-color:var(--accent);border-radius:50%;animation:spin .6s linear infinite;flex-shrink:0"></span><span style="color:var(--text3)">${T.loading}</span></div>`;
    const d = await api('fw-vm-list');
    if (!d.ok) return;
    _fwVmCache = d.guests;
    if (d.guests.length === 0) {
        el.innerHTML = `<div style="padding:14px;text-align:center;color:var(--text3)">${T.fw_vm_no_guests}</div>`;
        return;
    }
    const thStyle = 'padding:8px 12px;font-size:.68rem;color:var(--text3);font-weight:600;white-space:nowrap';
    const tdStyle = 'padding:6px 12px;font-size:.75rem;vertical-align:middle';
    let html = `<table style="width:100%;border-collapse:collapse;table-layout:fixed">
        <colgroup><col style="width:50px"><col><col style="width:38px"><col style="width:120px"><col style="width:60px"><col style="width:75px"><col style="width:48px"><col style="width:38px"><col style="width:115px"><col style="width:110px"></colgroup>
        <thead><tr style="border-bottom:1px solid var(--border-subtle)">
            <th style="${thStyle}">VMID</th><th style="${thStyle}">Name</th><th style="${thStyle}">Type</th><th style="${thStyle}">IP</th><th style="${thStyle}">Status</th>
            <th style="${thStyle}">Firewall</th><th style="${thStyle}">Policy</th><th style="${thStyle}">${T.fw_vm_rules}</th><th style="${thStyle}">Template</th><th style="${thStyle};text-align:right"></th>
        </tr></thead><tbody>`;
    d.guests.forEach(g => {
        const dot = (color) => `<span style="width:7px;height:7px;border-radius:50%;background:${color};flex-shrink:0"></span>`;
        const fwDot = dot(g.fw_enabled ? 'var(--green)' : 'var(--red)');
        const fwLabel = g.fw_enabled ? T.fw_vm_enabled : T.fw_vm_disabled;
        const statusDot = dot(g.status === 'running' ? 'var(--green)' : 'var(--text3)');
        const typeBadge = g.type === 'qemu'
            ? '<span style="background:rgba(59,130,246,.15);color:#3b82f6;padding:1px 5px;border-radius:3px;font-size:.6rem;font-weight:600">VM</span>'
            : '<span style="background:rgba(139,92,246,.15);color:#8b5cf6;padding:1px 5px;border-radius:3px;font-size:.6rem;font-weight:600">CT</span>';
        const policyBadge = g.fw_enabled ? `<span style="font-family:var(--mono);font-size:.65rem;background:rgba(255,255,255,.06);padding:2px 6px;border-radius:3px">${g.fw_policy_in}</span>` : '<span style="color:var(--text3)">—</span>';
        const toggleColor = g.fw_enabled ? 'var(--green)' : 'var(--text3)';
        const toggleLabel = g.fw_enabled ? 'ON' : 'OFF';
        const toggleNext = g.fw_enabled ? 0 : 1;
        html += `<tr style="border-bottom:1px solid var(--border-subtle)">
            <td style="${tdStyle};font-family:var(--mono);font-weight:600;color:var(--text2)">${g.vmid}</td>
            <td style="${tdStyle};overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${g.name}</td>
            <td style="${tdStyle}">${typeBadge}</td>
            <td style="${tdStyle};font-family:var(--mono);font-size:.65rem">${g.ips && g.ips.length ? g.ips.map(ip => `<div style="display:flex;align-items:center;gap:4px">${g.is_public ? '<span style="width:6px;height:6px;border-radius:50%;background:var(--yellow);flex-shrink:0" title="Public"></span>' : '<span style="width:6px;height:6px;border-radius:50%;background:var(--text3);flex-shrink:0" title="Intern"></span>'}${ip}</div>`).join('') : '<span style="color:var(--text3)">—</span>'}</td>
            <td style="${tdStyle}"><div style="display:flex;align-items:center;gap:5px">${statusDot}<span style="font-size:.7rem">${g.status}</span></div></td>
            <td style="${tdStyle}"><div style="display:flex;align-items:center;gap:5px">${fwDot}<span style="font-size:.7rem">${fwLabel}</span></div></td>
            <td style="${tdStyle}">${policyBadge}</td>
            <td style="${tdStyle};font-family:var(--mono);text-align:center">${g.rule_count}</td>
            <td style="${tdStyle};font-size:.65rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${g.template ? `<span style="color:var(--accent)">${g.template.template_name}</span>` : '<span style="color:var(--text3)">—</span>'}</td>
            <td style="${tdStyle};text-align:right;white-space:nowrap">
                <button class="btn btn-sm" onclick="fwViewVmRules(${g.vmid},'${g.type}','${g.name}')" style="padding:2px 8px;font-size:.55rem">${T.fw_view_rules}</button>
                <button class="btn btn-sm" onclick="fwToggleVm(${g.vmid},'${g.type}',${toggleNext})" style="padding:2px 6px;font-size:.55rem;min-width:32px;color:${toggleColor};border-color:${toggleColor}">${toggleLabel}</button>
            </td>
        </tr>`;
    });
    html += '</tbody></table>';
    el.innerHTML = html;
}

async function fwToggleVm(vmid, type, enable) {
    const msg = enable ? T.fw_vm_enable_confirm : T.fw_vm_disable_confirm;
    if (!await appConfirm(T.fw_vm_firewall, msg, enable ? 'warning' : 'danger')) return;
    await api('fw-vm-toggle', 'POST', { vmid, type, enable });
    _fwVmCache = [];
    loadFwVmList();
}

// ── VM/CT Regel-Viewer und Bearbeitung ──────────────
let _fwVmRulesCtx = {};
async function fwViewVmRules(vmid, type, name) {
    _fwVmRulesCtx = { vmid, type };
    document.getElementById('fwVmRulesTitle').textContent = `${name} (${type === 'qemu' ? 'VM' : 'CT'} ${vmid}) — Firewall`;
    const d = await api('fw-vm-rules&vmid=' + vmid + '&type=' + type);
    if (!d.ok) return;
    const el = document.getElementById('fwVmRulesContent');
    if (d.rules.length === 0) {
        el.innerHTML = `<div style="text-align:center;color:var(--text3);padding:14px;font-size:.75rem">${T.sec_no_rules}</div>`;
    } else {
        let html = '<table style="width:100%;border-collapse:collapse;font-size:.72rem"><thead><tr style="border-bottom:1px solid var(--border-subtle)">'
            + '<th style="padding:4px 8px;text-align:left;color:var(--text3)">#</th>'
            + '<th style="padding:4px 8px;text-align:left;color:var(--text3)">Action</th>'
            + '<th style="padding:4px 8px;text-align:left;color:var(--text3)">Port</th>'
            + '<th style="padding:4px 8px;text-align:left;color:var(--text3)">Proto</th>'
            + '<th style="padding:4px 8px;text-align:left;color:var(--text3)">Source</th>'
            + '<th style="padding:4px 8px;text-align:left;color:var(--text3)">Comment</th>'
            + '<th style="padding:4px 8px;text-align:right;color:var(--text3)"></th>'
            + '</tr></thead><tbody>';
        d.rules.forEach(r => {
            const ac = r.action === 'ACCEPT' ? 'var(--green)' : 'var(--red)';
            html += `<tr style="border-bottom:1px solid var(--border-subtle)">
                <td style="padding:4px 8px;color:var(--text3)">${r.pos ?? ''}</td>
                <td style="padding:4px 8px;color:${ac};font-weight:600">${r.action}</td>
                <td style="padding:4px 8px;font-family:var(--mono)">${r.dport || (r.macro ? '—' : '*')}</td>
                <td style="padding:4px 8px">${r.macro || r.proto || ''}</td>
                <td style="padding:4px 8px;font-family:var(--mono)">${r.source || '*'}</td>
                <td style="padding:4px 8px;color:var(--text3)">${r.comment || ''}</td>
                <td style="padding:4px 8px;text-align:right">
                    <button class="btn btn-sm btn-red" onclick="fwVmDeleteRule(${r.pos})" style="padding:1px 5px;font-size:.5rem" title="${T.sec_delete_rule}">\u2715</button>
                </td>
            </tr>`;
        });
        html += '</tbody></table>';
        el.innerHTML = html;
    }
    openModal('fwVmRulesModal');
}

async function fwVmDeleteRule(pos) {
    if (!await appConfirm(T.sec_delete_rule, T.sec_delete_rule_confirm)) return;
    await api('fw-vm-delete-rule', 'POST', { vmid: _fwVmRulesCtx.vmid, type: _fwVmRulesCtx.type, pos });
    fwViewVmRules(_fwVmRulesCtx.vmid, _fwVmRulesCtx.type, document.getElementById('fwVmRulesTitle').textContent.split(' —')[0]);
    _fwVmCache = [];
}

async function fwVmAddRule() {
    const d = await api('fw-vm-add-rule', 'POST', {
        vmid: _fwVmRulesCtx.vmid, type: _fwVmRulesCtx.type,
        rule_action: document.getElementById('fwVmrAction').value,
        rule_type: 'in',
        dport: document.getElementById('fwVmrPort').value,
        proto: document.getElementById('fwVmrProto').value,
        source: document.getElementById('fwVmrSource').value,
        comment: document.getElementById('fwVmrComment').value,
    });
    if (d.ok) {
        document.getElementById('fwVmrPort').value = '';
        document.getElementById('fwVmrComment').value = '';
        document.getElementById('fwVmrSource').value = '';
        fwViewVmRules(_fwVmRulesCtx.vmid, _fwVmRulesCtx.type, document.getElementById('fwVmRulesTitle').textContent.split(' —')[0]);
        _fwVmCache = [];
    }
}

// ── Custom Template Builder (Modal) ─────────────────
function fwOpenBuilder(editId) {
    document.getElementById('fwbEditId').value = editId || '';
    document.getElementById('fwbName').value = '';
    document.getElementById('fwbDesc').value = '';
    const rules = document.getElementById('fwbRules');
    rules.innerHTML = '';

    if (editId) {
        const tpl = _fwTemplates?.find(t => t.id === editId);
        if (tpl) {
            document.getElementById('fwbName').value = tpl.name;
            document.getElementById('fwbDesc').value = tpl.description;
            tpl.rules.forEach(r => fwbAddRow(r));
        }
    } else {
        // Start with SSH rule
        fwbAddRow({ action: 'ACCEPT', type: 'in', dport: '22', proto: 'tcp', comment: 'SSH' });
    }
    openModal('fwBuilderModal');
}

let _fwbRowIdx = 0;
function fwbAddRow(rule) {
    const r = rule || { action: 'ACCEPT', type: 'in', dport: '', proto: 'tcp', comment: '' };
    const idx = _fwbRowIdx++;
    const row = document.createElement('div');
    row.style.cssText = 'display:grid;grid-template-columns:80px 70px 80px 70px 100px 1fr 24px;gap:4px;margin-bottom:4px;align-items:center';
    row.innerHTML = `
        <select class="form-input" style="font-size:.65rem;padding:3px 4px" data-f="action"><option ${r.action==='ACCEPT'?'selected':''}>ACCEPT</option><option ${r.action==='DROP'?'selected':''}>DROP</option><option ${r.action==='REJECT'?'selected':''}>REJECT</option></select>
        <select class="form-input" style="font-size:.65rem;padding:3px 4px" data-f="type"><option value="in" ${r.type==='in'?'selected':''}>IN</option><option value="out" ${r.type==='out'?'selected':''}>OUT</option></select>
        <input class="form-input" style="font-size:.65rem;padding:3px 4px" data-f="dport" placeholder="Port" value="${r.dport||''}">
        <select class="form-input" style="font-size:.65rem;padding:3px 4px" data-f="proto"><option value="tcp" ${r.proto==='tcp'?'selected':''}>TCP</option><option value="udp" ${r.proto==='udp'?'selected':''}>UDP</option></select>
        <input class="form-input" style="font-size:.65rem;padding:3px 4px" data-f="source" placeholder="Source" value="${r.source||''}">
        <input class="form-input" style="font-size:.65rem;padding:3px 4px" data-f="comment" placeholder="Comment" value="${r.comment||''}">
        <button class="btn btn-sm btn-red" onclick="this.parentElement.remove()" style="padding:1px 4px;font-size:.5rem;line-height:1">\u2715</button>`;
    document.getElementById('fwbRules').appendChild(row);
}

async function fwSaveCustom() {
    const name = document.getElementById('fwbName').value.trim();
    const desc = document.getElementById('fwbDesc').value.trim();
    const editId = document.getElementById('fwbEditId').value;
    if (!name) return;

    const rules = [];
    document.querySelectorAll('#fwbRules > div').forEach(row => {
        const r = {};
        row.querySelectorAll('[data-f]').forEach(el => { r[el.dataset.f] = el.value; });
        if (r.dport || r.action) rules.push(r);
    });
    if (rules.length === 0) return;

    const payload = { name, description: desc, icon: 'shield', rules: JSON.stringify(rules) };
    if (editId) payload.id = editId;

    const d = await api('fw-template-save', 'POST', payload);
    if (d.ok) {
        closeModal('fwBuilderModal');
        loadFwTemplates();
    }
}
