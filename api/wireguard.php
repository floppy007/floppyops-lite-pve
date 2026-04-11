<?php
/**
 * FloppyOps Lite — API: WireGuard
 *
 * WireGuard VPN Tunnel-Verwaltung — Tunnel-Status, Config lesen/speichern,
 * Keys generieren, Tunnel erstellen/loeschen/starten/stoppen, Peers
 * hinzufuegen/bearbeiten/entfernen, Config importieren.
 *
 * Endpoints: wg-status, wg-config, wg-save, wg-genkeys, wg-net-ifaces, wg-list-ifaces, wg-create, wg-delete, wg-control, wg-add-peer, wg-update-peer, wg-remove-peer, wg-server-info, wg-import, wg-logs
 */

/**
 * Helper: WireGuard Config-Datei schreiben (direkt oder via sudo).
 *
 * @param string $path Pfad zur Config-Datei
 * @param string $content Neuer Config-Inhalt
 * @return bool true wenn erfolgreich
 */
function wgWriteConf(string $path, string $content): bool {
    // Try direct write first, fallback to sudo tee
    $ok = @file_put_contents($path, $content);
    if ($ok === false) {
        $tmp = tempnam('/tmp', 'wgconf_');
        file_put_contents($tmp, $content);
        shell_exec("sudo cp " . escapeshellarg($tmp) . " " . escapeshellarg($path) . " 2>&1");
        shell_exec("sudo chmod 660 " . escapeshellarg($path) . " 2>&1");
        shell_exec("sudo chown root:www-data " . escapeshellarg($path) . " 2>&1");
        unlink($tmp);
        $ok = file_exists($path) && file_get_contents($path) === $content;
    }
    return (bool)$ok;
}

function wgParseRouteNetworks(string $raw): array
{
    $items = preg_split('/[\s,;]+/', trim($raw)) ?: [];
    $routes = [];
    foreach ($items as $item) {
        $item = trim($item);
        if ($item === '') continue;
        if (!preg_match('#^\d{1,3}(?:\.\d{1,3}){3}/\d{1,2}$#', $item)) continue;
        $routes[$item] = true;
    }
    return array_keys($routes);
}

function wgIsPrivateIpv4Cidr(string $cidr): bool
{
    $ip = explode('/', $cidr, 2)[0] ?? '';
    return (bool)preg_match('#^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.)#', $ip);
}

function wgGetBridgeIpv4(string $bridge): ?string
{
    if ($bridge === '') return null;
    $raw = shell_exec("ip -4 -o addr show dev " . escapeshellarg($bridge) . " 2>/dev/null") ?? '';
    if (preg_match('/inet\s+(\d+\.\d+\.\d+\.\d+)\//', $raw, $m)) {
        return $m[1];
    }
    return null;
}

function wgParsePctNetworks(string $configRaw): array
{
    $nets = [];
    foreach (explode("\n", trim($configRaw)) as $line) {
        if (!preg_match('/^(net\d+):\s+(.+)$/', trim($line), $m)) continue;
        $attrs = ['slot' => $m[1]];
        foreach (explode(',', $m[2]) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $attrs[$key] = $value;
        }
        $nets[] = [
            'slot' => $attrs['slot'],
            'name' => $attrs['name'] ?? '',
            'bridge' => $attrs['bridge'] ?? '',
            'ip' => $attrs['ip'] ?? '',
            'gateway' => $attrs['gw'] ?? '',
        ];
    }
    return $nets;
}

function wgChooseLxcRouteTarget(array $nets): ?array
{
    foreach ($nets as $net) {
        if (!wgIsPrivateIpv4Cidr($net['ip'] ?? '')) continue;
        $gateway = trim($net['gateway'] ?? '');
        if ($gateway === '') {
            $gateway = wgGetBridgeIpv4($net['bridge'] ?? '') ?? '';
        }
        if ($gateway === '') continue;
        return [
            'iface' => $net['name'] ?? '',
            'bridge' => $net['bridge'] ?? '',
            'ip' => $net['ip'] ?? '',
            'gateway' => $gateway,
        ];
    }
    return null;
}

