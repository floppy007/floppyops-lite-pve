<?php
/**
 * FloppyOps Lite — API: Firewall Templates
 *
 * VM/CT Firewall Templates und Regelverwaltung — Vordefinierte Regelsaetze
 * fuer Server-Rollen (Mailserver, Webserver, Docker, etc.), Custom Templates
 * erstellen/bearbeiten/loeschen, Templates auf VMs/CTs anwenden,
 * VM-Firewall ein-/ausschalten, Regeln hinzufuegen/loeschen.
 *
 * Endpoints: fw-templates, fw-template-save, fw-template-delete, fw-vm-list, fw-vm-rules, fw-vm-apply-template, fw-vm-toggle, fw-vm-delete-rule, fw-vm-add-rule
 */

    function getBuiltinFwTemplates(): array {
        return [
            ['id' => 'mailserver', 'name' => 'Mailserver (Mailcow)', 'icon' => 'mail', 'description' => 'SMTP, IMAP, POP3, HTTP/S, ManageSieve', 'rules' => [
                ['action' => 'ACCEPT', 'type' => 'in', 'macro' => 'Ping', 'comment' => 'ICMP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '22', 'proto' => 'tcp', 'comment' => 'SSH'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '25', 'proto' => 'tcp', 'comment' => 'SMTP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '80', 'proto' => 'tcp', 'comment' => 'HTTP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '110', 'proto' => 'tcp', 'comment' => 'POP3'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '143', 'proto' => 'tcp', 'comment' => 'IMAP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '443', 'proto' => 'tcp', 'comment' => 'HTTPS'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '465', 'proto' => 'tcp', 'comment' => 'SMTPS'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '587', 'proto' => 'tcp', 'comment' => 'Submission'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '993', 'proto' => 'tcp', 'comment' => 'IMAPS'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '995', 'proto' => 'tcp', 'comment' => 'POP3S'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '4190', 'proto' => 'tcp', 'comment' => 'ManageSieve'],
            ]],
            ['id' => 'webserver', 'name' => 'Webserver', 'icon' => 'globe', 'description' => 'HTTP, HTTPS, SSH', 'rules' => [
                ['action' => 'ACCEPT', 'type' => 'in', 'macro' => 'Ping', 'comment' => 'ICMP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '22', 'proto' => 'tcp', 'comment' => 'SSH'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '80', 'proto' => 'tcp', 'comment' => 'HTTP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '443', 'proto' => 'tcp', 'comment' => 'HTTPS'],
            ]],
            ['id' => 'database', 'name' => 'Database Server', 'icon' => 'database', 'description' => 'MySQL/MariaDB (nur intern)', 'rules' => [
                ['action' => 'ACCEPT', 'type' => 'in', 'macro' => 'Ping', 'comment' => 'ICMP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '22', 'proto' => 'tcp', 'comment' => 'SSH'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '3306', 'proto' => 'tcp', 'source' => '10.0.0.0/8', 'comment' => 'MySQL (intern)'],
            ]],
            ['id' => 'proxmox', 'name' => 'Proxmox Host', 'icon' => 'server', 'description' => 'PVE WebUI, SSH, SPICE', 'rules' => [
                ['action' => 'ACCEPT', 'type' => 'in', 'macro' => 'Ping', 'comment' => 'ICMP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '22', 'proto' => 'tcp', 'comment' => 'SSH'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '8006', 'proto' => 'tcp', 'comment' => 'PVE WebUI'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '3128', 'proto' => 'tcp', 'comment' => 'SPICE Proxy'],
            ]],
            ['id' => 'docker', 'name' => 'Docker Host', 'icon' => 'box', 'description' => 'HTTP, HTTPS, SSH', 'rules' => [
                ['action' => 'ACCEPT', 'type' => 'in', 'macro' => 'Ping', 'comment' => 'ICMP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '22', 'proto' => 'tcp', 'comment' => 'SSH'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '80', 'proto' => 'tcp', 'comment' => 'HTTP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '443', 'proto' => 'tcp', 'comment' => 'HTTPS'],
            ]],
            ['id' => 'dns', 'name' => 'DNS Server', 'icon' => 'zap', 'description' => 'DNS (TCP+UDP), SSH', 'rules' => [
                ['action' => 'ACCEPT', 'type' => 'in', 'macro' => 'Ping', 'comment' => 'ICMP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '22', 'proto' => 'tcp', 'comment' => 'SSH'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '53', 'proto' => 'tcp', 'comment' => 'DNS (TCP)'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '53', 'proto' => 'udp', 'comment' => 'DNS (UDP)'],
            ]],
            ['id' => 'vpn-wg', 'name' => 'VPN (WireGuard)', 'icon' => 'shield', 'description' => 'WireGuard UDP, SSH', 'rules' => [
                ['action' => 'ACCEPT', 'type' => 'in', 'macro' => 'Ping', 'comment' => 'ICMP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '22', 'proto' => 'tcp', 'comment' => 'SSH'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '51820', 'proto' => 'udp', 'comment' => 'WireGuard'],
            ]],
            ['id' => 'virtualmin-web', 'name' => 'Virtualmin (Web)', 'icon' => 'globe', 'description' => 'HTTP/S, FTP, Webmin, DNS', 'rules' => [
                ['action' => 'ACCEPT', 'type' => 'in', 'macro' => 'Ping', 'comment' => 'ICMP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '22', 'proto' => 'tcp', 'comment' => 'SSH'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '80', 'proto' => 'tcp', 'comment' => 'HTTP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '443', 'proto' => 'tcp', 'comment' => 'HTTPS'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '20:21', 'proto' => 'tcp', 'comment' => 'FTP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '53', 'proto' => 'tcp', 'comment' => 'DNS (TCP)'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '53', 'proto' => 'udp', 'comment' => 'DNS (UDP)'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '10000', 'proto' => 'tcp', 'comment' => 'Webmin'],
            ]],
            ['id' => 'virtualmin', 'name' => 'Virtualmin (Web+Mail)', 'icon' => 'globe', 'description' => 'HTTP/S, SMTP, IMAP, POP3, FTP, Webmin, DNS', 'rules' => [
                ['action' => 'ACCEPT', 'type' => 'in', 'macro' => 'Ping', 'comment' => 'ICMP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '22', 'proto' => 'tcp', 'comment' => 'SSH'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '80', 'proto' => 'tcp', 'comment' => 'HTTP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '443', 'proto' => 'tcp', 'comment' => 'HTTPS'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '25', 'proto' => 'tcp', 'comment' => 'SMTP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '110', 'proto' => 'tcp', 'comment' => 'POP3'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '143', 'proto' => 'tcp', 'comment' => 'IMAP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '465', 'proto' => 'tcp', 'comment' => 'SMTPS'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '587', 'proto' => 'tcp', 'comment' => 'Submission'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '993', 'proto' => 'tcp', 'comment' => 'IMAPS'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '995', 'proto' => 'tcp', 'comment' => 'POP3S'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '20:21', 'proto' => 'tcp', 'comment' => 'FTP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '53', 'proto' => 'tcp', 'comment' => 'DNS (TCP)'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '53', 'proto' => 'udp', 'comment' => 'DNS (UDP)'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '10000', 'proto' => 'tcp', 'comment' => 'Webmin'],
            ]],
            ['id' => 'nginx-proxy', 'name' => 'Nginx Reverse Proxy', 'icon' => 'globe', 'description' => 'HTTP/S, SSH', 'rules' => [
                ['action' => 'ACCEPT', 'type' => 'in', 'macro' => 'Ping', 'comment' => 'ICMP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '22', 'proto' => 'tcp', 'comment' => 'SSH'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '80', 'proto' => 'tcp', 'comment' => 'HTTP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '443', 'proto' => 'tcp', 'comment' => 'HTTPS'],
            ]],
            ['id' => 'postgresql', 'name' => 'PostgreSQL', 'icon' => 'database', 'description' => 'PostgreSQL (nur intern)', 'rules' => [
                ['action' => 'ACCEPT', 'type' => 'in', 'macro' => 'Ping', 'comment' => 'ICMP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '22', 'proto' => 'tcp', 'comment' => 'SSH'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '5432', 'proto' => 'tcp', 'source' => '10.0.0.0/8', 'comment' => 'PostgreSQL (intern)'],
            ]],
            ['id' => 'redis', 'name' => 'Redis / Valkey', 'icon' => 'database', 'description' => 'Redis (nur intern)', 'rules' => [
                ['action' => 'ACCEPT', 'type' => 'in', 'macro' => 'Ping', 'comment' => 'ICMP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '22', 'proto' => 'tcp', 'comment' => 'SSH'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '6379', 'proto' => 'tcp', 'source' => '10.0.0.0/8', 'comment' => 'Redis (intern)'],
            ]],
            ['id' => 'elasticsearch', 'name' => 'Elasticsearch', 'icon' => 'database', 'description' => 'ES HTTP + Transport (intern)', 'rules' => [
                ['action' => 'ACCEPT', 'type' => 'in', 'macro' => 'Ping', 'comment' => 'ICMP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '22', 'proto' => 'tcp', 'comment' => 'SSH'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '9200', 'proto' => 'tcp', 'source' => '10.0.0.0/8', 'comment' => 'ES HTTP (intern)'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '9300', 'proto' => 'tcp', 'source' => '10.0.0.0/8', 'comment' => 'ES Transport (intern)'],
            ]],
            ['id' => 'minecraft', 'name' => 'Minecraft Server', 'icon' => 'box', 'description' => 'Minecraft Java + Bedrock, RCON', 'rules' => [
                ['action' => 'ACCEPT', 'type' => 'in', 'macro' => 'Ping', 'comment' => 'ICMP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '22', 'proto' => 'tcp', 'comment' => 'SSH'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '25565', 'proto' => 'tcp', 'comment' => 'Minecraft Java'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '19132', 'proto' => 'udp', 'comment' => 'Minecraft Bedrock'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '25575', 'proto' => 'tcp', 'source' => '10.0.0.0/8', 'comment' => 'RCON (intern)'],
            ]],
            ['id' => 'teamspeak', 'name' => 'TeamSpeak', 'icon' => 'zap', 'description' => 'Voice, FileTransfer, Query', 'rules' => [
                ['action' => 'ACCEPT', 'type' => 'in', 'macro' => 'Ping', 'comment' => 'ICMP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '22', 'proto' => 'tcp', 'comment' => 'SSH'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '9987', 'proto' => 'udp', 'comment' => 'TS3 Voice'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '30033', 'proto' => 'tcp', 'comment' => 'TS3 FileTransfer'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '10011', 'proto' => 'tcp', 'source' => '10.0.0.0/8', 'comment' => 'TS3 Query (intern)'],
            ]],
            ['id' => 'nextcloud', 'name' => 'Nextcloud', 'icon' => 'box', 'description' => 'HTTP/S, SSH', 'rules' => [
                ['action' => 'ACCEPT', 'type' => 'in', 'macro' => 'Ping', 'comment' => 'ICMP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '22', 'proto' => 'tcp', 'comment' => 'SSH'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '80', 'proto' => 'tcp', 'comment' => 'HTTP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '443', 'proto' => 'tcp', 'comment' => 'HTTPS'],
            ]],
            ['id' => 'gitea', 'name' => 'Gitea / GitLab', 'icon' => 'box', 'description' => 'HTTP/S, Git SSH', 'rules' => [
                ['action' => 'ACCEPT', 'type' => 'in', 'macro' => 'Ping', 'comment' => 'ICMP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '22', 'proto' => 'tcp', 'comment' => 'SSH'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '80', 'proto' => 'tcp', 'comment' => 'HTTP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '443', 'proto' => 'tcp', 'comment' => 'HTTPS'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '3022', 'proto' => 'tcp', 'comment' => 'Git SSH'],
            ]],
            ['id' => 'monitoring', 'name' => 'Monitoring (Grafana/Zabbix)', 'icon' => 'zap', 'description' => 'Grafana, Zabbix Agent+Server', 'rules' => [
                ['action' => 'ACCEPT', 'type' => 'in', 'macro' => 'Ping', 'comment' => 'ICMP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '22', 'proto' => 'tcp', 'comment' => 'SSH'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '80', 'proto' => 'tcp', 'comment' => 'HTTP'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '443', 'proto' => 'tcp', 'comment' => 'HTTPS'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '3000', 'proto' => 'tcp', 'comment' => 'Grafana'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '10050', 'proto' => 'tcp', 'source' => '10.0.0.0/8', 'comment' => 'Zabbix Agent (intern)'],
                ['action' => 'ACCEPT', 'type' => 'in', 'dport' => '10051', 'proto' => 'tcp', 'source' => '10.0.0.0/8', 'comment' => 'Zabbix Server (intern)'],
            ]],
        ];
    }

    function loadFwTemplateData(): array {
        $file = __DIR__ . '/../data/firewall-templates.json';
        if (file_exists($file)) return json_decode(file_get_contents($file), true) ?: [];
        return [];
    }

    function loadFwTemplates(): array {
        $data = loadFwTemplateData();
        return ['builtin' => getBuiltinFwTemplates(), 'custom' => $data['custom'] ?? [], 'assignments' => $data['assignments'] ?? []];
    }

    function saveFwTemplateData(array $data): void {
        $dir = __DIR__ . '/../data';
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        file_put_contents($dir . '/firewall-templates.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    function saveFwTemplates(array $custom): void {
        $data = loadFwTemplateData();
        $data['custom'] = $custom;
        saveFwTemplateData($data);
    }

    function saveFwAssignment(int $vmid, string $type, string $templateId, string $templateName): void {
        $data = loadFwTemplateData();
        $data['assignments'] = $data['assignments'] ?? [];
        $data['assignments']["$type:$vmid"] = ['template_id' => $templateId, 'template_name' => $templateName, 'applied_at' => date('Y-m-d H:i:s')];
        saveFwTemplateData($data);
    }

    function findFwTemplate(string $id): ?array {
        $all = loadFwTemplates();
        foreach ($all['builtin'] as $t) { if ($t['id'] === $id) return $t; }
        foreach ($all['custom'] as $t) { if ($t['id'] === $id) return $t; }
        return null;
    }

/**
 * VM/CT Firewall Templates: Vordefinierte Regelsaetze fuer Server-Rollen
 * (Mailserver, Webserver, etc.), Custom Templates, VM-Firewall verwalten.
 *
 * Endpoints: fw-templates, fw-template-save, fw-template-delete, fw-vm-list, fw-vm-rules, fw-vm-apply-template, fw-vm-toggle, fw-vm-delete-rule, fw-vm-add-rule
 *
 * @param string $action Der API-Action-Name
 * @return bool true wenn behandelt
 */
function handleFirewallAPI(string $action): bool {
    // GET: Alle Firewall-Templates (Builtin + Custom + Assignments)
    if ($action === 'fw-templates') {
        echo json_encode(['ok' => true, ...loadFwTemplates()]);
        return true;
    }

    // POST: Custom Template erstellen oder bearbeiten
    if ($action === 'fw-template-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $name = trim(substr($data['name'] ?? '', 0, 100));
        $desc = trim(substr($data['description'] ?? '', 0, 255));
        $icon = preg_replace('/[^a-z\-]/', '', $data['icon'] ?? 'shield');
        $rules = $data['rules'] ?? [];
        if (is_string($rules)) $rules = json_decode($rules, true) ?: [];
        $editId = $data['id'] ?? '';

        if ($name === '' || empty($rules)) { echo json_encode(['error' => 'Name and rules required']); return true; }

        // Validate rules
        $cleanRules = [];
        foreach ($rules as $r) {
            $rule = ['action' => in_array($r['action'] ?? '', ['ACCEPT','DROP','REJECT']) ? $r['action'] : 'ACCEPT', 'type' => ($r['type'] ?? 'in') === 'out' ? 'out' : 'in'];
            if (!empty($r['macro'])) { $rule['macro'] = preg_replace('/[^a-zA-Z]/', '', $r['macro']); }
            else {
                if (!empty($r['dport']) && preg_match('/^\d+([:\-]\d+)?$/', $r['dport'])) $rule['dport'] = $r['dport'];
                $rule['proto'] = in_array($r['proto'] ?? '', ['tcp','udp','icmp']) ? $r['proto'] : 'tcp';
            }
            if (!empty($r['source']) && preg_match('/^[\d\.\/]+$/', $r['source'])) $rule['source'] = $r['source'];
            $rule['comment'] = substr(preg_replace('/[^\w\s\-\.\/():,]/', '', $r['comment'] ?? ''), 0, 128);
            $cleanRules[] = $rule;
        }

        $all = loadFwTemplates();
        $custom = $all['custom'];
        if ($editId) {
            $found = false;
            foreach ($custom as &$t) {
                if ($t['id'] === $editId) { $t['name'] = $name; $t['description'] = $desc; $t['icon'] = $icon; $t['rules'] = $cleanRules; $found = true; break; }
            }
            unset($t);
            if (!$found) { echo json_encode(['error' => 'Template not found']); return true; }
        } else {
            $id = preg_replace('/[^a-z0-9\-]/', '', strtolower(str_replace(' ', '-', $name))) . '-' . substr(md5(time()), 0, 4);
            $custom[] = ['id' => $id, 'name' => $name, 'description' => $desc, 'icon' => $icon, 'rules' => $cleanRules];
        }
        saveFwTemplates($custom);
        echo json_encode(['ok' => true]);
        return true;
    }

    // POST: Custom Template loeschen
    if ($action === 'fw-template-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $id = $_POST['id'] ?? '';
        $all = loadFwTemplates();
        $custom = array_values(array_filter($all['custom'], fn($t) => $t['id'] !== $id));
        saveFwTemplates($custom);
        echo json_encode(['ok' => true]);
        return true;
    }

    // GET: Alle VMs/CTs mit Firewall-Status und Template-Zuweisung
    if ($action === 'fw-vm-list') {
        $fwCache = '/tmp/floppyops-lite-fw-vmlist.json';
        if (file_exists($fwCache) && (time() - filemtime($fwCache)) < 30) {
            echo file_get_contents($fwCache);
            return true;
        }

        $node = trim(shell_exec('hostname 2>/dev/null') ?? '');
        $ctRaw = shell_exec("sudo pvesh get /nodes/$node/lxc --output-format json 2>/dev/null") ?? '[]';
        $vmRaw = shell_exec("sudo pvesh get /nodes/$node/qemu --output-format json 2>/dev/null") ?? '[]';
        $guests = [];
        foreach (json_decode($ctRaw, true) ?: [] as $ct) {
            $guests[] = ['vmid' => (int)$ct['vmid'], 'name' => $ct['name'] ?? '', 'type' => 'lxc', 'status' => $ct['status'] ?? 'unknown'];
        }
        foreach (json_decode($vmRaw, true) ?: [] as $vm) {
            $guests[] = ['vmid' => (int)$vm['vmid'], 'name' => $vm['name'] ?? '', 'type' => 'qemu', 'status' => $vm['status'] ?? 'unknown'];
        }
        usort($guests, fn($a, $b) => $a['vmid'] - $b['vmid']);

        // Fetch firewall options + IPs per guest
        // Get server's public IPs for comparison
        $serverPubIps = [];
        $pubRaw = shell_exec("ip -4 addr show scope global 2>/dev/null | grep -oP 'inet \\K[\\d.]+'") ?? '';
        foreach (array_filter(explode("\n", trim($pubRaw))) as $ip) $serverPubIps[] = trim($ip);

        foreach ($guests as &$g) {
            $prefix = $g['type'] === 'qemu' ? 'qemu' : 'lxc';
            $opts = json_decode(shell_exec("sudo pvesh get /nodes/" . escapeshellarg($node) . "/$prefix/{$g['vmid']}/firewall/options --output-format json 2>/dev/null") ?? '{}', true) ?: [];
            $g['fw_enabled'] = !empty($opts['enable']);
            $g['fw_policy_in'] = $opts['policy_in'] ?? 'ACCEPT';
            $rules = json_decode(shell_exec("sudo pvesh get /nodes/" . escapeshellarg($node) . "/$prefix/{$g['vmid']}/firewall/rules --output-format json 2>/dev/null") ?? '[]', true) ?: [];
            $g['rule_count'] = count($rules);

            // Get IPs from CT/VM config via pvesh
            $conf = shell_exec("sudo pvesh get /nodes/" . escapeshellarg($node) . "/$prefix/{$g['vmid']}/config --output-format json 2>/dev/null") ?? '{}';
            $confData = json_decode($conf, true) ?: [];
            $g['ips'] = [];
            $g['is_public'] = false;
            for ($ni = 0; $ni < 8; $ni++) {
                $netKey = "net$ni";
                if (empty($confData[$netKey])) continue;
                if (preg_match('/ip=([^,\/\s]+)/', $confData[$netKey], $ipM)) {
                    $ip = $ipM[1];
                    if ($ip === 'dhcp' || $ip === 'manual') continue;
                    $g['ips'][] = $ip;
                    if (!preg_match('/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', $ip)) {
                        $g['is_public'] = true;
                    }
                }
            }
        }
        unset($g);
        $assignments = (loadFwTemplateData())['assignments'] ?? [];
        foreach ($guests as &$g) {
            $key = $g['type'] . ':' . $g['vmid'];
            $g['template'] = $assignments[$key] ?? null;
        }
        unset($g);
        $json = json_encode(['ok' => true, 'guests' => $guests, 'node' => $node]);
        @file_put_contents($fwCache, $json);
        echo $json;
        return true;
    }

    // GET: Firewall-Regeln einer einzelnen VM/CT
    if ($action === 'fw-vm-rules') {
        $node = trim(shell_exec('hostname 2>/dev/null') ?? '');
        $vmid = (int)($_GET['vmid'] ?? 0);
        $type = ($_GET['type'] ?? 'lxc') === 'qemu' ? 'qemu' : 'lxc';
        if ($vmid < 1) { echo json_encode(['error' => 'Invalid VMID']); return true; }
        $rules = json_decode(shell_exec("sudo pvesh get /nodes/" . escapeshellarg($node) . "/$type/$vmid/firewall/rules --output-format json 2>/dev/null") ?? '[]', true) ?: [];
        $opts = json_decode(shell_exec("sudo pvesh get /nodes/" . escapeshellarg($node) . "/$type/$vmid/firewall/options --output-format json 2>/dev/null") ?? '{}', true) ?: [];
        echo json_encode(['ok' => true, 'rules' => $rules, 'options' => $opts, 'vmid' => $vmid, 'type' => $type]);
        return true;
    }

    // POST: Template auf VM/CT anwenden (Duplikate werden uebersprungen)
    if ($action === 'fw-vm-apply-template' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $node = trim(shell_exec('hostname 2>/dev/null') ?? '');
        $vmid = (int)($_POST['vmid'] ?? 0);
        $type = ($_POST['type'] ?? 'lxc') === 'qemu' ? 'qemu' : 'lxc';
        $templateId = $_POST['template_id'] ?? '';
        $clearExisting = !empty($_POST['clear_existing']);

        if ($vmid < 1 || !$templateId) { echo json_encode(['error' => 'VMID and template required']); return true; }
        $tpl = findFwTemplate($templateId);
        if (!$tpl) { echo json_encode(['error' => 'Template not found']); return true; }

        $basePath = "/nodes/" . escapeshellarg($node) . "/$type/$vmid/firewall";

        // Clear existing rules (reverse order to avoid position shifts)
        if ($clearExisting) {
            $existing = json_decode(shell_exec("sudo pvesh get $basePath/rules --output-format json 2>/dev/null") ?? '[]', true) ?: [];
            $positions = array_column($existing, 'pos');
            rsort($positions);
            foreach ($positions as $pos) {
                shell_exec("sudo pvesh delete $basePath/rules/$pos 2>&1");
            }
        }

        // Enable firewall + set policy DROP
        shell_exec("sudo pvesh set $basePath/options --enable 1 --policy_in DROP 2>&1");

        // Use overridden rules if provided, otherwise template defaults
        $rulesOverride = $_POST['rules_override'] ?? '';
        $applyRules = $tpl['rules'];
        if ($rulesOverride) {
            $parsed = json_decode($rulesOverride, true);
            if (is_array($parsed) && !empty($parsed)) $applyRules = $parsed;
        }

        // Fetch existing rules to avoid duplicates
        $existing = json_decode(shell_exec("sudo pvesh get $basePath/rules --output-format json 2>/dev/null") ?? '[]', true) ?: [];
        $existingKeys = [];
        foreach ($existing as $er) {
            $key = ($er['action'] ?? '') . ':' . ($er['type'] ?? '') . ':' . ($er['dport'] ?? $er['macro'] ?? '') . ':' . ($er['proto'] ?? $er['macro'] ?? '') . ':' . ($er['source'] ?? '');
            $existingKeys[$key] = true;
        }

        // Apply rules (skip duplicates)
        $added = 0;
        $skipped = 0;
        foreach ($applyRules as $rule) {
            $key = ($rule['action'] ?? '') . ':' . ($rule['type'] ?? '') . ':' . ($rule['dport'] ?? $rule['macro'] ?? '') . ':' . ($rule['proto'] ?? $rule['macro'] ?? '') . ':' . ($rule['source'] ?? '');
            if (isset($existingKeys[$key])) { $skipped++; continue; }

            $cmd = "sudo pvesh create $basePath/rules --action " . escapeshellarg($rule['action']) . " --type " . escapeshellarg($rule['type']) . " --enable 1";
            if (!empty($rule['macro'])) {
                $cmd .= " --macro " . escapeshellarg($rule['macro']);
            } else {
                if (!empty($rule['dport'])) $cmd .= " --dport " . escapeshellarg($rule['dport']);
                if (!empty($rule['proto'])) $cmd .= " --proto " . escapeshellarg($rule['proto']);
            }
            if (!empty($rule['source'])) $cmd .= " --source " . escapeshellarg($rule['source']);
            if (!empty($rule['comment'])) $cmd .= " --comment " . escapeshellarg($rule['comment']);
            shell_exec("$cmd 2>&1");
            $added++;
        }
        saveFwAssignment($vmid, $type, $templateId, $tpl['name']);
        echo json_encode(['ok' => true, 'added' => $added, 'template' => $tpl['name']]);
        return true;
    }

    // POST: Firewall einer VM/CT ein-/ausschalten
    if ($action === 'fw-vm-toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $node = trim(shell_exec('hostname 2>/dev/null') ?? '');
        $vmid = (int)($_POST['vmid'] ?? 0);
        $type = ($_POST['type'] ?? 'lxc') === 'qemu' ? 'qemu' : 'lxc';
        $enable = (int)($_POST['enable'] ?? 0);
        if ($vmid < 1) { echo json_encode(['error' => 'Invalid VMID']); return true; }
        $cmd = "sudo pvesh set /nodes/" . escapeshellarg($node) . "/$type/$vmid/firewall/options --enable $enable";
        if ($enable) $cmd .= " --policy_in DROP";
        shell_exec("$cmd 2>&1");
        echo json_encode(['ok' => true]);
        return true;
    }

    // POST: Firewall-Regel einer VM/CT loeschen
    if ($action === 'fw-vm-delete-rule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $node = trim(shell_exec('hostname 2>/dev/null') ?? '');
        $vmid = (int)($_POST['vmid'] ?? 0);
        $type = ($_POST['type'] ?? 'lxc') === 'qemu' ? 'qemu' : 'lxc';
        $pos = (int)($_POST['pos'] ?? -1);
        if ($vmid < 1 || $pos < 0) { echo json_encode(['error' => 'Invalid params']); return true; }
        shell_exec("sudo pvesh delete /nodes/" . escapeshellarg($node) . "/$type/$vmid/firewall/rules/$pos 2>&1");
        echo json_encode(['ok' => true]);
        return true;
    }

    // POST: Neue Firewall-Regel zu VM/CT hinzufuegen
    if ($action === 'fw-vm-add-rule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $node = trim(shell_exec('hostname 2>/dev/null') ?? '');
        $vmid = (int)($_POST['vmid'] ?? 0);
        $type = ($_POST['type'] ?? 'lxc') === 'qemu' ? 'qemu' : 'lxc';
        $ruleAction = $_POST['rule_action'] ?? 'ACCEPT';
        $ruleType = $_POST['rule_type'] ?? 'in';
        $dport = $_POST['dport'] ?? '';
        $proto = $_POST['proto'] ?? 'tcp';
        $source = $_POST['source'] ?? '';
        $macro = $_POST['macro'] ?? '';
        $comment = substr(preg_replace('/[^\w\s\-\.\/():,]/', '', $_POST['comment'] ?? ''), 0, 128);

        if ($vmid < 1) { echo json_encode(['error' => 'Invalid VMID']); return true; }
        if (!in_array($ruleAction, ['ACCEPT','DROP','REJECT'])) { echo json_encode(['error' => 'Invalid action']); return true; }
        if (!in_array($ruleType, ['in','out'])) { echo json_encode(['error' => 'Invalid type']); return true; }

        $basePath = "/nodes/" . escapeshellarg($node) . "/$type/$vmid/firewall/rules";
        $cmd = "sudo pvesh create $basePath --action " . escapeshellarg($ruleAction) . " --type " . escapeshellarg($ruleType) . " --enable 1";
        if ($macro !== '') { $cmd .= " --macro " . escapeshellarg($macro); }
        else {
            if ($dport !== '' && preg_match('/^\d+([:\-]\d+)?$/', $dport)) $cmd .= " --dport " . escapeshellarg($dport);
            if (in_array($proto, ['tcp','udp','icmp'])) $cmd .= " --proto " . escapeshellarg($proto);
        }
        if ($source !== '' && preg_match('/^[\d\.\/]+$/', $source)) $cmd .= " --source " . escapeshellarg($source);
        if ($comment !== '') $cmd .= " --comment " . escapeshellarg($comment);
        shell_exec("$cmd 2>&1");
        echo json_encode(['ok' => true]);
        return true;
    }

    return false;
}
