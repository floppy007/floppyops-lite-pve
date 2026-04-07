<?php
/**
 * FloppyOps Lite — API: Dashboard
 *
 * System-Statistiken (CPU, RAM, Disk, Netzwerk) fuer Dashboard-Cards
 * und Live-Charts. Liefert auch Fail2ban-Summary, Nginx-Sites, ZFS
 * und Firewall-Kurzinfos.
 *
 * Endpoints: stats
 */

/**
 * Liefert System-Statistiken fuer Dashboard-Cards und Live-Charts.
 * CPU, RAM, Disk, Netzwerk, Fail2ban-Summary, Nginx-Sites, ZFS, Firewall.
 *
 * Endpoints: stats
 *
 * @param string $action Der API-Action-Name
 * @return bool true wenn behandelt
 */
function handleDashboardAPI(string $action): bool {
    // GET: Echtzeit-System-Statistiken (CPU, RAM, Disk, Netzwerk, F2B, Nginx)
    if ($action === 'stats') {
        $uptime = trim(shell_exec('uptime -p 2>/dev/null') ?? '');
        $uptimeSince = trim(shell_exec('uptime -s 2>/dev/null') ?? '');
        $load = sys_getloadavg();
        $hostname = trim(shell_exec('hostname 2>/dev/null') ?? '');
        $kernel = trim(shell_exec('uname -r 2>/dev/null') ?? '');
        $cpuCores = (int)trim(shell_exec('nproc 2>/dev/null') ?? '1');

        // Disk
        $diskTotal = disk_total_space('/');
        $diskFree = disk_free_space('/');
        $diskUsed = $diskTotal - $diskFree;

        // Memory
        $memInfo = shell_exec('free -b 2>/dev/null') ?? '';
        preg_match('/Mem:\s+(\d+)\s+(\d+)/', $memInfo, $mm);
        $memTotal = (int)($mm[1] ?? 0);
        $memUsed = (int)($mm[2] ?? 0);

        // Fail2ban summary
        $f2bRaw = shell_exec('sudo fail2ban-client status 2>/dev/null') ?? '';
        preg_match('/Jail list:\s*(.*)$/m', $f2bRaw, $jm);
        $jails = $jm[1] ?? '';
        $jailList = array_filter(array_map('trim', explode(',', $jails)));
        $totalBanned = 0;
        foreach ($jailList as $j) {
            $jStatus = shell_exec("sudo fail2ban-client status " . escapeshellarg($j) . " 2>/dev/null") ?? '';
            preg_match('/Currently banned:\s*(\d+)/', $jStatus, $bm);
            $totalBanned += (int)($bm[1] ?? 0);
        }

        // Nginx sites
        $sitesDir = NGINX_SITES_DIR;
        $sites = is_dir($sitesDir) ? count(array_diff(scandir($sitesDir), ['.', '..', 'default'])) : 0;

        // PVE Firewall
        $fwRules = trim(shell_exec('grep -c "^IN " /etc/pve/firewall/cluster.fw 2>/dev/null') ?? '0');

        // ZFS datasets
        $zfsRaw = shell_exec('sudo /usr/sbin/zfs list -Hp -o name,used,avail,refer,mountpoint 2>/dev/null') ?? '';
        $zfsDatasets = [];
        foreach (array_filter(explode("\n", trim($zfsRaw))) as $line) {
            $cols = preg_split('/\t/', $line);
            if (count($cols) >= 4) {
                $used = (int)$cols[1];
                $avail = (int)$cols[2];
                $zfsDatasets[] = [
                    'name' => $cols[0],
                    'used' => $used,
                    'avail' => $avail,
                    'total' => $used + $avail,
                    'refer' => (int)$cols[3],
                    'mount' => $cols[4] ?? '-',
                ];
            }
        }

        // CPU usage % — from /proc/stat (cumulative values, delta calculated client-side)
        $cpuStat = trim(shell_exec("head -1 /proc/stat") ?? '');
        $cpuIdle = 0; $cpuTotal = 0;
        if (preg_match('/^cpu\s+(.+)/', $cpuStat, $cm)) {
            $v = array_map('intval', preg_split('/\s+/', $cm[1]));
            $cpuIdle = $v[3] + ($v[4] ?? 0);
            $cpuTotal = array_sum($v);
        }
        // Fallback: load-based estimate
        $cpuPct = $cpuCores > 0 ? min(100, round($load[0] / $cpuCores * 100, 1)) : 0;

        // PVE Subscription
        $subRaw = trim(shell_exec('pvesubscription get 2>/dev/null') ?? '');
        $subActive = (bool)preg_match('/status:\s*active/i', $subRaw);
        $subLevel = '';
        if (preg_match('/level:\s*(\S+)/i', $subRaw, $sm)) $subLevel = $sm[1];

        // Network I/O (bytes from /proc/net/dev, first non-lo interface)
        $netRx = 0; $netTx = 0;
        $netDev = shell_exec('cat /proc/net/dev 2>/dev/null') ?? '';
        foreach (explode("\n", $netDev) as $line) {
            if (preg_match('/^\s*(eth|ens|eno|enp|vmbr0)\S*:\s*(\d+)\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+\S+\s+(\d+)/', $line, $nm)) {
                $netRx = (int)$nm[2]; $netTx = (int)$nm[3]; break;
            }
        }

        // Disk I/O (from /proc/diskstats, first sd/vd/nvme disk)
        $diskRead = 0; $diskWrite = 0;
        $diskStats = shell_exec('cat /proc/diskstats 2>/dev/null') ?? '';
        foreach (explode("\n", $diskStats) as $line) {
            if (preg_match('/\s+(sda|vda|nvme0n1)\s+\S+\s+\S+\s+(\d+)\s+\S+\s+\S+\s+(\d+)/', $line, $dm)) {
                $diskRead = (int)$dm[2] * 512; $diskWrite = (int)$dm[3] * 512; break;
            }
        }

        echo json_encode([
            'hostname' => $hostname,
            'kernel' => $kernel,
            'uptime' => $uptime,
            'uptime_since' => $uptimeSince,
            'load' => $load,
            'cpu_cores' => $cpuCores,
            'cpu_pct' => $cpuPct,
            'cpu_idle' => $cpuIdle,
            'cpu_total' => $cpuTotal,
            'disk_total' => $diskTotal,
            'disk_used' => $diskUsed,
            'mem_total' => $memTotal,
            'mem_used' => $memUsed,
            'net_rx' => $netRx,
            'net_tx' => $netTx,
            'disk_read' => $diskRead,
            'disk_write' => $diskWrite,
            'f2b_jails' => count($jailList),
            'f2b_banned' => $totalBanned,
            'nginx_sites' => $sites,
            'fw_rules' => (int)$fwRules,
            'sub_active' => $subActive,
            'sub_level' => $subLevel,
            'updates' => (function() {
                // Cache apt count for 5 min to avoid slow apt call every 4s
                $cache = '/tmp/floppyops-lite-apt-count';
                if (file_exists($cache) && (time() - filemtime($cache)) < 300) return (int)file_get_contents($cache);
                $count = (int)trim(shell_exec('apt list --upgradable 2>/dev/null | grep -c upgradable') ?? '0');
                @file_put_contents($cache, $count);
                return $count;
            })(),
            'reboot_required' => file_exists('/var/run/reboot-required'),
        ]);
        return true;
    }

    return false;
}
