</style>
</head>
<body>

<div class="app">
    <!-- ─ Topbar ──────────────────────────────────────── -->
    <div class="topbar">
        <div class="topbar-brand">
            <div style="width:26px;height:26px;border-radius:5px;background:linear-gradient(135deg,var(--accent),#e04d00);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M8 2l1.88 1.88M14.12 3.88L16 2M9 7.13v-1a3.003 3.003 0 116 0v1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a6 6 0 0112 0v3c0 3.3-2.7 6-6 6z"/><path d="M12 20v2M8.5 15h.01M15.5 15h.01"/></svg>
            </div>
            <span><?= APP_NAME ?></span>
        </div>
        <div style="display:flex;align-items:center;gap:2px;margin-left:24px;flex:1" id="topNavTabs">
            <button class="nav-tab active" data-tab="dashboard">
                <svg class="tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                <span class="tab-text"><?= __('tab_dashboard') ?></span>
            </button>
            <button class="nav-tab" data-tab="vms">
                <svg class="tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                <span class="tab-text"><?= __('tab_vms') ?></span>
            </button>
            <button class="nav-tab" data-tab="fail2ban">
                <svg class="tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                <span class="tab-text"><?= __('tab_fail2ban') ?></span>
                <span class="badge" id="f2bBadge" style="display:none">0</span>
            </button>
            <button class="nav-tab" data-tab="nginx">
                <svg class="tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <span class="tab-text"><?= __('tab_nginx') ?></span>
            </button>
            <button class="nav-tab" data-tab="zfs">
                <svg class="tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
                <span class="tab-text"><?= __('tab_zfs') ?></span>
            </button>
            <button class="nav-tab" data-tab="wireguard">
                <svg class="tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M16.24 7.76a6 6 0 0 1 0 8.49m-8.48-.01a6 6 0 0 1 0-8.49"/></svg>
                <span class="tab-text"><?= __('tab_vpn') ?></span>
            </button>
            <button class="nav-tab" data-tab="updates">
                <svg class="tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                <span class="tab-text">Updates</span>
            </button>
        </div>
        <div style="display:flex;align-items:center;gap:8px">
            <div class="topbar-host" id="hostLabel">---</div>
            <div style="display:flex;border-radius:4px;overflow:hidden;border:1px solid var(--border-subtle)">
                <a href="?lang=de" style="padding:2px 6px;font-size:.55rem;font-weight:600;text-decoration:none;<?= $lang === 'de' ? 'background:var(--accent);color:#fff' : 'color:var(--text3)' ?>">DE</a>
                <a href="?lang=en" style="padding:2px 6px;font-size:.55rem;font-weight:600;text-decoration:none;<?= $lang === 'en' ? 'background:var(--accent);color:#fff' : 'color:var(--text3)' ?>">EN</a>
            </div>
            <span style="font-size:.65rem;color:var(--text3);font-family:var(--mono)"><?= htmlspecialchars($_SESSION['auth_user'] ?? 'admin') ?></span>
            <a href="https://github.com/floppy007/floppyops-lite-pve/issues/new?template=bug_report.md" target="_blank" style="color:var(--text3);display:flex;text-decoration:none;padding:4px;border-radius:4px;transition:color .2s" title="Bug melden" onmouseover="this.style.color='var(--yellow)'" onmouseout="this.style.color='var(--text3)'">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2l1.88 1.88M14.12 3.88L16 2M9 7.13v-1a3.003 3.003 0 116 0v1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a6 6 0 0112 0v3c0 3.3-2.7 6-6 6z"/><path d="M12 20v2M8.5 15h.01M15.5 15h.01"/></svg>
            </a>
            <a href="https://github.com/floppy007/floppyops-lite-pve/issues/new?template=feature_request.md" target="_blank" style="color:var(--text3);display:flex;text-decoration:none;padding:4px;border-radius:4px;transition:color .2s" title="Feature Request" onmouseover="this.style.color='var(--blue)'" onmouseout="this.style.color='var(--text3)'">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
            </a>
            <a href="?logout=1" style="color:var(--text3);display:flex;text-decoration:none;padding:4px;border-radius:4px;transition:color .2s" title="Logout" onmouseover="this.style.color='var(--red)'" onmouseout="this.style.color='var(--text3)'">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </a>
        </div>
    </div>

    <!-- ─ Content ─────────────────────────────────────── -->
    <div class="content">
