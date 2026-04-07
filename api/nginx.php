<?php
/**
 * FloppyOps Lite — API: Nginx Reverse Proxy
 *
 * Nginx Reverse Proxy, Sites, SSL, System-Checks — Sites auflisten,
 * erstellen, bearbeiten, loeschen, SSL erneuern, Config testen,
 * System-Checks (IP-Forwarding, NAT, Bridges) und Fixes.
 *
 * Endpoints: nginx-checks, nginx-fix, nginx-sites, nginx-add, nginx-update, nginx-save, nginx-delete, nginx-renew, ssl-health, ssl-fix-ipv6only, nginx-reload
 */

/**
 * Nginx Reverse Proxy Verwaltung: Sites auflisten, erstellen, bearbeiten,
 * loeschen, SSL erneuern, Config testen, System-Checks und Fixes.
 *
 * Endpoints: nginx-checks, nginx-fix, nginx-sites, nginx-add, nginx-save, nginx-delete, nginx-renew, ssl-health, ssl-fix-ipv6only, nginx-reload
 *
 * @param string $action Der API-Action-Name
 * @return bool true wenn behandelt
 */
function handleNginxAPI(string $action): bool {
    // GET: System-Checks (IP-Forwarding, NAT, Bridges, Nginx, Certbot)
    if ($action === 'nginx-checks') {
        $checks = [];

        // IP Forwarding
        $ipv4Fwd = trim(file_get_contents('/proc/sys/net/ipv4/ip_forward') ?? '0');
        $ipv6Fwd = trim(@file_get_contents('/proc/sys/net/ipv6/conf/all/forwarding') ?? '0');
        $checks[] = ['id' => 'ipv4_fwd', 'label' => 'IPv4 Forwarding', 'ok' => $ipv4Fwd === '1', 'value' => $ipv4Fwd === '1' ? 'Aktiv' : 'Inaktiv', 'fix' => 'echo 1 > /proc/sys/net/ipv4/ip_forward'];
        $checks[] = ['id' => 'ipv6_fwd', 'label' => 'IPv6 Forwarding', 'ok' => $ipv6Fwd === '1', 'value' => $ipv6Fwd === '1' ? 'Aktiv' : 'Inaktiv', 'fix' => 'echo 1 > /proc/sys/net/ipv6/conf/all/forwarding'];

        // NDP Proxy (needed for IPv6 between bridges, e.g. vmbr1 CTs reaching vmbr0 CTs)
        $ndpProxy = trim(@file_get_contents('/proc/sys/net/ipv6/conf/all/proxy_ndp') ?? '0');
        $hasNdpEntries = !empty(trim(shell_exec('ip -6 neigh show proxy 2>/dev/null') ?? ''));
        // Only flag as problem if IPv6 forwarding is on AND NDP proxy entries exist but proxy_ndp is off
        $ndpRelevant = $ipv6Fwd === '1' && $hasNdpEntries;
        $ndpOk = !$ndpRelevant || $ndpProxy === '1';
        $checks[] = ['id' => 'ndp_proxy', 'label' => 'IPv6 NDP Proxy', 'ok' => $ndpOk, 'value' => $ndpProxy === '1' ? 'Aktiv' : ($ndpRelevant ? 'Inaktiv (NDP Einträge vorhanden!)' : 'Inaktiv'), 'fix' => $ndpOk ? '' : 'ndp_proxy'];

        // Bridges (needed for NAT check below)
        $bridges = [];
        $ifRaw = shell_exec('ip -o link show type bridge 2>/dev/null') ?? '';
        foreach (explode("\n", trim($ifRaw)) as $line) {
            if (preg_match('/\d+:\s+(\S+):/', $line, $m)) $bridges[] = $m[1];
        }

        // NAT/Masquerading
        $natRules = shell_exec('sudo iptables -t nat -L POSTROUTING -n 2>/dev/null') ?? '';
        $hasMasq = str_contains($natRules, 'MASQUERADE');
        $pubIface = trim(shell_exec("ip -4 route show default 2>/dev/null | grep -oP 'dev \\K\\S+'") ?? 'vmbr0');
        $intBridge = '';
        foreach ($bridges as $b) { if ($b !== $pubIface && $b !== 'vmbr0') { $intBridge = $b; break; } }
        if (!$intBridge && count($bridges) > 1) $intBridge = $bridges[1];
        $intSubnet = $intBridge ? trim(shell_exec("ip -4 addr show $intBridge 2>/dev/null | grep -oP 'inet \\K[\\d.]+/\\d+'") ?? '') : '';
        // Convert IP/mask to network (e.g. 10.10.10.1/24 → 10.10.10.0/24)
        $natSubnet = '';
        if ($intSubnet && preg_match('#^(\d+\.\d+\.\d+)\.\d+(/\d+)$#', $intSubnet, $sm)) $natSubnet = $sm[1] . '.0' . $sm[2];

        $checks[] = ['id' => 'nat', 'label' => 'NAT/Masquerading', 'ok' => $hasMasq, 'value' => $hasMasq ? 'Aktiv' : 'Nicht konfiguriert', 'fix' => ($hasMasq || !$natSubnet) ? '' : 'nat', 'nat_subnet' => $natSubnet, 'nat_iface' => $pubIface];

        // Internal bridge (already loaded above)
        $hasIntBridge = count($bridges) > 1;
        $checks[] = ['id' => 'bridge', 'label' => 'Interne Bridge (vmbr1+)', 'ok' => $hasIntBridge, 'value' => $hasIntBridge ? implode(', ', $bridges) : 'Nur ' . ($bridges[0] ?? 'keine'), 'fix' => ''];

        // Nginx running
        $nginxActive = trim(shell_exec('systemctl is-active nginx 2>/dev/null') ?? '') === 'active';
        $checks[] = ['id' => 'nginx', 'label' => 'Nginx', 'ok' => $nginxActive, 'value' => $nginxActive ? 'Running' : 'Stopped', 'fix' => 'systemctl start nginx'];

        // Certbot
        $certbotOk = file_exists('/usr/bin/certbot');
        $checks[] = ['id' => 'certbot', 'label' => 'Certbot (SSL)', 'ok' => $certbotOk, 'value' => $certbotOk ? 'Installiert' : 'Fehlt', 'fix' => 'apt install -y certbot python3-certbot-nginx'];

        echo json_encode(['ok' => true, 'checks' => $checks]);
        return true;
    }

    // POST: System-Problem beheben (Forwarding, NAT, Certbot installieren)
    if ($action === 'nginx-fix' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $fixId = $_POST['fix_id'] ?? '';
        $out = '';
        switch ($fixId) {
            case 'ipv4_fwd':
                shell_exec('echo 1 > /proc/sys/net/ipv4/ip_forward');
                // Make permanent
                shell_exec("sudo sed -i 's/#*net.ipv4.ip_forward.*/net.ipv4.ip_forward=1/' /etc/sysctl.conf 2>/dev/null");
                shell_exec("grep -q 'net.ipv4.ip_forward' /etc/sysctl.conf || echo 'net.ipv4.ip_forward=1' | sudo tee -a /etc/sysctl.conf > /dev/null");
                $out = 'IPv4 Forwarding aktiviert (permanent)';
                break;
            case 'ipv6_fwd':
                shell_exec('echo 1 > /proc/sys/net/ipv6/conf/all/forwarding');
                shell_exec("grep -q 'net.ipv6.conf.all.forwarding' /etc/sysctl.conf || echo 'net.ipv6.conf.all.forwarding=1' | sudo tee -a /etc/sysctl.conf > /dev/null");
                $out = 'IPv6 Forwarding aktiviert (permanent)';
                break;
            case 'ndp_proxy':
                shell_exec('sysctl -w net.ipv6.conf.all.proxy_ndp=1 > /dev/null 2>&1');
                shell_exec("grep -q 'net.ipv6.conf.all.proxy_ndp' /etc/sysctl.conf || echo 'net.ipv6.conf.all.proxy_ndp=1' | sudo tee -a /etc/sysctl.conf > /dev/null");
                $out = 'IPv6 NDP Proxy aktiviert (permanent)';
                break;
            case 'nginx':
                $out = shell_exec('sudo systemctl start nginx 2>&1') ?? '';
                break;
            case 'certbot':
                $out = shell_exec('sudo apt-get install -y certbot python3-certbot-nginx 2>&1') ?? '';
                break;
            case 'nat':
                $subnet = trim($_POST['subnet'] ?? '');
                $iface = trim($_POST['iface'] ?? '');
                if (!$subnet || !$iface || !preg_match('#^\d+\.\d+\.\d+\.\d+/\d+$#', $subnet)) {
                    echo json_encode(['ok' => false, 'error' => 'Subnet/Interface ungueltig']);
                    return true;
                }
                // Add iptables rule
                shell_exec("sudo iptables -t nat -A POSTROUTING -s " . escapeshellarg($subnet) . " -o " . escapeshellarg($iface) . " -j MASQUERADE 2>&1");
                // Make persistent in /etc/network/interfaces
                $intBridge = trim($_POST['bridge'] ?? 'vmbr1');
                $ifacesFile = '/etc/network/interfaces';
                $content = file_get_contents($ifacesFile) ?? '';
                if (!str_contains($content, 'MASQUERADE')) {
                    $postUp = "\tpost-up iptables -t nat -A POSTROUTING -s {$subnet} -o {$iface} -j MASQUERADE\n\tpost-down iptables -t nat -D POSTROUTING -s {$subnet} -o {$iface} -j MASQUERADE";
                    // Add after the bridge definition
                    $content = preg_replace("/(iface {$intBridge} inet static.*?)(\\n\\n|\\niface |\\nauto )/s", "$1\n{$postUp}$2", $content, 1);
                    $tmpFile = tempnam(sys_get_temp_dir(), 'ifaces_');
                    file_put_contents($tmpFile, $content);
                    shell_exec("sudo cp " . escapeshellarg($tmpFile) . " " . escapeshellarg($ifacesFile) . " 2>&1");
                    unlink($tmpFile);
                }
                $out = "NAT/Masquerading für {$subnet} via {$iface} aktiviert (permanent)";
                break;
            default:
                echo json_encode(['ok' => false, 'error' => 'Unbekannter Fix']);
                return true;
        }
        echo json_encode(['ok' => true, 'output' => trim($out)]);
        return true;
    }

    // GET: Alle Nginx-Sites mit Domains, Proxy-Target, SSL-Status
    if ($action === 'nginx-sites') {
        $dir = NGINX_SITES_DIR;
        $sites = [];
        if (is_dir($dir)) {
            foreach (array_diff(scandir($dir), ['.', '..', 'default', 'server-admin']) as $file) {
                $path = "$dir/$file";
                $content = file_get_contents($path) ?: '';
                preg_match_all('/server_name\s+([^;]+);/', $content, $sn);
                $domains = [];
                foreach ($sn[1] ?? [] as $d) {
                    foreach (preg_split('/\s+/', trim($d)) as $dd) {
                        if ($dd && $dd !== '_') $domains[] = $dd;
                    }
                }
                $domains = array_unique($domains);
                preg_match('/proxy_pass\s+(https?:\/\/[^;]+);/', $content, $pp);
                $target = $pp[1] ?? '';
                $ssl = (bool)preg_match('/listen\s+443\s+ssl/', $content);
                // SSL cert expiry via openssl s_client (works without root)
                $sslExpiry = null;
                $sslDaysLeft = null;
                if ($ssl && !empty($domains)) {
                    $mainDomain = reset($domains);
                    $raw = shell_exec("echo | openssl s_client -servername " . escapeshellarg($mainDomain) . " -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null") ?? '';
                    if (preg_match('/notAfter=(.+)$/', trim($raw), $em)) {
                        $ts = strtotime($em[1]);
                        if ($ts) {
                            $sslExpiry = date('Y-m-d', $ts);
                            $sslDaysLeft = (int)round(($ts - time()) / 86400);
                        }
                    }
                }
                $sites[] = [
                    'file' => $file,
                    'domains' => array_values($domains),
                    'target' => $target,
                    'ssl' => $ssl,
                    'ssl_expiry' => $sslExpiry,
                    'ssl_days_left' => $sslDaysLeft,
                    'content' => $content,
                ];
            }
        }
        echo json_encode($sites);
        return true;
    }

    // POST: Neue Reverse-Proxy Site erstellen (optional mit SSL/Certbot)
    if ($action === 'nginx-add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $domainRaw = trim($_POST['domain'] ?? '');
        $target = trim($_POST['target'] ?? '');
        $withSsl = ($_POST['ssl'] ?? '') === '1';

        // Parse multiple domains (comma or space separated)
        $domains = array_filter(array_map('trim', preg_split('/[\s,]+/', $domainRaw)));
        if (empty($domains)) {
            echo json_encode(['ok' => false, 'error' => 'Mindestens eine Domain erforderlich']);
            return true;
        }
        foreach ($domains as $d) {
            if (!preg_match('/^[a-zA-Z0-9.*-]+\.[a-zA-Z]{2,}$/', $d)) {
                echo json_encode(['ok' => false, 'error' => "Ungültiger Domain-Name: $d"]);
                return true;
            }
        }
        if (!preg_match('/^https?:\/\/[\d.:]+$/', $target)) {
            echo json_encode(['ok' => false, 'error' => 'Ungültiges Ziel (z.B. http://10.10.10.100:80)']);
            return true;
        }

        $withWs = ($_POST['ws'] ?? '') === '1';
        $forceSsl = ($_POST['force_ssl'] ?? '') === '1';
        $maxUpload = (int)($_POST['max_upload'] ?? 0);
        $timeout = (int)($_POST['timeout'] ?? 0);

        $serverNames = implode(' ', $domains);
        $safeFile = preg_replace('/[^a-zA-Z0-9.-]/', '_', $domains[0]);
        $conf = "server {\n    listen 80;\n    listen [::]:80;\n    server_name $serverNames;\n\n";
        if ($forceSsl) {
            $conf .= "    return 301 https://\$host\$request_uri;\n}\n\n";
            $conf .= "server {\n    listen 443 ssl;\n    listen [::]:443 ssl;\n    server_name $serverNames;\n\n";
        }
        if ($maxUpload > 0) {
            $conf .= "    client_max_body_size {$maxUpload}m;\n";
        } elseif ($maxUpload === 0 && isset($_POST['max_upload']) && $_POST['max_upload'] === '0') {
            $conf .= "    client_max_body_size 0;\n";
        }
        $conf .= "\n    location / {\n";
        $conf .= "        proxy_pass $target;\n";
        $conf .= "        proxy_set_header Host \$host;\n";
        $conf .= "        proxy_set_header X-Real-IP \$remote_addr;\n";
        $conf .= "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n";
        $conf .= "        proxy_set_header X-Forwarded-Proto \$scheme;\n";
        if ($withWs) {
            $conf .= "        proxy_http_version 1.1;\n";
            $conf .= "        proxy_set_header Upgrade \$http_upgrade;\n";
            $conf .= "        proxy_set_header Connection \"upgrade\";\n";
        }
        if ($timeout > 0) {
            $conf .= "        proxy_connect_timeout {$timeout}s;\n";
            $conf .= "        proxy_send_timeout {$timeout}s;\n";
            $conf .= "        proxy_read_timeout {$timeout}s;\n";
        }
        $conf .= "    }\n}\n";

        $availPath = NGINX_SITES_AVAILABLE . "/$safeFile";
        $enablePath = NGINX_SITES_DIR . "/$safeFile";

        if (!@file_put_contents($availPath, $conf)) {
            // Fallback: write via sudo
            $tmpFile = tempnam('/tmp', 'nginx_');
            file_put_contents($tmpFile, $conf);
            shell_exec('sudo cp ' . escapeshellarg($tmpFile) . ' ' . escapeshellarg($availPath) . ' && sudo chmod 644 ' . escapeshellarg($availPath) . ' 2>&1');
            @unlink($tmpFile);
            if (!file_exists($availPath)) {
                echo json_encode(['ok' => false, 'error' => 'Konnte Config nicht schreiben (Berechtigungen)']);
                return true;
            }
        }
        if (!file_exists($enablePath)) {
            if (!@symlink($availPath, $enablePath)) {
                shell_exec('sudo ln -sf ' . escapeshellarg($availPath) . ' ' . escapeshellarg($enablePath) . ' 2>&1');
            }
        }

        // Test config
        $test = shell_exec('sudo nginx -t 2>&1');
        if (strpos($test, 'successful') === false) {
            unlink($enablePath);
            unlink($availPath);
            echo json_encode(['ok' => false, 'error' => 'Nginx-Config ungueltig: ' . $test]);
            return true;
        }

        shell_exec('sudo systemctl reload nginx 2>&1');

        // Optionally run certbot for all domains
        $sslMsg = '';
        if ($withSsl) {
            $certArgs = implode(' ', array_map(fn($d) => '-d ' . escapeshellarg($d), $domains));
            $certOut = shell_exec("sudo certbot --nginx $certArgs --non-interactive --agree-tos --register-unsafely-without-email 2>&1");
            $sslMsg = (str_contains($certOut, 'Successfully') || str_contains($certOut, 'Congratulations')) ? ' + SSL aktiviert' : ' (SSL fehlgeschlagen)';
        }

        echo json_encode(['ok' => true, 'message' => "Site " . $domains[0] . " erstellt" . $sslMsg]);
        return true;
    }

    // POST: Nginx Site compact update (domains, ip, port, ws)
    if ($action === 'nginx-update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $file = basename($_POST['file'] ?? '');
        $domainsRaw = trim($_POST['domains'] ?? '');
        $ip = trim($_POST['ip'] ?? '');
        $port = trim($_POST['port'] ?? '80');
        $withWs = ($_POST['ws'] ?? '') === '1';
        $forceSsl = ($_POST['force_ssl'] ?? '') === '1';
        $maxUpload = $_POST['max_upload'] ?? '';
        $timeout = $_POST['timeout'] ?? '';

        if (!$file || !$domainsRaw || !$ip) {
            echo json_encode(['ok' => false, 'error' => 'Fehlende Felder']);
            return true;
        }

        $domains = array_filter(array_map('trim', preg_split('/[\s,]+/', $domainsRaw)));
        $availPath = NGINX_SITES_AVAILABLE . "/$file";
        $enablePath = NGINX_SITES_DIR . "/$file";
        $targetPath = file_exists($availPath) ? $availPath : $enablePath;
        $content = @file_get_contents($targetPath) ?: '';
        if (!$content) {
            $content = @shell_exec('sudo cat ' . escapeshellarg($targetPath) . ' 2>/dev/null') ?: '';
        }

        if ($content) {
            // Update server_name (all occurrences)
            $serverNames = implode(' ', $domains);
            $content = preg_replace('/server_name\s+[^;]+;/', "server_name $serverNames;", $content);
            // Update proxy_pass
            $newTarget = "http://$ip:$port";
            $content = preg_replace('/proxy_pass\s+https?:\/\/[^;]+;/', "proxy_pass $newTarget;", $content);

            // Toggle WebSocket
            $hasWs = (bool)preg_match('/proxy_set_header Upgrade/', $content);
            if ($withWs && !$hasWs) {
                $content = preg_replace('/(proxy_set_header X-Forwarded-Proto[^;]*;)/', "$1\n        proxy_http_version 1.1;\n        proxy_set_header Upgrade \$http_upgrade;\n        proxy_set_header Connection \"upgrade\";", $content);
            } elseif (!$withWs && $hasWs) {
                $content = preg_replace('/\s*proxy_http_version 1\.1;\s*\n/', "\n", $content);
                $content = preg_replace('/\s*proxy_set_header Upgrade[^;]*;\s*\n/', "\n", $content);
                $content = preg_replace('/\s*proxy_set_header Connection "upgrade";\s*\n/', "\n", $content);
            }

            // Toggle Force SSL
            $hasForceSsl = (bool)preg_match('/return 301 https/', $content);
            if ($forceSsl && !$hasForceSsl) {
                // Add redirect to first server block (port 80)
                $content = preg_replace('/(listen \[::\]:80;[^\n]*\n\s*server_name [^;]+;\s*\n)/', "$1\n    return 301 https://\$host\$request_uri;\n", $content, 1);
            } elseif (!$forceSsl && $hasForceSsl) {
                $content = preg_replace('/\s*return 301 https:\/\/\$host\$request_uri;\s*\n/', "\n", $content);
            }

            // Update client_max_body_size
            $hasUpload = (bool)preg_match('/client_max_body_size/', $content);
            if ($maxUpload !== '') {
                $val = $maxUpload === '0' ? '0' : "{$maxUpload}m";
                if ($hasUpload) {
                    $content = preg_replace('/client_max_body_size\s+[^;]+;/', "client_max_body_size $val;", $content);
                } else {
                    $content = preg_replace('/(server_name [^;]+;\s*\n)/', "$1    client_max_body_size $val;\n", $content);
                }
            } elseif ($hasUpload) {
                $content = preg_replace('/\s*client_max_body_size[^;]*;\s*\n/', "\n", $content);
            }

            // Update proxy timeouts
            $hasTimeout = (bool)preg_match('/proxy_read_timeout/', $content);
            if ($timeout !== '') {
                $tv = "{$timeout}s";
                if ($hasTimeout) {
                    $content = preg_replace('/proxy_connect_timeout\s+[^;]+;/', "proxy_connect_timeout $tv;", $content);
                    $content = preg_replace('/proxy_send_timeout\s+[^;]+;/', "proxy_send_timeout $tv;", $content);
                    $content = preg_replace('/proxy_read_timeout\s+[^;]+;/', "proxy_read_timeout $tv;", $content);
                } else {
                    $content = preg_replace('/(proxy_pass [^;]+;\s*\n)/', "$1        proxy_connect_timeout $tv;\n        proxy_send_timeout $tv;\n        proxy_read_timeout $tv;\n", $content, 1);
                }
            } elseif ($hasTimeout) {
                $content = preg_replace('/\s*proxy_connect_timeout[^;]*;\s*\n/', "\n", $content);
                $content = preg_replace('/\s*proxy_send_timeout[^;]*;\s*\n/', "\n", $content);
                $content = preg_replace('/\s*proxy_read_timeout[^;]*;\s*\n/', "\n", $content);
            }
        } else {
            // Fallback: generate fresh config (same as nginx-add)
            $serverNames = implode(' ', $domains);
            $content = "server {\n    listen 80;\n    listen [::]:80;\n    server_name $serverNames;\n\n";
            if ($forceSsl) {
                $content .= "    return 301 https://\$host\$request_uri;\n}\n\n";
                $content .= "server {\n    listen 443 ssl;\n    listen [::]:443 ssl;\n    server_name $serverNames;\n\n";
            }
            if ($maxUpload !== '') {
                $val = $maxUpload === '0' ? '0' : "{$maxUpload}m";
                $content .= "    client_max_body_size $val;\n";
            }
            $content .= "\n    location / {\n";
            $content .= "        proxy_pass http://$ip:$port;\n";
            $content .= "        proxy_set_header Host \$host;\n";
            $content .= "        proxy_set_header X-Real-IP \$remote_addr;\n";
            $content .= "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n";
            $content .= "        proxy_set_header X-Forwarded-Proto \$scheme;\n";
            if ($withWs) {
                $content .= "        proxy_http_version 1.1;\n";
                $content .= "        proxy_set_header Upgrade \$http_upgrade;\n";
                $content .= "        proxy_set_header Connection \"upgrade\";\n";
            }
            if ($timeout !== '') {
                $content .= "        proxy_connect_timeout {$timeout}s;\n";
                $content .= "        proxy_send_timeout {$timeout}s;\n";
                $content .= "        proxy_read_timeout {$timeout}s;\n";
            }
            $content .= "    }\n}\n";
        }

        if (!@file_put_contents($targetPath, $content)) {
            $tmpFile = tempnam('/tmp', 'nginx_');
            file_put_contents($tmpFile, $content);
            shell_exec('sudo cp ' . escapeshellarg($tmpFile) . ' ' . escapeshellarg($targetPath) . ' && sudo chmod 644 ' . escapeshellarg($targetPath) . ' 2>&1');
            @unlink($tmpFile);
        }
        $test = shell_exec('sudo nginx -t 2>&1');
        if (strpos($test, 'successful') === false) {
            echo json_encode(['ok' => false, 'error' => 'Nginx-Config ungueltig: ' . $test]);
            return true;
        }
        shell_exec('sudo systemctl reload nginx 2>&1');
        echo json_encode(['ok' => true]);
        return true;
    }

    // POST: Nginx Site-Config bearbeiten und Syntax pruefen
    if ($action === 'nginx-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $file = basename($_POST['file'] ?? '');
        $content = $_POST['content'] ?? '';
        if (!$file) {
            echo json_encode(['ok' => false, 'error' => 'Keine Datei angegeben']);
            return true;
        }
        $availPath = NGINX_SITES_AVAILABLE . "/$file";
        $enablePath = NGINX_SITES_DIR . "/$file";
        $targetPath = file_exists($availPath) ? $availPath : $enablePath;

        if (!@file_put_contents($targetPath, $content)) {
            $tmpFile = tempnam('/tmp', 'nginx_');
            file_put_contents($tmpFile, $content);
            shell_exec('sudo cp ' . escapeshellarg($tmpFile) . ' ' . escapeshellarg($targetPath) . ' && sudo chmod 644 ' . escapeshellarg($targetPath) . ' 2>&1');
            @unlink($tmpFile);
        }
        $test = shell_exec('sudo nginx -t 2>&1');
        if (strpos($test, 'successful') === false) {
            echo json_encode(['ok' => false, 'error' => 'Nginx-Config ungueltig: ' . $test]);
            return true;
        }
        shell_exec('sudo systemctl reload nginx 2>&1');
        echo json_encode(['ok' => true]);
        return true;
    }

    // POST: Nginx Site entfernen (sites-enabled + sites-available)
    if ($action === 'nginx-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $file = basename($_POST['file'] ?? '');
        if (!$file) {
            echo json_encode(['ok' => false, 'error' => 'Keine Datei angegeben']);
            return true;
        }
        $enablePath = NGINX_SITES_DIR . "/$file";
        $availPath = NGINX_SITES_AVAILABLE . "/$file";
        $errors = [];
        if (file_exists($enablePath)) {
            if (!@unlink($enablePath)) {
                shell_exec('sudo rm -f ' . escapeshellarg($enablePath) . ' 2>&1');
                if (file_exists($enablePath)) $errors[] = "sites-enabled/$file";
            }
        }
        if (file_exists($availPath)) {
            if (!@unlink($availPath)) {
                shell_exec('sudo rm -f ' . escapeshellarg($availPath) . ' 2>&1');
                if (file_exists($availPath)) $errors[] = "sites-available/$file";
            }
        }
        shell_exec('sudo systemctl reload nginx 2>&1');
        if ($errors) {
            echo json_encode(['ok' => false, 'error' => 'Konnte nicht löschen: ' . implode(', ', $errors)]);
        } else {
            echo json_encode(['ok' => true]);
        }
        return true;
    }

    // POST: SSL-Zertifikat erneuern via Certbot
    if ($action === 'nginx-renew' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $domain = trim($_POST['domain'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9.*-]+\.[a-zA-Z]{2,}$/', $domain)) {
            echo json_encode(['ok' => false, 'error' => 'Ungültiger Domain-Name']);
            return true;
        }
        $out = shell_exec("sudo certbot renew --cert-name " . escapeshellarg($domain) . " --force-renewal 2>&1") ?? '';
        $success = str_contains($out, 'Successfully') || str_contains($out, 'renewed') || str_contains($out, 'Congratulations');
        if (!$success) {
            // Try with certbot --nginx as fallback
            $out = shell_exec("sudo certbot --nginx -d " . escapeshellarg($domain) . " --non-interactive --agree-tos --register-unsafely-without-email --force-renewal 2>&1") ?? '';
            $success = str_contains($out, 'Successfully') || str_contains($out, 'renewed') || str_contains($out, 'Congratulations');
        }
        echo json_encode(['ok' => $success, 'output' => trim($out)]);
        return true;
    }

    // GET: SSL Health Check — DNS, Zertifikat-Match, Ablauf, IPv4/IPv6
    if ($action === 'ssl-health') {
        $dir = defined('NGINX_SITES_DIR') ? NGINX_SITES_DIR : '/etc/nginx/sites-enabled';
        $serverIps = [];
        // Collect all IPs of this server
        $ipRaw = shell_exec("hostname -I 2>/dev/null") ?? '';
        foreach (preg_split('/\s+/', trim($ipRaw)) as $ip) { if ($ip) $serverIps[] = $ip; }
        $ip6Raw = shell_exec("ip -6 addr show scope global 2>/dev/null | grep -oP 'inet6 \\K[^/]+'") ?? '';
        foreach (preg_split('/\s+/', trim($ip6Raw)) as $ip) { if ($ip) $serverIps[] = $ip; }

        $results = [];
        if (is_dir($dir)) {
            foreach (array_diff(scandir($dir), ['.', '..', 'default', 'server-admin']) as $file) {
                $content = file_get_contents("$dir/$file") ?: '';
                preg_match_all('/server_name\s+([^;]+);/', $content, $sn);
                $domains = [];
                foreach ($sn[1] ?? [] as $d) {
                    foreach (preg_split('/\s+/', trim($d)) as $dd) {
                        if ($dd && $dd !== '_') $domains[] = $dd;
                    }
                }
                $domains = array_unique($domains);
                $ssl = (bool)preg_match('/listen\s+443\s+ssl/', $content);
                if (!$ssl || empty($domains)) continue;

                // Detect config issues
                $issues = [];
                $hasIpv6only = (bool)preg_match('/ipv6only=on/', $content);
                if ($hasIpv6only) $issues[] = 'ipv6only_on';

                // Check cert SANs
                $mainDomain = reset($domains);
                $certSans = [];
                $certExpiry = null;
                $certDaysLeft = null;
                $certRaw = shell_exec("echo | openssl s_client -servername " . escapeshellarg($mainDomain) . " -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -enddate -ext subjectAltName 2>/dev/null") ?? '';
                if (preg_match('/notAfter=(.+)/', $certRaw, $em)) {
                    $ts = strtotime(trim($em[1]));
                    if ($ts) { $certExpiry = date('Y-m-d', $ts); $certDaysLeft = (int)round(($ts - time()) / 86400); }
                }
                if (preg_match_all('/DNS:([^\s,]+)/', $certRaw, $sm)) $certSans = $sm[1];

                // Check each domain
                foreach ($domains as $domain) {
                    // Skip wildcards for DNS check (can't resolve *.domain)
                    $isWildcard = str_starts_with($domain, '*.');
                    $checkDomain = $isWildcard ? substr($domain, 2) : $domain;

                    // DNS A record
                    $dnsA = trim(shell_exec("dig +short A " . escapeshellarg($checkDomain) . " 2>/dev/null") ?? '');
                    $dnsAIps = array_filter(explode("\n", $dnsA));
                    $dnsAMatch = false;
                    foreach ($dnsAIps as $ip) { if (in_array(trim($ip), $serverIps)) { $dnsAMatch = true; break; } }

                    // DNS AAAA record
                    $dnsAAAA = trim(shell_exec("dig +short AAAA " . escapeshellarg($checkDomain) . " 2>/dev/null") ?? '');
                    $dnsAAAAIps = array_filter(explode("\n", $dnsAAAA));
                    $dnsAAAAMatch = false;
                    $hasAAAA = !empty($dnsAAAAIps);
                    foreach ($dnsAAAAIps as $ip) { if (in_array(trim($ip), $serverIps)) { $dnsAAAAMatch = true; break; } }

                    // Cert match
                    $certMatch = false;
                    foreach ($certSans as $san) {
                        if ($san === $domain) { $certMatch = true; break; }
                        if (str_starts_with($san, '*.') && str_ends_with($domain, substr($san, 1))) { $certMatch = true; break; }
                        if ($isWildcard && $san === $domain) { $certMatch = true; break; }
                    }

                    // IPv4 vs IPv6 cert consistency
                    $v4v6Match = true;
                    if ($hasAAAA && !$isWildcard) {
                        $v4Cert = trim(shell_exec("echo | openssl s_client -servername " . escapeshellarg($domain) . " -connect " . escapeshellarg($dnsAIps[0] ?? '127.0.0.1') . ":443 2>/dev/null | openssl x509 -noout -subject 2>/dev/null") ?? '');
                        foreach ($dnsAAAAIps as $v6) {
                            $v6 = trim($v6);
                            $v6Cert = trim(shell_exec("echo | openssl s_client -servername " . escapeshellarg($domain) . " -connect " . escapeshellarg("[$v6]") . ":443 2>/dev/null | openssl x509 -noout -subject 2>/dev/null") ?? '');
                            if ($v6Cert && $v4Cert && $v6Cert !== $v4Cert) { $v4v6Match = false; break; }
                        }
                    }

                    $results[] = [
                        'domain' => $domain,
                        'file' => $file,
                        'dns_a' => $dnsAMatch,
                        'dns_a_ip' => $dnsAIps[0] ?? '',
                        'dns_aaaa' => $dnsAAAAMatch,
                        'dns_aaaa_ip' => $dnsAAAAIps[0] ?? '',
                        'has_aaaa' => $hasAAAA,
                        'ssl_valid' => $certDaysLeft !== null && $certDaysLeft > 0,
                        'ssl_expiry' => $certExpiry,
                        'ssl_days' => $certDaysLeft,
                        'cert_match' => $certMatch,
                        'v4v6_match' => $v4v6Match,
                        'issues' => $issues,
                    ];
                }
            }
        }
        echo json_encode(['ok' => true, 'results' => $results, 'server_ips' => $serverIps]);
        return true;
    }

    // POST: ipv6only=on aus Nginx-Config entfernen
    if ($action === 'ssl-fix-ipv6only' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $file = $_POST['file'] ?? '';
        if (!preg_match('/^[\w.\-]+$/', $file)) { echo json_encode(['error' => 'Invalid file']); return true; }
        $availDir = defined('NGINX_SITES_AVAILABLE') ? NGINX_SITES_AVAILABLE : '/etc/nginx/sites-available';
        $path = "$availDir/$file";
        if (!file_exists($path)) { echo json_encode(['error' => 'File not found']); return true; }
        $content = file_get_contents($path);
        $content = str_replace('ipv6only=on', '', $content);
        // Clean up double spaces
        $content = preg_replace('/listen\s+\[::]:(\d+)\s+ssl\s+;/', 'listen [::]:$1 ssl;', $content);
        file_put_contents($path, $content);
        $test = shell_exec('sudo nginx -t 2>&1');
        if (str_contains($test, 'successful')) {
            shell_exec('sudo systemctl reload nginx 2>&1');
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['error' => 'Nginx config test failed: ' . $test]);
        }
        return true;
    }

    // POST: Nginx Config testen und neu laden
    if ($action === 'nginx-reload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $test = shell_exec('sudo nginx -t 2>&1');
        if (strpos($test, 'successful') === false) {
            echo json_encode(['ok' => false, 'error' => $test]);
            return true;
        }
        shell_exec('sudo systemctl reload nginx 2>&1');
        echo json_encode(['ok' => true]);
        return true;
    }

    return false;
}
