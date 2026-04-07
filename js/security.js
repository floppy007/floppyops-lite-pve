/**
 * FloppyOps Lite — Security
 * Security — Port-Scan, Host-Firewall Regeln
 */

const SEC_RISK_COLORS = { critical: 'var(--red)', high: '#f97316', medium: 'var(--yellow)', low: 'var(--blue)' };

async function loadSecScan() {
    const sp = `<div style="display:flex;align-items:center;justify-content:center;gap:8px;padding:14px"><span style="width:14px;height:14px;border:2px solid var(--border-subtle);border-top-color:var(--accent);border-radius:50%;animation:spin .6s linear infinite;flex-shrink:0"></span><span style="color:var(--text3)">${T.sec_scanning}</span></div>`;
    ['secSummary','secPortList','secFwStatus'].forEach(id => { const e = document.getElementById(id); if (e) e.innerHTML = sp; });
    const d = await api('sec-scan');
    if (!d.ok) return;
    const s = d.summary;

    // Badge
    const badge = document.getElementById('secBadge');
    if (s.risky_ports > 0) { badge.textContent = s.risky_ports; badge.style.display = ''; }
    else badge.style.display = 'none';
    document.getElementById('secRiskCount').textContent = s.risky_ports;

    // Summary cards
    const fwColor = s.fw_active ? 'var(--green)' : 'var(--red)';
    const fwText = s.fw_active ? T.sec_fw_enabled : T.sec_fw_disabled;
    document.getElementById('secSummary').innerHTML = `
        <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:12px 14px;text-align:center">
            <div style="font-size:1.3rem;font-weight:800;color:var(--text1)">${s.total_ports}</div>
            <div style="font-size:.65rem;color:var(--text3)">${T.sec_total_ports}</div>
        </div>
        <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:12px 14px;text-align:center">
            <div style="font-size:1.3rem;font-weight:800;color:var(--yellow)">${s.external_ports}</div>
            <div style="font-size:.65rem;color:var(--text3)">${T.sec_external}</div>
        </div>
        <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:12px 14px;text-align:center">
            <div style="font-size:1.3rem;font-weight:800;color:${s.risky_ports > 0 ? 'var(--red)' : 'var(--green)'}">${s.risky_ports}</div>
            <div style="font-size:.65rem;color:var(--text3)">${T.sec_risky_ports}</div>
        </div>
        <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:12px 14px;text-align:center">
            <div style="font-size:1.3rem;font-weight:800;color:${fwColor}">${fwText}</div>
            <div style="font-size:.65rem;color:var(--text3)">${T.sec_pve_firewall}</div>
        </div>`;

    // Port list
    if (d.ports.length === 0) {
        document.getElementById('secPortList').innerHTML = `<div style="padding:14px;color:var(--text3);text-align:center">${T.sec_no_risks}</div>`;
    } else {
        let html = '<table style="width:100%;border-collapse:collapse"><thead><tr style="border-bottom:1px solid var(--border-subtle)">'
            + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_port}</th>`
            + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_service}</th>`
            + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_process}</th>`
            + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_address}</th>`
            + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_risk}</th>`
            + `<th style="padding:8px 12px;text-align:right;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_action}</th>`
            + '</tr></thead><tbody>';
        d.ports.forEach(p => {
            const riskBadge = p.risk
                ? `<span style="background:${SEC_RISK_COLORS[p.risk]};color:#fff;padding:1px 7px;border-radius:4px;font-size:.6rem;font-weight:600">${T['sec_risk_' + p.risk]}</span>`
                : (p.external ? `<span style="color:var(--text3);font-size:.65rem">—</span>` : `<span style="color:var(--green);font-size:.65rem">${T.sec_safe}</span>`);
            const addrBadge = p.external
                ? `<span style="color:var(--yellow);font-size:.7rem">${p.addr}</span>`
                : `<span style="color:var(--green);font-size:.7rem">${p.addr}</span>`;
            const blockBtn = p.risk
                ? `<button class="btn btn-sm btn-red" onclick="secBlockPort(${p.port},'${p.service}')" style="padding:2px 8px;font-size:.6rem">${T.sec_blocked}</button>`
                : '';
            html += `<tr style="border-bottom:1px solid var(--border-subtle)">
                <td style="padding:6px 12px;font-family:var(--mono);font-weight:600">${p.port}</td>
                <td style="padding:6px 12px">${p.service}</td>
                <td style="padding:6px 12px;color:var(--text3)">${p.process}</td>
                <td style="padding:6px 12px">${addrBadge}</td>
                <td style="padding:6px 12px">${riskBadge}</td>
                <td style="padding:6px 12px;text-align:right">${blockBtn}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        document.getElementById('secPortList').innerHTML = html;
    }

    // Firewall status
    const fw = d.firewall;
    const dot = (on) => `<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${on ? 'var(--green)' : 'var(--red)'};margin-right:6px"></span>`;
    const policyBadge = fw.dc_enabled ? `<span style="background:rgba(255,255,255,.06);padding:1px 8px;border-radius:4px;font-size:.6rem;font-family:var(--mono);margin-left:6px">Input: ${fw.dc_policy_in || 'ACCEPT'}</span>` : '';
    document.getElementById('secFwStatus').innerHTML = `
        <div style="display:flex;gap:24px;align-items:center;flex-wrap:wrap">
            <div style="display:flex;align-items:center;gap:12px">
                <div>${dot(fw.dc_enabled)}${T.sec_dc_level}: <strong>${fw.dc_enabled ? T.sec_fw_enabled : T.sec_fw_disabled}</strong>${policyBadge}</div>
                ${!fw.dc_enabled ? `<button class="btn btn-sm btn-green" onclick="secEnableFw('dc')" style="padding:2px 10px;font-size:.6rem">${T.sec_fw_enable}</button>` : ''}
            </div>
            <div style="display:flex;align-items:center;gap:12px">
                <div>${dot(fw.node_enabled)}${T.sec_node_level} (${fw.node}): <strong>${fw.node_enabled ? T.sec_fw_enabled : T.sec_fw_disabled}</strong></div>
                ${!fw.node_enabled ? `<button class="btn btn-sm btn-green" onclick="secEnableFw('node')" style="padding:2px 10px;font-size:.6rem">${T.sec_fw_enable}</button>` : ''}
            </div>
        </div>`;
}

async function loadSecFwRules() {
    const rl = document.getElementById('secRuleList');
    if (rl) rl.innerHTML = `<div style="display:flex;align-items:center;justify-content:center;gap:8px;padding:14px"><span style="width:14px;height:14px;border:2px solid var(--border-subtle);border-top-color:var(--accent);border-radius:50%;animation:spin .6s linear infinite;flex-shrink:0"></span><span style="color:var(--text3)">${T.loading}</span></div>`;
    const d = await api('sec-fw-rules');
    if (!d.ok) return;
    const all = [
        ...d.node_rules.map(r => ({...r, _level: 'node'})),
        ...d.cluster_rules.map(r => ({...r, _level: 'dc'}))
    ];
    if (all.length === 0) {
        document.getElementById('secRuleList').innerHTML = `<div style="padding:14px;color:var(--text3);text-align:center;font-size:.78rem">${T.sec_no_rules}</div>`;
        return;
    }
    let html = '<table style="width:100%;border-collapse:collapse"><thead><tr style="border-bottom:1px solid var(--border-subtle)">'
        + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">#</th>`
        + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_action}</th>`
        + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_rule_type}</th>`
        + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_rule_dport}</th>`
        + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_rule_source}</th>`
        + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_rule_comment}</th>`
        + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">Level</th>`
        + `<th style="padding:8px 12px;text-align:right;font-size:.7rem;color:var(--text3);font-weight:600"></th>`
        + '</tr></thead><tbody>';
    all.forEach(r => {
        const actionColor = r.action === 'ACCEPT' ? 'var(--green)' : r.action === 'DROP' ? 'var(--red)' : 'var(--yellow)';
        const levelBadge = r._level === 'dc'
            ? '<span style="background:rgba(96,165,250,.15);color:#60a5fa;padding:1px 6px;border-radius:3px;font-size:.6rem">DC</span>'
            : '<span style="background:rgba(255,255,255,.06);color:var(--text3);padding:1px 6px;border-radius:3px;font-size:.6rem">Node</span>';
        html += `<tr style="border-bottom:1px solid var(--border-subtle)">
            <td style="padding:6px 12px;color:var(--text3)">${r.pos ?? ''}</td>
            <td style="padding:6px 12px;font-weight:600;color:${actionColor}">${r.action ?? ''}</td>
            <td style="padding:6px 12px">${r.type ?? ''}</td>
            <td style="padding:6px 12px;font-family:var(--mono)">${r.dport ?? '*'}</td>
            <td style="padding:6px 12px;font-family:var(--mono)">${r.source ?? '*'}</td>
            <td style="padding:6px 12px;color:var(--text3);font-size:.7rem">${r.comment ?? ''}</td>
            <td style="padding:6px 12px">${levelBadge}</td>
            <td style="padding:6px 12px;text-align:right">
                <button class="btn btn-sm btn-red" onclick="secDeleteRule(${r.pos},'${r._level}')" style="padding:1px 6px;font-size:.55rem" title="${T.sec_delete_rule}">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                </button>
            </td>
        </tr>`;
    });
    html += '</tbody></table>';
    document.getElementById('secRuleList').innerHTML = html;
}

