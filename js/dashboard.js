/**
 * FloppyOps Lite — Dashboard
 * Dashboard — Live-Charts und System-Statistiken
 */

const _chartOpts = {responsive:true,maintainAspectRatio:false,animation:{duration:300},plugins:{legend:{display:false}},scales:{x:{display:false},y:{display:false,beginAtZero:true}}};
const _chartLen = 30; // data points
const _chartData = {cpu:[],mem:[],netRx:[],netTx:[],diskR:[],diskW:[]};
let _prevNet = null, _prevDisk = null, _prevCpu = null;
let _cpuChart, _memChart, _netChart, _diskChart;

function initDashCharts() {
    if (_cpuChart) return;
    const labels = Array(_chartLen).fill('');
    const mkDs = (color, alpha) => ({data:Array(_chartLen).fill(0),borderColor:color,backgroundColor:alpha,borderWidth:2,pointRadius:0,fill:true,tension:.4});
    _cpuChart = new Chart(document.getElementById('chartCpu'), {type:'line',data:{labels,datasets:[mkDs('rgba(64,196,255,1)','rgba(64,196,255,.1)')]},options:{..._chartOpts,scales:{..._chartOpts.scales,y:{display:false,beginAtZero:true,max:100}}}});
    _memChart = new Chart(document.getElementById('chartMem'), {type:'line',data:{labels,datasets:[mkDs('rgba(255,89,0,1)','rgba(255,89,0,.1)')]},options:{..._chartOpts,scales:{..._chartOpts.scales,y:{display:false,beginAtZero:true,max:100}}}});
    _netChart = new Chart(document.getElementById('chartNet'), {type:'line',data:{labels,datasets:[mkDs('rgba(40,167,69,1)','rgba(40,167,69,.08)'),mkDs('rgba(40,167,69,.5)','rgba(40,167,69,.04)')]},options:_chartOpts});
    _diskChart = new Chart(document.getElementById('chartDisk'), {type:'line',data:{labels,datasets:[mkDs('rgba(255,193,7,1)','rgba(255,193,7,.08)'),mkDs('rgba(255,193,7,.5)','rgba(255,193,7,.04)')]},options:_chartOpts});
}

function pushChart(chart, idx, val) {
    chart.data.datasets[idx].data.push(val);
    if (chart.data.datasets[idx].data.length > _chartLen) chart.data.datasets[idx].data.shift();
}

async function loadStats() {
    try {
        const d = await api('stats');
        document.getElementById('hostLabel').textContent = d.hostname;
        document.getElementById('sHostname').textContent = d.hostname;
        document.getElementById('sKernel').textContent = d.kernel;
        document.getElementById('sUptime').textContent = d.uptime.replace('up ', '');
        document.getElementById('sUptimeSince').textContent = 'seit ' + d.uptime_since;

        const loadPct = Math.min(100, Math.round(d.load[0] / d.cpu_cores * 100));
        document.getElementById('sLoad').textContent = d.cpu_pct + '%';
        document.getElementById('sLoadSub').textContent = 'Load: ' + d.load.map(l => l.toFixed(2)).join(' / ') + ' (' + d.cpu_cores + ' Cores)';
        document.getElementById('sLoadBar').style.width = d.cpu_pct + '%';

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

        // Subscription
        const subEl = document.getElementById('sSub');
        const subLvl = document.getElementById('sSubLevel');
        if (d.sub_active) {
            subEl.textContent = 'Active';
            subEl.style.color = 'var(--green)';
            subLvl.textContent = d.sub_level || 'Licensed';
        } else {
            subEl.textContent = 'None';
            subEl.style.color = 'var(--yellow)';
            subLvl.textContent = 'Community';
        }

        // Tab badge
        const badge = document.getElementById('f2bBadge');
        if (d.f2b_banned > 0) { badge.textContent = d.f2b_banned; badge.style.display = ''; }
        else badge.style.display = 'none';

        // Updates card on dashboard
        const updEl = document.getElementById('sUpdates');
        if (updEl) {
            updEl.textContent = d.updates;
            updEl.style.color = d.updates > 0 ? 'var(--accent)' : 'var(--green)';
        }

        // Charts
        initDashCharts();
        // CPU — client-side delta for accuracy
        let cpuPct = d.cpu_pct; // fallback: load-based
        if (_prevCpu !== null && d.cpu_total > _prevCpu.total) {
            const dTotal = d.cpu_total - _prevCpu.total;
            const dIdle = d.cpu_idle - _prevCpu.idle;
            cpuPct = Math.round((1 - dIdle / dTotal) * 100);
        }
        _prevCpu = {idle: d.cpu_idle, total: d.cpu_total};
        pushChart(_cpuChart, 0, cpuPct);
        document.getElementById('chartCpuVal').textContent = cpuPct + '%';
        document.getElementById('sLoad').textContent = cpuPct + '%';
        _cpuChart.update('none');

        pushChart(_memChart, 0, memP);
        document.getElementById('chartMemVal').textContent = memP + '%';
        _memChart.update('none');

        // Network rate (delta between polls)
        if (_prevNet !== null) {
            const rxRate = Math.max(0, (d.net_rx - _prevNet.rx) / 4); // 4s interval
            const txRate = Math.max(0, (d.net_tx - _prevNet.tx) / 4);
            pushChart(_netChart, 0, rxRate);
            pushChart(_netChart, 1, txRate);
            document.getElementById('chartNetVal').textContent = '↓' + fmtBytes(rxRate) + '/s  ↑' + fmtBytes(txRate) + '/s';
            _netChart.update('none');
        }
        _prevNet = {rx: d.net_rx, tx: d.net_tx};

        // Disk I/O rate
        if (_prevDisk !== null) {
            const rRate = Math.max(0, (d.disk_read - _prevDisk.r) / 4);
            const wRate = Math.max(0, (d.disk_write - _prevDisk.w) / 4);
            pushChart(_diskChart, 0, rRate);
            pushChart(_diskChart, 1, wRate);
            document.getElementById('chartDiskVal').textContent = 'R:' + fmtBytes(rRate) + '/s  W:' + fmtBytes(wRate) + '/s';
            _diskChart.update('none');
        }
        _prevDisk = {r: d.disk_read, w: d.disk_write};
    } catch (e) {
    }
}
