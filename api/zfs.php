<?php
/**
 * FloppyOps Lite — API: ZFS
 *
 * ZFS Pools, Datasets, Snapshots, Auto-Snapshot — Pools/Datasets anzeigen,
 * Snapshots erstellen/loeschen/rollback/klonen, Auto-Snapshot installieren
 * und konfigurieren (Retention pro Intervall).
 *
 * Endpoints: zfs-status, zfs-install-auto, zfs-snapshot, zfs-destroy-snap, zfs-rollback, zfs-clone, zfs-auto-config, zfs-auto-toggle, zfs-set-retention
 */

/**
 * ZFS Verwaltung: Pools/Datasets/Snapshots anzeigen, Snapshots erstellen/
 * loeschen/rollback/klonen, Auto-Snapshot installieren und konfigurieren.
 *
 * Endpoints: zfs-status, zfs-install-auto, zfs-snapshot, zfs-destroy-snap, zfs-rollback, zfs-clone, zfs-auto-config, zfs-auto-toggle, zfs-set-retention
 *
 * @param string $action Der API-Action-Name
 * @return bool true wenn behandelt
 */
function handleZfsAPI(string $action): bool {
    // GET: ZFS Pools, Datasets, Snapshots und Auto-Snapshot Status
    if ($action === 'zfs-status') {
        // Cache for 5 seconds to avoid repeated slow calls
        $cacheFile = '/tmp/floppyops-lite-zfs-cache.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 5) {
            echo file_get_contents($cacheFile);
            return true;
        }

        // Pools
        $poolRaw = shell_exec('sudo /usr/sbin/zpool list -Hp -o name,size,alloc,free,health,frag,cap 2>/dev/null') ?? '';
        $pools = [];
        foreach (array_filter(explode("\n", trim($poolRaw))) as $line) {
            $c = explode("\t", $line);
            if (count($c) >= 5) {
                $pools[] = ['name' => $c[0], 'size' => (int)$c[1], 'alloc' => (int)$c[2], 'free' => (int)$c[3], 'health' => $c[4], 'frag' => rtrim($c[5] ?? '0', '%'), 'cap' => rtrim($c[6] ?? '0', '%')];
            }
        }

        // Datasets
        $dsRaw = shell_exec('sudo /usr/sbin/zfs list -Hp -o name,used,avail,refer,mountpoint,compression,compressratio 2>/dev/null') ?? '';
        $datasets = [];
        foreach (array_filter(explode("\n", trim($dsRaw))) as $line) {
            $c = explode("\t", $line);
            if (count($c) >= 5) {
                $used = (int)$c[1]; $avail = (int)$c[2];
                $datasets[] = ['name' => $c[0], 'used' => $used, 'avail' => $avail, 'total' => $used + $avail, 'refer' => (int)$c[3], 'mount' => $c[4] ?? '-', 'compression' => $c[5] ?? '-', 'ratio' => $c[6] ?? '-'];
            }
        }

        // Snapshots (grouped by dataset)
        $snapRaw = shell_exec('sudo /usr/sbin/zfs list -t snapshot -Hp -o name,used,creation,refer -s creation 2>/dev/null') ?? '';
        $snapshots = [];
        foreach (array_filter(explode("\n", trim($snapRaw))) as $line) {
            $c = explode("\t", $line);
            if (count($c) >= 3) {
                $parts = explode('@', $c[0], 2);
                $snapshots[] = [
                    'name' => $c[0],
                    'dataset' => $parts[0],
                    'snap' => $parts[1] ?? '',
                    'used' => (int)$c[1],
                    'created' => date('Y-m-d H:i', (int)$c[2]),
                    'created_ts' => (int)$c[2],
                    'refer' => (int)($c[3] ?? 0),
                ];
            }
        }

        // Auto-snapshot installed?
        $autoInstalled = file_exists('/usr/sbin/zfs-auto-snapshot') || !empty(shell_exec('which zfs-auto-snapshot 2>/dev/null'));
        $autoCrons = [];
        $defaultRetention = ['frequent' => 4, 'hourly' => 24, 'daily' => 31, 'weekly' => 8, 'monthly' => 12];
        if ($autoInstalled) {
            foreach (['frequent' => '/etc/cron.d/zfs-auto-snapshot', 'hourly' => '/etc/cron.hourly/zfs-auto-snapshot', 'daily' => '/etc/cron.daily/zfs-auto-snapshot', 'weekly' => '/etc/cron.weekly/zfs-auto-snapshot', 'monthly' => '/etc/cron.monthly/zfs-auto-snapshot'] as $label => $path) {
                $keep = $defaultRetention[$label];
                if (file_exists($path)) {
                    $content = file_get_contents($path);
                    if (preg_match('/--keep=(\d+)/', $content, $km)) $keep = (int)$km[1];
                }
                $autoCrons[] = ['label' => $label, 'path' => $path, 'exists' => file_exists($path), 'keep' => $keep];
            }
        }

        $json = json_encode(['ok' => true, 'pools' => $pools, 'datasets' => $datasets, 'snapshots' => array_reverse($snapshots), 'auto_installed' => $autoInstalled, 'auto_crons' => $autoCrons]);
        @file_put_contents($cacheFile, $json);
        echo $json;
        return true;
    }

    // POST: zfs-auto-snapshot Paket installieren
    if ($action === 'zfs-install-auto' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $out = shell_exec('sudo apt-get install -y zfs-auto-snapshot 2>&1') ?? '';
        $ok = str_contains($out, 'is already') || str_contains($out, 'newly installed') || str_contains($out, 'wird eingerichtet');
        echo json_encode(['ok' => $ok, 'output' => trim($out)]);
        return true;
    }

    // POST: Manuellen ZFS Snapshot erstellen
    if ($action === 'zfs-snapshot' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $dataset = trim($_POST['dataset'] ?? '');
        $snapName = trim($_POST['name'] ?? '');
        if (!$dataset || !preg_match('/^[a-zA-Z0-9\/_-]+$/', $dataset)) {
            echo json_encode(['ok' => false, 'error' => 'Ungültiges Dataset']);
            return true;
        }
        if (!$snapName) $snapName = 'manual-' . date('Y-m-d_His');
        $out = shell_exec('sudo /usr/sbin/zfs snapshot ' . escapeshellarg($dataset . '@' . $snapName) . ' 2>&1') ?? '';
        $ok = empty(trim($out));
        echo json_encode(['ok' => $ok, 'output' => trim($out), 'snapshot' => $dataset . '@' . $snapName]);
        return true;
    }

    // POST: ZFS Snapshot loeschen
    if ($action === 'zfs-destroy-snap' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $snap = trim($_POST['snapshot'] ?? '');
        if (!$snap || !str_contains($snap, '@') || !preg_match('/^[a-zA-Z0-9\/_@:.-]+$/', $snap)) {
            echo json_encode(['ok' => false, 'error' => 'Ungültiger Snapshot-Name']);
            return true;
        }
        $out = shell_exec('sudo /usr/sbin/zfs destroy ' . escapeshellarg($snap) . ' 2>&1') ?? '';
        echo json_encode(['ok' => empty(trim($out)), 'output' => trim($out)]);
        return true;
    }

    // POST: ZFS Rollback auf Snapshot (loescht neuere Snapshots)
    if ($action === 'zfs-rollback' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $snap = trim($_POST['snapshot'] ?? '');
        if (!$snap || !str_contains($snap, '@') || !preg_match('/^[a-zA-Z0-9\/_@:.-]+$/', $snap)) {
            echo json_encode(['ok' => false, 'error' => 'Ungültiger Snapshot-Name']);
            return true;
        }
        // -r flag to destroy newer snapshots
        $out = shell_exec('sudo /usr/sbin/zfs rollback -r ' . escapeshellarg($snap) . ' 2>&1') ?? '';
        echo json_encode(['ok' => empty(trim($out)), 'output' => trim($out)]);
        return true;
    }

    // POST: ZFS Snapshot in neues Dataset klonen
    if ($action === 'zfs-clone' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $snap = trim($_POST['snapshot'] ?? '');
        $target = trim($_POST['target'] ?? '');
        if (!$snap || !$target || !str_contains($snap, '@')) {
            echo json_encode(['ok' => false, 'error' => 'Snapshot und Ziel-Dataset erforderlich']);
            return true;
        }
        if (!preg_match('/^[a-zA-Z0-9\/_-]+$/', $target)) {
            echo json_encode(['ok' => false, 'error' => 'Ungültiger Dataset-Name']);
            return true;
        }
        $out = shell_exec('sudo /usr/sbin/zfs clone ' . escapeshellarg($snap) . ' ' . escapeshellarg($target) . ' 2>&1') ?? '';
        echo json_encode(['ok' => empty(trim($out)), 'output' => trim($out)]);
        return true;
    }

    // GET: Auto-Snapshot Konfiguration pro Dataset
    if ($action === 'zfs-auto-config') {
        $dsRaw = shell_exec('sudo /usr/sbin/zfs list -Hp -o name 2>/dev/null') ?? '';
        $datasets = array_filter(explode("\n", trim($dsRaw)));
        $config = [];
        foreach ($datasets as $ds) {
            $val = trim(shell_exec('sudo /usr/sbin/zfs get -H -o value com.sun:auto-snapshot ' . escapeshellarg($ds) . ' 2>/dev/null') ?? '-');
            $config[] = ['dataset' => $ds, 'auto_snapshot' => $val !== 'false'];
        }
        echo json_encode(['ok' => true, 'config' => $config]);
        return true;
    }

    // POST: Auto-Snapshot fuer ein Dataset aktivieren/deaktivieren
    if ($action === 'zfs-auto-toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $dataset = trim($_POST['dataset'] ?? '');
        $enabled = ($_POST['enabled'] ?? '') === '1';
        if (!$dataset || !preg_match('/^[a-zA-Z0-9\/_-]+$/', $dataset)) {
            echo json_encode(['ok' => false, 'error' => 'Ungültiges Dataset']);
            return true;
        }
        $val = $enabled ? 'true' : 'false';
        $out = shell_exec('sudo /usr/sbin/zfs set com.sun:auto-snapshot=' . $val . ' ' . escapeshellarg($dataset) . ' 2>&1') ?? '';
        echo json_encode(['ok' => empty(trim($out)), 'output' => trim($out)]);
        return true;
    }

    // POST: Retention (--keep=N) in Cron-Datei aendern
    if ($action === 'zfs-set-retention' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $label = trim($_POST['label'] ?? '');
        $keep = (int)($_POST['keep'] ?? 0);
        $cronPaths = ['frequent' => '/etc/cron.d/zfs-auto-snapshot', 'hourly' => '/etc/cron.hourly/zfs-auto-snapshot', 'daily' => '/etc/cron.daily/zfs-auto-snapshot', 'weekly' => '/etc/cron.weekly/zfs-auto-snapshot', 'monthly' => '/etc/cron.monthly/zfs-auto-snapshot'];
        if (!isset($cronPaths[$label]) || $keep < 1 || $keep > 999) {
            echo json_encode(['ok' => false, 'error' => 'Ungültiges Intervall oder Wert']);
            return true;
        }
        $path = $cronPaths[$label];
        if (!file_exists($path)) {
            echo json_encode(['ok' => false, 'error' => 'Cron-Datei nicht gefunden: ' . $path]);
            return true;
        }
        $content = file_get_contents($path);
        $newContent = preg_replace('/--keep=\d+/', '--keep=' . $keep, $content);
        if ($newContent === $content && !str_contains($content, '--keep=')) {
            echo json_encode(['ok' => false, 'error' => 'Kein --keep Parameter in Cron-Datei gefunden']);
            return true;
        }
        file_put_contents($path, $newContent);
        echo json_encode(['ok' => true, 'label' => $label, 'keep' => $keep]);
        return true;
    }

    return false;
}
