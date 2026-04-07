<?php
/**
 * FloppyOps Lite — API: VMs & Container
 *
 * PVE VMs und Container (Liste, Clone, Control) — Auflisten aller VMs/CTs,
 * Klonen (inkl. aus ZFS-Snapshots), Start/Stop/Restart, Config lesen/setzen,
 * Storages auflisten.
 *
 * Endpoints: pve-vms, pve-nextid, pve-clone, pve-config, pve-setconfig, pve-snap-clone, pve-control, pve-storages
 */

/**
 * PVE VM/CT Verwaltung: Auflisten, Klonen (inkl. aus ZFS-Snapshots),
 * Start/Stop/Restart, Config lesen/setzen, Storages auflisten.
 *
 * Endpoints: pve-vms, pve-nextid, pve-clone, pve-config, pve-setconfig, pve-snap-clone, pve-control, pve-storages
 *
 * @param string $action Der API-Action-Name
 * @return bool true wenn behandelt
 */
function handleVmsAPI(string $action): bool {
    // GET: Alle VMs und CTs auf diesem Node via pvesh
    if ($action === 'pve-vms') {
        $cacheFile = '/tmp/floppyops-lite-pve-vms.json';
        $force = ($_GET['force'] ?? '') === '1';
        if (!$force && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 15) {
            echo file_get_contents($cacheFile);
            return true;
        }

        $node = trim(shell_exec('hostname 2>/dev/null') ?? '');
        // CTs
        $ctRaw = shell_exec("sudo pvesh get /nodes/$node/lxc --output-format json 2>/dev/null") ?? '[]';
        $cts = json_decode($ctRaw, true) ?: [];
        // VMs
        $vmRaw = shell_exec("sudo pvesh get /nodes/$node/qemu --output-format json 2>/dev/null") ?? '[]';
        $vms = json_decode($vmRaw, true) ?: [];

        $result = [];
        foreach ($cts as $ct) {
            $result[] = [
                'vmid' => (int)$ct['vmid'], 'name' => $ct['name'] ?? '', 'type' => 'lxc',
                'status' => $ct['status'] ?? 'unknown',
                'cpus' => (int)($ct['cpus'] ?? $ct['maxcpu'] ?? 0),
                'mem' => (int)($ct['maxmem'] ?? 0), 'mem_used' => (int)($ct['mem'] ?? 0),
                'disk' => (int)($ct['maxdisk'] ?? 0), 'disk_used' => (int)($ct['disk'] ?? 0),
                'uptime' => (int)($ct['uptime'] ?? 0),
            ];
        }
        foreach ($vms as $vm) {
            $result[] = [
                'vmid' => (int)$vm['vmid'], 'name' => $vm['name'] ?? '', 'type' => 'qemu',
                'status' => $vm['status'] ?? 'unknown',
                'cpus' => (int)($vm['cpus'] ?? $vm['maxcpu'] ?? 0),
                'mem' => (int)($vm['maxmem'] ?? 0), 'mem_used' => (int)($vm['mem'] ?? 0),
                'disk' => (int)($vm['maxdisk'] ?? 0), 'disk_used' => (int)($vm['disk'] ?? 0),
                'uptime' => (int)($vm['uptime'] ?? 0),
            ];
        }
        usort($result, fn($a, $b) => $a['vmid'] - $b['vmid']);
        $json = json_encode(['ok' => true, 'vms' => $result, 'node' => $node]);
        @file_put_contents($cacheFile, $json);
        echo $json;
        return true;
    }

    // GET: Naechste freie VMID vom Cluster
    if ($action === 'pve-nextid') {
        $raw = shell_exec('sudo pvesh get /cluster/nextid 2>/dev/null') ?? '';
        $id = (int)trim($raw);
        echo json_encode(['ok' => true, 'vmid' => $id > 0 ? $id : null]);
        return true;
    }

    // POST: VM/CT klonen (Full/Linked, mit Temp-Snapshot fuer laufende CTs)
    if ($action === 'pve-clone' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $vmid = (int)($_POST['vmid'] ?? 0);
        $type = $_POST['type'] ?? '';
        $newid = (int)($_POST['newid'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $full = ($_POST['full'] ?? '1') === '1';
        $storage = trim($_POST['storage'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (!$vmid || !$newid || !$name || !in_array($type, ['lxc', 'qemu'])) {
            echo json_encode(['ok' => false, 'error' => 'VMID, Name und Typ erforderlich']);
            return true;
        }

        $node = trim(shell_exec('hostname 2>/dev/null') ?? '');
        $endpoint = $type === 'qemu' ? "qemu/$vmid/clone" : "lxc/$vmid/clone";
        $nameParam = $type === 'qemu' ? 'name' : 'hostname';

        // For running CTs with full clone: create temp snapshot first
        $snapName = '';
        if ($type === 'lxc' && $full) {
            $statusRaw = shell_exec("sudo pvesh get /nodes/$node/lxc/$vmid/status/current --output-format json 2>/dev/null") ?? '{}';
            $status = json_decode($statusRaw, true);
            if (($status['status'] ?? '') === 'running') {
                $snapName = 'clone-temp-' . time();
                $snapOut = shell_exec("sudo pvesh create /nodes/$node/lxc/$vmid/snapshot --snapname " . escapeshellarg($snapName) . " 2>&1") ?? '';
                if (str_contains($snapOut, 'error') || str_contains($snapOut, 'ERROR')) {
                    echo json_encode(['ok' => false, 'output' => 'Snapshot fehlgeschlagen: ' . trim($snapOut)]);
                    return true;
                }
                // Wait for snapshot
                sleep(2);
            }
        }

        $cmd = "sudo pvesh create /nodes/$node/$endpoint";
        $cmd .= " --newid " . escapeshellarg($newid);
        $cmd .= " --$nameParam " . escapeshellarg($name);
        $cmd .= " --full " . ($full ? '1' : '0');
        if ($storage) $cmd .= " --storage " . escapeshellarg($storage);
        if ($description) $cmd .= " --description " . escapeshellarg($description);
        if ($snapName) $cmd .= " --snapname " . escapeshellarg($snapName);
        $cmd .= " 2>&1";

        $out = shell_exec($cmd) ?? '';
        $ok = !str_contains($out, 'ERROR') && !str_contains(strtolower($out), 'error') && !str_contains($out, 'only possible from');

        // Cleanup temp snapshot after clone started (background)
        if ($snapName && $ok) {
            // Delete after 60s to let clone finish reading
            exec("sleep 60 && sudo pvesh delete /nodes/$node/lxc/$vmid/snapshot/" . escapeshellarg($snapName) . " > /dev/null 2>&1 &");
        } elseif ($snapName && !$ok) {
            // Clone failed — delete snapshot immediately
            shell_exec("sudo pvesh delete /nodes/$node/lxc/$vmid/snapshot/" . escapeshellarg($snapName) . " 2>/dev/null");
        }

        echo json_encode(['ok' => $ok, 'output' => trim($out), 'message' => $ok ? ($type === 'qemu' ? 'VM' : 'CT') . " $vmid → $newid ($name) Clone gestartet" : trim($out)]);
        return true;
    }

    // GET: VM/CT Konfiguration lesen
    if ($action === 'pve-config') {
        $vmid = (int)($_GET['vmid'] ?? 0);
        $type = $_GET['type'] ?? 'lxc';
        if (!$vmid) { echo json_encode(['ok' => false]); return true; }
        $node = trim(shell_exec('hostname 2>/dev/null') ?? '');
        $endpoint = $type === 'qemu' ? "qemu/$vmid/config" : "lxc/$vmid/config";
        $raw = shell_exec("sudo pvesh get /nodes/$node/$endpoint --output-format json 2>/dev/null") ?? '{}';
        $config = json_decode($raw, true) ?: [];
        echo json_encode(['ok' => true, 'config' => $config]);
        return true;
    }

    // POST: VM/CT Hardware aendern (CPU, RAM, Swap, Onboot, Netzwerk)
    if ($action === 'pve-setconfig' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $vmid = (int)($_POST['vmid'] ?? 0);
        $type = $_POST['type'] ?? 'lxc';
        if (!$vmid) { echo json_encode(['ok' => false, 'error' => 'Keine VMID']); return true; }
        $node = trim(shell_exec('hostname 2>/dev/null') ?? '');
        $endpoint = $type === 'qemu' ? "qemu/$vmid/config" : "lxc/$vmid/config";

        $params = [];
        if (isset($_POST['cores']) && $_POST['cores'] !== '') $params[] = '--cores ' . (int)$_POST['cores'];
        if (isset($_POST['memory']) && $_POST['memory'] !== '') $params[] = '--memory ' . (int)$_POST['memory'];
        if ($type === 'lxc' && isset($_POST['swap']) && $_POST['swap'] !== '') $params[] = '--swap ' . (int)$_POST['swap'];
        if (isset($_POST['onboot'])) $params[] = '--onboot ' . (int)$_POST['onboot'];

        // Network disconnect: delete all netX interfaces
        if (($_POST['net_disconnect'] ?? '') === '1') {
            for ($i = 0; $i < 10; $i++) {
                $params[] = "--delete net$i";
            }
        }

        if (empty($params)) { echo json_encode(['ok' => true, 'message' => 'Keine Änderungen']); return true; }

        $cmd = "sudo pvesh set /nodes/$node/$endpoint " . implode(' ', $params) . " 2>&1";
        $out = shell_exec($cmd) ?? '';
        echo json_encode(['ok' => !str_contains($out, 'ERROR'), 'output' => trim($out)]);
        return true;
    }

    // POST: Neue VM/CT aus ZFS-Snapshot erstellen (ZFS-Level Clone)
    if ($action === 'pve-snap-clone' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $snapshot = trim($_POST['snapshot'] ?? ''); // e.g. data/subvol-100-disk-0@zfs-auto-snap_...
        $newVmid = (int)($_POST['new_vmid'] ?? 0);
        $newName = trim($_POST['new_name'] ?? '');
        $netDisconnect = ($_POST['net_disconnect'] ?? '') === '1';
        $autoStart = ($_POST['auto_start'] ?? '') === '1';
        $cores = ($_POST['cores'] ?? '');
        $memory = ($_POST['memory'] ?? '');
        $swap = ($_POST['swap'] ?? '');
        $onboot = ($_POST['onboot'] ?? '');
        $newIp = trim($_POST['new_ip'] ?? '');
        $newGw = trim($_POST['new_gw'] ?? '');
        $newIp6 = trim($_POST['new_ip6'] ?? '');
        $newGw6 = trim($_POST['new_gw6'] ?? '');
        $newBridge = trim($_POST['new_bridge'] ?? '');
        $newDns = trim($_POST['new_dns'] ?? '');

        if (!$snapshot || !$newVmid || !$newName || !str_contains($snapshot, '@')) {
            echo json_encode(['ok' => false, 'error' => 'Snapshot, neue VMID und Name erforderlich']);
            return true;
        }

        // Parse snapshot: data/subvol-100-disk-0@snapname
        if (!preg_match('#^([a-zA-Z0-9/_.-]+)/(vm|subvol|base)-(\d+)-(disk-\d+)@(.+)$#', $snapshot, $m)) {
            echo json_encode(['ok' => false, 'error' => 'Ungültiges Snapshot-Format']);
            return true;
        }

        $poolPath = $m[1];
        $diskPrefix = $m[2];
        $sourceVmid = (int)$m[3];
        $snapName = $m[5];
        $isLxc = in_array($diskPrefix, ['subvol', 'base']);
        $configDir = $isLxc ? '/etc/pve/lxc' : '/etc/pve/qemu-server';
        $newDiskPrefix = $isLxc ? 'subvol' : 'vm';

        // Check VMID not taken
        if (file_exists("/etc/pve/lxc/{$newVmid}.conf") || file_exists("/etc/pve/qemu-server/{$newVmid}.conf")) {
            echo json_encode(['ok' => false, 'error' => "VMID {$newVmid} ist bereits vergeben"]);
            return true;
        }

        // Check source config
        if (!file_exists("{$configDir}/{$sourceVmid}.conf")) {
            echo json_encode(['ok' => false, 'error' => "Quell-Config {$configDir}/{$sourceVmid}.conf nicht gefunden"]);
            return true;
        }

        // Find all related snapshots for this VM
        $allSnaps = shell_exec("sudo zfs list -t snapshot -o name -H -r " . escapeshellarg($poolPath) . " 2>/dev/null") ?? '';
        $relatedSnaps = [];
        foreach (array_filter(explode("\n", trim($allSnaps))) as $line) {
            if (preg_match('#/(vm|subvol|base)-' . $sourceVmid . '-disk-\d+@' . preg_quote($snapName, '#') . '$#', trim($line))) {
                $relatedSnaps[] = trim($line);
            }
        }
        if (empty($relatedSnaps)) {
            echo json_encode(['ok' => false, 'error' => 'Keine passenden Snapshots gefunden']);
            return true;
        }

        // Clone each ZFS dataset
        $clonedDatasets = [];
        foreach ($relatedSnaps as $snap) {
            $sourceDs = explode('@', $snap)[0];
            $newDs = preg_replace('#(vm|subvol|base)-' . $sourceVmid . '-#', $newDiskPrefix . '-' . $newVmid . '-', $sourceDs);
            $out = trim(shell_exec("sudo zfs clone " . escapeshellarg($snap) . " " . escapeshellarg($newDs) . " 2>&1") ?? '');
            if (!empty($out)) {
                foreach ($clonedDatasets as $ds) shell_exec("sudo zfs destroy " . escapeshellarg($ds) . " 2>/dev/null");
                echo json_encode(['ok' => false, 'error' => 'ZFS Clone fehlgeschlagen: ' . $out]);
                return true;
            }
            $clonedDatasets[] = $newDs;
        }

        // Copy config (via sudo — /etc/pve is a cluster filesystem)
        $confPath = "{$configDir}/{$newVmid}.conf";
        shell_exec("sudo cp " . escapeshellarg("{$configDir}/{$sourceVmid}.conf") . " " . escapeshellarg($confPath) . " 2>&1");

        // Read config for modification
        $conf = shell_exec("sudo cat " . escapeshellarg($confPath) . " 2>/dev/null") ?? '';
        $conf = preg_replace("/(?:vm|subvol|base)-{$sourceVmid}-/", "{$newDiskPrefix}-{$newVmid}-", $conf);

        // Update hostname/name
        if ($isLxc) {
            $conf = preg_replace('/^hostname:.*/m', "hostname: {$newName}", $conf);
        } else {
            $conf = preg_replace('/^name:.*/m', "name: {$newName}", $conf);
        }

        // New MAC address
        $mac = sprintf('BC:24:11:%02X:%02X:%02X', rand(0, 255), rand(0, 255), rand(0, 255));
        $conf = preg_replace('/hwaddr=[0-9A-Fa-f:]{17}/i', "hwaddr={$mac}", $conf);
        $conf = preg_replace('/(virtio|e1000)=[0-9A-Fa-f:]{17}/i', "$1={$mac}", $conf);

        // Remove snapshots section from config
        $conf = preg_replace('/^\[.*\]\n(?:(?!\[).*\n)*/m', '', $conf);

        // Network: disconnect / custom IP
        if ($netDisconnect) {
            if (str_contains($conf, 'link_down=')) {
                $conf = preg_replace('/link_down=[01]/', 'link_down=1', $conf);
            } else {
                $conf = preg_replace('/(^net0:.*)$/m', '$1,link_down=1', $conf);
            }
        } elseif ($newIp || $newGw || $newIp6 || $newGw6 || $newBridge || $newDns) {
            // Custom network settings
            if ($isLxc) {
                if ($newIp) $conf = preg_replace('/ip=[0-9.\/]+/', 'ip=' . $newIp, $conf);
                if ($newGw) {
                    if (preg_match('/gw=[0-9.]+/', $conf)) {
                        $conf = preg_replace('/gw=[0-9.]+/', 'gw=' . $newGw, $conf);
                    } else {
                        $conf = preg_replace('/(^net0:.*)$/m', '$1,gw=' . $newGw, $conf);
                    }
                }
                // IPv6
                if ($newIp6) {
                    if (preg_match('/ip6=[^,]+/', $conf)) {
                        $conf = preg_replace('/ip6=[^,]+/', 'ip6=' . $newIp6, $conf);
                    } else {
                        $conf = preg_replace('/(^net0:.*)$/m', '$1,ip6=' . $newIp6, $conf);
                    }
                }
                if ($newGw6) {
                    if (preg_match('/gw6=[^,\s]+/', $conf)) {
                        $conf = preg_replace('/gw6=[^,\s]+/', 'gw6=' . $newGw6, $conf);
                    } else {
                        $conf = preg_replace('/(^net0:.*)$/m', '$1,gw6=' . $newGw6, $conf);
                    }
                }
                if ($newDns) {
                    if (preg_match('/^nameserver[: ]/m', $conf)) {
                        $conf = preg_replace('/^nameserver[: ]+.*/m', 'nameserver: ' . $newDns, $conf);
                    } else {
                        $conf .= "\nnameserver: " . $newDns;
                    }
                }
            }
            if ($newBridge) {
                $conf = preg_replace('/bridge=[a-zA-Z0-9._-]+/', 'bridge=' . $newBridge, $conf);
            }
            // Remove link_down if present (user wants custom = connected)
            $conf = preg_replace('/,?link_down=[01]/', '', $conf);
        }

        // Write config via temp file + sudo (pmxcfs requires root)
        $tmpConf = tempnam(sys_get_temp_dir(), 'pveconf_');
        file_put_contents($tmpConf, $conf);
        shell_exec("sudo cp " . escapeshellarg($tmpConf) . " " . escapeshellarg($confPath) . " 2>&1");
        unlink($tmpConf);

        // Apply hardware changes
        $node = trim(shell_exec('hostname 2>/dev/null') ?? '');
        $endpoint = $isLxc ? "lxc/{$newVmid}/config" : "qemu/{$newVmid}/config";
        $hwParams = [];
        if ($cores) $hwParams[] = '--cores ' . (int)$cores;
        if ($memory) $hwParams[] = '--memory ' . (int)$memory;
        if ($isLxc && $swap !== '') $hwParams[] = '--swap ' . (int)$swap;
        if ($onboot !== '') $hwParams[] = '--onboot ' . (int)$onboot;
        if (!empty($hwParams)) {
            shell_exec("sudo pvesh set /nodes/$node/$endpoint " . implode(' ', $hwParams) . " 2>/dev/null");
        }

        // Auto-start
        if ($autoStart) {
            $startEndpoint = $isLxc ? "lxc/{$newVmid}/status/start" : "qemu/{$newVmid}/status/start";
            shell_exec("sudo pvesh create /nodes/$node/$startEndpoint 2>/dev/null");
        }

        $typeLabel = $isLxc ? 'CT' : 'VM';
        echo json_encode(['ok' => true, 'message' => "{$typeLabel} {$newVmid} ({$newName}) aus Snapshot erstellt"]);
        return true;
    }

    // POST: VM/CT starten, stoppen, neustarten oder herunterfahren
    if ($action === 'pve-control' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $vmid = (int)($_POST['vmid'] ?? 0);
        $type = $_POST['type'] ?? 'lxc';
        $act = $_POST['action'] ?? '';
        if (!$vmid || !in_array($act, ['start', 'stop', 'restart', 'shutdown'])) {
            echo json_encode(['ok' => false, 'error' => 'Ungültige Parameter']);
            return true;
        }
        $node = trim(shell_exec('hostname 2>/dev/null') ?? '');
        $endpoint = $type === 'qemu' ? "qemu/$vmid" : "lxc/$vmid";
        $statusCmd = $act === 'restart' ? 'reboot' : $act;
        $out = shell_exec("sudo pvesh create /nodes/$node/$endpoint/status/$statusCmd 2>&1") ?? '';
        @unlink('/tmp/floppyops-lite-pve-vms.json'); // invalidate cache
        echo json_encode(['ok' => !str_contains($out, 'ERROR'), 'output' => trim($out)]);
        return true;
    }

    // GET: Verfuegbare PVE-Storages (fuer Clone-Ziel)
    if ($action === 'pve-storages') {
        $node = trim(shell_exec('hostname 2>/dev/null') ?? '');
        $raw = shell_exec("sudo pvesh get /nodes/$node/storage --output-format json 2>/dev/null") ?? '[]';
        $storages = json_decode($raw, true) ?: [];
        $result = [];
        foreach ($storages as $s) {
            if (($s['active'] ?? 0) != 1) continue;
            $content = $s['content'] ?? '';
            if (!str_contains($content, 'rootdir') && !str_contains($content, 'images')) continue;
            $result[] = ['name' => $s['storage'], 'type' => $s['type'] ?? '', 'avail' => (int)($s['avail'] ?? 0), 'total' => (int)($s['total'] ?? 0), 'used' => (int)($s['used'] ?? 0)];
        }
        echo json_encode(['ok' => true, 'storages' => $result]);
        return true;
    }

    return false;
}