function wgInspectLxcReachability(int $vmid, array $routes): array
{
    $configRaw = shell_exec('sudo pct config ' . escapeshellarg((string)$vmid) . ' 2>/dev/null') ?? '';
    $nets = wgParsePctNetworks($configRaw);
    $target = wgChooseLxcRouteTarget($nets);
    $routeRaw = shell_exec('sudo pct exec ' . escapeshellarg((string)$vmid) . ' -- ip -4 route show table main 2>/dev/null') ?? '';
    $addrRaw = shell_exec('sudo pct exec ' . escapeshellarg((string)$vmid) . ' -- ip -4 -o addr show scope global 2>/dev/null') ?? '';
    $hasInterfacesFile = trim(shell_exec('sudo pct exec ' . escapeshellarg((string)$vmid) . " -- sh -lc 'test -f /etc/network/interfaces && echo yes || echo no' 2>/dev/null") ?? '') === 'yes';

    $defaultIface = '';
    if (preg_match('/^default\s+via\s+\S+\s+dev\s+(\S+)/m', $routeRaw, $m)) {
        $defaultIface = $m[1];
    }

    $missingRoutes = [];
    foreach ($routes as $route) {
        if (!preg_match('/^' . preg_quote($route, '/') . '(?:\s|$)/m', $routeRaw)) {
            $missingRoutes[] = $route;
        }
    }

    $addrLines = array_values(array_filter(array_map('trim', explode("\n", trim($addrRaw)))));
    $addrCount = count($addrLines);
    $defaultViaPrivate = $defaultIface !== '' && $target && $defaultIface === $target['iface'];

    return [
        'vmid' => $vmid,
        'networks' => $nets,
        'route_target' => $target,
        'default_iface' => $defaultIface,
        'route_table' => trim($routeRaw),
        'global_addrs' => $addrLines,
        'addr_count' => $addrCount,
        'dual_homed' => $addrCount > 1 || ($target && $defaultIface !== '' && $defaultIface !== $target['iface']),
        'default_via_private' => $defaultViaPrivate,
        'missing_routes' => $missingRoutes,
        'fixable' => !empty($missingRoutes) && $target !== null,
        'persistent_supported' => $hasInterfacesFile,
        'recommended_lines' => $target ? array_map(
            fn($route) => "post-up ip route replace $route via {$target['gateway']} dev {$target['iface']}",
            $missingRoutes
        ) : [],
    ];
}

/**
 * WireGuard VPN Verwaltung: Tunnel-Status, Config lesen/speichern,
 * Keys generieren, Tunnel erstellen/loeschen/starten/stoppen.
 *
 * Endpoints: wg-status, wg-config, wg-save, wg-genkeys, wg-net-ifaces, wg-list-ifaces, wg-create, wg-delete, wg-control, wg-add-peer, wg-update-peer, wg-remove-peer, wg-server-info, wg-import
 *
 * @param string $action Der API-Action-Name
 * @return bool true wenn behandelt
 */
