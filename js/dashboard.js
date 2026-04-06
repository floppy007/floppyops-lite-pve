/**
 * FloppyOps Lite PVE — Dashboard
 * Dashboard — load server stats, update UI elements, auto-refresh
 *
 * @requires app.js (api, toast, fmtBytes, pct)
 */

async function loadStats() {
    try {
        const d = await api('stats');
        document.getElementById('hostLabel').textContent = d.hostname;
        document.getElementById('sHostname').textContent = d.hostname;
        document.getElementById('sKernel').textContent = d.kernel;
        document.getElementById('sUptime').textContent = d.uptime.replace('up ', '');
        document.getElementById('sUptimeSince').textContent = T.since + ' ' + d.uptime_since;

        const loadPct = Math.min(100, Math.round(d.load[0] / d.cpu_cores * 100));
        document.getElementById('sLoad').textContent = d.load[0].toFixed(2);
        document.getElementById('sLoadSub').textContent = d.load.map(l => l.toFixed(2)).join(' / ') + ' (' + d.cpu_cores + ' Cores)';
        document.getElementById('sLoadBar').style.width = loadPct + '%';

        const memP = pct(d.mem_used, d.mem_total);
        document.getElementById('sMem').textContent = memP + '%';
        document.getElementById('sMemSub').textContent = fmtBytes(d.mem_used) + ' / ' + fmtBytes(d.mem_total);
        document.getElementById('sMemBar').style.width = memP + '%';

        const diskP = pct(d.disk_used, d.disk_total);
        document.getElementById('sDisk').textContent = diskP + '%';
        document.getElementById('sDiskSub').textContent = fmtBytes(d.disk_used) + ' / ' + fmtBytes(d.disk_total);
        document.getElementById('sDiskBar').style.width = diskP + '%';

        document.getElementById('sF2bJails').textContent = d.f2b_jails;
        document.getElementById('sF2bBanned').textContent = d.f2b_banned;
        document.getElementById('sNginxSites').textContent = d.nginx_sites;

        // ZFS datasets
        const zfsSec = document.getElementById('zfsSection');
        const zfsBody = document.getElementById('zfsBody');
        if (d.zfs && d.zfs.length > 0) {
            zfsSec.style.display = '';
            zfsBody.innerHTML = '';
            d.zfs.forEach(ds => {
                const p = pct(ds.used, ds.total);
                let barClass = 'green';
                if (p > 85) barClass = 'red';
                else if (p > 70) barClass = '';
                zfsBody.innerHTML += `<tr>
                    <td style="font-family:var(--mono);font-size:.82rem">${ds.name}</td>
                    <td>${fmtBytes(ds.used)}</td>
                    <td>${fmtBytes(ds.avail)}</td>
                    <td style="min-width:140px">
                        <div style="display:flex;align-items:center;gap:8px">
                            <span style="font-family:var(--mono);font-size:.78rem;min-width:32px">${p}%</span>
                            <div class="progress-wrap" style="flex:1;margin:0"><div class="progress-bar ${barClass}" style="width:${p}%"></div></div>
                        </div>
                    </td>
                </tr>`;
            });
        } else {
            zfsSec.style.display = 'none';
        }

        // Tab badge
        const badge = document.getElementById('f2bBadge');
        if (d.f2b_banned > 0) {
            badge.textContent = d.f2b_banned;
            badge.style.display = '';
        } else {
            badge.style.display = 'none';
        }
    } catch (e) { /* stats error */ }
}