async function secBlockPort(port, service) {
    const msg = T.sec_block_confirm.replace('%d', port).replace('%s', service);
    if (!await appConfirm(T.sec_block_port, msg)) return;
    const d = await api('sec-fw-block', 'POST', { port });
    if (d.ok) { loadSecScan(); loadSecFwRules(); }
}

async function secEnableFw(level) {
    if (!await appConfirm(T.sec_fw_enable, T.sec_fw_enable_warn, 'warning')) return;
    const d = await api('sec-fw-enable', 'POST', { level });
    if (d.ok) { loadSecScan(); loadSecFwRules(); }
}

async function secDeleteRule(pos, level) {
    if (!await appConfirm(T.sec_delete_rule, T.sec_delete_rule_confirm)) return;
    const d = await api('sec-fw-delete-rule', 'POST', { pos, level });
    if (d.ok) loadSecFwRules();
}

function secApplyDefaults() { openModal('secDefaultsModal'); }
async function secApplyDefaultsConfirm() {
    const selected = [];
    document.querySelectorAll('.sec-def-cb').forEach(cb => {
        if (cb.checked) selected.push(parseInt(cb.dataset.idx));
    });
    if (selected.length === 0) { closeModal('secDefaultsModal'); return; }
    closeModal('secDefaultsModal');
    const d = await api('sec-fw-defaults', 'POST', { selected: JSON.stringify(selected) });
    if (d.ok) { loadSecScan(); loadSecFwRules(); }
}

function secAddRuleModal() { openModal('secRuleModal'); }
async function secSaveRule() {
    const d = await api('sec-fw-add-rule', 'POST', {
        rule_action: document.getElementById('sarAction').value,
        type: document.getElementById('sarType').value,
        dport: document.getElementById('sarDport').value,
        source: document.getElementById('sarSource').value,
        comment: document.getElementById('sarComment').value,
        level: document.getElementById('sarLevel').value
    });
    if (d.ok) { closeModal('secRuleModal'); loadSecScan(); loadSecFwRules(); }
}