function handleWireguardAPI(string $action): bool {
    // GET: WireGuard Status aller Interfaces + Peers
    if ($action === 'wg-status') {
        $raw = shell_exec('sudo /usr/bin/wg show all dump 2>/dev/null') ?? '';
        $lines = array_filter(explode("\n", trim($raw)));
        $interfaces = [];
        $currentIf = null;

        foreach ($lines as $line) {
            $cols = explode("\t", $line);
            $colCount = count($cols);
            if ($colCount === 5) {
                // Interface line: name, private_key, public_key, listen_port, fwmark
                $currentIf = $cols[0];
                $interfaces[$currentIf] = [
                    'name' => $cols[0],
                    'public_key' => $cols[2],
                    'listen_port' => (int)$cols[3],
                    'peers' => [],
                ];
            } elseif ($colCount === 9) {
                // Peer line: interface, public_key, preshared_key, endpoint, allowed_ips, latest_handshake, rx, tx, keepalive
                $ifName = $cols[0];
                if (!isset($interfaces[$ifName])) continue;
                $handshake = (int)$cols[5];
                $interfaces[$ifName]['peers'][] = [
                    'public_key' => $cols[1],
                    'psk' => ($cols[2] !== '(none)') ? $cols[2] : null,
                    'endpoint' => $cols[3] !== '(none)' ? $cols[3] : null,
                    'allowed_ips' => $cols[4],
                    'latest_handshake' => $handshake > 0 ? date('Y-m-d H:i:s', $handshake) : null,
                    'handshake_ago' => $handshake > 0 ? time() - $handshake : null,
                    'rx_bytes' => (int)$cols[6],
                    'tx_bytes' => (int)$cols[7],
                    'keepalive' => (int)$cols[8],
                ];
            }
        }

        // Get status — interface exists in dump = running, regardless of systemd
        foreach ($interfaces as $name => &$iface) {
            $sysActive = trim(shell_exec("systemctl is-active wg-quick@$name 2>/dev/null") ?? '');
            // If we got data from wg show, the interface IS running
            $iface['active'] = true;
            $iface['status'] = $sysActive === 'active' ? 'active (systemd)' : 'active (manual)';
        }
        unset($iface);

        // Merge config-based peers with live data for ALL interfaces
        $configs = glob('/etc/wireguard/wg*.conf');
        foreach ($configs as $confPath) {
            $name = basename($confPath, '.conf');

            if (!isset($interfaces[$name])) {
                $active = trim(shell_exec("systemctl is-active wg-quick@$name 2>/dev/null") ?? '');
                $interfaces[$name] = [
                    'name' => $name,
                    'public_key' => null,
                    'listen_port' => null,
                    'peers' => [],
                    'active' => $active === 'active',
                    'status' => $active,
                ];
            }

            $conf = file_get_contents($confPath);

            // Check if config was modified after service started → restart needed
            $confMtime = filemtime($confPath);
            $svcStart = 0;
            $startRaw = trim(shell_exec("systemctl show wg-quick@$name --property=ActiveEnterTimestamp --value 2>/dev/null") ?? '');
            if ($startRaw) $svcStart = strtotime($startRaw) ?: 0;
            $interfaces[$name]['needs_restart'] = $interfaces[$name]['active'] && $confMtime > $svcStart;

            // Extract interface info from config
            if (!$interfaces[$name]['listen_port'] && preg_match('/ListenPort\s*=\s*(\d+)/', $conf, $pm)) {
                $interfaces[$name]['listen_port'] = (int)$pm[1];
            }
            if (!$interfaces[$name]['public_key'] && preg_match('/PrivateKey\s*=\s*(\S+)/', $conf, $pm)) {
                $pub = trim(shell_exec("echo '{$pm[1]}' | wg pubkey 2>/dev/null") ?? '');
                if ($pub) $interfaces[$name]['public_key'] = $pub;
            }
            if (empty($interfaces[$name]['address']) && preg_match('/Address\s*=\s*(\S+)/', $conf, $pm)) {
                $interfaces[$name]['address'] = $pm[1];
            }

            // Build map of existing live peers by public key
            $livePeerKeys = [];
            foreach ($interfaces[$name]['peers'] as &$lp) {
                $livePeerKeys[$lp['public_key']] = true;
                // Enrich live peers with PSK + name from config
                if (preg_match('/\[Peer\][^[]*PublicKey\s*=\s*' . preg_quote($lp['public_key'], '/') . '[^[]*/s', $conf, $pBlock)) {
                    if (empty($lp['psk']) && preg_match('/PresharedKey\s*=\s*(\S+)/', $pBlock[0], $pskM)) {
                        $lp['psk'] = $pskM[1];
                    }
                    if (empty($lp['name']) && preg_match('/^#\s*(.+)/m', $pBlock[0], $cm)) {
                        $lp['name'] = trim($cm[1]);
                    }
                }
            }
            unset($lp);

            // Parse config [Peer] sections and add missing peers (not yet live)
            $peerBlocks = preg_split('/(?=\[Peer\])/i', $conf);
            array_shift($peerBlocks); // remove [Interface] part
            foreach ($peerBlocks as $block) {
                $pubKey = '';
                if (preg_match('/PublicKey\s*=\s*(\S+)/', $block, $m)) $pubKey = $m[1];
                if (!$pubKey || isset($livePeerKeys[$pubKey])) continue; // already live

                // Extract comment/name (# line before PublicKey)
                $peerName = '';
                if (preg_match('/^#\s*(.+)/m', $block, $cm)) $peerName = trim($cm[1]);

                $peer = [
                    'public_key' => $pubKey,
                    'name' => $peerName,
                    'psk' => null,
                    'endpoint' => null,
                    'allowed_ips' => '',
                    'latest_handshake' => null,
                    'handshake_ago' => null,
                    'rx_bytes' => 0,
                    'tx_bytes' => 0,
                    'keepalive' => 0,
                    'from_config' => true,
                ];
                if (preg_match('/PresharedKey\s*=\s*(\S+)/', $block, $m)) $peer['psk'] = $m[1];
                if (preg_match('/Endpoint\s*=\s*(\S+)/', $block, $m)) $peer['endpoint'] = $m[1];
                if (preg_match('/AllowedIPs\s*=\s*(.+)/', $block, $m)) $peer['allowed_ips'] = trim($m[1]);
                if (preg_match('/PersistentKeepalive\s*=\s*(\d+)/', $block, $m)) $peer['keepalive'] = (int)$m[1];
                $interfaces[$name]['peers'][] = $peer;
            }
        }

        echo json_encode(array_values($interfaces));
        return true;
    }

    // GET: WireGuard Interface-Config lesen
    if ($action === 'wg-config') {
        $iface = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['iface'] ?? 'wg0');
        $path = "/etc/wireguard/$iface.conf";
        if (!file_exists($path)) {
            echo json_encode(['ok' => false, 'error' => 'Config nicht gefunden']);
            return true;
        }
        $content = file_get_contents($path);
        echo json_encode(['ok' => true, 'config' => $content]);
        return true;
    }

    // POST: WireGuard Config speichern
    if ($action === 'wg-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $iface = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['iface'] ?? '');
        $content = $_POST['content'] ?? '';
        if (!$iface) {
            echo json_encode(['ok' => false, 'error' => 'Kein Interface angegeben']);
            return true;
        }
        $path = "/etc/wireguard/$iface.conf";
        wgWriteConf($path, $content);
        echo json_encode(['ok' => true]);
        return true;
    }

    // GET: Neues WireGuard Keypair + PSK generieren
    if ($action === 'wg-genkeys') {
        $privkey = trim(shell_exec('wg genkey 2>/dev/null') ?? '');
        $pubkey = $privkey ? trim(shell_exec("echo '$privkey' | wg pubkey 2>/dev/null") ?? '') : '';
        $psk = trim(shell_exec('wg genpsk 2>/dev/null') ?? '');
        echo json_encode(['ok' => (bool)$privkey, 'private_key' => $privkey, 'public_key' => $pubkey, 'preshared_key' => $psk]);
        return true;
    }

    // GET: Netzwerk-Interfaces auflisten (fuer PostUp Wizard)
    if ($action === 'wg-net-ifaces') {
        $raw = shell_exec("ip -o link show 2>/dev/null") ?? '';
        $ifaces = [];
        foreach (explode("\n", trim($raw)) as $line) {
            if (preg_match('/^\d+:\s+(\S+):/', $line, $m)) {
                $name = rtrim($m[1], ':');
                if (in_array($name, ['lo'])) continue;
                // Get IP
                $ip = trim(shell_exec("ip -4 addr show $name 2>/dev/null | grep -oP 'inet \\K[\\d./]+'") ?? '');
                $ifaces[] = ['name' => $name, 'ip' => $ip];
            }
        }
        echo json_encode(['ok' => true, 'interfaces' => $ifaces]);
        return true;
    }

    // GET: Vorhandene WireGuard-Interfaces auflisten
    if ($action === 'wg-list-ifaces') {
        $existing = [];
        foreach (glob('/etc/wireguard/wg*.conf') as $f) {
            $existing[] = basename($f, '.conf');
        }
        echo json_encode(['ok' => true, 'interfaces' => $existing]);
        return true;
    }

    // POST: Neuen WireGuard Tunnel erstellen und optional starten
    if ($action === 'wg-create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $iface = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['iface'] ?? '');
        $listenPort = (int)($_POST['listen_port'] ?? 0);
        $address = trim($_POST['address'] ?? '');
        $privateKey = trim($_POST['private_key'] ?? '');
        $peerPublicKey = trim($_POST['peer_public_key'] ?? '');
        $peerEndpoint = trim($_POST['peer_endpoint'] ?? '');
        $peerAllowedIps = trim($_POST['peer_allowed_ips'] ?? '');
        $peerPsk = trim($_POST['peer_psk'] ?? '');
        $keepalive = (int)($_POST['keepalive'] ?? 25);
        $postUp = trim($_POST['post_up'] ?? '');
        $postDown = trim($_POST['post_down'] ?? '');
        $autoStart = ($_POST['auto_start'] ?? '') === '1';

        if (!$iface || !$address || !$privateKey || !$peerPublicKey) {
            echo json_encode(['ok' => false, 'error' => 'Interface, Adresse, Private Key und Peer Public Key erforderlich']);
            return true;
        }

        $path = "/etc/wireguard/$iface.conf";
        if (file_exists($path)) {
            echo json_encode(['ok' => false, 'error' => "Interface $iface existiert bereits"]);
            return true;
        }

        // Build config
        $conf = "[Interface]\n";
        $conf .= "PrivateKey = $privateKey\n";
        $conf .= "Address = $address\n";
        if ($listenPort > 0) $conf .= "ListenPort = $listenPort\n";
        if ($postUp) $conf .= "PostUp = $postUp\n";
        if ($postDown) $conf .= "PostDown = $postDown\n";
        $conf .= "\n[Peer]\n";
        $conf .= "PublicKey = $peerPublicKey\n";
        if ($peerPsk) $conf .= "PresharedKey = $peerPsk\n";
        if ($peerEndpoint) $conf .= "Endpoint = $peerEndpoint\n";
        if ($peerAllowedIps) $conf .= "AllowedIPs = $peerAllowedIps\n";
        if ($keepalive > 0) $conf .= "PersistentKeepalive = $keepalive\n";

        wgWriteConf($path, $conf);

        $started = false;
        if ($autoStart) {
            shell_exec("sudo systemctl enable wg-quick@$iface 2>&1");
            shell_exec("sudo systemctl start wg-quick@$iface 2>&1");
            $started = trim(shell_exec("systemctl is-active wg-quick@$iface 2>/dev/null") ?? '') === 'active';
        }

        // Firewall: Port in PVE Firewall eintragen wenn gewuenscht
        $fwAdded = false;
        $addFw = ($_POST['add_firewall'] ?? '') === '1';
        if ($addFw && $listenPort > 0) {
            $node = trim(shell_exec('hostname -s 2>/dev/null') ?? '');
            if ($node) {
                $comment = "WireGuard $iface (auto-added by FloppyOps)";
                shell_exec("sudo pvesh create /nodes/" . escapeshellarg($node) . "/firewall/rules"
                    . " --action ACCEPT --type in --proto udp --dport " . escapeshellarg((string)$listenPort)
                    . " --enable 1 --comment " . escapeshellarg($comment)
                    . " 2>&1");
                $fwAdded = true;
            }
        }

        echo json_encode(['ok' => true, 'interface' => $iface, 'started' => $started, 'fw_added' => $fwAdded]);
        return true;
    }

    // POST: WireGuard Tunnel stoppen und Config loeschen
    if ($action === 'wg-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $iface = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['iface'] ?? '');
        if (!$iface) { echo json_encode(['ok' => false, 'error' => 'Kein Interface']); return true; }

        shell_exec("sudo systemctl stop wg-quick@$iface 2>&1");
        shell_exec("sudo systemctl disable wg-quick@$iface 2>&1");
        $path = "/etc/wireguard/$iface.conf";
        if (file_exists($path)) unlink($path);
        echo json_encode(['ok' => true]);
        return true;
    }

    // POST: WireGuard Interface starten/stoppen/neustarten
    if ($action === 'wg-control' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $iface = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['iface'] ?? '');
        $cmd = $_POST['cmd'] ?? '';
        if (!$iface || !in_array($cmd, ['start', 'stop', 'restart'])) {
            echo json_encode(['ok' => false, 'error' => 'Ungültige Parameter']);
            return true;
        }
        $out = shell_exec("sudo systemctl $cmd wg-quick@$iface 2>&1") ?? '';
        $active = trim(shell_exec("systemctl is-active wg-quick@$iface 2>/dev/null") ?? '');
        echo json_encode(['ok' => true, 'status' => $active, 'output' => trim($out)]);
        return true;
    }

    // POST: WireGuard Config importieren (von anderem VPN-Server)
    if ($action === 'wg-import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $iface = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['iface'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $autoStart = ($_POST['auto_start'] ?? '') === '1';
        $addFw = ($_POST['add_firewall'] ?? '') === '1';

        if (!$iface || !$content) {
            echo json_encode(['ok' => false, 'error' => 'Interface-Name und Config-Inhalt erforderlich']);
            return true;
        }

        // Validate: must contain [Interface] section
        if (stripos($content, '[Interface]') === false) {
            echo json_encode(['ok' => false, 'error' => 'Ungültige Config — [Interface] Sektion fehlt']);
            return true;
        }

        $path = "/etc/wireguard/$iface.conf";
        if (file_exists($path)) {
            echo json_encode(['ok' => false, 'error' => "Interface $iface existiert bereits"]);
            return true;
        }

        wgWriteConf($path, $content . "\n");

        $started = false;
        if ($autoStart) {
            shell_exec("sudo systemctl enable wg-quick@$iface 2>&1");
            shell_exec("sudo systemctl start wg-quick@$iface 2>&1");
            $started = trim(shell_exec("systemctl is-active wg-quick@$iface 2>/dev/null") ?? '') === 'active';
        }

        // Firewall: extract ListenPort from config
        $fwAdded = false;
        if ($addFw && preg_match('/ListenPort\s*=\s*(\d+)/', $content, $pm)) {
            $port = (int)$pm[1];
            if ($port > 0) {
                $node = trim(shell_exec('hostname -s 2>/dev/null') ?? '');
                if ($node) {
                    $comment = "WireGuard $iface (imported by FloppyOps)";
                    shell_exec("sudo pvesh create /nodes/" . escapeshellarg($node) . "/firewall/rules"
                        . " --action ACCEPT --type in --proto udp --dport " . escapeshellarg((string)$port)
                        . " --enable 1 --comment " . escapeshellarg($comment)
                        . " 2>&1");
                    $fwAdded = true;
                }
            }
        }

        echo json_encode(['ok' => true, 'interface' => $iface, 'started' => $started, 'fw_added' => $fwAdded]);
        return true;
    }

    // GET: WireGuard Logs (journalctl)
    if ($action === 'wg-logs') {
        $iface = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['iface'] ?? '');
        $lines = (int)($_GET['lines'] ?? 50);
        if ($lines < 10) $lines = 10;
        if ($lines > 500) $lines = 500;
        if (!$iface) { echo json_encode(['ok' => false, 'error' => 'Kein Interface']); return true; }
        $log = shell_exec("sudo journalctl -u wg-quick@$iface --no-pager -n $lines 2>&1") ?? '';
        $dmesg = trim(shell_exec("dmesg | grep -i wireguard 2>/dev/null") ?? '');
        echo json_encode(['ok' => true, 'log' => trim($log), 'dmesg' => $dmesg]);
        return true;
    }

    // GET: Server-Info fuer ein Interface (Public Key, Listen Port, Public IP)
    if ($action === 'wg-server-info') {
        $iface = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['iface'] ?? '');
        if (!$iface) { echo json_encode(['ok' => false, 'error' => 'Kein Interface']); return true; }

        $path = "/etc/wireguard/$iface.conf";
        if (!file_exists($path)) { echo json_encode(['ok' => false, 'error' => 'Config nicht gefunden']); return true; }

        $conf = file_get_contents($path);
        $serverPub = '';
        $listenPort = 0;
        $address = '';

        // Extract PrivateKey → derive PublicKey
        if (preg_match('/PrivateKey\s*=\s*(\S+)/', $conf, $m)) {
            $serverPub = trim(shell_exec("echo '{$m[1]}' | wg pubkey 2>/dev/null") ?? '');
        }
        if (preg_match('/ListenPort\s*=\s*(\d+)/', $conf, $m)) $listenPort = (int)$m[1];
        if (preg_match('/Address\s*=\s*(\S+)/', $conf, $m)) $address = $m[1];

        // Detect public IP (cached 5min)
        $pubIpCache = '/tmp/floppyops-lite-pubip.cache';
        if (file_exists($pubIpCache) && (time() - filemtime($pubIpCache)) < 300) {
            $publicIp = trim(file_get_contents($pubIpCache));
        } else {
            $publicIp = trim(shell_exec("curl -4 -s --max-time 3 ifconfig.me 2>/dev/null") ?? '');
            if ($publicIp) @file_put_contents($pubIpCache, $publicIp);
        }

        // Count existing peers to suggest next IP
        $peerCount = preg_match_all('/\[Peer\]/', $conf);
        $nextPeerNum = $peerCount + 1;

        // Suggest next peer IP from address subnet
        $suggestedIp = '';
        if (preg_match('/^(\d+\.\d+\.\d+)\.(\d+)\/(\d+)$/', $address, $am)) {
            $suggestedIp = $am[1] . '.' . ($am[2] + $nextPeerNum) . '/' . $am[3];
        }

        echo json_encode([
            'ok' => true,
            'public_key' => $serverPub,
            'listen_port' => $listenPort,
            'address' => $address,
            'public_ip' => $publicIp,
            'suggested_ip' => $suggestedIp,
            'peer_count' => $peerCount,
        ]);
        return true;
    }

    if ($action === 'wg-lxc-route-audit') {
        $routes = wgParseRouteNetworks($_GET['routes'] ?? '');
        if ($routes === []) {
            echo json_encode(['ok' => false, 'error' => 'Mindestens ein Zielnetz erforderlich']);
            return true;
        }

        $node = trim(shell_exec('hostname 2>/dev/null') ?? '');
        $raw = shell_exec("sudo pvesh get /nodes/$node/lxc --output-format json 2>/dev/null") ?? '[]';
        $cts = json_decode($raw, true) ?: [];
        $result = [];

        foreach ($cts as $ct) {
            if (($ct['status'] ?? '') !== 'running') continue;
            $vmid = (int)($ct['vmid'] ?? 0);
            if ($vmid <= 0) continue;

            $inspect = wgInspectLxcReachability($vmid, $routes);
            $result[] = array_merge([
                'name' => $ct['name'] ?? '',
                'status' => $ct['status'] ?? 'unknown',
            ], $inspect);
        }

        usort($result, fn($a, $b) => $a['vmid'] <=> $b['vmid']);
        echo json_encode(['ok' => true, 'routes' => $routes, 'containers' => $result]);
        return true;
    }

    if ($action === 'wg-lxc-route-fix' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $vmid = (int)($_POST['vmid'] ?? 0);
        $routes = wgParseRouteNetworks($_POST['routes'] ?? '');
        $persist = ($_POST['persist'] ?? '1') === '1';

        if ($vmid <= 0 || $routes === []) {
            echo json_encode(['ok' => false, 'error' => 'VMID und Zielnetze erforderlich']);
            return true;
        }

        $inspect = wgInspectLxcReachability($vmid, $routes);
        $target = $inspect['route_target'] ?? null;
        $missingRoutes = $inspect['missing_routes'] ?? [];
        if ($target === null) {
            echo json_encode(['ok' => false, 'error' => 'Kein internes LXC-Interface mit passendem Gateway erkannt']);
            return true;
        }
        if ($missingRoutes === []) {
            echo json_encode(['ok' => true, 'message' => 'Keine fehlenden Routen erkannt', 'inspect' => $inspect]);
            return true;
        }
        if ($persist && empty($inspect['persistent_supported'])) {
            echo json_encode(['ok' => false, 'error' => '/etc/network/interfaces nicht gefunden — Persistenz aktuell nur fuer ifupdown']);
            return true;
        }

        $script = "set -e\n";
        foreach ($missingRoutes as $route) {
            $script .= 'ip route replace ' . escapeshellarg($route)
                . ' via ' . escapeshellarg($target['gateway'])
                . ' dev ' . escapeshellarg($target['iface']) . "\n";
        }
        if ($persist) {
            $script .= "test -f /etc/network/interfaces\n";
            $script .= "cp /etc/network/interfaces /etc/network/interfaces.floppyops-lite.bak\n";
            foreach ($missingRoutes as $route) {
                $line = "post-up ip route replace $route via {$target['gateway']} dev {$target['iface']}";
                $script .= "grep -qF " . escapeshellarg($line) . " /etc/network/interfaces || printf '\\n%s\\n' "
                    . escapeshellarg($line) . " >> /etc/network/interfaces\n";
            }
        }
        $script .= "ip -4 route show table main\n";

        $cmd = 'sudo pct exec ' . escapeshellarg((string)$vmid) . ' -- sh -lc ' . escapeshellarg($script) . ' 2>&1';
        $output = shell_exec($cmd) ?? '';
        $refreshed = wgInspectLxcReachability($vmid, $routes);

        echo json_encode([
            'ok' => true,
            'output' => trim($output),
            'message' => 'Routen angewendet',
            'inspect' => $refreshed,
        ]);
        return true;
    }

    // POST: Peer zu bestehendem Interface hinzufuegen
    if ($action === 'wg-add-peer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $iface = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['iface'] ?? '');
        $peerPubKey = trim($_POST['peer_public_key'] ?? '');
        $peerAllowedIps = trim($_POST['allowed_ips'] ?? '');
        $peerPsk = trim($_POST['psk'] ?? '');
        $keepalive = (int)($_POST['keepalive'] ?? 25);
        $peerEndpoint = trim($_POST['endpoint'] ?? '');
        $peerName = trim(preg_replace('/[^\w\s\-\.\(\)]/', '', $_POST['peer_name'] ?? ''));

        if (!$iface || !$peerPubKey || !$peerAllowedIps) {
            echo json_encode(['ok' => false, 'error' => 'Interface, Public Key und Allowed IPs erforderlich']);
            return true;
        }

        $path = "/etc/wireguard/$iface.conf";
        if (!file_exists($path)) {
            echo json_encode(['ok' => false, 'error' => "Config $iface.conf nicht gefunden"]);
            return true;
        }

        // Check if peer already exists
        $conf = file_get_contents($path);
        if (strpos($conf, $peerPubKey) !== false) {
            echo json_encode(['ok' => false, 'error' => 'Peer mit diesem Public Key existiert bereits']);
            return true;
        }

        // Append peer section to config
        $peerSection = "\n[Peer]\n";
        if ($peerName) $peerSection .= "# $peerName\n";
        $peerSection .= "PublicKey = $peerPubKey\n";
        if ($peerPsk) $peerSection .= "PresharedKey = $peerPsk\n";
        $peerSection .= "AllowedIPs = $peerAllowedIps\n";
        if ($keepalive > 0) $peerSection .= "PersistentKeepalive = $keepalive\n";

        if (!wgWriteConf($path, $conf . $peerSection)) {
            echo json_encode(['ok' => false, 'error' => 'Config konnte nicht geschrieben werden — Berechtigung prüfen']);
            return true;
        }

        // If interface is running, add peer live via wg set
        $isActive = trim(shell_exec("systemctl is-active wg-quick@$iface 2>/dev/null") ?? '') === 'active';
        if ($isActive) {
            $cmd = "sudo wg set $iface peer $peerPubKey allowed-ips $peerAllowedIps";
            if ($peerPsk) {
                // PSK must be passed via file
                $tmpPsk = tempnam('/tmp', 'wgpsk_');
                file_put_contents($tmpPsk, $peerPsk);
                $cmd .= " preshared-key $tmpPsk";
            }
            if ($keepalive > 0) $cmd .= " persistent-keepalive $keepalive";
            shell_exec("$cmd 2>&1");
            if (isset($tmpPsk)) unlink($tmpPsk);
        }

        echo json_encode(['ok' => true, 'live' => $isActive]);
        return true;
    }

    // POST: Peer in Config aktualisieren
    if ($action === 'wg-update-peer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $iface = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['iface'] ?? '');
        $pubKey = trim($_POST['public_key'] ?? '');
        $name = trim(preg_replace('/[^\w\s\-\.\(\)]/', '', $_POST['name'] ?? ''));
        $endpoint = trim($_POST['endpoint'] ?? '');
        $keepalive = (int)($_POST['keepalive'] ?? 25);
        $allowedIps = trim($_POST['allowed_ips'] ?? '');
        $psk = trim($_POST['psk'] ?? '');

        if (!$iface || !$pubKey || !$allowedIps) {
            echo json_encode(['ok' => false, 'error' => 'Interface, Public Key und AllowedIPs erforderlich']);
            return true;
        }

        $path = "/etc/wireguard/$iface.conf";
        if (!file_exists($path)) {
            echo json_encode(['ok' => false, 'error' => "Config nicht gefunden"]);
            return true;
        }

        $conf = file_get_contents($path);

        // Remove old [Peer] block for this public key
        $parts = preg_split('/(?=\[Peer\])/i', $conf);
        $newConf = '';
        foreach ($parts as $part) {
            if (strpos($part, $pubKey) !== false && preg_match('/\[Peer\]/i', $part)) {
                continue; // skip old peer block
            }
            $newConf .= $part;
        }

        // Append updated peer
        $newConf = rtrim($newConf) . "\n\n[Peer]\n";
        if ($name) $newConf .= "# $name\n";
        $newConf .= "PublicKey = $pubKey\n";
        if ($psk) $newConf .= "PresharedKey = $psk\n";
        if ($endpoint) $newConf .= "Endpoint = $endpoint\n";
        $newConf .= "AllowedIPs = $allowedIps\n";
        if ($keepalive > 0) $newConf .= "PersistentKeepalive = $keepalive\n";

        if (!wgWriteConf($path, $newConf)) {
            echo json_encode(['ok' => false, 'error' => 'Config konnte nicht geschrieben werden']);
            return true;
        }

        echo json_encode(['ok' => true]);
        return true;
    }

    // POST: Peer von Interface entfernen
    if ($action === 'wg-remove-peer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $iface = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['iface'] ?? '');
        $peerPubKey = trim($_POST['public_key'] ?? '');

        if (!$iface || !$peerPubKey) {
            echo json_encode(['ok' => false, 'error' => 'Interface und Public Key erforderlich']);
            return true;
        }

        $path = "/etc/wireguard/$iface.conf";
        if (!file_exists($path)) {
            echo json_encode(['ok' => false, 'error' => "Config nicht gefunden"]);
            return true;
        }

        // Parse config and remove the matching [Peer] block
        $conf = file_get_contents($path);
        $lines = explode("\n", $conf);
        $newLines = [];
        $inPeer = false;
        $skipPeer = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '[Peer]') {
                $inPeer = true;
                $skipPeer = false;
                $peerBuffer = [$line];
                continue;
            }
            if ($inPeer) {
                if ($trimmed === '[Interface]' || $trimmed === '[Peer]') {
                    // New section — flush buffered peer if not skipped
                    if (!$skipPeer) {
                        $newLines = array_merge($newLines, $peerBuffer);
                    }
                    if ($trimmed === '[Peer]') {
                        $peerBuffer = [$line];
                        $skipPeer = false;
                        continue;
                    } else {
                        $inPeer = false;
                        $newLines[] = $line;
                        continue;
                    }
                }
                $peerBuffer[] = $line;
                if (preg_match('/PublicKey\s*=\s*(\S+)/', $trimmed, $m) && $m[1] === $peerPubKey) {
                    $skipPeer = true;
                }
                continue;
            }
            $newLines[] = $line;
        }

        // Flush last peer buffer
        if ($inPeer && !$skipPeer) {
            $newLines = array_merge($newLines, $peerBuffer);
        }

        wgWriteConf($path, implode("\n", $newLines));

        // Remove peer live if interface is running
        $isActive = trim(shell_exec("systemctl is-active wg-quick@$iface 2>/dev/null") ?? '') === 'active';
        if ($isActive) {
            shell_exec("sudo wg set $iface peer $peerPubKey remove 2>&1");
        }

        echo json_encode(['ok' => true, 'live' => $isActive]);
        return true;
    }

    return false;
}
