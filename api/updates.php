<?php
/**
 * FloppyOps Lite — API: Updates
 *
 * App-Updates, Repositories, System-Updates (apt) — Prueft GitHub auf
 * neue Versionen, verwaltet PVE-Repos (Enterprise/No-Sub), apt upgrade,
 * Auto-Update Crons fuer App und System.
 *
 * Endpoints: update-check, update-pull, repo-check, repo-toggle, repo-add-nosub, apt-check, apt-refresh, apt-upgrade, app-auto-update-save, app-auto-update-status, auto-update-save, auto-update-status
 */

/**
 * Self-Update, Repositories, System-Updates (apt) und Auto-Update Crons.
 * Prueft GitHub auf neue Versionen, verwaltet PVE-Repos, apt upgrade.
 *
 * Endpoints: update-check, update-pull, repo-check, repo-toggle, repo-add-nosub, apt-check, apt-refresh, apt-upgrade, app-auto-update-save, app-auto-update-status, auto-update-save, auto-update-status
 *
 * @param string $action Der API-Action-Name
 * @return bool true wenn behandelt
 */
function handleUpdatesAPI(string $action): bool {
    // GET: Versionsvergleich lokal vs. GitHub
    if ($action === 'update-check') {
        $localVersion = APP_VERSION;
        $installDir = __DIR__ . '/..';
        $isGit = is_dir($installDir . '/.git') || is_dir(dirname($installDir) . '/.git');
        if ($isGit && !is_dir($installDir . '/.git')) $installDir = dirname($installDir);

        // Check latest version from GitHub
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => "User-Agent: FloppyOps-Lite\r\n"]]);
        $gh = @file_get_contents('https://raw.githubusercontent.com/floppy007/floppyops-lite/main/index.php', false, $ctx);
        $remoteVersion = $localVersion;
        if ($gh && preg_match("/define\('APP_VERSION',\s*'([^']+)'\)/", $gh, $m)) {
            $remoteVersion = $m[1];
        }
        $updateAvailable = version_compare($remoteVersion, $localVersion, '>');

        echo json_encode([
            'ok' => true,
            'local_version' => $localVersion,
            'remote_version' => $remoteVersion,
            'update_available' => $updateAvailable,
            'is_git' => $isGit,
            'install_dir' => $installDir,
        ]);
        return true;
    }

    // POST: Update durchfuehren (git pull oder Direct Download)
    if ($action === 'update-pull' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $installDir = __DIR__ . '/..';
        $isGit = is_dir($installDir . '/.git') || is_dir(dirname($installDir) . '/.git');
        if ($isGit && !is_dir($installDir . '/.git')) $installDir = dirname($installDir);

        if ($isGit) {
            // Git-based update — holt alle Dateien inkl. api/ und js/
            $out = shell_exec('cd ' . escapeshellarg($installDir) . ' && git pull origin main 2>&1') ?? '';
            $ok = str_contains($out, 'Already up to date') || str_contains($out, 'Fast-forward') || str_contains($out, 'files changed');
            if ($ok && $installDir !== dirname(__DIR__)) {
                // Hauptdateien kopieren
                foreach (['index.php', 'lang.php', 'setup.sh', 'update.sh'] as $f) {
                    if (file_exists($installDir . '/' . $f)) copy($installDir . '/' . $f, dirname(__DIR__) . '/' . $f);
                }
                // API-Module kopieren
                if (is_dir($installDir . '/api')) {
                    @mkdir(dirname(__DIR__) . '/api', 0755, true);
                    foreach (glob($installDir . '/api/*.php') as $f) {
                        copy($f, dirname(__DIR__) . '/api/' . basename($f));
                    }
                }
                // JS-Module kopieren
                if (is_dir($installDir . '/js')) {
                    @mkdir(dirname(__DIR__) . '/js', 0755, true);
                    foreach (glob($installDir . '/js/*.js') as $f) {
                        copy($f, dirname(__DIR__) . '/js/' . basename($f));
                    }
                }
            }
        } else {
            // Direct download from GitHub (alle Dateien einzeln)
            $ctx = stream_context_create(['http' => ['timeout' => 15, 'header' => "User-Agent: FloppyOps-Lite\r\n"]]);
            $ok = true; $out = '';
            $baseUrl = 'https://raw.githubusercontent.com/floppy007/floppyops-lite/main';
            $appDir = dirname(__DIR__);

            // Hauptdateien
            foreach (['index.php', 'lang.php', 'setup.sh', 'update.sh'] as $f) {
                $content = @file_get_contents("{$baseUrl}/{$f}", false, $ctx);
                if ($content) {
                    file_put_contents($appDir . '/' . $f, $content);
                    $out .= "{$f} aktualisiert\n";
                } else {
                    $out .= "{$f} Download fehlgeschlagen\n";
                    $ok = false;
                }
            }

            // API-Module
            @mkdir($appDir . '/api', 0755, true);
            foreach (['dashboard','fail2ban','firewall','nginx','security','updates','vms','wireguard','zfs'] as $m) {
                $content = @file_get_contents("{$baseUrl}/api/{$m}.php", false, $ctx);
                if ($content) {
                    file_put_contents($appDir . "/api/{$m}.php", $content);
                    $out .= "api/{$m}.php aktualisiert\n";
                } else {
                    $out .= "api/{$m}.php fehlgeschlagen\n";
                    $ok = false;
                }
            }

            // JS-Module
            @mkdir($appDir . '/js', 0755, true);
            foreach (['core','dashboard','fail2ban','firewall','nginx','security','updates','vms','wireguard','zfs'] as $m) {
                $content = @file_get_contents("{$baseUrl}/js/{$m}.js", false, $ctx);
                if ($content) {
                    file_put_contents($appDir . "/js/{$m}.js", $content);
                    $out .= "js/{$m}.js aktualisiert\n";
                } else {
                    $out .= "js/{$m}.js fehlgeschlagen\n";
                    $ok = false;
                }
            }
        }
        // Reload PHP-FPM
        shell_exec('sudo systemctl reload php*-fpm 2>&1 || sudo systemctl restart php*-fpm 2>&1');
        echo json_encode(['ok' => $ok, 'output' => trim($out)]);
        return true;
    }

    // GET: PVE Repository-Status (Enterprise/No-Sub, Subscription)
    if ($action === 'repo-check') {
        $codename = trim(shell_exec('lsb_release -cs 2>/dev/null') ?? 'bookworm');
        $isTrixie = ($codename === 'trixie');
        $hasEnterprise = false;
        $hasNoSub = false;

        // Scan ALL repo files for PVE enterprise/no-subscription
        // DEB822 .sources: "Enabled: no" = disabled, no Enabled field = active
        foreach (glob('/etc/apt/sources.list.d/*.sources') as $f) {
            $c = file_get_contents($f);
            $enabled = !preg_match('/^Enabled:\s*no/mi', $c);
            if ($enabled && str_contains($c, 'pve-enterprise')) $hasEnterprise = true;
            if ($enabled && str_contains($c, 'pve-no-subscription')) $hasNoSub = true;
        }
        // .list files (PVE 8 style, but can exist on PVE 9 too)
        foreach (glob('/etc/apt/sources.list.d/*.list') as $f) {
            $c = file_get_contents($f);
            if (preg_match('/^[^#]*pve-enterprise/m', $c)) $hasEnterprise = true;
            if (preg_match('/^[^#]*pve-no-subscription/m', $c)) $hasNoSub = true;
        }

        $subStatus = trim(shell_exec('pvesubscription get 2>/dev/null | grep -i status') ?? '');
        $hasSubscription = str_contains(strtolower($subStatus), 'active');

        // List all repos in sources.list.d
        $repos = [];
        foreach (glob('/etc/apt/sources.list.d/*.list') as $f) {
            $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $active = !str_starts_with(trim($line), '#');
                $clean = ltrim($line, '# ');
                if (preg_match('/^deb\s+(\S+)\s+(\S+)\s+(.+)/', $clean, $m)) {
                    $repos[] = ['file' => basename($f), 'url' => $m[1], 'suite' => $m[2], 'components' => trim($m[3]), 'active' => $active, 'format' => 'list'];
                }
            }
        }
        foreach (glob('/etc/apt/sources.list.d/*.sources') as $f) {
            $c = file_get_contents($f);
            $enabled = !preg_match('/^Enabled:\s*no/mi', $c);
            $uri = ''; $suite = ''; $comp = '';
            if (preg_match('/^URIs?:\s*(.+)/mi', $c, $m)) $uri = trim($m[1]);
            if (preg_match('/^Suites?:\s*(.+)/mi', $c, $m)) $suite = trim($m[1]);
            if (preg_match('/^Components?:\s*(.+)/mi', $c, $m)) $comp = trim($m[1]);
            if ($uri) $repos[] = ['file' => basename($f), 'url' => $uri, 'suite' => $suite, 'components' => $comp, 'active' => $enabled, 'format' => 'deb822'];
        }

        // Standard PVE repos — always show, even if file doesn't exist
        $standardRepos = [
            ['id' => 'pve-enterprise', 'label' => 'Enterprise', 'components' => 'pve-enterprise', 'desc' => 'Stabile Updates (Subscription nötig)'],
            ['id' => 'pve-no-subscription', 'label' => 'No-Subscription', 'components' => 'pve-no-subscription', 'desc' => 'Community-Updates (kostenlos)'],
        ];
        $pveRepos = [];
        foreach ($standardRepos as $sr) {
            // Find all matching repos, prefer active one
            $matches = [];
            foreach ($repos as &$r) {
                if (str_contains($r['components'], $sr['components'])) {
                    $r['_standard'] = $sr['id'];
                    $r['_label'] = $sr['label'];
                    $r['_desc'] = $sr['desc'];
                    $matches[] = $r;
                }
            }
            unset($r);
            $found = !empty($matches);
            if ($found) {
                // Prefer active repo, then official URL
                usort($matches, function($a, $b) {
                    if ($a['active'] !== $b['active']) return $b['active'] ? 1 : -1;
                    $aOfficial = str_contains($a['url'], 'download.proxmox.com');
                    $bOfficial = str_contains($b['url'], 'download.proxmox.com');
                    return $bOfficial - $aOfficial;
                });
                $pveRepos[] = $matches[0];
            }
            if (!$found) {
                $pveRepos[] = [
                    'file' => null, 'url' => 'http://download.proxmox.com/debian/pve',
                    'suite' => $codename, 'components' => $sr['components'],
                    'active' => false, 'format' => $isTrixie ? 'deb822' : 'list',
                    '_standard' => $sr['id'], '_label' => $sr['label'], '_desc' => $sr['desc'],
                    '_missing' => true,
                ];
            }
        }
        // Other repos (non-PVE)
        $otherRepos = array_filter($repos, fn($r) => !isset($r['_standard']));

        echo json_encode([
            'ok' => true,
            'enterprise_active' => $hasEnterprise,
            'no_sub_active' => $hasNoSub,
            'has_subscription' => $hasSubscription,
            'warning' => $hasEnterprise && !$hasSubscription,
            'codename' => $codename,
            'format' => $isTrixie ? 'deb822' : 'list',
            'pve_repos' => array_values($pveRepos),
            'other_repos' => array_values($otherRepos),
        ]);
        return true;
    }

    // POST: Repository aktivieren/deaktivieren
    if ($action === 'repo-toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $file = trim($_POST['file'] ?? '');
        $component = trim($_POST['component'] ?? ''); // for creating new repos
        $enable = ($_POST['enable'] ?? '') === '1';
        $codename = trim(shell_exec('lsb_release -cs 2>/dev/null') ?? 'bookworm');
        $isTrixie = ($codename === 'trixie');
        $output = [];

        // Create new repo if file doesn't exist
        if (!$file && $component && $enable) {
            $url = 'http://download.proxmox.com/debian/pve';
            if ($isTrixie) {
                $file = 'pve-' . str_replace('pve-', '', $component) . '.sources';
                $path = '/etc/apt/sources.list.d/' . $file;
                $content = "Enabled: yes\nTypes: deb\nURIs: {$url}\nSuites: {$codename}\nComponents: {$component}\nSigned-By: /usr/share/keyrings/proxmox-archive-keyring.gpg\n";
                file_put_contents($path, $content);
            } else {
                $file = 'pve-' . str_replace('pve-', '', $component) . '.list';
                $path = '/etc/apt/sources.list.d/' . $file;
                file_put_contents($path, "deb {$url} {$codename} {$component}\n");
            }
            $output[] = 'Erstellt: ' . $file;
        } elseif ($file && preg_match('/^[a-zA-Z0-9._-]+$/', $file)) {
            $path = '/etc/apt/sources.list.d/' . $file;
            if (str_ends_with($file, '.sources') && file_exists($path)) {
                $c = file_get_contents($path);
                $c = preg_replace('/^Enabled:\s*(yes|no)\s*\n?/mi', '', $c);
                $c = "Enabled: " . ($enable ? 'yes' : 'no') . "\n" . trim($c) . "\n";
                file_put_contents($path, $c);
                $output[] = ($enable ? 'Aktiviert' : 'Deaktiviert') . ': ' . $file;
            } elseif (str_ends_with($file, '.list') && file_exists($path)) {
                $c = file_get_contents($path);
                if ($enable) $c = preg_replace('/^#\s*/m', '', $c);
                else $c = preg_replace('/^(?!#)(.+)/m', '# $1', $c);
                file_put_contents($path, $c);
                $output[] = ($enable ? 'Aktiviert' : 'Deaktiviert') . ': ' . $file;
            }
        }

        shell_exec('apt-get update -qq 2>&1');
        $output[] = 'apt update ausgeführt';
        echo json_encode(['ok' => true, 'output' => implode("\n", $output)]);
        return true;
    }

    // POST: No-Subscription Repository hinzufuegen
    if ($action === 'repo-add-nosub' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $codename = trim(shell_exec('lsb_release -cs 2>/dev/null') ?? 'bookworm');
        $isTrixie = ($codename === 'trixie');

        if ($isTrixie) {
            $src = '/etc/apt/sources.list.d/proxmox.sources';
            $content = "Enabled: yes\nTypes: deb\nURIs: http://download.proxmox.com/debian/pve\nSuites: {$codename}\nComponents: pve-no-subscription\nSigned-By: /usr/share/keyrings/proxmox-archive-keyring.gpg\n";
            file_put_contents($src, $content);
        } else {
            $f = '/etc/apt/sources.list.d/pve-no-subscription.list';
            file_put_contents($f, "deb http://download.proxmox.com/debian/pve {$codename} pve-no-subscription\n");
        }

        shell_exec('apt-get update -qq 2>&1');
        $output[] = 'apt update ausgeführt';
        echo json_encode(['ok' => true, 'output' => implode("\n", $output)]);
        return true;
    }

    // GET: Verfuegbare apt-Updates auflisten
    if ($action === 'apt-check') {
        $updates = [];
        $raw = shell_exec('apt list --upgradable 2>/dev/null') ?? '';
        foreach (explode("\n", trim($raw)) as $line) {
            if (str_contains($line, 'Listing') || empty(trim($line))) continue;
            if (preg_match('/^(\S+)\/\S+\s+(\S+)\s+\S+\s+\[upgradable from: (\S+)\]/', $line, $m)) {
                $updates[] = ['name' => $m[1], 'new' => $m[2], 'old' => $m[3]];
            }
        }
        $lastCheck = trim(shell_exec('stat -c %Y /var/cache/apt/pkgcache.bin 2>/dev/null') ?? '0');
        $rebootRequired = file_exists('/var/run/reboot-required');
        echo json_encode(['ok' => true, 'updates' => $updates, 'count' => count($updates), 'last_check' => (int)$lastCheck, 'reboot_required' => $rebootRequired]);
        return true;
    }

    // POST: apt-get update ausfuehren
    if ($action === 'apt-refresh' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $out = shell_exec('sudo apt-get update 2>&1') ?? '';
        $ok = str_contains($out, 'Reading package lists') || str_contains($out, 'Paketlisten werden gelesen');
        echo json_encode(['ok' => $ok, 'output' => trim(substr($out, -500))]);
        return true;
    }

    // POST: apt dist-upgrade + autoremove ausfuehren
    if ($action === 'apt-upgrade' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $out = shell_exec('sudo DEBIAN_FRONTEND=noninteractive apt-get dist-upgrade -y 2>&1') ?? '';
        $ok = str_contains($out, '0 newly installed') || str_contains($out, 'newly installed') || str_contains($out, 'neu installiert');
        $autoremove = shell_exec('sudo apt-get autoremove -y 2>&1') ?? '';
        echo json_encode(['ok' => $ok, 'output' => trim(substr($out, -800)), 'autoremove' => trim(substr($autoremove, -200))]);
        return true;
    }

    // POST: App Auto-Update Cron konfigurieren
    if ($action === 'app-auto-update-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $enabled = ($_POST['enabled'] ?? '') === '1';
        $day = (int)($_POST['day'] ?? 0);
        $hour = max(0, min(23, (int)($_POST['hour'] ?? 4)));
        $cronFile = '/etc/cron.d/floppyops-lite-app-update';
        @unlink('/etc/cron.daily/floppyops-lite-app-update'); // remove old format
        if ($enabled) {
            $dayField = $day === 0 ? '*' : (string)$day;
            $installDir = __DIR__ . '/..';
            $isGit = is_dir($installDir . '/.git') || is_dir(dirname($installDir) . '/.git');
            if ($isGit) {
                $gitDir = is_dir($installDir . '/.git') ? $installDir : dirname($installDir);
                $cmd = "cd {$gitDir} && git pull origin main -q 2>/dev/null";
                if ($gitDir !== $installDir) {
                    $cmd .= " && cp {$gitDir}/index.php {$gitDir}/lang.php {$installDir}/";
                    $cmd .= " && cp -r {$gitDir}/api {$gitDir}/js {$installDir}/";
                }
            } else {
                $appDir = escapeshellarg(dirname(__DIR__));
                $base = 'https://raw.githubusercontent.com/floppy007/floppyops-lite/main';
                $cmd = "mkdir -p {$appDir}/api {$appDir}/js";
                // Hauptdateien
                foreach (['index.php', 'lang.php'] as $f) {
                    $cmd .= " && curl -sf {$base}/{$f} -o {$appDir}/{$f}";
                }
                // API-Module
                foreach (['dashboard','fail2ban','firewall','nginx','security','updates','vms','wireguard','zfs'] as $m) {
                    $cmd .= " && curl -sf {$base}/api/{$m}.php -o {$appDir}/api/{$m}.php";
                }
                // JS-Module
                foreach (['core','dashboard','fail2ban','firewall','nginx','security','updates','vms','wireguard','zfs'] as $m) {
                    $cmd .= " && curl -sf {$base}/js/{$m}.js -o {$appDir}/js/{$m}.js";
                }
            }
            $cmd .= " && systemctl reload php*-fpm 2>/dev/null";
            $script = "# FloppyOps Lite App Auto-Update\n0 {$hour} * * {$dayField} root {$cmd} > /var/log/floppyops-lite-app-update.log 2>&1\n";
            file_put_contents($cronFile, $script);
            chmod($cronFile, 0644);
        } else {
            @unlink($cronFile);
        }
        echo json_encode(['ok' => true, 'enabled' => $enabled, 'day' => $day, 'hour' => $hour]);
        return true;
    }

    // GET: App Auto-Update Cron-Status lesen
    if ($action === 'app-auto-update-status') {
        $cronFile = '/etc/cron.d/floppyops-lite-app-update';
        $oldCron = '/etc/cron.daily/floppyops-lite-app-update';
        $enabled = file_exists($cronFile) || file_exists($oldCron);
        $day = 0; $hour = 4;
        if (file_exists($cronFile)) {
            $c = file_get_contents($cronFile);
            if (preg_match('/^0\s+(\d+)\s+\*\s+\*\s+(\S+)/m', $c, $m)) {
                $hour = (int)$m[1]; $day = $m[2] === '*' ? 0 : (int)$m[2];
            }
        }
        echo json_encode(['ok' => true, 'enabled' => $enabled, 'day' => $day, 'hour' => $hour]);
        return true;
    }

    // POST: System Auto-Update Cron konfigurieren
    if ($action === 'auto-update-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $enabled = ($_POST['enabled'] ?? '') === '1';
        $day = (int)($_POST['day'] ?? 0); // 0=daily, 1-7=Mon-Sun
        $hour = max(0, min(23, (int)($_POST['hour'] ?? 3)));
        $cronFile = '/etc/cron.d/floppyops-lite-update';
        if ($enabled) {
            $dayField = $day === 0 ? '*' : (string)$day;
            $script = "# FloppyOps Lite Auto-Update\n0 {$hour} * * {$dayField} root apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get dist-upgrade -y -qq && apt-get autoremove -y -qq > /var/log/floppyops-lite-update.log 2>&1\n";
            file_put_contents($cronFile, $script);
            chmod($cronFile, 0644);
            // Remove old cron.daily file if exists
            @unlink('/etc/cron.daily/floppyops-lite-update');
        } else {
            @unlink($cronFile);
            @unlink('/etc/cron.daily/floppyops-lite-update');
        }
        echo json_encode(['ok' => true, 'enabled' => $enabled, 'day' => $day, 'hour' => $hour]);
        return true;
    }

    // GET: System Auto-Update Cron-Status lesen
    if ($action === 'auto-update-status') {
        $cronFile = '/etc/cron.d/floppyops-lite-update';
        $oldCron = '/etc/cron.daily/floppyops-lite-update';
        $enabled = file_exists($cronFile) || file_exists($oldCron);
        $day = 0; $hour = 3;
        if (file_exists($cronFile)) {
            $c = file_get_contents($cronFile);
            if (preg_match('/^0\s+(\d+)\s+\*\s+\*\s+(\S+)/m', $c, $m)) {
                $hour = (int)$m[1];
                $day = $m[2] === '*' ? 0 : (int)$m[2];
            }
        }
        $tz = trim(shell_exec('timedatectl show --property=Timezone --value 2>/dev/null') ?? '') ?: (trim(file_get_contents('/etc/timezone') ?? '') ?: 'UTC');
        echo json_encode(['ok' => true, 'enabled' => $enabled, 'day' => $day, 'hour' => $hour, 'timezone' => $tz]);
        return true;
    }

    return false;
}
