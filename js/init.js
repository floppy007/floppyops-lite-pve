/**
 * FloppyOps Lite PVE — Init
 * Initialization — restore saved tab from URL hash, start auto-refresh
 *
 * @requires app.js (api, toast, fmtBytes, pct)
 */

    m.addEventListener('click', e => {
        if (e.target === m && m.id !== 'wgWizardModal') closeModal(m.id);
    });
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.active').forEach(m => closeModal(m.id));
});

// ── Init ─────────────────────────────────────────────
loadStats();
setInterval(loadStats, 30000);
// Restore tab from URL hash (after all functions are defined)
if (location.hash && location.hash.length > 1) {
    const savedTab = location.hash.substring(1);
    if (document.querySelector('.nav-tab[data-tab="' + savedTab + '"]')) {
        switchTab(savedTab);
    }
}

