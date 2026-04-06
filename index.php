<?php
// ╔══════════════════════════════════════════════════════════════════╗
// ║                    FloppyOps Lite — index.php                   ║
// ║  Single-File Server Management Panel for Proxmox VE            ║
// ║                                                                ║
// ║  Structure:                                                    ║
// ║    1. PHP Configuration & Authentication                       ║
// ║    2. API Handler Functions (grouped)                          ║
// ║    3. API Router (Dispatch)                                    ║
// ║    4. HTML Structure + CSS Styling                             ║
// ║    5. JavaScript (Dashboard, VMs, Security, etc.)              ║
// ╚══════════════════════════════════════════════════════════════════╝

define('APP_VERSION', '1.2.1');
require_once __DIR__ . '/config.php';
session_start();
require_once __DIR__ . '/lang.php';

// ╔══════════════════════════════════════════════════════════════════╗
// ║              AUTHENTIFIZIERUNG (PVE / PAM / Auto)               ║
// ╚══════════════════════════════════════════════════════════════════╝
$authMethod = defined('AUTH_METHOD') ? AUTH_METHOD : 'auto'; // auto, pve, pam, local
$loginError = '';

function authenticateUser(string $user, string $pass, string $method): array {
    // PVE API Auth
    if ($method === 'pve' || $method === 'auto') {
        $realm = str_contains($user, '@') ? '' : '@pam';
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query(['username' => $user . $realm, 'password' => $pass]),
                'timeout' => 5,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $result = @file_get_contents('https://127.0.0.1:8006/api2/json/access/ticket', false, $ctx);
        if ($result) {
            $data = json_decode($result, true);
            if (!empty($data['data']['ticket'])) {
                $pveUser = $data['data']['username'] ?? $user;
                return ['ok' => true, 'user' => $pveUser, 'method' => 'pve'];
            }
        }
        if ($method === 'pve') return ['ok' => false, 'error' => 'PVE-Authentifizierung fehlgeschlagen'];
    }

    // Linux PAM Auth (via su)
    if ($method === 'pam' || $method === 'auto') {
        $safeUser = escapeshellarg($user);
        $safePass = escapeshellarg($pass);
        $out = shell_exec("echo $safePass | su - $safeUser -c 'echo AUTH_SUCCESS' 2>/dev/null") ?? '';
        if (str_contains($out, 'AUTH_SUCCESS')) {
            return ['ok' => true, 'user' => $user, 'method' => 'pam'];
        }
        if ($method === 'pam') return ['ok' => false, 'error' => 'Linux-Authentifizierung fehlgeschlagen'];
    }

    return ['ok' => false, 'error' => 'Benutzername oder Passwort falsch'];
}

// Auto-create auth log if missing
$authLog = '/var/log/floppyops-lite-auth.log';
if (!file_exists($authLog)) { @touch($authLog); @chmod($authLog, 0640); }

if (isset($_POST['_login'])) {
    $realm = $_POST['realm'] ?? $authMethod;
    $loginUser = $_POST['user'] ?? '';
    $clientIp = $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $authResult = authenticateUser($loginUser, $_POST['pass'] ?? '', $realm);
    if ($authResult['ok']) {
        $_SESSION['authed'] = true;
        $_SESSION['auth_user'] = $authResult['user'];
        $_SESSION['auth_method'] = $authResult['method'];
        @file_put_contents('/var/log/floppyops-lite-auth.log', date('Y-m-d H:i:s') . " LOGIN OK user={$loginUser} ip={$clientIp}\n", FILE_APPEND);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    sleep(2);
    @file_put_contents('/var/log/floppyops-lite-auth.log', date('Y-m-d H:i:s') . " LOGIN FAILED user={$loginUser} ip={$clientIp}\n", FILE_APPEND);
    $loginError = $authResult['error'] ?? 'Benutzername oder Passwort falsch';
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit; }

if (!isset($_SESSION['authed'])) {
    showLoginPage($loginError);
    exit;
}

function showLoginPage(string $error = ''): void {
    global $lang;
    $appName = APP_NAME;
    $errHtml = $error ? '<div class="login-error">' . htmlspecialchars($error) . '</div>' : '';
    $lblUser = __('login_user');
    $lblPass = __('login_pass');
    $lblBtn = __('login_btn');
    $lblHint = __('login_hint');
    $appVersion = APP_VERSION;
    $langDe = $lang === 'de' ? 'background:var(--accent);color:#fff' : 'color:var(--text3)';
    $langEn = $lang === 'en' ? 'background:var(--accent);color:#fff' : 'color:var(--text3)';
    echo <<<HTML
<!-- ╔══════════════════════════════════════════════════════════════╗ -->
<!-- ║                    HTML + CSS + LAYOUT                        ║ -->
<!-- ╚══════════════════════════════════════════════════════════════╝ -->
<!DOCTYPE html>
<html lang="{$lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$appName} — Login</title>
<style>
@font-face{font-family:'Outfit';src:url('public/fonts/outfit-latin.woff2') format('woff2');font-weight:300 900;font-display:swap}
@font-face{font-family:'JetBrains Mono';src:url('public/fonts/jetbrains-mono-latin.woff2') format('woff2');font-weight:300 700;font-display:swap}
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#050810;--accent:#ff5900;--surface:rgba(17,24,39,.55);--border:rgba(255,255,255,.05);--text:#e8eaed;--text2:#9aa0a6;--text3:#5f6368}
html,body{height:100%}
body{
    font-family:'Outfit',sans-serif;
    background:var(--bg);
    color:var(--text);
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
}
/* Animated mesh background */
body::before{
    content:'';position:fixed;inset:0;
    background:
        radial-gradient(ellipse 120% 80% at 30% 20%, rgba(255,89,0,.06) 0%, transparent 50%),
        radial-gradient(ellipse 80% 60% at 70% 80%, rgba(64,196,255,.04) 0%, transparent 50%),
        radial-gradient(ellipse 50% 50% at 50% 50%, rgba(255,89,0,.03) 0%, transparent 40%);
    animation:meshDrift 12s ease-in-out infinite alternate;
}
@keyframes meshDrift{
    0%{filter:hue-rotate(0deg)}
    100%{filter:hue-rotate(15deg)}
}
/* Grid */
body::after{
    content:'';position:fixed;inset:0;
    background-image:
        linear-gradient(rgba(255,255,255,.015) 1px,transparent 1px),
        linear-gradient(90deg,rgba(255,255,255,.015) 1px,transparent 1px);
    background-size:50px 50px;
    animation:gridPulse 4s ease-in-out infinite;
}
@keyframes gridPulse{0%,100%{opacity:.6}50%{opacity:1}}

.login-wrap{
    position:relative;z-index:1;
    width:100%;max-width:420px;
    padding:0 24px;
}
.login-card{
    background:var(--surface);
    backdrop-filter:blur(24px) saturate(1.4);
    border:1px solid var(--border);
    border-radius:20px;
    padding:48px 40px 40px;
    position:relative;
    overflow:hidden;
    box-shadow:0 32px 80px rgba(0,0,0,.5),0 0 0 1px rgba(255,89,0,.04);
}
.login-card::before{
    content:'';position:absolute;top:0;left:0;right:0;height:2px;
    background:linear-gradient(90deg,transparent 10%,var(--accent) 50%,transparent 90%);
    opacity:.7;
}
.login-card::after{
    content:'';position:absolute;top:-50%;left:-50%;width:200%;height:200%;
    background:radial-gradient(circle at 50% 0%,rgba(255,89,0,.04) 0%,transparent 50%);
    pointer-events:none;
}

.login-brand{
    text-align:center;
    margin-bottom:36px;
    position:relative;
}
.login-dot{
    width:48px;height:48px;
    background:radial-gradient(circle,var(--accent) 30%,rgba(255,89,0,.3) 70%);
    border-radius:50%;
    margin:0 auto 18px;
    box-shadow:0 0 30px rgba(255,89,0,.3),0 0 60px rgba(255,89,0,.1);
    animation:dotGlow 3s ease-in-out infinite;
    display:flex;align-items:center;justify-content:center;
}
.login-dot svg{width:24px;height:24px;color:#fff}
@keyframes dotGlow{
    0%,100%{box-shadow:0 0 30px rgba(255,89,0,.3),0 0 60px rgba(255,89,0,.1)}
    50%{box-shadow:0 0 40px rgba(255,89,0,.4),0 0 80px rgba(255,89,0,.15)}
}
.login-title{
    font-size:1.4rem;font-weight:800;letter-spacing:-.03em;
    margin-bottom:4px;
}
.login-sub{
    font-size:.78rem;color:var(--text3);font-family:'JetBrains Mono',monospace;
}

.login-field{margin-bottom:18px;position:relative}
.login-label{
    display:block;font-size:.68rem;font-weight:600;
    color:var(--text3);text-transform:uppercase;
    letter-spacing:.08em;margin-bottom:7px;
}
.login-input{
    width:100%;padding:12px 16px;
    background:rgba(255,255,255,.03);
    border:1px solid rgba(255,255,255,.06);
    border-radius:10px;
    color:var(--text);
    font-family:'JetBrains Mono',monospace;
    font-size:.88rem;
    outline:none;
    transition:border-color .25s,box-shadow .25s;
}
.login-input:focus{
    border-color:var(--accent);
    box-shadow:0 0 0 3px rgba(255,89,0,.12),0 0 20px rgba(255,89,0,.06);
}
.login-input::placeholder{color:var(--text3);opacity:.6}
select.login-input{-webkit-appearance:none;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center}
select.login-input option{background:#0c0f15;color:var(--text);padding:8px}

.login-btn{
    width:100%;padding:13px;margin-top:8px;
    background:linear-gradient(135deg,var(--accent),#e04d00);
    color:#fff;border:none;border-radius:10px;
    font-family:'Outfit',sans-serif;
    font-size:.9rem;font-weight:700;
    cursor:pointer;
    position:relative;overflow:hidden;
    transition:transform .15s,box-shadow .25s;
    box-shadow:0 4px 20px rgba(255,89,0,.25);
}
.login-btn:hover{
    transform:translateY(-2px);
    box-shadow:0 8px 30px rgba(255,89,0,.35);
}
.login-btn:active{transform:translateY(0)}
.login-btn::after{
    content:'';position:absolute;inset:0;
    background:linear-gradient(90deg,transparent,rgba(255,255,255,.1),transparent);
    transform:translateX(-100%);
    transition:transform .5s;
}
.login-btn:hover::after{transform:translateX(100%)}

.login-error{
    background:rgba(255,61,87,.08);
    border:1px solid rgba(255,61,87,.15);
    border-radius:10px;
    padding:10px 14px;
    font-size:.8rem;
    color:#ff6b7a;
    margin-bottom:18px;
    text-align:center;
}

.login-footer{
    text-align:center;margin-top:28px;
    font-size:.7rem;color:var(--text3);
    font-family:'JetBrains Mono',monospace;
}
</style>
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div style="position:absolute;top:14px;right:16px;display:flex;gap:1px;background:rgba(255,255,255,.04);border-radius:5px;padding:2px;z-index:2">
            <a href="?lang=en" style="padding:3px 8px;border-radius:3px;font-size:.55rem;font-weight:600;text-decoration:none;letter-spacing:.03em;transition:all .2s;{$langEn}">EN</a>
            <a href="?lang=de" style="padding:3px 8px;border-radius:3px;font-size:.55rem;font-weight:600;text-decoration:none;letter-spacing:.03em;transition:all .2s;{$langDe}">DE</a>
        </div>
        <div class="login-brand">
            <div class="login-dot">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <div class="login-title">{$appName}</div>
            <div class="login-sub">Server Management Panel</div>
        </div>
        {$errHtml}
        <form method="POST">
            <input type="hidden" name="_login" value="1">
            <div class="login-field">
                <label class="login-label">{$lblUser}</label>
                <input class="login-input" name="user" type="text" placeholder="root" autocomplete="username" autofocus>
            </div>
            <div class="login-field">
                <label class="login-label">{$lblPass}</label>
                <input class="login-input" name="pass" type="password" placeholder="{$lblPass}" autocomplete="current-password">
            </div>
            <div class="login-field">
                <label class="login-label">Realm</label>
                <select class="login-input" name="realm" style="cursor:pointer">
                    <option value="pve">Proxmox VE (PVE)</option>
                    <option value="pam">Linux (PAM)</option>
                </select>
                <div style="font-size:.6rem;color:rgba(255,255,255,.25);margin-top:6px">{$lblHint}</div>
            </div>
            <button class="login-btn" type="submit">{$lblBtn}</button>
        </form>
        <div class="login-footer" style="font-size:.62rem;color:rgba(255,255,255,.3)">
            <a href="https://comnic-it.de" target="_blank" style="color:rgba(255,255,255,.4);text-decoration:none">Comnic-IT</a> &middot; v{$appVersion}
        </div>
    </div>
</div>
</body>
</html>
HTML;
}

// ── CSRF Token Management ────────────────────────────────
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf'];

function csrf_check(): void {
    if (($_POST['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'CSRF-Token ungueltig']));
    }
}

// ╔══════════════════════════════════════════════════════════════════╗
// ║                      API HANDLER FUNCTIONS                      ║
// ║  Each group returns true when it handled the request            ║
// ║  false if the action name doesn't match.                        ║
// ╚══════════════════════════════════════════════════════════════════╝

// ── Dashboard API ──────────────────────────────────────────────────

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
    // GET: Real-time system statistics (CPU, RAM, Disk, Network, F2B, Nginx)
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

// ── Fail2ban API ───────────────────────────────────────────────────

/**
 * Verwaltet alle Fail2ban-Operationen: Jails anzeigen, Logs lesen,
 * Config bearbeiten, Filter auflisten, IPs entbannen.
 *
 * Endpoints: f2b-jails, f2b-log, f2b-config, f2b-save, f2b-filters, f2b-unban
 *
 * @param string $action Der API-Action-Name
 * @return bool true wenn behandelt
 */
function handleFail2banAPI(string $action): bool {
    // GET: All Fail2ban jails with ban statistics
    if ($action === 'f2b-jails') {
        $raw = shell_exec('sudo fail2ban-client status 2>/dev/null') ?? '';
        preg_match('/Jail list:\s*(.*)$/m', $raw, $m);
        $names = array_filter(array_map('trim', explode(',', $m[1] ?? '')));
        $jails = [];
        foreach ($names as $name) {
            $st = shell_exec("sudo fail2ban-client status " . escapeshellarg($name) . " 2>/dev/null") ?? '';
            preg_match('/Currently failed:\s*(\d+)/', $st, $cf);
            preg_match('/Total failed:\s*(\d+)/', $st, $tf);
            preg_match('/Currently banned:\s*(\d+)/', $st, $cb);
            preg_match('/Total banned:\s*(\d+)/', $st, $tb);
            preg_match('/Banned IP list:\s*(.*)$/m', $st, $bl);
            $bannedIPs = array_filter(array_map('trim', explode(' ', $bl[1] ?? '')));
            $jails[] = [
                'name' => $name,
                'failed_current' => (int)($cf[1] ?? 0),
                'failed_total' => (int)($tf[1] ?? 0),
                'banned_current' => (int)($cb[1] ?? 0),
                'banned_total' => (int)($tb[1] ?? 0),
                'banned_ips' => array_values($bannedIPs),
            ];
        }
        echo json_encode($jails);
        return true;
    }

    // GET: Last 80 lines from the Fail2ban ban log
    if ($action === 'f2b-log') {
        $log = F2B_LOG;
        $lines = [];
        if (file_exists($log) && is_readable($log)) {
            $lines = array_slice(file($log, FILE_IGNORE_NEW_LINES), -80);
            $lines = array_reverse($lines);
        } else {
            $raw = shell_exec("sudo tail -80 " . escapeshellarg($log) . " 2>/dev/null") ?? '';
            $lines = array_reverse(array_filter(explode("\n", $raw)));
        }
        echo json_encode($lines);
        return true;
    }

    // GET: Read Fail2ban config file (jail.local or filter)
    if ($action === 'f2b-config') {
        $file = $_GET['file'] ?? 'jail.local';
        $allowed = ['jail.local', 'jail.conf'];
        // Also allow filter files
        if (preg_match('/^filter\.d\/[\w-]+\.conf$/', $file)) {
            $allowed[] = $file;
        }
        if (!in_array($file, $allowed)) {
            echo json_encode(['ok' => false, 'error' => 'Datei nicht erlaubt']);
            return true;
        }
        $path = "/etc/fail2ban/$file";
        if (!file_exists($path)) {
            echo json_encode(['ok' => false, 'error' => 'Datei nicht gefunden']);
            return true;
        }
        echo json_encode(['ok' => true, 'content' => file_get_contents($path), 'file' => $file]);
        return true;
    }

    // POST: Save config and restart Fail2ban
    if ($action === 'f2b-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $file = $_POST['file'] ?? '';
        $content = $_POST['content'] ?? '';
        if (!in_array($file, ['jail.local']) && !preg_match('/^filter\.d\/[\w-]+\.conf$/', $file)) {
            echo json_encode(['ok' => false, 'error' => 'Datei nicht erlaubt']);
            return true;
        }
        $path = "/etc/fail2ban/$file";
        file_put_contents($path, $content);
        // Restart fail2ban
        $out = shell_exec('sudo systemctl restart fail2ban 2>&1') ?? '';
        $active = trim(shell_exec('systemctl is-active fail2ban 2>/dev/null') ?? '');
        echo json_encode(['ok' => $active === 'active', 'status' => $active, 'output' => trim($out)]);
        return true;
    }

    // GET: List available filter files
    if ($action === 'f2b-filters') {
        $files = glob('/etc/fail2ban/filter.d/*.conf');
        $filters = array_map(fn($f) => 'filter.d/' . basename($f), $files ?: []);
        sort($filters);
        echo json_encode($filters);
        return true;
    }

    // POST: Unban IP from a jail
    if ($action === 'f2b-unban' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $jail = $_POST['jail'] ?? '';
        $ip = $_POST['ip'] ?? '';
        if (!preg_match('/^[\w-]+$/', $jail) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['ok' => false, 'error' => 'Ungültige Parameter']);
            return true;
        }
        $out = shell_exec("sudo fail2ban-client set " . escapeshellarg($jail) . " unbanip " . escapeshellarg($ip) . " 2>&1");
        echo json_encode(['ok' => true, 'output' => trim($out ?? '')]);
        return true;
    }

    return false;
}

// ── Nginx API ──────────────────────────────────────────────────────

/**
 * Nginx Reverse Proxy Verwaltung: Sites auflisten, erstellen, bearbeiten,
 * loeschen, SSL erneuern, Config testen, System-Checks und Fixes.
 *
 * Endpoints: nginx-checks, nginx-fix, nginx-sites, nginx-add, nginx-save, nginx-delete, nginx-renew, ssl-health, ssl-fix-ipv6only, nginx-reload
 *
 * @param string $action Der API-Action-Name
 * @return bool true wenn behandelt
 */
function buildNginxProxyConfig(string $serverNames, string $target, bool $forceSsl, $maxUpload, bool $withWs, $timeout): string {
    $conf = "server {\n    listen 80;\n    listen [::]:80;\n    server_name $serverNames;\n\n";
    if ($forceSsl) {
        $conf .= "    return 301 https://\$host\$request_uri;\n}\n\n";
        $conf .= "server {\n    listen 443 ssl;\n    listen [::]:443 ssl;\n    server_name $serverNames;\n\n";
    }
    $mu = is_numeric($maxUpload) ? (int)$maxUpload : -1;
    if ($mu > 0) {
        $conf .= "    client_max_body_size {$mu}m;\n";
    } elseif ($maxUpload === '0' || $mu === 0) {
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
    $to = is_numeric($timeout) ? (int)$timeout : 0;
    if ($to > 0) {
        $conf .= "        proxy_connect_timeout {$to}s;\n";
        $conf .= "        proxy_send_timeout {$to}s;\n";
        $conf .= "        proxy_read_timeout {$to}s;\n";
    }
    $conf .= "    }\n}\n";
    return $conf;
}

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
        $checks[] = ['id' => 'certbot', 'label' => 'Certbot (SSL)', 'ok' => $certbotOk, 'value' => $certbotOk ? __('installed') : __('missing'), 'fix' => 'apt install -y certbot python3-certbot-nginx'];

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

    // GET: All Nginx sites with domains, proxy target, SSL status
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

    // POST: Create new reverse proxy site (optionally with SSL/Certbot)
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
        $maxUpload = $_POST['max_upload'] ?? '';
        $timeout = $_POST['timeout'] ?? '';

        $serverNames = implode(' ', $domains);
        $safeFile = preg_replace('/[^a-zA-Z0-9.-]/', '_', $domains[0]);
        $conf = buildNginxProxyConfig($serverNames, $target, $forceSsl, $maxUpload, $withWs, $timeout);

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
            $serverNames = implode(' ', $domains);
            $content = buildNginxProxyConfig($serverNames, "http://$ip:$port", $forceSsl, $maxUpload, $withWs, $timeout);
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

    // POST: Edit Nginx site config and check syntax
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

    // POST: Remove Nginx site (sites-enabled + sites-available)
    if ($action === 'nginx-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $file = basename($_POST['file'] ?? '');
        if (!$file) {
            echo json_encode(['ok' => false, 'error' => 'Keine Datei angegeben']);
            return true;
        }
        $enablePath = NGINX_SITES_DIR . "/$file";
        $availPath = NGINX_SITES_AVAILABLE . "/$file";

        // Read config BEFORE deletion to extract domains for cert cleanup
        $confContent = '';
        if (file_exists($availPath)) {
            $confContent = @file_get_contents($availPath) ?: '';
        } elseif (file_exists($enablePath)) {
            $realPath = is_link($enablePath) ? readlink($enablePath) : $enablePath;
            $confContent = @file_get_contents($realPath) ?: '';
        }

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

        // Delete certbot certificate
        if ($confContent && preg_match('/server_name\s+([^;]+);/', $confContent, $m)) {
            $siteDomains = array_filter(preg_split('/\s+/', trim($m[1])));
            if ($siteDomains) {
                shell_exec("sudo certbot delete --cert-name " . escapeshellarg($siteDomains[0]) . " --non-interactive 2>&1");
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

    // POST: Renew SSL certificate via Certbot
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

    // SSE: Create Nginx site with live progress
    if ($action === 'nginx-add-stream' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        set_time_limit(120);
        ignore_user_abort(true);
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $sendStep = function(string $step, string $msg, bool $ok = true) {
            echo "data: " . json_encode(['step' => $step, 'message' => $msg, 'ok' => $ok]) . "\n\n";
            if (ob_get_level()) ob_flush();
            flush();
        };

        $domainRaw = trim($_POST['domain'] ?? '');
        $target = trim($_POST['target'] ?? '');
        $withSsl = ($_POST['ssl'] ?? '') === '1';
        $domains = array_filter(array_map('trim', preg_split('/[\s,]+/', $domainRaw)));

        if (empty($domains)) {
            $sendStep('error', 'Mindestens eine Domain erforderlich', false);
            echo "data: " . json_encode(['done' => true, 'ok' => false, 'error' => 'Mindestens eine Domain erforderlich']) . "\n\n";
            flush(); return true;
        }
        if (!preg_match('/^https?:\/\/[\d.:]+$/', $target)) {
            $sendStep('error', 'Ungültiges Ziel', false);
            echo "data: " . json_encode(['done' => true, 'ok' => false, 'error' => 'Ungültiges Ziel']) . "\n\n";
            flush(); return true;
        }

        $sendStep('config', 'Nginx-Config erstellen...');
        $withWs = ($_POST['ws'] ?? '') === '1';
        $forceSsl = ($_POST['force_ssl'] ?? '') === '1';
        $maxUpload = $_POST['max_upload'] ?? '';
        $timeout = $_POST['timeout'] ?? '';
        $serverNames = implode(' ', $domains);
        $safeFile = preg_replace('/[^a-zA-Z0-9.-]/', '_', $domains[0]);
        $conf = buildNginxProxyConfig($serverNames, $target, $forceSsl, $maxUpload, $withWs, $timeout);

        $availPath = NGINX_SITES_AVAILABLE . "/$safeFile";
        $enablePath = NGINX_SITES_DIR . "/$safeFile";

        $sendStep('write', 'Config schreiben: ' . $safeFile);
        if (!@file_put_contents($availPath, $conf)) {
            $tmpFile = tempnam('/tmp', 'nginx_');
            file_put_contents($tmpFile, $conf);
            shell_exec('sudo cp ' . escapeshellarg($tmpFile) . ' ' . escapeshellarg($availPath) . ' && sudo chmod 644 ' . escapeshellarg($availPath) . ' 2>&1');
            @unlink($tmpFile);
        }
        if (!file_exists($enablePath)) {
            if (!@symlink($availPath, $enablePath)) {
                shell_exec('sudo ln -sf ' . escapeshellarg($availPath) . ' ' . escapeshellarg($enablePath) . ' 2>&1');
            }
        }

        $sendStep('test', 'Nginx-Config testen...');
        $test = shell_exec('sudo nginx -t 2>&1');
        if (strpos($test, 'successful') === false) {
            @unlink($enablePath);
            @unlink($availPath);
            $sendStep('error', 'Nginx-Config ungültig: ' . trim($test), false);
            echo "data: " . json_encode(['done' => true, 'ok' => false, 'error' => 'Config ungültig']) . "\n\n";
            flush(); return true;
        }

        $sendStep('reload', 'Nginx neu laden...');
        shell_exec('sudo systemctl reload nginx 2>&1');

        if ($withSsl) {
            $challenge = $_POST['challenge'] ?? 'http';
            $dnsProvider = $_POST['dns_provider'] ?? '';
            $email = trim($_POST['email'] ?? '');
            $certArgs = implode(' ', array_map(fn($d) => '-d ' . escapeshellarg($d), $domains));
            $emailArg = $email ? '--email ' . escapeshellarg($email) : '--register-unsafely-without-email';

            if ($challenge === 'dns' && $dnsProvider === 'cloudflare') {
                $cfToken = $_POST['cf_token'] ?? '';
                $sendStep('certbot', 'SSL via Cloudflare DNS-01 Challenge...');

                $cfIni = tempnam('/tmp', 'cf_');
                file_put_contents($cfIni, "dns_cloudflare_api_token = $cfToken\n");
                chmod($cfIni, 0600);

                $sendStep('dns-challenge', 'DNS-Record erstellen & validieren...');
                $certOut = shell_exec("sudo certbot certonly --dns-cloudflare --dns-cloudflare-credentials " . escapeshellarg($cfIni) . " $certArgs --non-interactive --agree-tos --force-renewal $emailArg 2>&1") ?? '';
                @unlink($cfIni);

                $sslOk = str_contains($certOut, 'Successfully') || str_contains($certOut, 'Congratulations') || str_contains($certOut, 'not yet due');
                if ($sslOk) {
                    $sendStep('install', 'Zertifikat in Nginx installieren...');
                    shell_exec("sudo certbot install --nginx $certArgs --non-interactive 2>&1");
                    $sendStep('ssl-done', 'SSL-Zertifikat via Cloudflare DNS aktiviert');
                } else {
                    $sendStep('ssl-fail', 'SSL fehlgeschlagen: ' . trim(substr($certOut, 0, 200)), false);
                }

            } elseif ($challenge === 'dns' && $dnsProvider === 'hetzner') {
                $hetznerToken = $_POST['hetzner_token'] ?? '';
                $sendStep('certbot', 'SSL via Hetzner DNS-01 Challenge...');

                $hetznerIni = tempnam('/tmp', 'hz_');
                file_put_contents($hetznerIni, "dns_hetzner_api_token = $hetznerToken\n");
                chmod($hetznerIni, 0600);

                $sendStep('dns-challenge', 'DNS-Record erstellen & validieren...');
                $certOut = shell_exec("sudo certbot certonly --authenticator dns-hetzner --dns-hetzner-credentials " . escapeshellarg($hetznerIni) . " $certArgs --non-interactive --agree-tos --force-renewal $emailArg 2>&1") ?? '';
                @unlink($hetznerIni);

                $sslOk = str_contains($certOut, 'Successfully') || str_contains($certOut, 'Congratulations') || str_contains($certOut, 'not yet due');
                if ($sslOk) {
                    $sendStep('install', 'Zertifikat in Nginx installieren...');
                    shell_exec("sudo certbot install --nginx $certArgs --non-interactive 2>&1");
                    $sendStep('ssl-done', 'SSL-Zertifikat via Hetzner DNS aktiviert');
                } else {
                    $sendStep('ssl-fail', 'SSL fehlgeschlagen: ' . trim(substr($certOut, 0, 200)), false);
                }

            } elseif ($challenge === 'dns' && $dnsProvider === 'ipv64') {
                $ipv64Key = $_POST['ipv64_key'] ?? '';
                $sendStep('certbot', 'SSL via IPv64 DNS-01 Challenge...');

                $authHook = tempnam('/tmp', 'ipv64_auth_');
                $cleanHook = tempnam('/tmp', 'ipv64_clean_');
                file_put_contents($authHook, "#!/bin/bash\ncurl -s -X POST https://ipv64.net/api.php -H 'Authorization: Bearer $ipv64Key' -d \"add_record=\$CERTBOT_DOMAIN\" -d 'praefix=_acme-challenge' -d 'type=TXT' -d \"content=\$CERTBOT_VALIDATION\"\nsleep 15\n");
                file_put_contents($cleanHook, "#!/bin/bash\ncurl -s -X DELETE https://ipv64.net/api.php -H 'Authorization: Bearer $ipv64Key' -d \"del_record=\$CERTBOT_DOMAIN\" -d 'praefix=_acme-challenge' -d 'type=TXT' -d \"content=\$CERTBOT_VALIDATION\"\n");
                chmod($authHook, 0700);
                chmod($cleanHook, 0700);

                $sendStep('dns-challenge', 'DNS-Record erstellen & validieren...');
                $certOut = shell_exec("sudo certbot certonly --manual --preferred-challenges dns --manual-auth-hook " . escapeshellarg($authHook) . " --manual-cleanup-hook " . escapeshellarg($cleanHook) . " $certArgs --non-interactive --agree-tos --force-renewal $emailArg 2>&1") ?? '';
                @unlink($authHook);
                @unlink($cleanHook);

                $sslOk = str_contains($certOut, 'Successfully') || str_contains($certOut, 'Congratulations') || str_contains($certOut, 'not yet due');
                if ($sslOk) {
                    $sendStep('install', 'Zertifikat in Nginx installieren...');
                    shell_exec("sudo certbot install --nginx $certArgs --non-interactive 2>&1");
                    $sendStep('ssl-done', 'SSL-Zertifikat via IPv64 DNS aktiviert');
                } else {
                    $sendStep('ssl-fail', 'SSL fehlgeschlagen: ' . trim(substr($certOut, 0, 200)), false);
                }

            } else {
                $sendStep('certbot', 'SSL-Zertifikat anfordern (HTTP-01)...');
                $certOut = shell_exec("sudo certbot --nginx $certArgs --non-interactive --agree-tos --force-renewal $emailArg 2>&1") ?? '';
                $sslOk = str_contains($certOut, 'Successfully') || str_contains($certOut, 'Congratulations') || str_contains($certOut, 'not yet due');
                if ($sslOk) {
                    $sendStep('ssl-done', 'SSL-Zertifikat erfolgreich aktiviert');
                } else {
                    $sendStep('ssl-fail', 'SSL fehlgeschlagen: ' . trim(substr($certOut, 0, 200)), false);
                }
            }
        }

        $sendStep('done', 'Site ' . $domains[0] . ' erfolgreich erstellt');
        echo "data: " . json_encode(['done' => true, 'ok' => true, 'message' => 'Site ' . $domains[0] . ' erstellt']) . "\n\n";
        if (ob_get_level()) ob_flush();
        flush();
        return true;
    }

    // SSE: Renew SSL certificate with live progress
    if ($action === 'nginx-renew-stream' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        set_time_limit(120);
        ignore_user_abort(true);
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $sendStep = function(string $step, string $msg, bool $ok = true) {
            echo "data: " . json_encode(['step' => $step, 'message' => $msg, 'ok' => $ok]) . "\n\n";
            if (ob_get_level()) ob_flush();
            flush();
        };

        $domain = trim($_POST['domain'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9.*-]+\.[a-zA-Z]{2,}$/', $domain)) {
            $sendStep('error', 'Ungültiger Domain-Name', false);
            echo "data: " . json_encode(['done' => true, 'ok' => false]) . "\n\n";
            flush(); return true;
        }

        $sendStep('renew', 'Zertifikat erneuern für ' . $domain . '...');
        $out = shell_exec("sudo certbot renew --cert-name " . escapeshellarg($domain) . " --force-renewal 2>&1") ?? '';
        $success = str_contains($out, 'Successfully') || str_contains($out, 'renewed') || str_contains($out, 'Congratulations');

        if (!$success) {
            $sendStep('fallback', 'Fallback: certbot --nginx...');
            $out = shell_exec("sudo certbot --nginx -d " . escapeshellarg($domain) . " --non-interactive --agree-tos --register-unsafely-without-email --force-renewal 2>&1") ?? '';
            $success = str_contains($out, 'Successfully') || str_contains($out, 'renewed') || str_contains($out, 'Congratulations');
        }

        if ($success) {
            $sendStep('done', 'Zertifikat für ' . $domain . ' erfolgreich erneuert');
        } else {
            $sendStep('error', 'Erneuerung fehlgeschlagen: ' . trim(substr($out, 0, 300)), false);
        }

        echo "data: " . json_encode(['done' => true, 'ok' => $success, 'output' => trim($out)]) . "\n\n";
        if (ob_get_level()) ob_flush();
        flush();
        return true;
    }

    // GET: SSL Health Check — DNS, certificate match, expiry, IPv4/IPv6
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

    // POST: Remove ipv6only=on from Nginx config
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

    // POST: Test Nginx config and reload
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

// ── Vms API ────────────────────────────────────────────────────────

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
    // GET: All VMs and CTs on this node via pvesh
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

    // GET: CT/VM list with IPs (for nginx target selection)
    if ($action === 'pve-ct-ips') {
        $node = trim(shell_exec('hostname 2>/dev/null') ?? '');
        $result = [];
        // CTs
        $ctRaw = shell_exec("sudo pvesh get /nodes/$node/lxc --output-format json 2>/dev/null") ?? '[]';
        foreach (json_decode($ctRaw, true) ?: [] as $ct) {
            $vmid = (int)$ct['vmid'];
            $confFile = "/etc/pve/lxc/{$vmid}.conf";
            $ip = '';
            if (file_exists($confFile)) {
                $conf = file_get_contents($confFile);
                if (preg_match('/ip=(\d+\.\d+\.\d+\.\d+)/', $conf, $m)) {
                    $ip = $m[1];
                }
            }
            if ($ip) {
                $result[] = ['vmid' => $vmid, 'name' => $ct['name'] ?? '', 'ip' => $ip, 'status' => $ct['status'] ?? ''];
            }
        }
        // VMs (try agent or config)
        $vmRaw = shell_exec("sudo pvesh get /nodes/$node/qemu --output-format json 2>/dev/null") ?? '[]';
        foreach (json_decode($vmRaw, true) ?: [] as $vm) {
            $vmid = (int)$vm['vmid'];
            $agentNet = shell_exec("sudo pvesh get /nodes/$node/qemu/$vmid/agent/network-get-interfaces --output-format json 2>/dev/null") ?? '';
            $ip = '';
            if ($agentNet) {
                $ifaces = json_decode($agentNet, true)['result'] ?? [];
                foreach ($ifaces as $iface) {
                    foreach ($iface['ip-addresses'] ?? [] as $addr) {
                        if ($addr['ip-address-type'] === 'ipv4' && !str_starts_with($addr['ip-address'], '127.')) {
                            $ip = $addr['ip-address'];
                            break 2;
                        }
                    }
                }
            }
            if ($ip) {
                $result[] = ['vmid' => $vmid, 'name' => $vm['name'] ?? '', 'ip' => $ip, 'status' => $vm['status'] ?? ''];
            }
        }
        usort($result, fn($a, $b) => $a['vmid'] - $b['vmid']);
        echo json_encode(['ok' => true, 'targets' => $result]);
        return true;
    }

    // GET: Naechste freie VMID vom Cluster
    if ($action === 'pve-nextid') {
        $raw = shell_exec('sudo pvesh get /cluster/nextid 2>/dev/null') ?? '';
        $id = (int)trim($raw);
        echo json_encode(['ok' => true, 'vmid' => $id > 0 ? $id : null]);
        return true;
    }

    // POST: Clone VM/CT (Full/Linked, with temp snapshot for running CTs)
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

    // GET: Read VM/CT configuration
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

    // POST: Change VM/CT hardware (CPU, RAM, Swap, Onboot, Network)
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

    // POST: Create new VM/CT from ZFS snapshot (ZFS-level clone)
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

    // POST: Start, stop, restart or shutdown VM/CT
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

    // GET: Available PVE storages (for clone target)
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

// ── Zfs API ────────────────────────────────────────────────────────

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
    // GET: ZFS pools, datasets, snapshots and auto-snapshot status
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

    // POST: Create manual ZFS snapshot
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

    // POST: Delete ZFS snapshot
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

    // POST: ZFS rollback to snapshot (deletes newer snapshots)
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

    // POST: Clone ZFS snapshot into new dataset
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

    // GET: Auto-snapshot configuration per dataset
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

    // POST: Enable/disable auto-snapshot for a dataset
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

    // POST: Change retention (--keep=N) in cron file
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

// ── Wireguard API ──────────────────────────────────────────────────

/**
 * WireGuard VPN Verwaltung: Tunnel-Status, Config lesen/speichern,
 * Keys generieren, Tunnel erstellen/loeschen/starten/stoppen.
 *
 * Endpoints: wg-status, wg-config, wg-save, wg-genkeys, wg-net-ifaces, wg-list-ifaces, wg-create, wg-delete, wg-control, wg-add-peer, wg-update-peer, wg-remove-peer, wg-server-info, wg-import
 *
 * @param string $action Der API-Action-Name
 * @return bool true wenn behandelt
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

function handleWireguardAPI(string $action): bool {
    // GET: WireGuard status of all interfaces + peers
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
            $peerBlocks = preg_split('/\[Peer\]/i', $conf);
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

    // GET: Read WireGuard interface config
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

    // POST: Save WireGuard config
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

    // GET: Generate new WireGuard keypair + PSK
    if ($action === 'wg-genkeys') {
        $privkey = trim(shell_exec('wg genkey 2>/dev/null') ?? '');
        $pubkey = $privkey ? trim(shell_exec("echo '$privkey' | wg pubkey 2>/dev/null") ?? '') : '';
        $psk = trim(shell_exec('wg genpsk 2>/dev/null') ?? '');
        echo json_encode(['ok' => (bool)$privkey, 'private_key' => $privkey, 'public_key' => $pubkey, 'preshared_key' => $psk]);
        return true;
    }

    // GET: List network interfaces (for PostUp wizard)
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

    // GET: List existing WireGuard interfaces
    if ($action === 'wg-list-ifaces') {
        $existing = [];
        foreach (glob('/etc/wireguard/wg*.conf') as $f) {
            $existing[] = basename($f, '.conf');
        }
        echo json_encode(['ok' => true, 'interfaces' => $existing]);
        return true;
    }

    // POST: Create new WireGuard tunnel and optionally start
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

        // Firewall: Add port to PVE Firewall if requested
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

    // POST: Stop WireGuard tunnel and delete config
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

    // POST: Start/stop/restart WireGuard interface
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

    // POST: Import WireGuard config (from another VPN server)
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

    // GET: Server info for an interface (Public Key, Listen Port, Public IP)
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

        // Detect public IP
        $publicIp = trim(shell_exec("curl -4 -s --max-time 3 ifconfig.me 2>/dev/null") ?? '');

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

    // POST: Add peer to existing interface
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
        if ($peerEndpoint) $peerSection .= "Endpoint = $peerEndpoint\n";
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

    // POST: Update peer in config
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

    // POST: Remove peer from interface
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

// ── Updates API ────────────────────────────────────────────────────

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
        $installDir = __DIR__;
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

    // POST: Perform update via update.sh (single source of truth)
    if ($action === 'update-pull' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $updateScript = __DIR__ . '/update.sh';
        if (!file_exists($updateScript)) {
            echo json_encode(['ok' => false, 'output' => 'update.sh not found']);
            return true;
        }
        $out = shell_exec('sudo bash ' . escapeshellarg($updateScript) . ' 2>&1') ?? '';
        $ok = str_contains($out, 'Update complete') || str_contains($out, 'Already up to date');
        echo json_encode(['ok' => $ok, 'output' => trim(preg_replace('/\x1b\[[0-9;]*m/', '', $out))]);
        return true;
    }

    // GET: PVE repository status (Enterprise/No-Sub, Subscription)
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

    // POST: Enable/disable repository
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

    // POST: Add no-subscription repository
    if ($action === 'repo-add-nosub' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $output = [];
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

    // GET: List available apt updates
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

    // POST: Configure app auto-update cron
    if ($action === 'app-auto-update-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $enabled = ($_POST['enabled'] ?? '') === '1';
        $day = (int)($_POST['day'] ?? 0);
        $hour = max(0, min(23, (int)($_POST['hour'] ?? 4)));
        $cronFile = '/etc/cron.d/floppyops-lite-app-update';
        @unlink('/etc/cron.daily/floppyops-lite-app-update');
        if ($enabled) {
            $dayField = $day === 0 ? '*' : (string)$day;
            $updateScript = __DIR__ . '/update.sh';
            $script = "# FloppyOps Lite App Auto-Update\n0 {$hour} * * {$dayField} root bash {$updateScript} > /var/log/floppyops-lite-app-update.log 2>&1\n";
            file_put_contents($cronFile, $script);
            chmod($cronFile, 0644);
        } else {
            @unlink($cronFile);
        }
        echo json_encode(['ok' => true, 'enabled' => $enabled, 'day' => $day, 'hour' => $hour]);
        return true;
    }

    // GET: Read app auto-update cron status
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

    // POST: Configure system auto-update cron
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

    // GET: Read system auto-update cron status
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

// ── Security API ───────────────────────────────────────────────────

/**
 * Security-Scanner und PVE Host-Firewall: Port-Scan, Firewall-Regeln
 * lesen/erstellen/loeschen, Firewall aktivieren, Standard-Regeln.
 *
 * Endpoints: sec-scan, sec-fw-rules, sec-fw-enable, sec-fw-block, sec-fw-add-rule, sec-fw-defaults, sec-fw-delete-rule
 *
 * @param string $action Der API-Action-Name
 * @return bool true wenn behandelt
 */
function handleSecurityAPI(string $action): bool {
    // GET: Port scan + PVE firewall status (risk assessment)
    if ($action === 'sec-scan') {
        $node = trim(shell_exec('hostname -s 2>/dev/null') ?? '');

        // Port-Scan via ss
        $raw = shell_exec('ss -tlnpH 2>/dev/null') ?? '';
        $riskyPorts = [
            111  => ['service' => 'rpcbind',       'risk' => 'high'],
            2049 => ['service' => 'NFS',            'risk' => 'medium'],
            3306 => ['service' => 'MySQL/MariaDB',  'risk' => 'high'],
            5432 => ['service' => 'PostgreSQL',     'risk' => 'high'],
            5900 => ['service' => 'VNC',            'risk' => 'high'],
            6379 => ['service' => 'Redis',          'risk' => 'critical'],
            9200 => ['service' => 'Elasticsearch',  'risk' => 'high'],
            11211 => ['service' => 'Memcached',     'risk' => 'critical'],
            27017 => ['service' => 'MongoDB',       'risk' => 'critical'],
        ];
        $ports = [];
        foreach (explode("\n", trim($raw)) as $line) {
            if (!$line) continue;
            if (!preg_match('/\s+([\[\]:0-9.*]+):(\d+)\s+/', $line, $m)) continue;
            $addr = trim($m[1], '[]');
            $port = (int)$m[2];
            $external = !in_array($addr, ['127.0.0.1', '::1', '0:0:0:0:0:0:0:1']);
            $process = '';
            if (preg_match('/users:\(\("([^"]+)"/', $line, $pm)) $process = $pm[1];
            $risk = null;
            $service = $process ?: "port-$port";
            if ($external && isset($riskyPorts[$port])) {
                $risk = $riskyPorts[$port]['risk'];
                $service = $riskyPorts[$port]['service'];
            }
            // Known PVE services
            $knownServices = [22 => 'SSH', 8006 => 'PVE WebUI', 3128 => 'SPICE', 111 => 'rpcbind',
                85 => 'pvedaemon', 25 => 'SMTP', 80 => 'HTTP', 443 => 'HTTPS', 53 => 'DNS'];
            if (isset($knownServices[$port]) && $service === $process) $service = $knownServices[$port];

            $ports[] = ['port' => $port, 'addr' => $addr, 'process' => $process,
                        'external' => $external, 'risk' => $risk, 'service' => $service];
        }
        // Deduplicate (same port may appear for v4+v6)
        $seen = [];
        $unique = [];
        foreach ($ports as $p) {
            $key = $p['port'];
            if (isset($seen[$key])) {
                // If any binding is external, mark as external
                if ($p['external'] && !$unique[$seen[$key]]['external']) {
                    $unique[$seen[$key]]['external'] = true;
                    $unique[$seen[$key]]['addr'] = $p['addr'];
                    if ($p['risk']) $unique[$seen[$key]]['risk'] = $p['risk'];
                }
                continue;
            }
            $seen[$key] = count($unique);
            $unique[] = $p;
        }
        usort($unique, fn($a, $b) => ($b['risk'] ? 1 : 0) - ($a['risk'] ? 1 : 0) ?: $a['port'] - $b['port']);

        // PVE Firewall status
        $dcFw = json_decode(shell_exec("sudo pvesh get /cluster/firewall/options --output-format json 2>/dev/null") ?? '{}', true);
        $nodeFw = json_decode(shell_exec("sudo pvesh get /nodes/" . escapeshellarg($node) . "/firewall/options --output-format json 2>/dev/null") ?? '{}', true);

        $riskyCount = count(array_filter($unique, fn($p) => $p['risk']));
        $dcOn = !empty($dcFw['enable']);
        $nodeOn = !empty($nodeFw['enable']);
        // PVE: DC firewall is the main switch — if DC is on, firewall is active
        $fwActive = $dcOn;
        echo json_encode(['ok' => true, 'ports' => $unique, 'firewall' => [
            'dc_enabled' => $dcOn,
            'node_enabled' => $nodeOn,
            'dc_policy_in' => $dcFw['policy_in'] ?? 'ACCEPT',
            'node' => $node
        ], 'summary' => [
            'total_ports' => count($unique),
            'external_ports' => count(array_filter($unique, fn($p) => $p['external'])),
            'risky_ports' => $riskyCount,
            'fw_active' => $fwActive
        ]]);
        return true;
    }

    // GET: Read PVE firewall rules (Node + Datacenter)
    if ($action === 'sec-fw-rules') {
        $node = trim(shell_exec('hostname -s 2>/dev/null') ?? '');
        $nodeRules = json_decode(shell_exec("sudo pvesh get /nodes/" . escapeshellarg($node) . "/firewall/rules --output-format json 2>/dev/null") ?? '[]', true) ?: [];
        $dcRules = json_decode(shell_exec("sudo pvesh get /cluster/firewall/rules --output-format json 2>/dev/null") ?? '[]', true) ?: [];
        echo json_encode(['ok' => true, 'node_rules' => $nodeRules, 'cluster_rules' => $dcRules, 'node' => $node]);
        return true;
    }

    // POST: Enable PVE Firewall (with SSH/WebUI safety)
    if ($action === 'sec-fw-enable' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $node = trim(shell_exec('hostname -s 2>/dev/null') ?? '');
        $level = $_POST['level'] ?? 'node';

        if ($level === 'node') {
            // Safety: ensure SSH + PVE WebUI ACCEPT rules exist
            $rules = json_decode(shell_exec("sudo pvesh get /nodes/" . escapeshellarg($node) . "/firewall/rules --output-format json 2>/dev/null") ?? '[]', true) ?: [];
            $has22 = false; $has8006 = false;
            foreach ($rules as $r) {
                if (($r['action'] ?? '') === 'ACCEPT' && ($r['type'] ?? '') === 'in') {
                    $dp = $r['dport'] ?? '';
                    if ($dp == '22') $has22 = true;
                    if ($dp == '8006') $has8006 = true;
                }
            }
            if (!$has22) shell_exec("sudo pvesh create /nodes/" . escapeshellarg($node) . "/firewall/rules --action ACCEPT --type in --dport 22 --enable 1 --comment 'SSH (auto-added)' 2>&1");
            if (!$has8006) shell_exec("sudo pvesh create /nodes/" . escapeshellarg($node) . "/firewall/rules --action ACCEPT --type in --dport 8006 --enable 1 --comment 'PVE WebUI (auto-added)' 2>&1");
            shell_exec("sudo pvesh set /nodes/" . escapeshellarg($node) . "/firewall/options --enable 1 2>&1");
        } else {
            shell_exec("sudo pvesh set /cluster/firewall/options --enable 1 2>&1");
        }
        echo json_encode(['ok' => true]);
        return true;
    }

    // POST: Block port (DROP rule)
    if ($action === 'sec-fw-block' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $node = trim(shell_exec('hostname -s 2>/dev/null') ?? '');
        $port = (int)($_POST['port'] ?? 0);
        if ($port < 1 || $port > 65535) { echo json_encode(['error' => 'Invalid port']); return true; }
        $out = shell_exec("sudo pvesh create /nodes/" . escapeshellarg($node) . "/firewall/rules"
            . " --action DROP --type in --dport " . escapeshellarg((string)$port)
            . " --enable 1 --comment " . escapeshellarg("Blocked by FloppyOps (port $port)")
            . " 2>&1");
        echo json_encode(['ok' => true, 'output' => trim($out ?? '')]);
        return true;
    }

    // POST: Add new firewall rule
    if ($action === 'sec-fw-add-rule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $node = trim(shell_exec('hostname -s 2>/dev/null') ?? '');
        $ruleAction = $_POST['rule_action'] ?? 'DROP';
        $type = $_POST['type'] ?? 'in';
        $dport = $_POST['dport'] ?? '';
        $source = $_POST['source'] ?? '';
        $comment = substr(preg_replace('/[^\w\s\-\.\/():,]/', '', $_POST['comment'] ?? ''), 0, 256);
        $level = $_POST['level'] ?? 'node';

        if (!in_array($ruleAction, ['ACCEPT', 'DROP', 'REJECT'])) { echo json_encode(['error' => 'Invalid action']); return true; }
        if (!in_array($type, ['in', 'out'])) { echo json_encode(['error' => 'Invalid type']); return true; }
        if ($dport !== '' && !preg_match('/^\d+([:\-]\d+)?$/', $dport)) { echo json_encode(['error' => 'Invalid port']); return true; }
        if ($source !== '' && !preg_match('/^[\d\.\/]+$/', $source)) { echo json_encode(['error' => 'Invalid source']); return true; }

        $path = $level === 'dc' ? "/cluster/firewall/rules" : "/nodes/" . escapeshellarg($node) . "/firewall/rules";
        $cmd = "sudo pvesh create $path --action " . escapeshellarg($ruleAction) . " --type " . escapeshellarg($type) . " --enable 1";
        if ($dport !== '') $cmd .= " --dport " . escapeshellarg($dport);
        if ($source !== '') $cmd .= " --source " . escapeshellarg($source);
        if ($comment !== '') $cmd .= " --comment " . escapeshellarg($comment);
        $out = shell_exec("$cmd 2>&1");
        echo json_encode(['ok' => true, 'output' => trim($out ?? '')]);
        return true;
    }

    // POST: Apply default rule set
    if ($action === 'sec-fw-defaults' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $node = trim(shell_exec('hostname -s 2>/dev/null') ?? '');
        $basePath = "/nodes/" . escapeshellarg($node) . "/firewall/rules";

        // Existing rules — avoid duplicates
        $existing = json_decode(shell_exec("sudo pvesh get $basePath --output-format json 2>/dev/null") ?? '[]', true) ?: [];
        $existingPorts = [];
        foreach ($existing as $r) {
            $dp = $r['dport'] ?? '';
            $act = $r['action'] ?? '';
            $existingPorts["$act:$dp"] = true;
        }

        $defaults = [
            ['action' => 'ACCEPT', 'dport' => '22',   'comment' => 'SSH'],
            ['action' => 'ACCEPT', 'dport' => '8006', 'comment' => 'PVE WebUI'],
            ['action' => 'ACCEPT', 'dport' => '3128', 'comment' => 'SPICE Proxy'],
            ['action' => 'DROP',   'dport' => '111',  'comment' => 'rpcbind (blocked)'],
            ['action' => 'DROP',   'dport' => '3306', 'comment' => 'MySQL (blocked)'],
            ['action' => 'DROP',   'dport' => '5432', 'comment' => 'PostgreSQL (blocked)'],
            ['action' => 'DROP',   'dport' => '5900', 'comment' => 'VNC (blocked)'],
            ['action' => 'DROP',   'dport' => '6379', 'comment' => 'Redis (blocked)'],
            ['action' => 'DROP',   'dport' => '11211','comment' => 'Memcached (blocked)'],
            ['action' => 'DROP',   'dport' => '27017','comment' => 'MongoDB (blocked)'],
        ];

        // Filter by selected indices
        $selected = json_decode($_POST['selected'] ?? '[]', true) ?: [];
        if (!empty($selected)) {
            $filtered = [];
            foreach ($selected as $idx) {
                if (isset($defaults[$idx])) $filtered[] = $defaults[$idx];
            }
            $defaults = $filtered;
        }

        $added = 0;
        foreach ($defaults as $rule) {
            $key = $rule['action'] . ':' . $rule['dport'];
            if (isset($existingPorts[$key])) continue;
            shell_exec("sudo pvesh create $basePath"
                . " --action " . escapeshellarg($rule['action'])
                . " --type in --enable 1"
                . " --dport " . escapeshellarg($rule['dport'])
                . " --comment " . escapeshellarg($rule['comment'])
                . " 2>&1");
            $added++;
        }
        echo json_encode(['ok' => true, 'added' => $added]);
        return true;
    }

    // POST: Delete firewall rule
    if ($action === 'sec-fw-delete-rule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $node = trim(shell_exec('hostname -s 2>/dev/null') ?? '');
        $pos = (int)($_POST['pos'] ?? -1);
        $level = $_POST['level'] ?? 'node';
        if ($pos < 0) { echo json_encode(['error' => 'Invalid position']); return true; }
        $path = $level === 'dc' ? "/cluster/firewall/rules/$pos" : "/nodes/" . escapeshellarg($node) . "/firewall/rules/$pos";
        $out = shell_exec("sudo pvesh delete $path 2>&1");
        echo json_encode(['ok' => true, 'output' => trim($out ?? '')]);
        return true;
    }

    return false;
}

// ── Firewall API ───────────────────────────────────────────────────

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
        $file = __DIR__ . '/data/firewall-templates.json';
        if (file_exists($file)) return json_decode(file_get_contents($file), true) ?: [];
        return [];
    }

    function loadFwTemplates(): array {
        $data = loadFwTemplateData();
        return ['builtin' => getBuiltinFwTemplates(), 'custom' => $data['custom'] ?? [], 'assignments' => $data['assignments'] ?? []];
    }

    function saveFwTemplateData(array $data): void {
        $dir = __DIR__ . '/data';
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
    // GET: All firewall templates (builtin + custom + assignments)
    if ($action === 'fw-templates') {
        echo json_encode(['ok' => true, ...loadFwTemplates()]);
        return true;
    }

    // POST: Create or edit custom template
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

    // POST: Delete custom template
    if ($action === 'fw-template-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $id = $_POST['id'] ?? '';
        $all = loadFwTemplates();
        $custom = array_values(array_filter($all['custom'], fn($t) => $t['id'] !== $id));
        saveFwTemplates($custom);
        echo json_encode(['ok' => true]);
        return true;
    }

    // GET: All VMs/CTs with firewall status and template assignment
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

    // GET: Firewall rules of a single VM/CT
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

    // POST: Apply template to VM/CT (duplicates are skipped)
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

    // POST: Delete firewall rule of a VM/CT
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

    // POST: Add new firewall rule to VM/CT
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

// ── API Router ──────────────────────────────────────────────────────
// Dispatch: Each handler function is called in order.
// The first one returning true has handled the request.
if (isset($_GET['api'])) {
    $action = $_GET['api'];
    $sseActions = ['nginx-add-stream', 'nginx-renew-stream'];
    if (!in_array($action, $sseActions)) {
        header('Content-Type: application/json');
    }

    if (handleDashboardAPI($action)) exit;
    if (handleFail2banAPI($action)) exit;
    if (handleNginxAPI($action)) exit;
    if (handleVmsAPI($action)) exit;
    if (handleZfsAPI($action)) exit;
    if (handleWireguardAPI($action)) exit;
    if (handleUpdatesAPI($action)) exit;
    if (handleSecurityAPI($action)) exit;
    if (handleFirewallAPI($action)) exit;

    // No handler matched the request
    http_response_code(404);
    echo json_encode(['error' => 'Unknown API']);
    exit;
}
?>
<!-- ╔══════════════════════════════════════════════════════════════╗ -->
<!-- ║                    HTML + CSS + LAYOUT                        ║ -->
<!-- ╚══════════════════════════════════════════════════════════════╝ -->
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= APP_NAME ?></title>
<link rel="icon" type="image/png" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAIGNIUk0AAHomAACAhAAA+gAAAIDoAAB1MAAA6mAAADqYAAAXcJy6UTwAAAAGYktHRAAAAAAAAPlDu38AAAAJcEhZcwAACxMAAAsTAQCanBgAAAAHdElNRQfqBAILOC7jnqLaAAAKyUlEQVRYw8WVd3CcxRnGn/32K/fdd10n3VknnSSfXDEGNwm5QBJMiW0gEAKZCSGkkZ5Mepn0MpkMaTYkkAQwwzCBFJyYQGaMY8cIN8BgY8myLVmS1axyd9L1r+5u/iDM2JS0f/L8tzs77/Obd3efF/g/i5y/WHLx8isURXlc8/l0KstEkiQJwDDn7MOHu7v3v3oulWpUVJ8eZVxECZF8gHAUWZ7ljM0NDZ5xAGDtFVdsASH3CCGSnDHuOA6smllxbPv6wYH+wxcAeHd1QbLH6eU7jFjO1bb5fOqtsiwTQiQQSYIQ/AiEeH8pnx8ClTsKhcJVnm11QHjzJAifAHEkWZ32+QPHw5HoU36/XhUEDwBkkRAcnHE4js0ty97+T3e+uO/ddknE5jP6oWdeAWDfTnaCiC9JlLi9c/Lge/ZH1nBJvopSCiJJAADO2FOyRKeKuXPXwpwVIUUMBv3yiCqjJCApFQfJooXFnhwIBWPJY0KiDULwFZxxyfNcocB9ascNlZ5MiM3njieB43v0W7ke+Z+dmAVHGwdWLIu65V+tn33g9r0hv0TpOgKAKspUQNeXsOL0psaw0l3ftuCuYF1TgCMHivlizW7PtDI3Nyj1nOi7vFwxH6zmRq/kSmgHUVQXQnSCsz2/v8E8mwnxz3Am/OBkP4SYu+ANsG8kVoOIh0GwRILIPTmsP/ipA8ZVzHMXtbc0jhYK5fZVHWvk9LIVZdsy87ds3sR8mubatm0/vXu3IwD7dP+ZhtP9/Qskp0LGzg5NGXWpg9VK0XhoY+3EhmbxQS4QBucvQZDb6fdnTgDAqx0A/d70Efb1hk8KxrczIdJbUvZtU8vx2N+kFbj60sb59+54TvYlm5Evl4PxYDDY0toKw+9HuVzCSy/3oDmdBqEUjstg24DJ5OS8+sTG92Uqj22oc27jJglDwgAIPkG/mz/xqq90/i+g35/ZKzx8VrjIcheNlzQaH7h5XdNiEZkXlvQwJJkiO50FEQLnpqcxPjWFM8NnUa5WYQQCyGbzcDwPuq7juhtvwsYr1gUnTdzyYs6IE9ubgMM/Rb+bP3y+p4zXSNi+P4FYMSHwk74xKzJ27jCOVgzMFspwXQarVETf8ePITk0jEAiAM4ZIOIzJyUlYrgMqSQiFQli1ahUSfpscHGyI3N+fy/9sWeXz8tNNu4C5C/yk1wKo28aF4Op24pEfqJWi1TteQd/gBEAILNPC4tY0DIUil82C+vxwuUBrUwqKRDA/k0EgEMDo6CheeLkHB/fsxdRYFcesSM/lf7R3Kv09rwui1wEAgHb3OSZxZevFQWx9Z2rOXeSbQyISRNCvw7UtdHfvAyUCly6/COnGBLqf2YuBU32IhYPwPA/nxscwNjKCY6fHMTwxDeqVJ1CtOW/kJeNNRO6btLNfiOzSStZHVYLwk0oU6VQKy9vTWL+2E1zxIRQ0ILe24MYbb4BCZdQYgeM4YFYNQy+/gBWZKFIRs2+oaP7mQBn8vwJg2+oXWzX+Q09TQklVBivoqI/XobE1AwiBXU/sBC8UYKSacM2m6yE4w8Hnj8C2baRCMj68OIvVC2tIG7zcVi+Pkc+9sc8bXoF3V7wFQvxC00lnNKoQ3nQpCg5FLjuDwaEhTM7MIKqqqJ/LoaW5CRXbRsmyMZPNAp6L5QszuGnzelyWMJGOk04Ofg/7UazxDTv92g33m/EE0fiviY9cT2QBq2kj7u5tw859R+E6Jggh8Os6AoEAdL8Ow2/Ab/gRCAYxMjKKidN9SIoSurZch48v6oc6ug/wiBAm/4OoSJ9QflrIvSmAuANRFgtuIwZ5j+STiJt5K+4bXoz7f78Luk+D7XHIsgJFVeF5DJ7ngXMOz3WgahpkRcMSw4FWm8a+rIpPv3cz7kz2Qh08AG5zLmp8Oy3XPkceQel1AF/+wB3175D7vuPX3Tstn05rRhwTyY3Y9cIAPMeEpshwhQTGBRjnqNVMCCFAKAUBAeMcQgBJXYA5JsZLDKqm4ab1F6Ft8u8ImLPwOTWv4qj3POpe9P14H30sDwCksSlNIdHr4/H6L65ctnRNMFYnuxwglGKuMIeh4SEkGhLYdPVVmJdM4NjxHtQsE2/dsB6cc/T2nYRt27h46VJYto2T/QMIBoLY190Nn09DqrEJhWIB+WwWsaABs1xwTw6NHcnn8z/lnv1n6jeMpYTKD6/t6lz21a99RWpvz8AwdGx8ywZMT03ixZeO4s733461nWswk53BdZvejrpYFNFwBCf6+vCOLVtQH4+jLhbF5NQkNl99NTRVxopLlqOrswNtrc0Ih4K4bvO1uPbaa7B42TKazWWbh8+e7fI8b7cUCocTgUAgum7tWpRKZfz16afx551PwDAM9PT2wqxW0NbSgocf+S22brsHpmlCURRomor6eBw100Q+n4NEKXyaDyDAvb/6NaKRCFavXInHd/wJP/v5Vpw9O4JKtYpDzz2PsfFx+HR/JByJJmhm4ZKLBOe33Hrrrarm02BbFqamp7B02cV4Zt8z0FQVXevWY3xiAg2JBOqT83BuahoeEzh06CB+++ij8ASQTDXjwP5n8bs/PI49e/agVK2huS2Dhx58EP39p3G6vx+yrCDVmMLqjg6cOnmSQaJ/kYuF4tpEMuEvmQ4+/4WP4NTJPtx487tQNB2874MfQe/LR7F79x50XNYF07Jw//0PoK4ujmqlgkce3g4hBKiioWba+OXdW+G6Lggh6OnpweDQWVBK4VgWdN1AXSKFQrmGBak0gqGQPj09cxlpbms/GIvFuuZn2vHUk0/AcRykGlMIRSKwLAulYhHVagXhSAyceahVq1A0HwgAxhgAAUJeyTPbtsA5B2MMlFJEIlGUyiVYtSqi0SguWbkasXg9HMvEof3dCARDh0iyKX3Mc71LEjyHjjjwXNmHUzmGrhYNC+o1zFoSBFXRHJUxPEdQYiqWNyrghKJiCzDGYSgcBZNDgYdC1UXC4KjZHjTigUAgW+XY219DOsSwIWrjSJbiHI1DVZRjcrVUuMUIRu5UIu131CfNuo9Fyvjxszbe1q5gUZzA4gSawtEQcLB/iIERB1sWS6gxgmrtlUyoC0go2wKUcZhMQHgMmgI4XMCgBINzEoZmCD7eoWOqmoCmBrLqbHl7aS7/m1eDSGpItbwllW790qoW5crSyBl5craMibJA1McR0QiifoKsSTFny6jTPOiygCYBhAh4noChCpRtAkXiCCgCDgckCShZQM0liEdDCLUscF8cdf82MTbyo+zEyLMA+AVR7Pf7I5d2rL+tIRb6tGVZGZdxqVKzYNsuXNeD7XoQABRFRiigg0oE5aoN22XgXECVKXSfAsOnQACoWi4sy4HHGK+LBAeZEFt7Xzz0iFmrFd90GAFAMBReQGW6VtV8nZrmuzIgiUwjr9CU4iDspyhAwUBVwaRFIJiHlMbQpnpQBUeFS8hJBvJqyCua1plKqbTHdd3nOGOHq5XywL+dhheAaBQNLQtbNF2/UuPuO5tppeuyaDXaOc+BUR9EX16BOTaOGPMwWZVxwglhVInnav7YAU+Sd8zmsn8fOHVy7F95/EuA81XftlD3+42VIVW6KW24m9dlWGZN2pOPdk/h+ZzfGRbBU2VJ/4sLsjM3NXm8nJu2/5O6/zHA+UotWtEeC/tvjmj89pmZ2XLBEQ85rvvk3Pjw2P9S7/+qfwDouDM3tiVkPwAAACV0RVh0ZGF0ZTpjcmVhdGUAMjAyNi0wMy0wN1QxMjozNzozNyswMDowMO2HUoYAAAAldEVYdGRhdGU6bW9kaWZ5ADIwMjYtMDItMTZUMTk6MjU6MTgrMDA6MDD0Kfd6AAAAKHRFWHRkYXRlOnRpbWVzdGFtcAAyMDI2LTA0LTAyVDExOjU2OjQ2KzAwOjAw7adSpgAAAABJRU5ErkJggg==">
<script src="js/chart.min.js"></script>
<style>
/* Fonts already loaded in login CSS above */

:root {
    --bg: #050810;
    --bg2: #0a0e1a;
    --surface: rgba(17, 24, 39, .65);
    --surface-solid: #111827;
    --border: rgba(255,89,0,.08);
    --border-subtle: rgba(255,255,255,.04);
    --accent: #ff5900;
    --accent-dim: rgba(255,89,0,.12);
    --accent-glow: rgba(255,89,0,.25);
    --green: #00e676;
    --green-dim: rgba(0,230,118,.1);
    --red: #ff3d57;
    --red-dim: rgba(255,61,87,.1);
    --yellow: #ffc107;
    --yellow-dim: rgba(255,193,7,.1);
    --blue: #40c4ff;
    --blue-dim: rgba(64,196,255,.1);
    --text: #e8eaed;
    --text2: #9aa0a6;
    --text3: #5f6368;
    --mono: 'JetBrains Mono', monospace;
    --sans: 'Outfit', -apple-system, sans-serif;
    --radius: 14px;
    --radius-sm: 8px;
}

* { margin: 0; padding: 0; box-sizing: border-box; }
html { font-size: 15px; scrollbar-gutter: stable; }
body {
    font-family: var(--sans);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    overflow-x: hidden;
}

/* ── Ambient Background ─────────────────────────────── */
body::before {
    content: '';
    position: fixed; inset: 0;
    background:
        radial-gradient(ellipse 80% 60% at 20% 10%, rgba(255,89,0,.04) 0%, transparent 60%),
        radial-gradient(ellipse 60% 50% at 80% 90%, rgba(64,196,255,.03) 0%, transparent 50%),
        radial-gradient(ellipse 40% 40% at 50% 50%, rgba(255,89,0,.02) 0%, transparent 40%);
    pointer-events: none;
    z-index: 0;
}

/* ── Grid Overlay ───────────────────────────────────── */
body::after {
    content: '';
    position: fixed; inset: 0;
    background-image:
        linear-gradient(rgba(255,255,255,.012) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.012) 1px, transparent 1px);
    background-size: 60px 60px;
    pointer-events: none;
    z-index: 0;
}

/* ── Layout ─────────────────────────────────────────── */
.app { position: relative; z-index: 1; }

.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 32px;
    height: 64px;
    background: rgba(5,8,16,.85);
    backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--border-subtle);
    position: sticky; top: 0; z-index: 100;
}

.topbar-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 800;
    font-size: 1.1rem;
    letter-spacing: -.02em;
}

.topbar-brand .dot {
    width: 10px; height: 10px;
    background: var(--accent);
    border-radius: 50%;
    box-shadow: 0 0 12px var(--accent-glow), 0 0 4px var(--accent);
    animation: pulse-dot 2s ease-in-out infinite;
}

@keyframes pulse-dot {
    0%, 100% { opacity: 1; box-shadow: 0 0 12px var(--accent-glow); }
    50% { opacity: .6; box-shadow: 0 0 20px var(--accent-glow), 0 0 40px rgba(255,89,0,.1); }
}

.topbar-host {
    font-family: var(--mono);
    font-size: .75rem;
    color: var(--text3);
    background: rgba(255,255,255,.03);
    padding: 4px 10px;
    border-radius: 20px;
    border: 1px solid var(--border-subtle);
}

/* ── Navigation Tabs (in topbar) ─────────────────────── */
.nav-tab {
    padding: 8px 14px;
    font-size: .75rem;
    font-weight: 600;
    color: var(--text3);
    cursor: pointer;
    border: none;
    background: none;
    font-family: var(--sans);
    position: relative;
    transition: all .2s;
    letter-spacing: .01em;
    display: flex;
    align-items: center;
    gap: 6px;
    border-radius: 6px;
}

.nav-tab:hover { color: var(--text2); background: rgba(255,255,255,.03); }
.nav-tab.active { color: var(--text); background: rgba(255,89,0,.08); }

.nav-tab .tab-icon {
    width: 16px; height: 16px;
    opacity: .5;
    transition: opacity .25s;
}
.nav-tab.active .tab-icon,
.nav-tab:hover .tab-icon { opacity: .9; }

.nav-tab .badge {
    font-size: .65rem;
    font-family: var(--mono);
    font-weight: 600;
    padding: 1px 6px;
    border-radius: 10px;
    background: var(--accent-dim);
    color: var(--accent);
    min-width: 18px;
    text-align: center;
}

/* ── Content Area ───────────────────────────────────── */
.content {
    max-width: 1320px;
    margin: 0 auto;
    padding: 28px 32px 70px;
}

.tab-panel { display: none; animation: fadeSlide .35s ease; }
.tab-panel.active { display: block; }

@keyframes fadeSlide {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ── Dashboard Cards ────────────────────────────────── */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}

.stat-card {
    background: var(--surface);
    backdrop-filter: blur(12px);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius);
    padding: 20px;
    position: relative;
    overflow: hidden;
    transition: border-color .3s, transform .2s;
}
.stat-card:hover {
    border-color: var(--border);
    transform: translateY(-2px);
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 1px;
    background: linear-gradient(90deg, transparent, var(--accent-dim), transparent);
}

.stat-label {
    font-size: .7rem;
    font-weight: 600;
    color: var(--text3);
    text-transform: uppercase;
    letter-spacing: .08em;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.stat-label .indicator {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: var(--green);
    box-shadow: 0 0 6px var(--green);
}

.stat-value {
    font-size: 1.6rem;
    font-weight: 800;
    letter-spacing: -.03em;
    line-height: 1;
    margin-bottom: 4px;
}

.stat-sub {
    font-size: .72rem;
    color: var(--text3);
    font-family: var(--mono);
}

/* ── Progress Bars ──────────────────────────────────── */
.progress-wrap {
    margin-top: 14px;
    background: rgba(255,255,255,.04);
    border-radius: 6px;
    height: 6px;
    overflow: hidden;
}
.progress-bar {
    height: 100%;
    border-radius: 6px;
    transition: width .8s cubic-bezier(.22,1,.36,1);
    background: linear-gradient(90deg, var(--accent), #ff8a3d);
}
.progress-bar.green { background: linear-gradient(90deg, #00c853, var(--green)); }
.progress-bar.red { background: linear-gradient(90deg, #ff1744, var(--red)); }
.progress-bar.blue { background: linear-gradient(90deg, #0091ea, var(--blue)); }

/* ── Section Headers ────────────────────────────────── */
.section-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}

.section-title {
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: -.01em;
    display: flex;
    align-items: center;
    gap: 8px;
}

.section-title .count {
    font-family: var(--mono);
    font-size: .72rem;
    font-weight: 500;
    color: var(--text3);
    background: rgba(255,255,255,.04);
    padding: 2px 8px;
    border-radius: 10px;
}

/* ── Tables / Lists ─────────────────────────────────── */
.data-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: var(--surface);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius);
    overflow: hidden;
}

.data-table th {
    text-align: left;
    padding: 12px 16px;
    font-size: .68rem;
    font-weight: 600;
    color: var(--text3);
    text-transform: uppercase;
    letter-spacing: .08em;
    background: rgba(255,255,255,.02);
    border-bottom: 1px solid var(--border-subtle);
}

.data-table td {
    padding: 14px 16px;
    font-size: .85rem;
    border-bottom: 1px solid var(--border-subtle);
    vertical-align: middle;
}

.data-table tr:last-child td { border-bottom: none; }

.data-table tr:hover td { background: rgba(255,255,255,.015); }

/* ── Tags / Badges ──────────────────────────────────── */
.tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: .7rem;
    font-weight: 600;
    font-family: var(--mono);
    padding: 3px 9px;
    border-radius: 6px;
}
.tag-green { background: var(--green-dim); color: var(--green); }
.tag-red { background: var(--red-dim); color: var(--red); }
.tag-yellow { background: var(--yellow-dim); color: var(--yellow); }
.tag-blue { background: var(--blue-dim); color: var(--blue); }
.tag-accent { background: var(--accent-dim); color: var(--accent); }
.tag-muted { background: rgba(255,255,255,.04); color: var(--text3); }

.ip-tag {
    font-family: var(--mono);
    font-size: .78rem;
    font-weight: 500;
    color: var(--text);
}

/* ── Buttons ────────────────────────────────────────── */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: var(--radius-sm);
    font-size: .8rem;
    font-weight: 600;
    font-family: var(--sans);
    border: 1px solid var(--border-subtle);
    background: rgba(255,255,255,.03);
    color: var(--text2);
    cursor: pointer;
    transition: all .2s;
    white-space: nowrap;
}
.btn:hover {
    background: rgba(255,255,255,.06);
    color: var(--text);
    border-color: rgba(255,255,255,.1);
    transform: translateY(-1px);
}

.btn-accent {
    background: var(--accent);
    color: #fff;
    border-color: var(--accent);
    box-shadow: 0 2px 12px rgba(255,89,0,.2);
}
.btn-accent:hover {
    background: #e55100;
    border-color: #e55100;
    box-shadow: 0 4px 20px rgba(255,89,0,.3);
}

.btn-sm { padding: 5px 10px; font-size: .72rem; }

.btn-red { border-color: rgba(255,61,87,.2); color: var(--red); }
.btn-red:hover { background: var(--red-dim); border-color: var(--red); }

.btn-green { border-color: rgba(0,230,118,.2); color: var(--green); }
.btn-green:hover { background: var(--green-dim); border-color: var(--green); }

/* ── Forms / Inputs ─────────────────────────────────── */
.form-group { margin-bottom: 16px; }
.form-label {
    display: block;
    font-size: .72rem;
    font-weight: 600;
    color: var(--text3);
    text-transform: uppercase;
    letter-spacing: .06em;
    margin-bottom: 6px;
}

.form-input, .form-select, .form-textarea {
    width: 100%;
    padding: 10px 14px;
    background: rgba(255,255,255,.03);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm);
    color: var(--text);
    font-family: var(--mono);
    font-size: .82rem;
    transition: border-color .2s, box-shadow .2s;
    outline: none;
}
.form-input:focus, .form-textarea:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-dim);
}

.form-textarea {
    min-height: 300px;
    resize: vertical;
    line-height: 1.5;
}

.form-hint {
    font-size: .68rem;
    color: var(--text3);
    margin-top: 4px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.form-check {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: .82rem;
    color: var(--text2);
    cursor: pointer;
}
.form-check input[type="checkbox"] {
    width: 16px; height: 16px;
    accent-color: var(--accent);
}

/* ── Modal ──────────────────────────────────────────── */
.modal-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,.7);
    backdrop-filter: blur(4px);
    z-index: 200;
    align-items: center;
    justify-content: center;
    padding: 24px;
}
.modal-overlay.active { display: flex; }

.modal {
    background: var(--surface-solid);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius);
    width: 100%;
    max-width: 700px;
    max-height: 85vh;
    overflow-y: auto;
    animation: modalIn .25s ease;
}

@keyframes modalIn {
    from { opacity: 0; transform: scale(.96) translateY(10px); }
}

.modal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px;
    border-bottom: 1px solid var(--border-subtle);
}

.modal-title { font-size: .95rem; font-weight: 700; }

.modal-close {
    width: 32px; height: 32px;
    border-radius: 8px;
    border: 1px solid var(--border-subtle);
    background: rgba(255,255,255,.03);
    color: var(--text3);
    cursor: pointer;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all .2s;
}
.modal-close:hover { color: var(--red); border-color: var(--red); }

.modal-body { padding: 24px; }
.modal-foot {
    padding: 16px 24px;
    border-top: 1px solid var(--border-subtle);
    display: flex;
    gap: 8px;
    justify-content: flex-end;
}

/* ── Log Viewer ─────────────────────────────────────── */
.log-viewer {
    background: rgba(0,0,0,.3);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius);
    padding: 16px;
    max-height: 400px;
    overflow-y: auto;
    font-family: var(--mono);
    font-size: .72rem;
    line-height: 1.7;
    color: var(--text3);
}

.log-line { padding: 1px 0; }
.log-line:hover { color: var(--text2); }
.log-line .log-ban { color: var(--red); font-weight: 600; }
.log-line .log-unban { color: var(--green); font-weight: 600; }
.log-line .log-found { color: var(--yellow); }
.log-line .log-ts { color: var(--text3); }

/* ── Jail Cards ─────────────────────────────────────── */
.jail-grid { display: flex; flex-direction: column; gap: 14px; }

.jail-card {
    background: var(--surface);
    backdrop-filter: blur(12px);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius);
    overflow: hidden;
    transition: border-color .3s;
}
.jail-card:hover { border-color: var(--border); }

.jail-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-subtle);
}

.jail-name {
    font-family: var(--mono);
    font-size: .88rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.jail-stats {
    display: flex;
    gap: 16px;
    font-size: .72rem;
    color: var(--text3);
    font-family: var(--mono);
}

.jail-stats span { display: flex; align-items: center; gap: 4px; }

.jail-body { padding: 16px 20px; }
.jail-body:empty { display: none; }

.banned-list { display: flex; flex-wrap: wrap; gap: 8px; }

.banned-ip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--red-dim);
    border: 1px solid rgba(255,61,87,.15);
    border-radius: var(--radius-sm);
    padding: 6px 8px 6px 12px;
    font-family: var(--mono);
    font-size: .78rem;
    color: var(--text);
    animation: fadeSlide .3s ease;
}

.banned-ip .unban-btn {
    width: 22px; height: 22px;
    border-radius: 6px;
    border: 1px solid rgba(255,255,255,.1);
    background: rgba(255,255,255,.05);
    color: var(--text3);
    cursor: pointer;
    font-size: .7rem;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all .2s;
}
.banned-ip .unban-btn:hover {
    background: var(--green-dim);
    border-color: var(--green);
    color: var(--green);
}

/* ── Nginx Site Cards ───────────────────────────────── */
.site-grid { display: flex; flex-direction: column; gap: 10px; }

.site-row {
    display: flex;
    align-items: center;
    gap: 16px;
    background: var(--surface);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius);
    padding: 16px 20px;
    transition: border-color .3s, transform .15s;
}
.site-row:hover {
    border-color: var(--border);
    transform: translateX(3px);
}

.site-domain {
    flex: 1;
    font-weight: 600;
    font-size: .88rem;
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}

.site-domain .domains {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

.site-target {
    font-family: var(--mono);
    font-size: .78rem;
    color: var(--text2);
    min-width: 180px;
}

.site-actions {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
}

/* ── Toast ──────────────────────────────────────────── */
.toast-container {
    position: fixed;
    top: 76px; right: 24px;
    z-index: 300;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.toast {
    background: var(--surface-solid);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm);
    padding: 12px 18px;
    font-size: .82rem;
    font-weight: 500;
    box-shadow: 0 8px 32px rgba(0,0,0,.4);
    animation: toastIn .3s ease, toastOut .3s ease 3.7s forwards;
    display: flex;
    align-items: center;
    gap: 8px;
    max-width: 380px;
}

.toast.success { border-left: 3px solid var(--green); }
.toast.error { border-left: 3px solid var(--red); }

@keyframes toastIn { from { opacity: 0; transform: translateX(30px); } }
@keyframes toastOut { to { opacity: 0; transform: translateX(30px); } }

/* ── Empty State ────────────────────────────────────── */
.empty {
    text-align: center;
    padding: 48px 24px;
    color: var(--text3);
    font-size: .85rem;
}

/* ── Skeleton Loading ───────────────────────────────── */
.skeleton {
    background: linear-gradient(90deg, rgba(255,255,255,.03) 25%, rgba(255,255,255,.06) 50%, rgba(255,255,255,.03) 75%);
    background-size: 200% 100%;
    animation: shimmer 1.5s infinite;
    border-radius: var(--radius-sm);
}
@keyframes shimmer { to { background-position: -200% 0; } }
@keyframes spin { to { transform: rotate(360deg); } }
.sub-tabs { display:flex;gap:2px;margin-bottom:14px;border-bottom:1px solid var(--border-subtle);padding-bottom:0 }
.sub-tab { padding:8px 16px;font-size:.75rem;font-weight:500;color:var(--text3);background:none;border:none;border-bottom:2px solid transparent;cursor:pointer;transition:all .15s }
.sub-tab:hover { color:var(--text2) }
.sub-tab.active { color:var(--accent);border-bottom-color:var(--accent) }
.sub-panel { display:none }
.sub-panel.active { display:block }
select.form-input option { background:var(--surface);color:var(--text) }
.help-section.open .help-arrow { transform:rotate(180deg) }
.help-body h4 { font-size:.78rem;font-weight:600;color:var(--text);margin:12px 0 6px }
.help-body ul, .help-body ol { margin:6px 0;padding-left:18px }
.help-body li { margin:4px 0 }
.help-body p { margin:6px 0 }
.help-body mark { background:rgba(255,89,0,.25);color:var(--text);padding:0 2px;border-radius:2px }

/* ── Responsive ─────────────────────────────────────── */
@media (max-width: 768px) {
    .topbar, .nav-tabs, .content { padding-left: 16px; padding-right: 16px; }
    .stat-grid { grid-template-columns: 1fr 1fr; }
    .form-row { grid-template-columns: 1fr; }
    .site-row { flex-direction: column; align-items: flex-start; }
    .site-target { min-width: 0; }
    .nav-tab { padding: 12px 14px; font-size: .76rem; }
    .nav-tab span.tab-text { display: none; }
}

/* ── Scrollbar ──────────────────────────────────────── */
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(255,255,255,.08); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,.15); }
</style>
</head>
<body>

<div class="app">
    <!-- ─ Topbar ──────────────────────────────────────── -->
    <div class="topbar">
        <div class="topbar-brand">
            <div style="width:26px;height:26px;border-radius:5px;background:linear-gradient(135deg,var(--accent),#e04d00);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M8 2l1.88 1.88M14.12 3.88L16 2M9 7.13v-1a3.003 3.003 0 116 0v1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a6 6 0 0112 0v3c0 3.3-2.7 6-6 6z"/><path d="M12 20v2M8.5 15h.01M15.5 15h.01"/></svg>
            </div>
            <span><?= APP_NAME ?></span>
        </div>
        <div style="display:flex;align-items:center;gap:2px;margin-left:24px;flex:1" id="topNavTabs">
            <button class="nav-tab active" data-tab="dashboard">
                <svg class="tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                <span class="tab-text"><?= __('tab_dashboard') ?></span>
            </button>
            <button class="nav-tab" data-tab="security">
                <svg class="tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                <span class="tab-text"><?= __('tab_security') ?></span>
                <span class="badge" id="secBadge" style="display:none">0</span>
                <span class="badge" id="f2bBadge" style="display:none;margin-left:-4px">0</span>
            </button>
            <button class="nav-tab" data-tab="network">
                <svg class="tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                <span class="tab-text">Network</span>
            </button>
            <button class="nav-tab" data-tab="zfs">
                <svg class="tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
                <span class="tab-text">ZFS</span>
            </button>
            <button class="nav-tab" data-tab="system">
                <svg class="tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <span class="tab-text">Updates</span>
            </button>
            <button class="nav-tab" data-tab="help">
                <svg class="tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <span class="tab-text"><?= __('tab_help') ?></span>
            </button>
        </div>
        <div style="display:flex;align-items:center;gap:8px">
            <div class="topbar-host" id="hostLabel">---</div>
            <div style="display:flex;border-radius:4px;overflow:hidden;border:1px solid var(--border-subtle)">
                <a href="?lang=de" style="padding:2px 6px;font-size:.55rem;font-weight:600;text-decoration:none;<?= $lang === 'de' ? 'background:var(--accent);color:#fff' : 'color:var(--text3)' ?>">DE</a>
                <a href="?lang=en" style="padding:2px 6px;font-size:.55rem;font-weight:600;text-decoration:none;<?= $lang === 'en' ? 'background:var(--accent);color:#fff' : 'color:var(--text3)' ?>">EN</a>
            </div>
            <span style="font-size:.65rem;color:var(--text3);font-family:var(--mono)"><?= htmlspecialchars($_SESSION['auth_user'] ?? 'admin') ?></span>
            <a href="https://github.com/floppy007/floppyops-lite/issues/new?template=bug_report.md" target="_blank" style="color:var(--text3);display:flex;text-decoration:none;padding:4px;border-radius:4px;transition:color .2s" title="Bug melden" onmouseover="this.style.color='var(--yellow)'" onmouseout="this.style.color='var(--text3)'">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2l1.88 1.88M14.12 3.88L16 2M9 7.13v-1a3.003 3.003 0 116 0v1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a6 6 0 0112 0v3c0 3.3-2.7 6-6 6z"/><path d="M12 20v2M8.5 15h.01M15.5 15h.01"/></svg>
            </a>
            <a href="https://github.com/floppy007/floppyops-lite/issues/new?template=feature_request.md" target="_blank" style="color:var(--text3);display:flex;text-decoration:none;padding:4px;border-radius:4px;transition:color .2s" title="Feature Request" onmouseover="this.style.color='var(--blue)'" onmouseout="this.style.color='var(--text3)'">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
            </a>
            <a href="?logout=1" style="color:var(--text3);display:flex;text-decoration:none;padding:4px;border-radius:4px;transition:color .2s" title="Logout" onmouseover="this.style.color='var(--red)'" onmouseout="this.style.color='var(--text3)'">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </a>
        </div>
    </div>

    <!-- ─ Content ─────────────────────────────────────── -->
    <div class="content">

        <!-- Dashboard -->
        <div class="tab-panel active" id="panel-dashboard">
            <div class="stat-grid" id="statsGrid">
                <div class="stat-card">
                    <div class="stat-label"><span class="indicator"></span> <?= __('hostname') ?></div>
                    <div class="stat-value" id="sHostname" style="font-size:1.1rem">---</div>
                    <div class="stat-sub" id="sKernel">---</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><span class="indicator"></span> <?= __('uptime') ?></div>
                    <div class="stat-value" id="sUptime" style="font-size:1rem">---</div>
                    <div class="stat-sub" id="sUptimeSince">---</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><?= __('cpu_load') ?></div>
                    <div class="stat-value" id="sLoad">---</div>
                    <div class="stat-sub" id="sLoadSub">---</div>
                    <div class="progress-wrap"><div class="progress-bar blue" id="sLoadBar" style="width:0"></div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><?= __('memory') ?></div>
                    <div class="stat-value" id="sMem">---</div>
                    <div class="stat-sub" id="sMemSub">---</div>
                    <div class="progress-wrap"><div class="progress-bar" id="sMemBar" style="width:0"></div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><?= __('disk') ?> /</div>
                    <div class="stat-value" id="sDisk">---</div>
                    <div class="stat-sub" id="sDiskSub">---</div>
                    <div class="progress-wrap"><div class="progress-bar green" id="sDiskBar" style="width:0"></div></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Fail2ban <?= __('jails') ?></div>
                    <div class="stat-value" id="sF2bJails">---</div>
                    <div class="stat-sub"><?= __('active') ?> <?= __('jails') ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><?= __('banned_ips') ?></div>
                    <div class="stat-value" id="sF2bBanned" style="color:var(--red)">---</div>
                    <div class="stat-sub"><?= __('blocked') ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><?= __('nginx_sites') ?></div>
                    <div class="stat-value" id="sNginxSites">---</div>
                    <div class="stat-sub"><?= __('active_sites') ?></div>
                </div>
                <div class="stat-card" style="cursor:pointer" onclick="switchTab('system')">
                    <div class="stat-label">Updates</div>
                    <div class="stat-value" id="sUpdates">---</div>
                    <div class="stat-sub"><?= $lang === 'en' ? 'available' : 'verfügbar' ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Subscription</div>
                    <div class="stat-value" id="sSub" style="font-size:1rem">---</div>
                    <div class="stat-sub" id="sSubLevel">---</div>
                </div>
            </div>
            <!-- Live Charts -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px">
                <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:10px 12px">
                    <div style="font-size:.68rem;font-weight:600;margin-bottom:4px;display:flex;align-items:center;gap:6px">
                        <span style="color:var(--blue)">CPU</span>
                        <span id="chartCpuVal" style="margin-left:auto;font-family:var(--mono);font-size:.62rem;color:var(--text3)">0%</span>
                    </div>
                    <div style="height:80px"><canvas id="chartCpu"></canvas></div>
                </div>
                <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:10px 12px">
                    <div style="font-size:.68rem;font-weight:600;margin-bottom:4px;display:flex;align-items:center;gap:6px">
                        <span style="color:var(--accent)">RAM</span>
                        <span id="chartMemVal" style="margin-left:auto;font-family:var(--mono);font-size:.62rem;color:var(--text3)">0%</span>
                    </div>
                    <div style="height:80px"><canvas id="chartMem"></canvas></div>
                </div>
                <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:10px 12px">
                    <div style="font-size:.68rem;font-weight:600;margin-bottom:4px;display:flex;align-items:center;gap:6px">
                        <span style="color:var(--green)">Network</span>
                        <span id="chartNetVal" style="margin-left:auto;font-family:var(--mono);font-size:.62rem;color:var(--text3)">0 B/s</span>
                    </div>
                    <div style="height:80px"><canvas id="chartNet"></canvas></div>
                </div>
                <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:10px 12px">
                    <div style="font-size:.68rem;font-weight:600;margin-bottom:4px;display:flex;align-items:center;gap:6px">
                        <span style="color:var(--yellow)">Disk I/O</span>
                        <span id="chartDiskVal" style="margin-left:auto;font-family:var(--mono);font-size:.62rem;color:var(--text3)">0 B/s</span>
                    </div>
                    <div style="height:80px"><canvas id="chartDisk"></canvas></div>
                </div>
            </div>
            <!-- VM/CT Status Widget -->
            <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:12px 16px;margin-top:10px">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                    <div style="font-size:.72rem;font-weight:600;display:flex;align-items:center;gap:6px">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                        VMs & Container
                        <span class="count" id="pveVmCount" style="font-size:.55rem">0</span>
                    </div>
                    <button class="btn btn-sm" onclick="loadDashboardVms()" style="font-size:.6rem;padding:2px 8px">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                    </button>
                </div>
                <div id="dashVmList" style="font-size:.75rem;color:var(--text3)"><?= __('loading') ?></div>
            </div>
        </div>

        <!-- Security (grouped: Firewall Templates, Port-Scan/Rules, Fail2ban) -->
        <div class="tab-panel" id="panel-security">
            <div class="sub-tabs" id="secSubTabs">
                <button class="sub-tab active" onclick="switchSubTab('security','firewall')"><?= __('fw_templates') ?></button>
                <button class="sub-tab" onclick="switchSubTab('security','portscan')"><?= __('sec_title') ?></button>
                <button class="sub-tab" onclick="switchSubTab('security','fail2ban')">Fail2ban</button>
            </div>

            <!-- Sub: Firewall Templates -->
            <div class="sub-panel active" id="sub-security-firewall">
                <div style="background:rgba(96,165,250,.06);border:1px solid rgba(96,165,250,.15);border-radius:var(--radius);padding:12px 16px;margin-bottom:14px;display:flex;align-items:flex-start;gap:10px">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <span style="font-size:.75rem;color:#94a3b8;line-height:1.5"><?= __('fw_templates_desc') ?></span>
                </div>
                <div style="display:flex;justify-content:flex-end;margin-bottom:10px"><button class="btn btn-sm btn-green" onclick="fwOpenBuilder()">+ <?= __('fw_create_custom') ?></button></div>
                <div id="fwTemplateGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-bottom:14px"></div>

                <!-- VM/CT Firewall -->
                <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);overflow:hidden">
                    <div style="padding:10px 16px;border-bottom:1px solid var(--border-subtle);display:flex;align-items:center;justify-content:space-between">
                        <div style="display:flex;align-items:center;gap:8px">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            <span style="font-size:.78rem;font-weight:600"><?= __('fw_vm_firewall') ?></span>
                        </div>
                        <button class="btn btn-sm" onclick="loadFwVmList()"><?= __('refresh') ?></button>
                    </div>
                    <div id="fwVmList" style="font-size:.78rem;padding:14px;text-align:center;color:var(--text3)"><?= __('loading') ?></div>
                </div>
            </div>

            <!-- Sub: Fail2ban -->
            <div class="sub-panel" id="sub-security-fail2ban">
                <div class="section-head">
                    <div class="section-title">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        Jails <span class="count" id="jailCount">0</span>
                    </div>
                    <div style="display:flex;gap:6px">
                        <button class="btn btn-sm" onclick="showF2bConfig('jail.local')" title="jail.local bearbeiten">Config</button>
                        <button class="btn btn-sm" onclick="loadF2b()"><?= __('refresh') ?></button>
                    </div>
                </div>
                <div class="jail-grid" id="jailGrid"></div>
                <div style="margin-top:32px">
                    <div class="section-head">
                        <div class="section-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--text3)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            Ban-Log
                        </div>
                    </div>
                    <div class="log-viewer" id="f2bLog"><?= __('loading') ?></div>
                </div>
            </div>

            <!-- Sub: Port-Scan / Host Firewall -->
            <div class="sub-panel" id="sub-security-portscan">
            <div class="section-head">
                <div class="section-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <?= __('sec_title') ?> <span class="count" id="secRiskCount">0</span>
                </div>
                <button class="btn btn-sm" onclick="loadSecScan()"><?= __('refresh') ?></button>
            </div>

            <!-- Info -->
            <div style="background:rgba(96,165,250,.06);border:1px solid rgba(96,165,250,.15);border-radius:var(--radius);padding:12px 16px;margin-bottom:14px;display:flex;align-items:flex-start;gap:10px">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <span style="font-size:.75rem;color:#94a3b8;line-height:1.5"><?= __('sec_info') ?></span>
            </div>

            <!-- Summary Cards -->
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px" id="secSummary"></div>

            <!-- Port Scan -->
            <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);margin-bottom:14px;overflow:hidden">
                <div style="padding:10px 16px;border-bottom:1px solid var(--border-subtle);display:flex;align-items:center;gap:8px">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <span style="font-size:.78rem;font-weight:600"><?= __('sec_open_ports') ?></span>
                </div>
                <div id="secPortList" style="font-size:.78rem"><?= __('sec_scanning') ?></div>
            </div>

            <!-- PVE Firewall -->
            <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);margin-bottom:14px;overflow:hidden">
                <div style="padding:10px 16px;border-bottom:1px solid var(--border-subtle);display:flex;align-items:center;gap:8px">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--yellow)" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <span style="font-size:.78rem;font-weight:600"><?= __('sec_pve_firewall') ?></span>
                </div>
                <div id="secFwStatus" style="padding:12px 16px"><?= __('loading') ?></div>
            </div>

            <!-- Firewall Rules -->
            <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);overflow:hidden">
                <div style="padding:10px 16px;border-bottom:1px solid var(--border-subtle);display:flex;align-items:center;justify-content:space-between">
                    <div style="display:flex;align-items:center;gap:8px">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <span style="font-size:.78rem;font-weight:600"><?= __('sec_fw_rules') ?></span>
                    </div>
                    <div style="display:flex;gap:6px">
                        <button class="btn btn-sm" onclick="secApplyDefaults()" style="font-size:.65rem"><?= __('sec_default_rules') ?></button>
                        <button class="btn btn-sm btn-green" onclick="secAddRuleModal()">+ <?= __('sec_add_rule') ?></button>
                    </div>
                </div>
                <div id="secRuleList" style="font-size:.78rem"><?= __('loading') ?></div>
            </div>

            </div><!-- /sub-security-portscan -->
        </div><!-- /panel-security -->

        <!-- Network (grouped: Nginx, WireGuard) -->
        <div class="tab-panel" id="panel-network">
            <div class="sub-tabs" id="netSubTabs">
                <button class="sub-tab active" onclick="switchSubTab('network','nginx')"><?= __('tab_nginx') ?></button>
                <button class="sub-tab" onclick="switchSubTab('network','wireguard')"><?= __('tab_vpn') ?></button>
            </div>
            <div class="sub-panel active" id="sub-network-nginx">
            <div class="section-head">
                <div class="section-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                    <?= __('proxy_sites') ?>
                    <span class="count" id="siteCount">0</span>
                </div>
                <div style="display:flex;gap:8px">
                    <button class="btn btn-sm btn-green" onclick="showAddSite()">+ <?= __('new_site') ?></button>
                    <button class="btn btn-sm" onclick="reloadNginx()"><?= __('reload_nginx') ?></button>
                </div>
            </div>
            <!-- Setup Guide -->
            <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);margin-bottom:14px;overflow:hidden">
                <div style="padding:10px 14px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--border-subtle);cursor:pointer" onclick="var g=document.getElementById('nginxGuide');g.style.display=g.style.display==='none'?'':'none';if(g.style.display!=='none')loadNginxChecks()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <span style="font-size:.75rem;font-weight:600;flex:1"><?= $lang === 'de' ? 'Wie funktioniert der Reverse Proxy?' : 'How does the reverse proxy work?' ?></span>
                    <span style="font-size:.6rem;color:var(--text3)">&#9660;</span>
                </div>
                <div id="nginxGuide" style="display:none;padding:14px">
                    <div style="font-size:.72rem;color:var(--text2);line-height:1.8;margin-bottom:14px">
                        <?php if ($lang === 'de'): ?>
                        Nginx auf diesem Server empfaengt alle HTTP/HTTPS-Anfragen und leitet sie an interne Container oder VMs weiter.
                        So können mehrere Webseiten/Apps auf einem Server laufen, jede mit eigener Domain und SSL-Zertifikat.
                        <div style="margin:10px 0;padding:8px 12px;background:rgba(0,0,0,.2);border-radius:6px;font-family:var(--mono);font-size:.65rem;color:var(--text3)">
                            Browser → <span style="color:var(--accent)">nginx (:443 SSL)</span> → <span style="color:var(--green)">CT/VM (10.10.10.x:80)</span>
                        </div>
                        <?php else: ?>
                        Nginx on this server receives all HTTP/HTTPS requests and forwards them to internal containers or VMs.
                        Multiple websites/apps can run on one server, each with its own domain and SSL certificate.
                        <div style="margin:10px 0;padding:8px 12px;background:rgba(0,0,0,.2);border-radius:6px;font-family:var(--mono);font-size:.65rem;color:var(--text3)">
                            Browser → <span style="color:var(--accent)">nginx (:443 SSL)</span> → <span style="color:var(--green)">CT/VM (10.10.10.x:80)</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Status Checks -->
                    <div style="font-size:.68rem;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px"><?= $lang === 'de' ? 'System-Status' : 'System Status' ?></div>
                    <div id="nginxChecks" style="display:flex;flex-direction:column;gap:4px">
                        <div style="color:var(--text3);font-size:.72rem;padding:6px"><span class="loading-spinner" style="width:10px;height:10px;border-width:1.5px;margin-right:6px"></span> <?= $lang === 'de' ? 'Prüfe...' : 'Checking...' ?></div>
                    </div>
                </div>
            </div>
            <!-- SSL Health Check -->
            <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);margin-bottom:14px;overflow:hidden">
                <div style="padding:10px 16px;border-bottom:1px solid var(--border-subtle);display:flex;align-items:center;justify-content:space-between">
                    <div style="display:flex;align-items:center;gap:8px">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--yellow)" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 12 15 16 10"/></svg>
                        <span style="font-size:.78rem;font-weight:600"><?= __('ssl_health') ?></span>
                        <span class="count" id="sslIssueCount" style="display:none">0</span>
                    </div>
                    <button class="btn btn-sm" onclick="loadSslHealth()"><?= __('refresh') ?></button>
                </div>
                <div id="sslHealthResult" style="font-size:.78rem;padding:12px 16px;color:var(--text3)"><?= __('ssl_click_scan') ?></div>
            </div>

            <div class="site-grid" id="siteGrid"></div>
        </div>

            <div class="sub-panel" id="sub-network-wireguard">
            <div class="section-head">
                <div class="section-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M16.24 7.76a6 6 0 0 1 0 8.49m-8.48-.01a6 6 0 0 1 0-8.49"/></svg>
                    VPN Tunnels
                    <span class="count" id="wgCount">0</span>
                </div>
                <div style="display:flex;gap:6px">
                    <button class="btn btn-sm btn-green" onclick="wgWizardOpen()">+ Neuer Tunnel</button>
                    <button class="btn btn-sm" onclick="wgImportOpen()"><?= $lang === 'de' ? 'Config importieren' : 'Import Config' ?></button>
                    <button class="btn btn-sm" onclick="loadWg()"><?= $lang === 'de' ? 'Aktualisieren' : 'Refresh' ?></button>
                </div>
            </div>
            <!-- Info Box -->
            <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);margin-bottom:14px;overflow:hidden">
                <div style="padding:10px 14px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--border-subtle);cursor:pointer" onclick="var g=document.getElementById('wgGuide');g.style.display=g.style.display==='none'?'':'none'">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <span style="font-size:.75rem;font-weight:600;flex:1"><?= $lang === 'de' ? 'Warum WireGuard VPN?' : 'Why WireGuard VPN?' ?></span>
                    <span style="font-size:.6rem;color:var(--text3)">&#9660;</span>
                </div>
                <div id="wgGuide" style="display:none;padding:14px;font-size:.72rem;color:var(--text2);line-height:1.8">
                    <?php if ($lang === 'de'): ?>
                    <strong style="color:var(--text)">Sichere Verbindung zu deinem Server</strong><br>
                    WireGuard erstellt einen verschlüsselten Tunnel zwischen deinem lokalen Netzwerk und dem Dedicated Server.
                    So erreichst du alle internen Dienste (CTs, VMs, PVE WebUI) sicher über das Internet — ohne Ports öffentlich freizugeben.

                    <div style="margin:10px 0;padding:8px 12px;background:rgba(0,0,0,.2);border-radius:6px;font-family:var(--mono);font-size:.65rem;color:var(--text3)">
                        Büro/Zuhause (10.10.20.2) → <span style="color:var(--accent)">WireGuard Tunnel</span> → Dedicated Server (10.10.20.1) → <span style="color:var(--green)">CTs (10.10.10.x)</span>
                    </div>

                    <strong style="color:var(--text)">Typische Einsatzszenarien:</strong>
                    <div style="display:flex;flex-direction:column;gap:4px;margin-top:6px">
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> PVE WebUI sicher erreichbar ohne öffentlichen Port 8006</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> Zugriff auf interne CTs/VMs von überall (Homeoffice, Mobil)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> Site-to-Site VPN zwischen Standorten (z.B. Büro ↔ Rechenzentrum)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> Backup-Traffic über verschlüsselte Verbindung (PBS, ZFS Replikation)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> Monitoring & Management ohne öffentliche Angriffsfläche</div>
                    </div>

                    <div style="margin-top:10px;padding:8px 12px;background:rgba(255,89,0,.04);border:1px solid rgba(255,89,0,.1);border-radius:6px;font-size:.65rem">
                        <strong style="color:var(--accent)">Tipp:</strong> Auf der Gegenstelle (Router/Gateway) muss ebenfalls WireGuard installiert und der Peer konfiguriert sein.
                        Der Wizard oben generiert die passende Remote-Config zum Kopieren.
                    </div>
                    <?php else: ?>
                    <strong style="color:var(--text)">Secure connection to your server</strong><br>
                    WireGuard creates an encrypted tunnel between your local network and the dedicated server.
                    Access all internal services (CTs, VMs, PVE WebUI) securely over the internet — without exposing ports publicly.

                    <div style="margin:10px 0;padding:8px 12px;background:rgba(0,0,0,.2);border-radius:6px;font-family:var(--mono);font-size:.65rem;color:var(--text3)">
                        Office/Home (10.10.20.2) → <span style="color:var(--accent)">WireGuard Tunnel</span> → Dedicated Server (10.10.20.1) → <span style="color:var(--green)">CTs (10.10.10.x)</span>
                    </div>

                    <strong style="color:var(--text)">Typical use cases:</strong>
                    <div style="display:flex;flex-direction:column;gap:4px;margin-top:6px">
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> Secure PVE WebUI access without public port 8006</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> Access internal CTs/VMs from anywhere (home office, mobile)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> Site-to-site VPN between locations (office ↔ datacenter)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> Backup traffic over encrypted connection (PBS, ZFS replication)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> Monitoring & management without public attack surface</div>
                    </div>

                    <div style="margin-top:10px;padding:8px 12px;background:rgba(255,89,0,.04);border:1px solid rgba(255,89,0,.1);border-radius:6px;font-size:.65rem">
                        <strong style="color:var(--accent)">Tip:</strong> The remote side (router/gateway) also needs WireGuard installed and the peer configured.
                        The wizard above generates the matching remote config for copying.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Traffic Graph -->
            <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:14px 16px 8px;margin-bottom:16px">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
                    <div style="display:flex;align-items:center;gap:6px;font-size:.7rem;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.06em">
                        <span class="indicator"></span> Live Traffic
                    </div>
                    <div style="display:flex;gap:16px;font-family:var(--mono);font-size:.68rem">
                        <span style="color:#00e676">&#9660; <span id="wgGraphRx">---</span></span>
                        <span style="color:#40c4ff">&#9650; <span id="wgGraphTx">---</span></span>
                    </div>
                </div>
                <div style="height:100px"><canvas id="wgCanvas"></canvas></div>
            </div>

            <div id="wgRestartBanner" style="display:none;background:rgba(255,193,7,.06);border:1px solid rgba(255,193,7,.2);border-radius:var(--radius);padding:10px 14px;margin-bottom:12px">
                <div style="display:flex;align-items:center;gap:10px">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--yellow)" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <span style="flex:1;font-size:.76rem" id="wgRestartMsg"></span>
                    <button class="btn btn-sm" id="wgRestartBtn" style="font-size:.7rem">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                        Restart
                    </button>
                    <button class="btn btn-sm" onclick="document.getElementById('wgRestartBanner').style.display='none'" style="padding:2px 6px;font-size:.6rem">&times;</button>
                </div>
            </div>
            <div id="wgGrid" class="jail-grid"></div>
            </div><!-- /sub-network-wireguard -->
        </div><!-- /panel-network -->

        <!-- ZFS (eigener Tab) -->
        <div class="tab-panel" id="panel-zfs">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                <div style="display:flex;gap:2px;background:rgba(255,255,255,.02);border:1px solid var(--border-subtle);border-radius:8px;padding:2px">
                    <button class="btn btn-sm zfs-sub active" data-zfstab="pools" onclick="zfsSwitchTab('pools',this)" style="border:none;border-radius:6px;font-size:.7rem;padding:5px 14px">Pools & Datasets</button>
                    <button class="btn btn-sm zfs-sub" data-zfstab="snaps" onclick="zfsSwitchTab('snaps',this)" style="border:none;border-radius:6px;font-size:.7rem;padding:5px 14px">Snapshots <span class="count" id="zfsSnapCount" style="margin-left:4px">0</span></button>
                    <button class="btn btn-sm zfs-sub" data-zfstab="auto" onclick="zfsSwitchTab('auto',this)" style="border:none;border-radius:6px;font-size:.7rem;padding:5px 14px">Auto-Snapshots <span id="zfsAutoStatus" style="margin-left:4px"></span></button>
                </div>
                <div style="display:flex;gap:6px">
                    <button class="btn btn-sm btn-accent" onclick="zfsCreateSnapModal()">+ Snapshot</button>
                    <button class="btn btn-sm" onclick="loadZfs()"><?= __('refresh') ?></button>
                </div>
            </div>

            <!-- Info Box -->
            <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);margin-bottom:14px;overflow:hidden">
                <div style="padding:10px 14px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--border-subtle);cursor:pointer" onclick="var g=document.getElementById('zfsGuide');g.style.display=g.style.display==='none'?'':'none'">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <span style="font-size:.75rem;font-weight:600;flex:1">ZFS Snapshots &amp; Auto-Backup</span>
                    <span style="font-size:.6rem;color:var(--text3)">&#9660;</span>
                </div>
                <div id="zfsGuide" style="display:none;padding:14px;font-size:.72rem;color:var(--text2);line-height:1.8">
                    <?php if ($lang === 'de'): ?>
                    <strong style="color:var(--text)">Datensicherung direkt auf dem Server</strong><br>
                    ZFS Snapshots sind sofortige, platzsparende Sicherungspunkte deiner Container und VMs. Sie ermöglichen sekundenschnelles Zurückrollen bei Problemen.

                    <div style="display:flex;flex-direction:column;gap:4px;margin-top:8px">
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Auto-Snapshots</strong> — Automatisch alle 15 Min, stündlich, täglich, wöchentlich, monatlich</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Rollback</strong> — Container auf einen früheren Zustand zurücksetzen</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Clone</strong> — Neuen CT/VM aus einem Snapshot erstellen (Test, Migration)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Platzsparend</strong> — Nur geänderte Blöcke werden gespeichert (Copy-on-Write)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Keine Downtime</strong> — Snapshots sind sofort, ohne den CT/VM zu stoppen</div>
                    </div>

                    <div style="margin-top:10px;padding:8px 12px;background:rgba(255,89,0,.04);border:1px solid rgba(255,89,0,.1);border-radius:6px;font-size:.65rem">
                        <strong style="color:var(--accent)">Empfehlung:</strong> Installiere <code style="padding:1px 4px;background:rgba(255,255,255,.04);border-radius:3px">zfs-auto-snapshot</code> im Auto-Snapshots Tab für automatische Sicherungen. Standard-Retention: 4 frequent, 24 hourly, 31 daily, 8 weekly, 12 monthly = ca. 1 Jahr Historie.
                    </div>
                    <?php else: ?>
                    <strong style="color:var(--text)">Data protection directly on the server</strong><br>
                    ZFS snapshots are instant, space-efficient backup points of your containers and VMs. Roll back in seconds when problems occur.

                    <div style="display:flex;flex-direction:column;gap:4px;margin-top:8px">
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Auto-Snapshots</strong> — Automatically every 15 min, hourly, daily, weekly, monthly</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Rollback</strong> — Restore container to a previous state</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Clone</strong> — Create new CT/VM from a snapshot (testing, migration)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Space-efficient</strong> — Only changed blocks are stored (copy-on-write)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>No downtime</strong> — Snapshots are instant, no CT/VM stop required</div>
                    </div>

                    <div style="margin-top:10px;padding:8px 12px;background:rgba(255,89,0,.04);border:1px solid rgba(255,89,0,.1);border-radius:6px;font-size:.65rem">
                        <strong style="color:var(--accent)">Recommendation:</strong> Install <code style="padding:1px 4px;background:rgba(255,255,255,.04);border-radius:3px">zfs-auto-snapshot</code> in the Auto-Snapshots tab for automatic backups. Default retention: 4 frequent, 24 hourly, 31 daily, 8 weekly, 12 monthly = ~1 year history.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sub: Pools & Datasets -->
            <div id="zfsTabPools">
                <div id="zfsPools" style="margin-bottom:14px"></div>
                <table class="data-table">
                    <thead><tr><th><?= __('dataset') ?></th><th><?= __('used') ?></th><th><?= __('available') ?></th><th><?= __('mountpoint') ?></th><th style="width:150px"><?= __('utilization') ?></th></tr></thead>
                    <tbody id="zfsDsBody"></tbody>
                </table>
            </div>

            <!-- Sub: Snapshots -->
            <div id="zfsTabSnaps" style="display:none">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
                    <div style="font-size:.72rem;font-weight:600">Snapshots</div>
                    <div style="display:flex;gap:6px;align-items:center">
                        <select id="zfsSnapSort" onchange="zfsRenderSnaps()" style="background:var(--surface-solid);border:1px solid var(--border-subtle);border-radius:4px;padding:3px 8px;font-size:.65rem;color:var(--text)">
                            <option value="date-desc">Neueste zuerst</option>
                            <option value="date-asc">Älteste zuerst</option>
                            <option value="size-desc">Größte zuerst</option>
                            <option value="name-asc">Name A-Z</option>
                        </select>
                        <select id="zfsSnapFilter" onchange="zfsRenderSnaps()" style="background:var(--surface-solid);border:1px solid var(--border-subtle);border-radius:4px;padding:3px 8px;font-size:.65rem;color:var(--text)">
                            <option value=""><?= __('all_datasets') ?></option>
                        </select>
                    </div>
                </div>
                <div id="zfsSnapBody"></div>
            </div>

            <!-- Sub: Auto-Snapshots -->
            <div id="zfsTabAuto" style="display:none">
                <div id="zfsAutoBody"></div>
            </div>
        </div>

        <!-- System (Updates) -->
        <div class="tab-panel" id="panel-system">
            <div class="section-head">
                <div class="section-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                    Updates
                    <span id="updCount" class="count" style="font-size:.58rem">—</span>
                </div>
                <div style="display:flex;gap:6px">
                    <button class="btn btn-sm" onclick="aptRefresh()" id="btnAptRefresh"><?= $lang === 'en' ? 'Check for updates' : 'Nach Updates suchen' ?></button>
                </div>
            </div>

            <!-- Banners -->
            <div id="updRepoBanner" style="display:none;background:rgba(220,53,69,.05);border:1px solid rgba(220,53,69,.15);border-radius:var(--radius);padding:12px 16px;margin-bottom:10px">
                <div style="display:flex;align-items:center;gap:12px;font-size:.76rem">
                    <div style="width:32px;height:32px;border-radius:8px;background:rgba(220,53,69,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </div>
                    <div style="flex:1">
                        <div style="font-weight:600;color:var(--red)"><?= $lang === 'en' ? 'Enterprise repository active without subscription' : 'Enterprise-Repository aktiv ohne Subscription' ?></div>
                        <div style="font-size:.68rem;color:var(--text3);margin-top:2px"><?= $lang === 'en' ? 'Updates will fail. We can switch to the free community repository.' : 'Updates werden fehlschlagen. Wir können auf das kostenlose Community-Repository wechseln.' ?></div>
                    </div>
                    <button class="btn btn-sm btn-accent" onclick="repoFix()" id="btnRepoFix"><?= $lang === 'en' ? 'Fix now' : 'Jetzt fixen' ?></button>
                </div>
            </div>

            <div id="updRebootBanner" style="display:none;background:rgba(255,193,7,.05);border:1px solid rgba(255,193,7,.15);border-radius:var(--radius);padding:12px 16px;margin-bottom:10px">
                <div style="display:flex;align-items:center;gap:12px;font-size:.76rem">
                    <div style="width:32px;height:32px;border-radius:8px;background:rgba(255,193,7,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--yellow)" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    </div>
                    <div>
                        <div style="font-weight:600;color:var(--yellow)"><?= $lang === 'en' ? 'Reboot required' : 'Neustart erforderlich' ?></div>
                        <div style="font-size:.68rem;color:var(--text3);margin-top:2px"><?= $lang === 'en' ? 'A system update requires a server reboot to take effect.' : 'Ein System-Update erfordert einen Neustart des Servers.' ?></div>
                    </div>
                </div>
            </div>

            <!-- Update Status -->
            <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:16px;margin-bottom:12px">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
                    <div id="updStatusIcon" style="width:40px;height:40px;border-radius:10px;background:rgba(40,167,69,.1);display:flex;align-items:center;justify-content:center">
                        <div class="spinner-small"></div>
                    </div>
                    <div style="flex:1">
                        <div id="updStatusText" style="font-size:.85rem;font-weight:600"><?= $lang === 'en' ? 'Checking...' : 'Prüfe...' ?></div>
                        <div id="updStatusSub" style="font-size:.68rem;color:var(--text3)"></div>
                    </div>
                    <button class="btn btn-sm btn-accent" onclick="aptUpgrade()" id="btnAptUpgrade" style="display:none"><?= $lang === 'en' ? 'Install updates' : 'Updates installieren' ?></button>
                </div>
                <div id="updOutput" style="display:none;background:rgba(0,0,0,.15);border-radius:var(--radius-sm);padding:10px;font-family:var(--mono);font-size:.62rem;max-height:180px;overflow-y:auto;white-space:pre-wrap;color:var(--text3)"></div>
            </div>

            <!-- Repositories -->
            <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);overflow:hidden;margin-bottom:12px">
                <div style="padding:12px 16px;border-bottom:1px solid var(--border-subtle);display:flex;align-items:center;gap:10px">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                    <span style="font-size:.78rem;font-weight:600">Repositories</span>
                    <span id="repoSubBadge" style="display:none;font-size:.56rem;padding:2px 6px;border-radius:8px;margin-left:4px"></span>
                </div>
                <div id="repoWarning" style="display:none;padding:10px 16px;background:rgba(255,193,7,.05);border-bottom:1px solid var(--border-subtle);font-size:.72rem;align-items:center;gap:8px">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--yellow)" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <span id="repoWarningText"></span>
                </div>
                <div id="repoList" style="padding:0;font-size:.72rem">
                    <div style="color:var(--text3);padding:12px 16px"><span class="spinner-small"></span> <?= __('loading') ?></div>
                </div>
            </div>

            <!-- App + Auto-Updates -->
            <div style="display:flex;gap:12px;flex-wrap:wrap">
                <!-- FloppyOps Lite -->
                <div style="flex:1;min-width:300px;background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:14px 16px">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <span style="font-size:.78rem;font-weight:600">FloppyOps Lite</span>
                    </div>
                    <div id="appUpdateInfo" style="font-size:.74rem">
                        <div style="color:var(--text3);display:flex;align-items:center;gap:6px"><div class="spinner-small"></div> <?= __('checking') ?></div>
                    </div>
                    <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border-subtle)">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.72rem">
                            <input type="checkbox" id="appAutoUpdateToggle" onchange="appAutoUpdateChanged()" style="width:14px;height:14px;accent-color:var(--accent);cursor:pointer">
                            <span style="color:var(--text2)"><?= $lang === 'en' ? 'Auto-update app' : 'App automatisch aktualisieren' ?></span>
                        </label>
                        <div id="appAutoSchedule" style="margin-top:6px;display:flex;gap:6px;align-items:center;font-size:.68rem;flex-wrap:wrap;opacity:.4;pointer-events:none">
                            <select id="appAutoDay" onchange="appAutoUpdateChanged()" style="background:var(--surface-solid);border:1px solid var(--border-subtle);border-radius:4px;padding:2px 5px;font-size:.66rem;color:var(--text)">
                                <option value="0"><?= $lang === 'en' ? 'Daily' : 'Täglich' ?></option>
                                <?php foreach (['Mo','Di','Mi','Do','Fr','Sa','So'] as $i => $d): ?><option value="<?= $i+1 ?>"><?= $d ?></option><?php endforeach; ?>
                            </select>
                            <select id="appAutoHour" onchange="appAutoUpdateChanged()" style="background:var(--surface-solid);border:1px solid var(--border-subtle);border-radius:4px;padding:2px 5px;font-size:.66rem;color:var(--text)">
                                <?php for ($h = 0; $h < 24; $h++): ?><option value="<?= $h ?>"<?= $h === 4 ? ' selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option><?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- System Auto-Update -->
                <div style="flex:1;min-width:300px;background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:14px 16px">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><path d="M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48l2.83-2.83"/></svg>
                        <span style="font-size:.78rem;font-weight:600"><?= __('system_auto_update') ?></span>
                        <span id="autoUpdateStatus" style="font-size:.58rem;color:var(--text3);margin-left:auto"></span>
                    </div>
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:.76rem;padding:4px 0">
                        <input type="checkbox" id="autoUpdateToggle" onchange="autoUpdateChanged()" style="width:16px;height:16px;accent-color:var(--accent);cursor:pointer">
                        <span style="color:var(--text2)"><?= $lang === 'en' ? 'Automatic system updates (apt dist-upgrade)' : 'Automatische System-Updates (apt dist-upgrade)' ?></span>
                    </label>
                    <div id="autoUpdateSchedule" style="margin-top:8px;display:flex;gap:8px;align-items:center;font-size:.72rem;flex-wrap:wrap;opacity:.4;pointer-events:none">
                        <select id="autoUpdateDay" onchange="autoUpdateChanged()" style="background:var(--surface-solid);border:1px solid var(--border-subtle);border-radius:4px;padding:3px 6px;font-size:.68rem;color:var(--text)">
                            <option value="0"><?= $lang === 'en' ? 'Daily' : 'Täglich' ?></option>
                            <?php foreach (['Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag','Sonntag'] as $i => $d): ?><option value="<?= $i+1 ?>"><?= $d ?></option><?php endforeach; ?>
                        </select>
                        <span style="color:var(--text3)"><?= $lang === 'en' ? 'at' : 'um' ?></span>
                        <select id="autoUpdateHour" onchange="autoUpdateChanged()" style="background:var(--surface-solid);border:1px solid var(--border-subtle);border-radius:4px;padding:3px 6px;font-size:.68rem;color:var(--text)">
                            <?php for ($h = 0; $h < 24; $h++): ?><option value="<?= $h ?>"<?= $h === 3 ? ' selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option><?php endfor; ?>
                        </select>
                        <span id="autoUpdateTz" style="font-size:.6rem;color:var(--text3)"></span>
                    </div>
                </div>
            </div>
            </div>
        </div><!-- /panel-system -->

        <!-- Help -->
        <div class="tab-panel" id="panel-help">
            <div style="max-width:800px;margin:0 auto">
            <div style="text-align:center;margin-bottom:24px">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="1.5" style="margin-bottom:8px"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <div style="font-size:1.1rem;font-weight:700;color:var(--text)"><?= __('help_title') ?></div>
                <div style="font-size:.75rem;color:var(--text3);margin-top:4px">FloppyOps Lite v<?= APP_VERSION ?></div>
            </div>

            <!-- Search -->
            <div style="margin-bottom:20px;position:relative">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--text3)" stroke-width="2" style="position:absolute;left:12px;top:50%;transform:translateY(-50%)"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input class="form-input" id="helpSearch" placeholder="<?= __('help_search') ?>" oninput="filterHelp(this.value)" style="padding-left:36px">
            </div>
            <div id="helpNoResults" style="display:none;text-align:center;color:var(--text3);padding:24px;font-size:.82rem"><?= __('help_no_results') ?></div>

            <div id="helpSections">
            <?php
            $helpSections = $lang === 'de' ? [
                ['id' => 'h-dashboard', 'icon' => '📊', 'title' => 'Dashboard', 'content' => '
                    <p>Das Dashboard zeigt eine Echtzeit-Übersicht deines Servers:</p>
                    <ul>
                        <li><strong>CPU-Auslastung</strong> — Aktuelle Prozessorlast in Prozent mit Live-Graph</li>
                        <li><strong>RAM-Verbrauch</strong> — Genutzter/Gesamter Arbeitsspeicher</li>
                        <li><strong>Disk-Auslastung</strong> — Speicherplatz pro Partition</li>
                        <li><strong>Netzwerk-Traffic</strong> — Ein-/Ausgehender Traffic pro Sekunde</li>
                        <li><strong>Uptime</strong> — Wie lange der Server läuft</li>
                        <li><strong>Load Average</strong> — Systemlast (1/5/15 Minuten)</li>
                    </ul>
                    <p>Die Statistiken werden automatisch alle paar Sekunden aktualisiert. Die Stat-Cards oben zeigen Zusammenfassungen für VMs/CTs, Fail2ban Jails, Nginx Sites und offene Ports.</p>
                '],
                ['id' => 'h-vms', 'icon' => '🖥️', 'title' => 'VMs & Container', 'content' => '
                    <p>Verwaltung aller virtuellen Maschinen und LXC-Container auf diesem PVE-Host.</p>
                    <h4>Funktionen:</h4>
                    <ul>
                        <li><strong>Clone</strong> — Erstellt eine Kopie einer bestehenden VM/CT mit anpassbarer Hardware (CPU, RAM, Disk, Netzwerk)</li>
                        <li><strong>Hardware anpassen</strong> — CPU-Kerne, RAM und Swap direkt ändern (erfordert Neustart)</li>
                        <li><strong>Start/Stop/Reboot</strong> — VMs und Container steuern</li>
                        <li><strong>Netzwerk</strong> — Bridge, VLAN, IP-Konfiguration beim Klonen anpassen</li>
                    </ul>
                    <h4>Hinweise:</h4>
                    <ul>
                        <li>Hardware-Änderungen an laufenden VMs erfordern einen Neustart</li>
                        <li>Beim Klonen wird die nächste freie VMID automatisch vergeben</li>
                        <li>Full Clone erstellt eine unabhängige Kopie, Linked Clone teilt die Basis-Disk</li>
                    </ul>
                '],
                ['id' => 'h-firewall', 'icon' => '🛡️', 'title' => 'Firewall Templates', 'content' => '
                    <p>Vordefinierte Regelsätze für typische Server-Rollen, die per Klick auf VMs/CTs angewendet werden. 18 eingebaute Templates (Mailcow, Webserver, Database, Proxmox, Docker, DNS, WireGuard, Virtualmin Web, Virtualmin Web+Mail, Nginx Proxy, PostgreSQL, Redis, Elasticsearch, Minecraft, TeamSpeak, Nextcloud, Gitea/GitLab, Monitoring) + eigene Custom Templates.</p>
                    <h4>Zwei Firewall-Ebenen:</h4>
                    <ul>
                        <li><strong>PVE Host-Firewall</strong> (Security Check) — Schützt den Proxmox-Host selbst. Regelt welche Ports von außen erreichbar sind (SSH, WebUI, etc.).</li>
                        <li><strong>VM/CT Firewall</strong> (Templates) — Schützt einzelne VMs und Container. Jede Maschine bekommt eigene Regeln passend zu ihrer Rolle.</li>
                    </ul>
                    <h4>Wann welche Firewall?</h4>
                    <ul>
                        <li><strong>CTs mit öffentlicher IP</strong> (gelber Punkt in der IP-Spalte) — Erhalten direkten Traffic aus dem Internet. Die CT-Firewall filtert eingehende Verbindungen direkt am Container.</li>
                        <li><strong>CTs mit interner IP</strong> (grauer Punkt) — Sind typischerweise hinter Nginx. Die CT-Firewall schützt gegen laterale Angriffe (CT-zu-CT Bewegung im internen Netz).</li>
                        <li><strong>Nginx-proxied CTs</strong> — Brauchen keine zusätzlichen PVE-Host-Regeln für Port 80/443, da diese bereits auf dem Host offen sind. Die CT-Firewall sollte trotzdem die erlaubten Ports auf das Minimum beschränken.</li>
                    </ul>
                    <h4>Templates anwenden:</h4>
                    <ol>
                        <li>Template-Card anklicken (z.B. "Mailserver", "Webserver")</li>
                        <li>Im Modal: Regeln prüfen — <strong>Ports und Sources sind editierbar!</strong></li>
                        <li>Einzelne Regeln per Checkbox an-/abwählen</li>
                        <li>Ziel-VM/CT aus dem Dropdown wählen</li>
                        <li>"Anwenden" klicken — Firewall wird aktiviert, Policy auf DROP gesetzt</li>
                    </ol>
                    <h4>Duplikat-Schutz:</h4>
                    <p>Bereits vorhandene Regeln werden automatisch erkannt und nicht doppelt angelegt. Wenn ein Template erneut auf eine VM/CT angewendet wird, werden nur fehlende Regeln ergänzt.</p>
                    <h4>Custom Templates:</h4>
                    <p>Über "Eigenes Template" können eigene Regelsätze erstellt, gespeichert und wiederverwendet werden.</p>
                    <h4>VM/CT Firewall-Tabelle:</h4>
                    <p>Zeigt den Firewall-Status aller VMs/CTs: Aktiv/Inaktiv, Policy, Anzahl Regeln, zugewiesenes Template. Per "ON/OFF" Button kann die Firewall pro Maschine ein-/ausgeschaltet werden.</p>
                    <h4>IP-Spalte:</h4>
                    <p>Die VM/CT-Tabelle zeigt die IP-Adresse jeder Maschine mit farbigem Punkt: <strong>Gelb</strong> = öffentliche IP (direkt aus dem Internet erreichbar), <strong>Grau</strong> = interne IP (nur im lokalen Netz). So siehst du sofort welche Maschinen besonders geschützt werden müssen.</p>
                    <h4>Wichtig:</h4>
                    <ul>
                        <li>Policy DROP bedeutet: Alles ist blockiert, nur explizit erlaubte Ports sind erreichbar</li>
                        <li>SSH (Port 22) ist in allen Templates enthalten — Zugriff bleibt gewährleistet</li>
                        <li>Templates gelten nur für VMs/CTs, nicht für den PVE-Host selbst (dafür: Security Check)</li>
                    </ul>
                '],
                ['id' => 'h-security', 'icon' => '⚠️', 'title' => 'Security Check', 'content' => '
                    <p>Scannt offene Ports auf dem Host und zeigt Sicherheitsrisiken.</p>
                    <h4>Port-Scan:</h4>
                    <ul>
                        <li>Zeigt alle offenen TCP-Ports mit Prozess, Adresse und Risikobewertung</li>
                        <li>Riskante Ports (Redis, MongoDB, MySQL von außen) werden rot markiert</li>
                        <li>Per "Blockieren" Button kann ein Port sofort per Firewall-Regel gesperrt werden</li>
                    </ul>
                    <h4>PVE Host-Firewall:</h4>
                    <ul>
                        <li><strong>Datacenter-Level</strong> — Hauptschalter für die PVE Firewall (muss aktiv sein)</li>
                        <li><strong>Node-Level</strong> — Firewall auf diesem spezifischen Host</li>
                        <li>Beim Aktivieren werden automatisch SSH (22) und PVE WebUI (8006) erlaubt</li>
                    </ul>
                    <h4>Firewall-Regeln:</h4>
                    <p>Node- und Cluster-Regeln werden gemeinsam angezeigt. Neue Regeln können mit Action (ACCEPT/DROP/REJECT), Richtung, Port, Source und Kommentar erstellt werden.</p>
                    <h4>Standard-Regeln:</h4>
                    <p>Ein vordefinierter Satz empfohlener Regeln: SSH, PVE WebUI und SPICE erlauben, riskante Dienste blockieren.</p>
                    <h4>IPv6 & NDP Proxy:</h4>
                    <ul>
                        <li>Prüft ob IPv4/IPv6-Forwarding und NDP Proxy aktiviert sind</li>
                        <li><strong>1-Klick Fix</strong> — aktiviert fehlende Einstellungen permanent (sysctl.conf)</li>
                        <li>NDP Proxy wird benötigt wenn IPv6-Adressen über verschiedene Bridges geroutet werden</li>
                    </ul>
                '],
                ['id' => 'h-fail2ban', 'icon' => '🔒', 'title' => 'Fail2ban', 'content' => '
                    <p>Überwacht und verwaltet Fail2ban Jails — automatischer Schutz gegen Brute-Force-Angriffe.</p>
                    <h4>Jail-Übersicht:</h4>
                    <ul>
                        <li>Zeigt alle aktiven Jails mit Anzahl gebannter IPs und fehlgeschlagener Versuche</li>
                        <li>Gebannte IPs werden aufgelistet und können per Klick entbannt werden</li>
                    </ul>
                    <h4>Config-Editor:</h4>
                    <p>Die jail.local und Filter-Konfigurationen können direkt im Browser bearbeitet werden. Nach dem Speichern wird Fail2ban automatisch neu gestartet.</p>
                    <h4>Ban-Log:</h4>
                    <p>Zeigt die letzten Einträge aus dem Fail2ban-Log mit Zeitstempel, Jail und IP-Adresse.</p>
                '],
                ['id' => 'h-nginx', 'icon' => '🌐', 'title' => 'Nginx Reverse Proxy', 'content' => '
                    <p>Verwaltet Nginx als Reverse Proxy für interne Container und VMs.</p>
                    <h4>Neue Site anlegen:</h4>
                    <ol>
                        <li>Domain und E-Mail eingeben</li>
                        <li>Ziel wählen — CT/VM aus der Liste (IPs werden automatisch erkannt) oder manuell eingeben</li>
                        <li>SSL-Challenge wählen: <strong>HTTP-01</strong> (Standard, Port 80) oder <strong>DNS-01</strong> (Cloudflare, Hetzner DNS, IPv64)</li>
                        <li>"Erstellen" — Fortschritts-Modal zeigt jeden Schritt live an</li>
                    </ol>
                    <h4>DNS-01 Challenge:</h4>
                    <p>Wenn Port 80 nicht verfügbar ist (z.B. hinter Cloudflare Proxy), kann SSL über DNS-01 Challenge beantragt werden. Unterstützte Provider:</p>
                    <ul>
                        <li><strong>Cloudflare</strong> — API Token eingeben (certbot-dns-cloudflare)</li>
                        <li><strong>Hetzner DNS</strong> — API Token eingeben (certbot-dns-hetzner)</li>
                        <li><strong>IPv64.net</strong> — Account API Key eingeben (certbot manual hooks)</li>
                    </ul>
                    <h4>SSL-Erneuerung:</h4>
                    <p>Per "Renew" Button kann das Zertifikat jeder Site erneuert werden — mit Live-Fortschrittsanzeige. Beim Löschen einer Site wird das zugehörige Zertifikat automatisch mit entfernt.</p>
                    <h4>SSL Health Check:</h4>
                    <p>Der SSL Health Check prüft alle konfigurierten Nginx-Sites automatisch auf häufige Probleme:</p>
                    <ul>
                        <li><strong>DNS A-Record</strong> — Prüft ob ein IPv4-DNS-Eintrag auf die IP dieses Servers zeigt. Ohne korrekten A-Record kann kein SSL-Zertifikat ausgestellt werden.</li>
                        <li><strong>DNS AAAA-Record</strong> — Prüft ob ein IPv6-DNS-Eintrag existiert und auf diesen Server zeigt. Optional, aber wichtig für IPv6-Erreichbarkeit.</li>
                        <li><strong>SSL-Zertifikat</strong> — Ist ein gültiges Zertifikat vorhanden? Wann läuft es ab? Abgelaufene oder fehlende Zertifikate werden rot markiert.</li>
                        <li><strong>Cert-Match</strong> — Stimmt das ausgelieferte Zertifikat mit der Domain überein? Ein Mismatch tritt auf wenn z.B. ein anderes Zertifikat geladen wird.</li>
                        <li><strong>IPv4/IPv6 Konsistenz</strong> — Werden über IPv4 und IPv6 die gleichen Zertifikate ausgeliefert? Unterschiedliche Zertifikate deuten auf eine Fehlkonfiguration hin.</li>
                    </ul>
                    <h4>ipv6only=on Problem:</h4>
                    <p>Wenn mehrere Nginx-Sites auf Port 443 lauschen, kann es zu einem Konflikt kommen: Nur ein <code>server</code>-Block darf <code>ipv6only=on</code> in der <code>listen [::]:443</code> Direktive haben. Fehlt diese Einstellung oder ist sie bei mehreren Sites gesetzt, liefert Nginx über IPv6 möglicherweise das falsche Zertifikat aus. Der SSL Health Check erkennt dieses Problem und bietet einen <strong>1-Klick Fix</strong> an, der die Nginx-Konfiguration automatisch korrigiert.</p>
                    <h4>System-Checks:</h4>
                    <p>Prüft Voraussetzungen: IP-Forwarding, NAT/Masquerading, interne Bridge, Nginx-Status, Certbot.</p>
                    <h4>Cloudflare Proxy Support:</h4>
                    <p>Wenn du Cloudflare als DNS-Proxy (orange Wolke) nutzt, sieht Nginx nur Cloudflare\'s IP statt der echten Client-IP. Das führt zu Problemen mit IP-Whitelists und Logs.</p>
                    <p><strong>Lösung:</strong> Bei der Installation fragt das Setup-Script ob du Cloudflare nutzt. Wenn ja, wird automatisch eine Config erstellt (<code>/etc/nginx/conf.d/cloudflare-realip.conf</code>) die Nginx anweist, die echte IP aus dem <code>CF-Connecting-IP</code> Header zu lesen.</p>
                    <h4>Was das bedeutet:</h4>
                    <ul>
                        <li><strong>IP-Whitelists</strong> in Nginx funktionieren korrekt (deine echte IP wird erkannt)</li>
                        <li><strong>Logs</strong> zeigen die echte Client-IP statt Cloudflare\'s IP</li>
                        <li><strong>Fail2ban</strong> bannt die richtige IP, nicht Cloudflare</li>
                    </ul>
                    <h4>Welche Domains proxied (orange)?</h4>
                    <ul>
                        <li><strong>Ja:</strong> Webseiten (HTTP/HTTPS) — DDoS-Schutz, IP versteckt</li>
                        <li><strong>Nein:</strong> Mail (SMTP/IMAP), WireGuard (UDP), PVE WebUI (Port 8006) — diese Protokolle laufen nicht über CF Proxy</li>
                    </ul>
                    <p><strong>Tipp:</strong> Die Cloudflare IP-Ranges ändern sich selten, sollten aber ~1x jährlich aktualisiert werden. Aktuelle Listen: <code>cloudflare.com/ips-v4</code> und <code>cloudflare.com/ips-v6</code></p>
                '],
                ['id' => 'h-zfs', 'icon' => '💾', 'title' => 'ZFS Storage', 'content' => '
                    <p>Verwaltung von ZFS-Pools, Datasets und Snapshots.</p>
                    <h4>Pools & Datasets:</h4>
                    <ul>
                        <li>Zeigt alle ZFS-Pools mit Größe, Belegung und Health-Status</li>
                        <li>Datasets mit Quota, Kompression und Mountpoint</li>
                    </ul>
                    <h4>Snapshots:</h4>
                    <ul>
                        <li><strong>Erstellen</strong> — Manueller Snapshot eines Datasets</li>
                        <li><strong>Rollback</strong> — Dataset auf einen früheren Snapshot zurücksetzen</li>
                        <li><strong>Clone</strong> — Neuen CT aus einem Snapshot erstellen (CPU, RAM, Swap, Netzwerk IPv4/IPv6 konfigurierbar)</li>
                        <li><strong>Löschen</strong> — Alte Snapshots entfernen</li>
                    </ul>
                    <h4>Auto-Snapshots:</h4>
                    <p>Automatische Snapshots mit konfigurierbarer Retention (alle 15 Min, stündlich, täglich, wöchentlich, monatlich). Der Zeitraum wird live berechnet wenn die Retention geändert wird.</p>
                    <h4>Hinweise:</h4>
                    <ul>
                        <li>Rollback löscht alle neueren Snapshots!</li>
                        <li>ZFS Kompression (lz4) spart Speicherplatz ohne Performance-Einbußen</li>
                        <li>Snapshots werden nach CT/VM gruppiert angezeigt, die 5 neuesten sind direkt sichtbar</li>
                    </ul>
                '],
                ['id' => 'h-wireguard', 'icon' => '🔐', 'title' => 'WireGuard VPN', 'content' => '
                    <p>Einrichtung und Verwaltung von WireGuard VPN-Tunneln.</p>
                    <h4>Tunnel-Wizard:</h4>
                    <ol>
                        <li>Interface-Name und Port wählen</li>
                        <li>Subnetz konfigurieren (z.B. 10.10.20.0/24)</li>
                        <li>Peer hinzufügen (Remote-Seite)</li>
                        <li>Config wird automatisch generiert — Remote-Config zum Kopieren</li>
                    </ol>
                    <h4>Live-Traffic:</h4>
                    <p>Echtzeit-Graph zeigt ein-/ausgehenden Traffic pro Tunnel mit RX/TX Werten.</p>
                    <h4>Config-Editor:</h4>
                    <p>WireGuard-Konfigurationen können direkt bearbeitet werden. Firewall-Regeln für den WireGuard-Port werden automatisch vorgeschlagen.</p>
                    <h4>Typische Einsatzszenarien:</h4>
                    <ul>
                        <li>Sichere Verbindung zwischen Büro/Homeoffice und Dedicated Server</li>
                        <li>PVE WebUI ohne öffentlichen Port 8006 erreichbar machen</li>
                        <li>Site-to-Site VPN zwischen Standorten</li>
                        <li>Backup-Traffic (PBS, ZFS Replikation) verschlüsseln</li>
                    </ul>
                '],
                ['id' => 'h-updates', 'icon' => '🔄', 'title' => 'System-Updates', 'content' => '
                    <p>Verwaltung von Paket-Updates und der FloppyOps Lite App selbst.</p>
                    <h4>System-Updates:</h4>
                    <ul>
                        <li>Zeigt verfügbare Paket-Updates mit Version und Repository</li>
                        <li>"Alle installieren" führt apt update + upgrade durch</li>
                        <li>Einzelne Pakete können ausgewählt werden</li>
                    </ul>
                    <h4>Repositories:</h4>
                    <p>Zeigt konfigurierte APT-Repositories (PVE Enterprise, No-Subscription, Ceph etc.).</p>
                    <h4>App-Update:</h4>
                    <p>FloppyOps Lite kann sich selbst aktualisieren. Zeigt aktuelle und verfügbare Version.</p>
                    <h4>Auto-Update:</h4>
                    <p>Optionales automatisches Update zu einem konfigurierbaren Zeitpunkt (Tag + Uhrzeit).</p>
                '],
                ['id' => 'h-navigation', 'icon' => '📑', 'title' => 'Navigation & Aufbau', 'content' => '
                    <p>Die Navigation ist in 6 Gruppen-Tabs organisiert, um zusammengehörige Funktionen übersichtlich zu bündeln:</p>
                    <h4>Tab-Gruppen:</h4>
                    <ul>
                        <li><strong>Dashboard</strong> — Server-Übersicht mit Live-Charts und Stat-Cards</li>
                        <li><strong>VMs/CTs</strong> — Alle virtuellen Maschinen und Container mit IP-Anzeige und Template-Zuweisung</li>
                        <li><strong>Security</strong> — Enthält: Firewall Templates, Security Check (Port-Scanner + PVE Host-Firewall) und Fail2ban</li>
                        <li><strong>Network</strong> — Enthält: Nginx Reverse Proxy (mit SSL Health Check) und WireGuard VPN</li>
                        <li><strong>System</strong> — Enthält: ZFS Storage und System-Updates/Repositories</li>
                        <li><strong>Help</strong> — Diese Hilfe-Seite mit Suchfunktion</li>
                    </ul>
                    <h4>Allgemein:</h4>
                    <ul>
                        <li>Der aktive Tab bleibt nach einem Seitenreload erhalten (URL-Hash)</li>
                        <li>Ladeanimationen (Spinner) zeigen an wenn Daten geladen werden</li>
                        <li>Sprache (DE/EN) kann jederzeit über den Button in der Topbar gewechselt werden</li>
                        <li>Die Template-Zuweisung einer VM/CT ist sowohl auf den Firewall-Template-Cards als auch in der VM/CT-Tabelle sichtbar</li>
                    </ul>
                '],
            ] : [
                ['id' => 'h-dashboard', 'icon' => '📊', 'title' => 'Dashboard', 'content' => '
                    <p>The dashboard shows a real-time overview of your server:</p>
                    <ul>
                        <li><strong>CPU Usage</strong> — Current processor load in percent with live graph</li>
                        <li><strong>RAM Usage</strong> — Used/Total memory</li>
                        <li><strong>Disk Usage</strong> — Storage per partition</li>
                        <li><strong>Network Traffic</strong> — In/outbound traffic per second</li>
                        <li><strong>Uptime</strong> — How long the server has been running</li>
                        <li><strong>Load Average</strong> — System load (1/5/15 minutes)</li>
                    </ul>
                    <p>Statistics refresh automatically every few seconds. The stat cards at the top show summaries for VMs/CTs, Fail2ban jails, Nginx sites and open ports.</p>
                '],
                ['id' => 'h-vms', 'icon' => '🖥️', 'title' => 'VMs & Containers', 'content' => '
                    <p>Management of all virtual machines and LXC containers on this PVE host.</p>
                    <h4>Features:</h4>
                    <ul>
                        <li><strong>Clone</strong> — Create a copy of an existing VM/CT with customizable hardware (CPU, RAM, Disk, Network)</li>
                        <li><strong>Hardware Adjust</strong> — Change CPU cores, RAM and swap directly (requires restart)</li>
                        <li><strong>Start/Stop/Reboot</strong> — Control VMs and containers</li>
                        <li><strong>Network</strong> — Configure bridge, VLAN, IP when cloning</li>
                    </ul>
                    <h4>Notes:</h4>
                    <ul>
                        <li>Hardware changes on running VMs require a restart</li>
                        <li>When cloning, the next free VMID is assigned automatically</li>
                        <li>Full Clone creates an independent copy, Linked Clone shares the base disk</li>
                    </ul>
                '],
                ['id' => 'h-firewall', 'icon' => '🛡️', 'title' => 'Firewall Templates', 'content' => '
                    <p>Predefined rule sets for common server roles that can be applied to VMs/CTs with one click. 18 built-in templates (Mailcow, Webserver, Database, Proxmox, Docker, DNS, WireGuard, Virtualmin Web, Virtualmin Web+Mail, Nginx Proxy, PostgreSQL, Redis, Elasticsearch, Minecraft, TeamSpeak, Nextcloud, Gitea/GitLab, Monitoring) plus custom templates.</p>
                    <h4>Two Firewall Levels:</h4>
                    <ul>
                        <li><strong>PVE Host Firewall</strong> (Security Check) — Protects the Proxmox host itself. Controls which ports are reachable from the internet (SSH, WebUI, etc.).</li>
                        <li><strong>VM/CT Firewall</strong> (Templates) — Protects individual VMs and containers. Each machine gets its own rules matching its role.</li>
                    </ul>
                    <h4>When to Use Which Firewall?</h4>
                    <ul>
                        <li><strong>CTs with public IP</strong> (yellow dot in the IP column) — Receive direct traffic from the internet. The CT firewall filters incoming connections directly at the container.</li>
                        <li><strong>CTs with internal IP</strong> (gray dot) — Typically sit behind Nginx. The CT firewall protects against lateral movement (CT-to-CT traffic within the internal network).</li>
                        <li><strong>Nginx-proxied CTs</strong> — Do not need additional PVE host rules for ports 80/443, as those are already open on the host. However, the CT firewall should still restrict allowed ports to a minimum.</li>
                    </ul>
                    <h4>Applying Templates:</h4>
                    <ol>
                        <li>Click a template card (e.g. "Mailserver", "Webserver")</li>
                        <li>In the modal: Review rules — <strong>ports and sources are editable!</strong></li>
                        <li>Enable/disable individual rules via checkboxes</li>
                        <li>Select target VM/CT from dropdown</li>
                        <li>Click "Apply" — Firewall is enabled, policy set to DROP</li>
                    </ol>
                    <h4>Duplicate Protection:</h4>
                    <p>Existing rules are automatically detected and not created twice. When re-applying a template to a VM/CT, only missing rules are added.</p>
                    <h4>Custom Templates:</h4>
                    <p>Create your own rule sets via "Custom Template", save and reuse them.</p>
                    <h4>VM/CT Firewall Table:</h4>
                    <p>Shows firewall status of all VMs/CTs: Active/Inactive, policy, rule count, assigned template. Toggle firewall per machine with ON/OFF button.</p>
                    <h4>IP Column:</h4>
                    <p>The VM/CT table shows each machine\'s IP address with a colored dot: <strong>Yellow</strong> = public IP (directly reachable from the internet), <strong>Gray</strong> = internal IP (local network only). This helps you immediately see which machines need the most protection.</p>
                    <h4>Important:</h4>
                    <ul>
                        <li>Policy DROP means: Everything is blocked, only explicitly allowed ports are reachable</li>
                        <li>SSH (port 22) is included in all templates — access is always maintained</li>
                        <li>Templates only apply to VMs/CTs, not the PVE host itself (for that: Security Check)</li>
                    </ul>
                '],
                ['id' => 'h-security', 'icon' => '⚠️', 'title' => 'Security Check', 'content' => '
                    <p>Scans open ports on the host and highlights security risks.</p>
                    <h4>Port Scan:</h4>
                    <ul>
                        <li>Shows all open TCP ports with process, address and risk assessment</li>
                        <li>Risky ports (Redis, MongoDB, external MySQL) are highlighted in red</li>
                        <li>The "Block" button instantly creates a firewall DROP rule</li>
                    </ul>
                    <h4>PVE Host Firewall:</h4>
                    <ul>
                        <li><strong>Datacenter Level</strong> — Main switch for the PVE Firewall (must be active)</li>
                        <li><strong>Node Level</strong> — Firewall on this specific host</li>
                        <li>When enabling, SSH (22) and PVE WebUI (8006) are automatically allowed</li>
                    </ul>
                    <h4>Firewall Rules:</h4>
                    <p>Node and cluster rules are shown together. New rules can be created with action (ACCEPT/DROP/REJECT), direction, port, source and comment.</p>
                    <h4>Default Rules:</h4>
                    <p>A predefined set of recommended rules: Allow SSH, PVE WebUI and SPICE, block risky services.</p>
                    <h4>IPv6 & NDP Proxy:</h4>
                    <ul>
                        <li>Checks if IPv4/IPv6 forwarding and NDP proxy are enabled</li>
                        <li><strong>1-click fix</strong> — enables missing settings permanently (sysctl.conf)</li>
                        <li>NDP proxy is required when routing IPv6 addresses across different bridges</li>
                    </ul>
                '],
                ['id' => 'h-fail2ban', 'icon' => '🔒', 'title' => 'Fail2ban', 'content' => '
                    <p>Monitors and manages Fail2ban jails — automatic protection against brute-force attacks.</p>
                    <h4>Jail Overview:</h4>
                    <ul>
                        <li>Shows all active jails with banned IP count and failed attempts</li>
                        <li>Banned IPs are listed and can be unbanned with one click</li>
                    </ul>
                    <h4>Config Editor:</h4>
                    <p>Edit jail.local and filter configurations directly in the browser. Fail2ban is automatically restarted after saving.</p>
                    <h4>Ban Log:</h4>
                    <p>Shows recent entries from the Fail2ban log with timestamp, jail and IP address.</p>
                '],
                ['id' => 'h-nginx', 'icon' => '🌐', 'title' => 'Nginx Reverse Proxy', 'content' => '
                    <p>Manages Nginx as a reverse proxy for internal containers and VMs.</p>
                    <h4>Create New Site:</h4>
                    <ol>
                        <li>Enter domain and email</li>
                        <li>Select target — pick CT/VM from the list (IPs auto-detected) or enter manually</li>
                        <li>Choose SSL challenge: <strong>HTTP-01</strong> (default, port 80) or <strong>DNS-01</strong> (Cloudflare, Hetzner DNS, IPv64)</li>
                        <li>"Create" — progress modal shows each step live</li>
                    </ol>
                    <h4>DNS-01 Challenge:</h4>
                    <p>When port 80 is not available (e.g. behind Cloudflare Proxy), SSL can be requested via DNS-01 challenge. Supported providers:</p>
                    <ul>
                        <li><strong>Cloudflare</strong> — enter API Token (certbot-dns-cloudflare)</li>
                        <li><strong>Hetzner DNS</strong> — enter API Token (certbot-dns-hetzner)</li>
                        <li><strong>IPv64.net</strong> — enter Account API Key (certbot manual hooks)</li>
                    </ul>
                    <h4>SSL Renewal:</h4>
                    <p>The "Renew" button per site triggers certificate renewal with a live progress log. When deleting a site, the associated certificate is automatically removed.</p>
                    <h4>SSL Health Check:</h4>
                    <p>The SSL Health Check automatically inspects all configured Nginx sites for common issues:</p>
                    <ul>
                        <li><strong>DNS A Record</strong> — Checks if an IPv4 DNS entry points to this server\'s IP. Without a correct A record, no SSL certificate can be issued.</li>
                        <li><strong>DNS AAAA Record</strong> — Checks if an IPv6 DNS entry exists and points to this server. Optional but important for IPv6 reachability.</li>
                        <li><strong>SSL Certificate</strong> — Is a valid certificate present? When does it expire? Expired or missing certificates are highlighted in red.</li>
                        <li><strong>Cert Match</strong> — Does the served certificate match the domain? A mismatch occurs when e.g. a different certificate is loaded.</li>
                        <li><strong>IPv4/IPv6 Consistency</strong> — Are the same certificates served over IPv4 and IPv6? Different certificates indicate a misconfiguration.</li>
                    </ul>
                    <h4>ipv6only=on Issue:</h4>
                    <p>When multiple Nginx sites listen on port 443, a conflict can occur: only one <code>server</code> block may have <code>ipv6only=on</code> in its <code>listen [::]:443</code> directive. If this setting is missing or set on multiple sites, Nginx may serve the wrong certificate over IPv6. The SSL Health Check detects this issue and offers a <strong>1-click fix</strong> that automatically corrects the Nginx configuration.</p>
                    <h4>System Checks:</h4>
                    <p>Verifies prerequisites: IP forwarding, NAT/masquerading, internal bridge, Nginx status, Certbot.</p>
                    <h4>Cloudflare Proxy Support:</h4>
                    <p>When using Cloudflare as DNS proxy (orange cloud), Nginx only sees Cloudflare\'s IP instead of the real client IP. This breaks IP whitelists and logs.</p>
                    <p><strong>Solution:</strong> During installation, the setup script asks if you use Cloudflare. If yes, it automatically creates a config (<code>/etc/nginx/conf.d/cloudflare-realip.conf</code>) that tells Nginx to read the real IP from the <code>CF-Connecting-IP</code> header.</p>
                    <h4>What this means:</h4>
                    <ul>
                        <li><strong>IP whitelists</strong> in Nginx work correctly (your real IP is recognized)</li>
                        <li><strong>Logs</strong> show the real client IP instead of Cloudflare\'s IP</li>
                        <li><strong>Fail2ban</strong> bans the correct IP, not Cloudflare</li>
                    </ul>
                    <h4>Which domains to proxy (orange)?</h4>
                    <ul>
                        <li><strong>Yes:</strong> Websites (HTTP/HTTPS) — DDoS protection, IP hidden</li>
                        <li><strong>No:</strong> Mail (SMTP/IMAP), WireGuard (UDP), PVE WebUI (port 8006) — these protocols don\'t work through CF Proxy</li>
                    </ul>
                    <p><strong>Tip:</strong> Cloudflare IP ranges rarely change but should be updated ~once a year. Current lists: <code>cloudflare.com/ips-v4</code> and <code>cloudflare.com/ips-v6</code></p>
                '],
                ['id' => 'h-zfs', 'icon' => '💾', 'title' => 'ZFS Storage', 'content' => '
                    <p>Management of ZFS pools, datasets and snapshots.</p>
                    <h4>Pools & Datasets:</h4>
                    <ul>
                        <li>Shows all ZFS pools with size, usage and health status</li>
                        <li>Datasets with quota, compression and mountpoint</li>
                    </ul>
                    <h4>Snapshots:</h4>
                    <ul>
                        <li><strong>Create</strong> — Manual snapshot of a dataset</li>
                        <li><strong>Rollback</strong> — Revert dataset to a previous snapshot</li>
                        <li><strong>Clone</strong> — Create new CT from a snapshot (CPU, RAM, Swap, IPv4/IPv6 network configurable)</li>
                        <li><strong>Delete</strong> — Remove old snapshots</li>
                    </ul>
                    <h4>Auto-Snapshots:</h4>
                    <p>Automatic snapshots with configurable retention (every 15 min, hourly, daily, weekly, monthly). The coverage period updates live when retention is changed.</p>
                    <h4>Notes:</h4>
                    <ul>
                        <li>Rollback deletes all newer snapshots!</li>
                        <li>ZFS compression (lz4) saves storage without performance penalty</li>
                        <li>Snapshots are grouped by CT/VM, most recent 5 shown directly</li>
                    </ul>
                '],
                ['id' => 'h-wireguard', 'icon' => '🔐', 'title' => 'WireGuard VPN', 'content' => '
                    <p>Setup and management of WireGuard VPN tunnels.</p>
                    <h4>Tunnel Wizard:</h4>
                    <ol>
                        <li>Choose interface name and port</li>
                        <li>Configure subnet (e.g. 10.10.20.0/24)</li>
                        <li>Add peer (remote side)</li>
                        <li>Config is automatically generated — remote config ready to copy</li>
                    </ol>
                    <h4>Live Traffic:</h4>
                    <p>Real-time graph shows in/outbound traffic per tunnel with RX/TX values.</p>
                    <h4>Config Editor:</h4>
                    <p>WireGuard configurations can be edited directly. Firewall rules for the WireGuard port are automatically suggested.</p>
                    <h4>Common Use Cases:</h4>
                    <ul>
                        <li>Secure connection between office/home and dedicated server</li>
                        <li>Access PVE WebUI without public port 8006</li>
                        <li>Site-to-site VPN between locations</li>
                        <li>Encrypt backup traffic (PBS, ZFS replication)</li>
                    </ul>
                '],
                ['id' => 'h-updates', 'icon' => '🔄', 'title' => 'System Updates', 'content' => '
                    <p>Management of package updates and the FloppyOps Lite app itself.</p>
                    <h4>System Updates:</h4>
                    <ul>
                        <li>Shows available package updates with version and repository</li>
                        <li>"Install all" runs apt update + upgrade</li>
                        <li>Individual packages can be selected</li>
                    </ul>
                    <h4>Repositories:</h4>
                    <p>Shows configured APT repositories (PVE Enterprise, No-Subscription, Ceph etc.).</p>
                    <h4>App Update:</h4>
                    <p>FloppyOps Lite can update itself. Shows current and available version.</p>
                    <h4>Auto-Update:</h4>
                    <p>Optional automatic update at a configurable time (day + hour).</p>
                '],
                ['id' => 'h-navigation', 'icon' => '📑', 'title' => 'Navigation & Layout', 'content' => '
                    <p>The navigation is organized into 6 grouped tabs to keep related features together:</p>
                    <h4>Tab Groups:</h4>
                    <ul>
                        <li><strong>Dashboard</strong> — Server overview with live charts and stat cards</li>
                        <li><strong>VMs/CTs</strong> — All virtual machines and containers with IP display and template assignment</li>
                        <li><strong>Security</strong> — Contains: Firewall Templates, Security Check (port scanner + PVE host firewall) and Fail2ban</li>
                        <li><strong>Network</strong> — Contains: Nginx Reverse Proxy (with SSL Health Check) and WireGuard VPN</li>
                        <li><strong>System</strong> — Contains: ZFS Storage and System Updates/Repositories</li>
                        <li><strong>Help</strong> — This help page with search</li>
                    </ul>
                    <h4>General:</h4>
                    <ul>
                        <li>The active tab persists after page reload (URL hash)</li>
                        <li>Loading spinners indicate when data is being fetched</li>
                        <li>Language (DE/EN) can be switched anytime via the topbar button</li>
                        <li>Template assignments for VMs/CTs are visible both on firewall template cards and in the VM/CT table</li>
                    </ul>
                '],
            ];
            foreach ($helpSections as $s): ?>
            <div class="help-section" id="<?= $s['id'] ?>" style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);margin-bottom:10px;overflow:hidden">
                <div style="padding:12px 16px;display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none" onclick="toggleHelp('<?= $s['id'] ?>')">
                    <span style="font-size:1.1rem;width:24px;text-align:center"><?= $s['icon'] ?></span>
                    <span style="font-size:.82rem;font-weight:600;flex:1"><?= $s['title'] ?></span>
                    <span class="help-arrow" style="font-size:.6rem;color:var(--text3);transition:transform .2s">&#9660;</span>
                </div>
                <div class="help-body" style="display:none;padding:0 16px 16px 50px;font-size:.78rem;color:var(--text2);line-height:1.8">
                    <?= $s['content'] ?>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            </div><!-- /max-width -->
        </div>

    </div>
</div>

<!-- ─ Fail2ban Config Modal ─────────────────────────────── -->
<div class="modal-overlay" id="f2bConfigModal">
    <div class="modal">
        <div class="modal-head">
            <div class="modal-title" id="f2bConfigTitle">jail.local</div>
            <button class="modal-close" onclick="closeModal('f2bConfigModal')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="f2bConfigFile">
            <div class="form-group">
                <textarea class="form-textarea" id="f2bConfigContent" style="min-height:300px"></textarea>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn" onclick="closeModal('f2bConfigModal')">Abbrechen</button>
            <button class="btn btn-accent" onclick="saveF2bConfig()">Speichern & Restart</button>
        </div>
    </div>
</div>

<!-- ─ Security: Default Rules Preview ───────────────── -->
<div class="modal-overlay" id="secDefaultsModal">
    <div class="modal" style="max-width:520px">
        <div class="modal-head">
            <div class="modal-title"><?= __('sec_default_rules') ?></div>
            <button class="modal-close" onclick="closeModal('secDefaultsModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div style="background:rgba(96,165,250,.06);border:1px solid rgba(96,165,250,.15);border-radius:var(--radius);padding:10px 14px;margin-bottom:14px;font-size:.72rem;color:#94a3b8">
                <?= __('sec_default_rules_desc') ?>
            </div>
            <table style="width:100%;border-collapse:collapse;font-size:.75rem">
                <thead><tr style="border-bottom:1px solid var(--border-subtle)">
                    <th style="padding:6px 6px;width:28px"></th>
                    <th style="padding:6px 10px;text-align:left;color:var(--text3);font-weight:600;font-size:.65rem"><?= __('sec_action') ?></th>
                    <th style="padding:6px 10px;text-align:left;color:var(--text3);font-weight:600;font-size:.65rem"><?= __('sec_rule_type') ?></th>
                    <th style="padding:6px 10px;text-align:left;color:var(--text3);font-weight:600;font-size:.65rem"><?= __('sec_port') ?></th>
                    <th style="padding:6px 10px;text-align:left;color:var(--text3);font-weight:600;font-size:.65rem"><?= __('sec_service') ?></th>
                </tr></thead>
                <tbody>
<?php
$defaultRules = [
    ['ACCEPT', '22',    'SSH'],
    ['ACCEPT', '8006',  'PVE WebUI'],
    ['ACCEPT', '3128',  'SPICE Proxy'],
    ['DROP',   '111',   'rpcbind'],
    ['DROP',   '3306',  'MySQL'],
    ['DROP',   '5432',  'PostgreSQL'],
    ['DROP',   '5900',  'VNC'],
    ['DROP',   '6379',  'Redis'],
    ['DROP',   '11211', 'Memcached'],
    ['DROP',   '27017', 'MongoDB'],
];
foreach ($defaultRules as $i => $r):
    $isDrop = $r[0] === 'DROP';
    $color = $isDrop ? 'var(--red)' : 'var(--green)';
    $bg = $isDrop ? 'background:rgba(220,53,69,.03)' : '';
?>
                    <tr style="border-bottom:1px solid var(--border-subtle);<?= $bg ?>">
                        <td style="padding:5px 6px;text-align:center"><input type="checkbox" class="sec-def-cb" data-idx="<?= $i ?>" checked style="accent-color:var(--accent);cursor:pointer"></td>
                        <td style="padding:5px 10px;color:<?= $color ?>;font-weight:600"><?= $r[0] ?></td>
                        <td style="padding:5px 10px">IN</td>
                        <td style="padding:5px 10px;font-family:var(--mono)"><?= $r[1] ?></td>
                        <td style="padding:5px 10px"><?= $r[2] ?></td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="modal-foot">
            <button class="btn" onclick="closeModal('secDefaultsModal')"><?= __('cancel') ?></button>
            <button class="btn btn-accent" onclick="secApplyDefaultsConfirm()"><?= __('sec_default_rules') ?></button>
        </div>
    </div>
</div>

<!-- ─ Security: Add Rule Modal ──────────────────────── -->
<div class="modal-overlay" id="secRuleModal">
    <div class="modal" style="max-width:460px">
        <div class="modal-head">
            <div class="modal-title"><?= __('sec_add_rule') ?></div>
            <button class="modal-close" onclick="closeModal('secRuleModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
                <div class="form-group" style="margin:0">
                    <label class="form-label"><?= __('sec_action') ?></label>
                    <select class="form-input" id="sarAction"><option value="DROP">DROP</option><option value="ACCEPT">ACCEPT</option><option value="REJECT">REJECT</option></select>
                </div>
                <div class="form-group" style="margin:0">
                    <label class="form-label"><?= __('sec_rule_type') ?></label>
                    <select class="form-input" id="sarType"><option value="in">IN</option><option value="out">OUT</option></select>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
                <div class="form-group" style="margin:0">
                    <label class="form-label"><?= __('sec_rule_dport') ?></label>
                    <input class="form-input" id="sarDport" placeholder="3306">
                </div>
                <div class="form-group" style="margin:0">
                    <label class="form-label"><?= __('sec_rule_source') ?></label>
                    <input class="form-input" id="sarSource" placeholder="10.0.0.0/8">
                </div>
            </div>
            <div class="form-group" style="margin-bottom:10px">
                <label class="form-label"><?= __('sec_rule_comment') ?></label>
                <input class="form-input" id="sarComment" placeholder="Optional">
            </div>
            <div class="form-group" style="margin:0">
                <label class="form-label">Level</label>
                <select class="form-input" id="sarLevel"><option value="node"><?= __('sec_node_level') ?></option><option value="dc"><?= __('sec_dc_level') ?></option></select>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn" onclick="closeModal('secRuleModal')"><?= __('cancel') ?></button>
            <button class="btn btn-accent" onclick="secSaveRule()"><?= __('save') ?></button>
        </div>
    </div>
</div>

<!-- FW Template Detail/Apply Modal -->
<div class="modal-overlay" id="fwTemplateModal">
    <div class="modal" style="max-width:580px">
        <div class="modal-head">
            <div class="modal-title" id="fwTplTitle">Template</div>
            <button class="modal-close" onclick="closeModal('fwTemplateModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div id="fwTplDesc" style="font-size:.72rem;color:var(--text3);margin-bottom:10px"></div>
            <div id="fwTplRules" style="max-height:250px;overflow-y:auto;margin-bottom:14px"></div>
            <div style="border-top:1px solid var(--border-subtle);padding-top:12px">
                <div class="form-group">
                    <label class="form-label"><?= __('fw_apply_to') ?></label>
                    <select class="form-input" id="fwTplTarget"><option value=""><?= __('fw_select_vm') ?></option></select>
                </div>
                <label style="display:flex;align-items:center;gap:8px;font-size:.72rem;margin-top:8px;cursor:pointer">
                    <input type="checkbox" id="fwTplClear">
                    <span><?= __('fw_clear_existing') ?></span>
                </label>
                <div id="fwTplClearWarn" style="display:none;font-size:.65rem;color:var(--red);margin-top:4px"><?= __('fw_clear_warn') ?></div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn" onclick="closeModal('fwTemplateModal')"><?= __('cancel') ?></button>
            <span id="fwTplDeleteBtn"></span>
            <button class="btn btn-accent" onclick="fwApplyTemplate()"><?= __('fw_apply') ?></button>
        </div>
    </div>
</div>

<!-- FW Custom Template Builder Modal -->
<div class="modal-overlay" id="fwBuilderModal">
    <div class="modal" style="max-width:620px">
        <div class="modal-head">
            <div class="modal-title"><?= __('fw_create_custom') ?></div>
            <button class="modal-close" onclick="closeModal('fwBuilderModal')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="fwbEditId" value="">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
                <div class="form-group"><label class="form-label"><?= __('fw_template_name') ?></label><input class="form-input" id="fwbName" maxlength="100"></div>
                <div class="form-group"><label class="form-label"><?= __('fw_template_desc') ?></label><input class="form-input" id="fwbDesc" maxlength="255"></div>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <span style="font-size:.75rem;font-weight:600"><?= __('sec_fw_rules') ?></span>
                <button class="btn btn-sm btn-green" onclick="fwbAddRow()" style="font-size:.6rem">+ <?= __('fw_add_rule') ?></button>
            </div>
            <div id="fwbRules" style="max-height:280px;overflow-y:auto"></div>
        </div>
        <div class="modal-foot">
            <button class="btn" onclick="closeModal('fwBuilderModal')"><?= __('cancel') ?></button>
            <button class="btn btn-accent" onclick="fwSaveCustom()"><?= __('fw_save_template') ?></button>
        </div>
    </div>
</div>

<!-- FW VM/CT Rule Viewer Modal -->
<div class="modal-overlay" id="fwVmRulesModal">
    <div class="modal" style="max-width:620px">
        <div class="modal-head">
            <div class="modal-title" id="fwVmRulesTitle">Firewall Rules</div>
            <button class="modal-close" onclick="closeModal('fwVmRulesModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div id="fwVmRulesContent" style="max-height:300px;overflow-y:auto"></div>
            <div style="border-top:1px solid var(--border-subtle);padding-top:12px;margin-top:12px">
                <div style="font-size:.72rem;font-weight:600;margin-bottom:8px"><?= __('fw_add_rule') ?></div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:6px;font-size:.7rem">
                    <select class="form-input" id="fwVmrAction" style="font-size:.7rem"><option>ACCEPT</option><option>DROP</option><option>REJECT</option></select>
                    <input class="form-input" id="fwVmrPort" placeholder="Port" style="font-size:.7rem">
                    <select class="form-input" id="fwVmrProto" style="font-size:.7rem"><option value="tcp">TCP</option><option value="udp">UDP</option></select>
                    <input class="form-input" id="fwVmrComment" placeholder="<?= __('sec_rule_comment') ?>" style="font-size:.7rem">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:6px">
                    <input class="form-input" id="fwVmrSource" placeholder="<?= __('sec_rule_source') ?> (optional)" style="font-size:.7rem">
                    <button class="btn btn-sm btn-accent" onclick="fwVmAddRule()" style="font-size:.65rem"><?= __('fw_add_rule') ?></button>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn" onclick="closeModal('fwVmRulesModal')"><?= __('close') ?></button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="wgConfigModal">
    <div class="modal">
        <div class="modal-head">
            <div class="modal-title" id="wgConfigTitle">WireGuard Config</div>
            <button class="modal-close" onclick="closeModal('wgConfigModal')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="wgConfigIface">
            <div class="form-group">
                <label class="form-label">Konfiguration</label>
                <textarea class="form-textarea" id="wgConfigContent" style="min-height:260px"></textarea>
                <div class="form-hint">Private/Preshared Keys werden beim Laden maskiert. Zum Speichern den echten Key eintragen.</div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn" onclick="closeModal('wgConfigModal')"><?= $lang === 'de' ? 'Schließen' : 'Close' ?></button>
            <button class="btn" id="wgConfigEditBtn" onclick="wgConfigMakeEditable()" style="display:none"><?= $lang === 'de' ? 'Gesamte Config bearbeiten' : 'Edit full config' ?></button>
            <button class="btn btn-accent" id="wgConfigSaveBtn" onclick="saveWgConfig()" style="display:none"><?= $lang === 'de' ? 'Speichern' : 'Save' ?></button>
        </div>
    </div>
</div>

<!-- ─ WireGuard Wizard Modal ───────────────────────────── -->
<div class="modal-overlay" id="wgWizardModal">
    <div class="modal" style="max-width:600px">
        <div class="modal-head">
            <div class="modal-title" id="wgWizardTitle">Neuer VPN-Tunnel</div>
            <button class="modal-close" onclick="closeModal('wgWizardModal')">&times;</button>
        </div>
        <div class="modal-body" id="wgWizardBody"></div>
        <div class="modal-foot" id="wgWizardFoot"></div>
    </div>
</div>

<!-- ─ WireGuard Add Peer Modal ────────────────────────── -->
<div class="modal-overlay" id="wgAddPeerModal">
    <div class="modal" style="max-width:640px">
        <div class="modal-head">
            <div class="modal-title" id="wgAddPeerTitle"><?= $lang === 'de' ? 'Peer hinzufügen' : 'Add Peer' ?></div>
            <button class="modal-close" onclick="closeModal('wgAddPeerModal')">&times;</button>
        </div>
        <div class="modal-body" id="wgAddPeerBody"></div>
        <div class="modal-foot" id="wgAddPeerFoot"></div>
    </div>
</div>

<!-- ─ WireGuard Edit Interface Modal ──────────────────── -->
<div class="modal-overlay" id="wgEditIfaceModal">
    <div class="modal" style="max-width:520px">
        <div class="modal-head">
            <div class="modal-title" id="wgEditIfaceTitle">Interface</div>
            <button class="modal-close" onclick="closeModal('wgEditIfaceModal')">&times;</button>
        </div>
        <div class="modal-body" id="wgEditIfaceBody"></div>
        <div class="modal-foot">
            <button class="btn" onclick="closeModal('wgEditIfaceModal')"><?= $lang === 'de' ? 'Abbrechen' : 'Cancel' ?></button>
            <button class="btn btn-accent" id="wgEditIfaceSaveBtn" onclick="wgEditIfaceSave()"><?= $lang === 'de' ? 'Speichern' : 'Save' ?></button>
        </div>
    </div>
</div>

<!-- ─ WireGuard Logs Modal ────────────────────────────── -->
<div class="modal-overlay" id="wgLogsModal">
    <div class="modal" style="max-width:720px">
        <div class="modal-head">
            <div class="modal-title" id="wgLogsTitle">WireGuard Logs</div>
            <button class="modal-close" onclick="closeModal('wgLogsModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div style="display:flex;gap:6px;margin-bottom:10px">
                <button class="btn btn-sm" id="wgLogsRefreshBtn" onclick="wgRefreshLogs()" style="font-size:.65rem"><?= __('refresh') ?></button>
                <select id="wgLogsLines" onchange="wgRefreshLogs()" style="background:var(--surface-solid);border:1px solid var(--border-subtle);border-radius:4px;padding:3px 8px;font-size:.65rem;color:var(--text)">
                    <option value="30">30 Zeilen</option>
                    <option value="50" selected>50 Zeilen</option>
                    <option value="100">100 Zeilen</option>
                    <option value="200">200 Zeilen</option>
                </select>
            </div>
            <pre id="wgLogsContent" style="background:rgba(0,0,0,.3);border:1px solid var(--border-subtle);border-radius:8px;padding:12px;font-family:var(--mono);font-size:.65rem;line-height:1.6;overflow:auto;max-height:400px;color:var(--text2);margin:0;white-space:pre-wrap"></pre>
        </div>
        <div class="modal-foot">
            <button class="btn" onclick="closeModal('wgLogsModal')"><?= $lang === 'de' ? 'Schließen' : 'Close' ?></button>
        </div>
    </div>
</div>

<!-- ─ WireGuard Edit Peer Modal ───────────────────────── -->
<div class="modal-overlay" id="wgEditPeerModal">
    <div class="modal" style="max-width:520px">
        <div class="modal-head">
            <div class="modal-title" id="wgEditPeerTitle">Peer bearbeiten</div>
            <button class="modal-close" onclick="closeModal('wgEditPeerModal')">&times;</button>
        </div>
        <div class="modal-body" id="wgEditPeerBody"></div>
        <div class="modal-foot">
            <button class="btn" onclick="closeModal('wgEditPeerModal')"><?= $lang === 'de' ? 'Abbrechen' : 'Cancel' ?></button>
            <button class="btn btn-accent" id="wgEditPeerSaveBtn" onclick="wgEditPeerSave()"><?= $lang === 'de' ? 'Speichern' : 'Save' ?></button>
        </div>
    </div>
</div>

<!-- ─ WireGuard Import Config Modal ──────────────────── -->
<div class="modal-overlay" id="wgImportModal">
    <div class="modal" style="max-width:560px">
        <div class="modal-head">
            <div class="modal-title"><?= $lang === 'de' ? 'WireGuard Config importieren' : 'Import WireGuard Config' ?></div>
            <button class="modal-close" onclick="closeModal('wgImportModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div style="background:rgba(64,196,255,.04);border:1px solid rgba(64,196,255,.1);border-radius:6px;padding:8px 12px;margin-bottom:14px;font-size:.68rem;color:var(--text2);line-height:1.5">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2" style="margin-right:4px;vertical-align:middle"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <?= $lang === 'de'
                    ? 'Config-Datei (.conf) eines anderen WireGuard-Servers einfügen oder hochladen. Die Datei wird unter /etc/wireguard/ gespeichert.'
                    : 'Paste or upload a config file (.conf) from another WireGuard server. It will be saved to /etc/wireguard/.' ?>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label"><?= $lang === 'de' ? 'Interface-Name' : 'Interface name' ?></label>
                    <input class="form-input" id="wgImportIface" placeholder="wg0">
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end">
                    <label class="btn btn-sm" style="cursor:pointer;margin-bottom:0">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <?= $lang === 'de' ? '.conf hochladen' : 'Upload .conf' ?>
                        <input type="file" accept=".conf,.txt" id="wgImportFile" onchange="wgImportFileLoad(this)" style="display:none">
                    </label>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label"><?= $lang === 'de' ? 'Config-Inhalt' : 'Config content' ?></label>
                <textarea class="form-textarea" id="wgImportContent" style="min-height:200px;font-family:var(--mono);font-size:.7rem" placeholder="[Interface]
PrivateKey = ...
Address = 10.10.30.2/24

[Peer]
PublicKey = ...
Endpoint = ...
AllowedIPs = ..."></textarea>
            </div>
            <div class="form-row">
                <label class="form-check">
                    <input type="checkbox" id="wgImportAutoStart" checked>
                    <?= $lang === 'de' ? 'Tunnel starten + Autostart' : 'Start tunnel + enable at boot' ?>
                </label>
                <label class="form-check">
                    <input type="checkbox" id="wgImportAddFw">
                    <?= $lang === 'de' ? 'ListenPort in Firewall freigeben' : 'Open ListenPort in firewall' ?>
                </label>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn" onclick="closeModal('wgImportModal')"><?= $lang === 'de' ? 'Abbrechen' : 'Cancel' ?></button>
            <button class="btn btn-accent" onclick="wgImportSave()" id="wgImportBtn">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                <?= $lang === 'de' ? 'Importieren' : 'Import' ?>
            </button>
        </div>
    </div>
</div>

<!-- ─ Add Site Modal ──────────────────────────────────── -->
<div class="modal-overlay" id="addSiteModal">
    <div class="modal">
        <div class="modal-head">
            <div class="modal-title"><?= __('new_site') ?></div>
            <button class="modal-close" onclick="closeModal('addSiteModal')">&times;</button>
        </div>
        <div class="modal-body">
            <!-- Info -->
            <div style="background:rgba(64,196,255,.04);border:1px solid rgba(64,196,255,.1);border-radius:6px;padding:8px 12px;margin-bottom:14px;font-size:.68rem;color:var(--text2);line-height:1.5">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2" style="margin-right:4px;vertical-align:middle"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <?= $lang === 'de' ? 'Erstellt einen Nginx Reverse Proxy. Der DNS A-Record der Domain muss auf die IP dieses Servers zeigen.' : 'Creates an Nginx reverse proxy. The domain DNS A record must point to this server\'s IP.' ?>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                <div class="form-group">
                    <label class="form-label"><?= __('domains') ?></label>
                    <input class="form-input" id="newDomain" placeholder="example.com, www.example.com">
                    <div class="form-hint"><?= __('multi_domain_hint') ?></div>
                </div>
                <div class="form-group">
                    <label class="form-label">E-Mail (SSL)</label>
                    <input class="form-input" id="newEmail" type="email" placeholder="admin@example.com">
                    <div class="form-hint"><?= $lang === 'de' ? 'Für Let\'s Encrypt Benachrichtigungen' : 'For Let\'s Encrypt notifications' ?></div>
                </div>
            </div>
            <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr 80px;gap:8px">
                <div class="form-group">
                    <label class="form-label"><?= $lang === 'de' ? 'Ziel (CT/VM)' : 'Target (CT/VM)' ?></label>
                    <select class="form-input" id="newTargetSelect" onchange="onTargetSelect()">
                        <option value=""><?= $lang === 'de' ? 'Laden...' : 'Loading...' ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= __('target_ip') ?></label>
                    <input class="form-input" id="newTargetIp" placeholder="10.10.10.100">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= __('target_port') ?></label>
                    <input class="form-input" id="newTargetPort" placeholder="80" value="80">
                </div>
            </div>
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
                <label class="form-check"><input type="checkbox" id="newSsl" checked onchange="toggleSslChallenge()"> <?= __('enable_ssl') ?></label>
                <label class="form-check"><input type="checkbox" id="newForceSsl" checked> <?= __('force_ssl') ?></label>
                <label class="form-check"><input type="checkbox" id="newWs"> <?= __('enable_ws') ?></label>
            </div>
            <!-- SSL Challenge Type -->
            <div id="sslChallengeRow" style="margin-top:8px;display:flex;gap:8px;align-items:end;flex-wrap:wrap">
                <div style="flex:0 0 auto">
                    <label style="font-size:.7rem;color:var(--text2);display:block;margin-bottom:3px"><?= $lang === 'de' ? 'Challenge' : 'Challenge' ?></label>
                    <select class="form-input" id="newChallengeType" onchange="toggleDnsFields()" style="width:110px;padding:4px 6px;font-size:.7rem">
                        <option value="http">HTTP-01</option>
                        <option value="dns">DNS-01</option>
                    </select>
                </div>
                <div id="dnsProviderWrap" style="display:none;flex:0 0 auto">
                    <label style="font-size:.7rem;color:var(--text2);display:block;margin-bottom:3px">Provider</label>
                    <select class="form-input" id="newDnsProvider" onchange="toggleDnsFields()" style="width:110px;padding:4px 6px;font-size:.7rem">
                        <option value="cloudflare">Cloudflare</option>
                        <option value="hetzner">Hetzner</option>
                        <option value="ipv64">IPv64.net</option>
                    </select>
                </div>
                <div id="dnsCfTokenWrap" style="display:none;flex:1;min-width:140px">
                    <label style="font-size:.7rem;color:var(--text2);display:block;margin-bottom:3px">API Token</label>
                    <input class="form-input" id="newCfToken" type="password" placeholder="Cloudflare API Token" style="font-size:.7rem;padding:4px 6px">
                </div>
                <div id="dnsHetznerWrap" style="display:none;flex:1;min-width:140px">
                    <label style="font-size:.7rem;color:var(--text2);display:block;margin-bottom:3px">API Token</label>
                    <input class="form-input" id="newHetznerToken" type="password" placeholder="Hetzner DNS API Token" style="font-size:.7rem;padding:4px 6px">
                </div>
                <div id="dnsIpv64Wrap" style="display:none;flex:1;min-width:140px">
                    <label style="font-size:.7rem;color:var(--text2);display:block;margin-bottom:3px">API Key</label>
                    <input class="form-input" id="newIpv64Key" type="password" placeholder="IPv64 Account API Key" style="font-size:.7rem;padding:4px 6px">
                </div>
            </div>
            <div style="display:flex;gap:8px;margin-top:8px;align-items:center">
                <label style="font-size:.7rem;color:var(--text2);white-space:nowrap"><?= __('max_upload') ?></label>
                <select class="form-input" id="newMaxUpload" style="width:90px;padding:4px 6px;font-size:.7rem">
                    <option value="">Default</option>
                    <option value="10">10 MB</option>
                    <option value="50">50 MB</option>
                    <option value="100" selected>100 MB</option>
                    <option value="500">500 MB</option>
                    <option value="1024">1 GB</option>
                    <option value="0">Unlimited</option>
                </select>
                <label style="font-size:.7rem;color:var(--text2);white-space:nowrap;margin-left:8px"><?= __('proxy_timeout') ?></label>
                <select class="form-input" id="newTimeout" style="width:80px;padding:4px 6px;font-size:.7rem">
                    <option value="">Default</option>
                    <option value="60" selected>60s</option>
                    <option value="120">120s</option>
                    <option value="300">300s</option>
                    <option value="600">600s</option>
                </select>
            </div>
            <div class="form-hint" style="margin-top:6px"><?= __('dns_hint') ?></div>
        </div>
        <div class="modal-foot">
            <button class="btn" onclick="closeModal('addSiteModal')"><?= __('cancel') ?></button>
            <button class="btn btn-accent" onclick="addSite()"><?= __('create_site') ?></button>
        </div>
    </div>
</div>

<!-- ─ Progress Modal (Site Create / SSL Renew) ────────── -->
<div class="modal-overlay" id="sslProgressModal">
    <div class="modal" style="max-width:420px">
        <div class="modal-head">
            <div class="modal-title" id="sslProgressTitle">
                <span class="loading-spinner" style="width:14px;height:14px;border-width:2px;margin-right:8px;vertical-align:middle"></span>
                <?= $lang === 'de' ? 'Bitte warten...' : 'Please wait...' ?>
            </div>
        </div>
        <div class="modal-body" style="padding:10px 16px">
            <div id="sslProgressLog" style="font-family:var(--mono);font-size:.7rem;line-height:1.7;max-height:180px;overflow-y:auto;color:var(--text2)"></div>
        </div>
        <div class="modal-foot" id="sslProgressFoot" style="display:none">
            <button class="btn btn-accent" onclick="closeModal('sslProgressModal')">OK</button>
        </div>
    </div>
</div>

<!-- ─ Edit Site Modal ─────────────────────────────────── -->
<div class="modal-overlay" id="editSiteModal">
    <div class="modal">
        <div class="modal-head">
            <div class="modal-title" id="editSiteTitle"><?= __('edit') ?></div>
            <button class="modal-close" onclick="closeModal('editSiteModal')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="editSiteFile">
            <!-- Compact Settings -->
            <div class="form-group">
                <label class="form-label"><?= __('domains') ?></label>
                <input class="form-input" id="editDomains" placeholder="example.com, www.example.com">
            </div>
            <div class="form-row" style="display:grid;grid-template-columns:1fr 100px;gap:8px">
                <div class="form-group">
                    <label class="form-label"><?= __('target_ip') ?></label>
                    <input class="form-input" id="editTargetIp" placeholder="10.10.10.100">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= __('target_port') ?></label>
                    <input class="form-input" id="editTargetPort" placeholder="80">
                </div>
            </div>
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
                <label class="form-check"><input type="checkbox" id="editForceSsl"> <?= __('force_ssl') ?></label>
                <label class="form-check"><input type="checkbox" id="editWs"> <?= __('enable_ws') ?></label>
            </div>
            <div style="display:flex;gap:8px;margin-top:8px;margin-bottom:12px;align-items:center">
                <label style="font-size:.7rem;color:var(--text2);white-space:nowrap"><?= __('max_upload') ?></label>
                <select class="form-input" id="editMaxUpload" style="width:90px;padding:4px 6px;font-size:.7rem">
                    <option value="">Default</option>
                    <option value="10">10 MB</option>
                    <option value="50">50 MB</option>
                    <option value="100">100 MB</option>
                    <option value="500">500 MB</option>
                    <option value="1024">1 GB</option>
                    <option value="0">Unlimited</option>
                </select>
                <label style="font-size:.7rem;color:var(--text2);white-space:nowrap;margin-left:8px"><?= __('proxy_timeout') ?></label>
                <select class="form-input" id="editTimeout" style="width:80px;padding:4px 6px;font-size:.7rem">
                    <option value="">Default</option>
                    <option value="60">60s</option>
                    <option value="120">120s</option>
                    <option value="300">300s</option>
                    <option value="600">600s</option>
                </select>
            </div>
            <!-- Custom Config (collapsible) -->
            <div style="border-top:1px solid var(--border-subtle);padding-top:10px;margin-top:4px">
                <div style="display:flex;align-items:center;gap:6px;cursor:pointer;user-select:none" onclick="var el=document.getElementById('editConfigWrap');el.style.display=el.style.display==='none'?'':'none'">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--text3)" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                    <span style="font-size:.72rem;color:var(--text3);font-weight:600"><?= __('custom_config') ?></span>
                </div>
                <div id="editConfigWrap" style="display:none;margin-top:8px">
                    <textarea class="form-textarea" id="editSiteContent" style="min-height:200px;font-family:var(--mono);font-size:.7rem"></textarea>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn" onclick="closeModal('editSiteModal')"><?= __('cancel') ?></button>
            <button class="btn btn-accent" onclick="saveSite()"><?= __('save_reload') ?></button>
        </div>
    </div>
</div>

<!-- ─ Toast Container ─────────────────────────────────── -->
<div class="toast-container" id="toasts"></div>

<script>
const CSRF = '<?= $csrf ?>';
const LANG = '<?= $lang ?>';
const T = <?= json_encode($t, JSON_UNESCAPED_UNICODE) ?>;

// ╔══════════════════════════════════════════════════════════════╗
// ║                    JAVASCRIPT                                ║
// ╚══════════════════════════════════════════════════════════════╝

// ── Navigation & Tabs ────────────────────────────────
function switchTab(tabName) {
    document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    const tab = document.querySelector('.nav-tab[data-tab="' + tabName + '"]');
    const panel = document.getElementById('panel-' + tabName);
    if (tab) tab.classList.add('active');
    if (panel) panel.classList.add('active');
    location.hash = tabName;

    stopWgGraph();
    if (tabName === 'security') { Promise.all([loadFwTemplates(), loadFwVmList()]); }
    if (tabName === 'network') { loadNginx(); loadNginxChecks(); loadWg(); startWgGraph(); }
    if (tabName === 'zfs') loadZfs();
    if (tabName === 'system') loadUpdates();
}

// Legacy hash support: map old tab names to grouped tabs
const _tabHashMap = { fail2ban: ['security','fail2ban'], firewall: ['security','firewall'], portscan: ['security','portscan'],
    nginx: ['network','nginx'], wireguard: ['network','wireguard'] };

function switchSubTab(group, sub) {
    const tabs = document.querySelectorAll('#' + group + 'SubTabs .sub-tab, [onclick*="switchSubTab(\'' + group + '\'"] ');
    // Find parent sub-tabs container
    const container = document.getElementById(group === 'security' ? 'secSubTabs' : group === 'network' ? 'netSubTabs' : 'sysSubTabs');
    if (container) container.querySelectorAll('.sub-tab').forEach(t => t.classList.remove('active'));
    // Activate clicked tab
    const clicked = document.querySelector('[onclick="switchSubTab(\'' + group + '\',\'' + sub + '\')"]');
    if (clicked) clicked.classList.add('active');
    // Show/hide sub-panels
    document.querySelectorAll('[id^="sub-' + group + '-"]').forEach(p => p.classList.remove('active'));
    const panel = document.getElementById('sub-' + group + '-' + sub);
    if (panel) panel.classList.add('active');
    // Load data on sub-tab switch
    if (group === 'security' && sub === 'portscan') { loadSecScan(); loadSecFwRules(); }
    if (group === 'security' && sub === 'fail2ban') loadF2b();
    if (group === 'security' && sub === 'firewall') { loadFwTemplates(); loadFwVmList(); }
    if (group === 'network' && sub === 'nginx') { loadNginx(); loadNginxChecks(); }
    if (group === 'network' && sub === 'wireguard') { loadWg(); startWgGraph(); }
    if (group === 'system' && sub === 'zfs') loadZfs();
    if (group === 'system' && sub === 'updates') loadUpdates();
}

document.querySelectorAll('.nav-tab').forEach(tab => {
    tab.addEventListener('click', () => switchTab(tab.dataset.tab));
});


// ── Toast Benachrichtigungen ─────────────────────────
function toast(msg, type = 'success') {
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.innerHTML = (type === 'success' ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>' : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>') + '<span>' + msg + '</span>';
    document.getElementById('toasts').appendChild(el);
    setTimeout(() => el.remove(), 4000);
}

// ── API Helper (fetch wrapper with CSRF) ─────────────
async function api(endpoint, method = 'GET', data = null) {
    const opts = { method };
    if (data) {
        const fd = new FormData();
        fd.append('_csrf', CSRF);
        Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        opts.body = fd;
    }
    const res = await fetch('?api=' + endpoint, opts);
    return res.json();
}

// ── Formatierungs-Hilfsfunktionen ───────────────────
function fmtBytes(b) {
    if (b < 1073741824) return (b / 1048576).toFixed(0) + ' MB';
    return (b / 1073741824).toFixed(1) + ' GB';
}
function pct(used, total) {
    return total > 0 ? Math.round(used / total * 100) : 0;
}

// ┌──────────────────────────────────────────────────────────┐
// │              Dashboard: Stats + Live-Charts               │
// └──────────────────────────────────────────────────────────┘
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
        document.getElementById('sUptimeSince').textContent = T.since + ' ' + d.uptime_since;

        const loadPct = Math.min(100, Math.round(d.load[0] / d.cpu_cores * 100));
        document.getElementById('sLoad').textContent = d.cpu_pct + '%';
        document.getElementById('sLoadSub').textContent = T.load_label + ' ' + d.load.map(l => l.toFixed(2)).join(' / ') + ' (' + d.cpu_cores + ' ' + T.cores + ')';
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
            subEl.textContent = T.active;
            subEl.style.color = 'var(--green)';
            subLvl.textContent = d.sub_level || 'Licensed';
        } else {
            subEl.textContent = T.none;
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

// ┌──────────────────────────────────────────────────────────┐
// │              Fail2ban: Jails, Logs, Config                │
// └──────────────────────────────────────────────────────────┘
async function loadF2b() {
    try {
        const [jails, log] = await Promise.all([api('f2b-jails'), api('f2b-log')]);

        document.getElementById('jailCount').textContent = jails.length;
        const grid = document.getElementById('jailGrid');
        grid.innerHTML = '';

        jails.forEach(j => {
            const bannedHtml = j.banned_ips.length > 0
                ? j.banned_ips.map(ip => `<div class="banned-ip"><span>${ip}</span><button class="unban-btn" title="${T.unban}" onclick="unban('${j.name}','${ip}')">&#10005;</button></div>`).join('')
                : '<span style="color:var(--text3);font-size:.78rem">Keine gebannten IPs</span>';

            grid.innerHTML += `
                <div class="jail-card">
                    <div class="jail-header">
                        <div class="jail-name">
                            <span class="tag ${j.banned_current > 0 ? 'tag-red' : 'tag-green'}">${j.banned_current > 0 ? 'AKTIV' : 'OK'}</span>
                            ${j.name}
                        </div>
                        <div class="jail-stats">
                            <span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg> ${j.banned_current} gebannt</span>
                            <span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--yellow)" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg> ${j.failed_current} fehlgeschlagen</span>
                            <span style="color:var(--text3)">Gesamt: ${j.banned_total} Bans / ${j.failed_total} Fails</span>
                        </div>
                    </div>
                    <div class="jail-body">
                        <div class="banned-list">${bannedHtml}</div>
                    </div>
                </div>`;
        });

        // Log
        const logEl = document.getElementById('f2bLog');
        logEl.innerHTML = '';
        log.forEach(line => {
            let hl = line.replace(/&/g, '&amp;').replace(/</g, '&lt;');
            if (hl.includes(' Ban ')) { hl = hl.replace(/( Ban )/, '<span class="log-ban">$1</span>'); }
            else if (hl.includes(' Unban ')) { hl = hl.replace(/( Unban )/, '<span class="log-unban">$1</span>'); }
            else if (hl.includes(' Found ')) { hl = hl.replace(/( Found )/, '<span class="log-found">$1</span>'); }
            // Timestamp
            hl = hl.replace(/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/, '<span class="log-ts">$1</span>');
            logEl.innerHTML += `<div class="log-line">${hl}</div>`;
        });
    } catch (e) {
    }
}

async function unban(jail, ip) {
    try {
        const res = await api('f2b-unban', 'POST', { jail, ip });
        if (res.ok) {
            toast(`${ip} aus ${jail} entbannt`);
            loadF2b();
            loadStats();
        } else {
            toast(res.error || 'Fehler', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function showF2bConfig(file) {
    try {
        const res = await api('f2b-config&file=' + encodeURIComponent(file));
        if (res.ok) {
            document.getElementById('f2bConfigFile').value = res.file;
            document.getElementById('f2bConfigTitle').textContent = res.file;
            document.getElementById('f2bConfigContent').value = res.content;
            openModal('f2bConfigModal');
        } else {
            toast(res.error || 'Config nicht gefunden', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function saveF2bConfig() {
    const file = document.getElementById('f2bConfigFile').value;
    const content = document.getElementById('f2bConfigContent').value;
    try {
        const res = await api('f2b-save', 'POST', { file, content });
        if (res.ok) {
            toast('Config gespeichert, Fail2ban: ' + res.status);
            closeModal('f2bConfigModal');
            loadF2b();
        } else {
            toast('Fail2ban Restart fehlgeschlagen: ' + (res.output || res.status), 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

// ┌──────────────────────────────────────────────────────────┐
// │              Nginx: Sites, Checks, SSL                    │
// └──────────────────────────────────────────────────────────┘

// ── Nginx System-Checks (IP-Forwarding, NAT, Certbot) ──
async function loadNginxChecks() {
    try {
        const d = await api('nginx-checks');
        if (!d.ok) return;
        const el = document.getElementById('nginxChecks');
        el.innerHTML = d.checks.map(c => {
            const icon = c.ok
                ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2.5" style="flex-shrink:0"><polyline points="20 6 9 17 4 12"/></svg>'
                : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2.5" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
            let fixBtn = '';
            if (!c.ok && c.fix) {
                if (c.id === 'nat') {
                    fixBtn = '<button class="btn btn-sm btn-green" onclick="nginxApplyFix(\'nat\',{subnet:\'' + (c.nat_subnet||'') + '\',iface:\'' + (c.nat_iface||'') + '\'})" style="padding:2px 8px;font-size:.6rem">Aktivieren</button>';
                } else {
                    fixBtn = '<button class="btn btn-sm btn-green" onclick="nginxApplyFix(\'' + c.id + '\')" style="padding:2px 8px;font-size:.6rem">Fix</button>';
                }
            }
            return '<div style="display:flex;align-items:center;gap:8px;padding:5px 8px;border-radius:5px;background:' + (c.ok ? 'rgba(34,197,94,.03)' : 'rgba(255,61,87,.03)') + ';border:1px solid ' + (c.ok ? 'rgba(34,197,94,.1)' : 'rgba(255,61,87,.1)') + '">' +
                icon +
                '<span style="font-size:.75rem;font-weight:500;flex:1">' + c.label + '</span>' +
                '<span style="font-size:.65rem;font-family:var(--mono);color:' + (c.ok ? 'var(--green)' : 'var(--red)') + '">' + c.value + '</span>' +
                fixBtn +
            '</div>';
        }).join('');
    } catch (e) {
    }
}

async function nginxApplyFix(fixId, extra) {
    toast(T.applying_fix);
    try {
        const data = { fix_id: fixId };
        if (extra) Object.assign(data, extra);
        const res = await api('nginx-fix', 'POST', data);
        if (res.ok) {
            toast(res.output || 'Fix angewendet');
            loadNginxChecks();
        } else toast(res.error || 'Fehler', 'error');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

// ── Nginx Sites Management ──────────────────────────
let sitesData = [];

async function loadNginx() {
    try {
        sitesData = await api('nginx-sites');
        document.getElementById('siteCount').textContent = sitesData.length;
        const grid = document.getElementById('siteGrid');
        grid.innerHTML = '';

        if (sitesData.length === 0) {
            grid.innerHTML = '<div class="empty">Keine Proxy-Sites konfiguriert</div>';
            return;
        }

        sitesData.forEach((s, i) => {
            const domainTags = s.domains.map(d => `<span class="tag tag-accent">${d}</span>`).join(' ');
            let sslTag = '<span class="tag tag-muted">HTTP</span>';
            let sslInfo = '';
            let renewBtn = '';

            if (s.ssl) {
                if (s.ssl_days_left !== null) {
                    let tagClass = 'tag-green';
                    let statusText = s.ssl_days_left + 'd';
                    if (s.ssl_days_left <= 7) { tagClass = 'tag-red'; }
                    else if (s.ssl_days_left <= 30) { tagClass = 'tag-yellow'; }
                    sslTag = '<span class="tag ' + tagClass + '">SSL ' + statusText + '</span>';
                    sslInfo = '<div style="font-size:.68rem;color:var(--text3);margin-top:2px;font-family:var(--mono)">Ablauf: ' + s.ssl_expiry + '</div>';
                } else {
                    sslTag = '<span class="tag tag-green">SSL</span>';
                }
                const mainDomain = s.domains[0] || '';
                renewBtn = `<button class="btn btn-sm btn-green" onclick="renewCert('${mainDomain}')" title="SSL erneuern"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg></button>`;
            }

            grid.innerHTML += `
                <div class="site-row">
                    <div class="site-domain">
                        ${sslTag}
                        <div>
                            <div class="domains">${domainTags}</div>
                            ${sslInfo}
                        </div>
                    </div>
                    <div class="site-target">${s.target || '<span style="color:var(--text3)">---</span>'}</div>
                    <div class="site-actions">
                        ${renewBtn}
                        <button class="btn btn-sm" onclick="editSite(${i})" title="${T.edit}">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        <button class="btn btn-sm btn-red" onclick="deleteSite('${s.file}')" title="${T.delete}">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </div>
                </div>`;
        });
    } catch (e) {
    }
}

function toggleSslChallenge() {
    const show = document.getElementById('newSsl').checked;
    document.getElementById('sslChallengeRow').style.display = show ? 'flex' : 'none';
}
function toggleDnsFields() {
    const isDns = document.getElementById('newChallengeType').value === 'dns';
    document.getElementById('dnsProviderWrap').style.display = isDns ? '' : 'none';
    if (!isDns) {
        document.getElementById('dnsCfTokenWrap').style.display = 'none';
        document.getElementById('dnsHetznerWrap').style.display = 'none';
        document.getElementById('dnsIpv64Wrap').style.display = 'none';
        return;
    }
    const provider = document.getElementById('newDnsProvider').value;
    document.getElementById('dnsCfTokenWrap').style.display = provider === 'cloudflare' ? '' : 'none';
    document.getElementById('dnsHetznerWrap').style.display = provider === 'hetzner' ? '' : 'none';
    document.getElementById('dnsIpv64Wrap').style.display = provider === 'ipv64' ? '' : 'none';
}

let _ctTargets = [];
async function loadCtTargets() {
    const sel = document.getElementById('newTargetSelect');
    try {
        const res = await api('pve-ct-ips');
        _ctTargets = res.ok ? res.targets : [];
    } catch(e) { _ctTargets = []; }
    let html = '<option value="manual">' + (document.documentElement.lang === 'de' ? '— Manuell —' : '— Manual —') + '</option>';
    _ctTargets.forEach(t => {
        const label = t.vmid + ': ' + t.name + ' (' + t.ip + ')';
        html += '<option value="' + t.ip + '">' + label + '</option>';
    });
    sel.innerHTML = html;
}
function onTargetSelect() {
    const val = document.getElementById('newTargetSelect').value;
    if (val && val !== 'manual') {
        document.getElementById('newTargetIp').value = val;
    }
}

function showAddSite() {
    document.getElementById('newDomain').value = '';
    document.getElementById('newTargetIp').value = '';
    document.getElementById('newTargetPort').value = '80';
    loadCtTargets();
    document.getElementById('newSsl').checked = true;
    document.getElementById('newForceSsl').checked = true;
    document.getElementById('newWs').checked = false;
    document.getElementById('newMaxUpload').value = '100';
    document.getElementById('newTimeout').value = '60';
    document.getElementById('newEmail').value = '';
    document.getElementById('newChallengeType').value = 'http';
    document.getElementById('newDnsProvider').value = 'cloudflare';
    document.getElementById('newCfToken').value = '';
    document.getElementById('newHetznerToken').value = '';
    document.getElementById('newIpv64Key').value = '';
    toggleSslChallenge();
    toggleDnsFields();
    openModal('addSiteModal');
}

function addSite() {
    const domain = document.getElementById('newDomain').value.trim();
    const ip = document.getElementById('newTargetIp').value.trim();
    const port = document.getElementById('newTargetPort').value.trim() || '80';
    const ssl = document.getElementById('newSsl').checked ? '1' : '0';
    const forceSsl = document.getElementById('newForceSsl').checked ? '1' : '0';
    const ws = document.getElementById('newWs').checked ? '1' : '0';
    const maxUpload = document.getElementById('newMaxUpload').value;
    const timeout = document.getElementById('newTimeout').value;
    const target = 'http://' + ip + ':' + port;

    if (!domain || !ip) { toast(T.domain_ip_required, 'error'); return; }

    const email = document.getElementById('newEmail').value.trim();
    const data = { domain, target, ssl, ws, force_ssl: forceSsl, max_upload: maxUpload, timeout, email };

    // DNS challenge params
    if (ssl === '1') {
        const challengeType = document.getElementById('newChallengeType').value;
        data.challenge = challengeType;
        if (challengeType === 'dns') {
            const provider = document.getElementById('newDnsProvider').value;
            data.dns_provider = provider;
            if (provider === 'cloudflare') {
                data.cf_token = document.getElementById('newCfToken').value;
                if (!data.cf_token) { toast('Cloudflare API Token erforderlich', 'error'); return; }
            } else if (provider === 'hetzner') {
                data.hetzner_token = document.getElementById('newHetznerToken').value;
                if (!data.hetzner_token) { toast('Hetzner DNS API Token erforderlich', 'error'); return; }
            } else if (provider === 'ipv64') {
                data.ipv64_key = document.getElementById('newIpv64Key').value;
                if (!data.ipv64_key) { toast('IPv64 API Key erforderlich', 'error'); return; }
            }
        }
    }

    closeModal('addSiteModal');
    runWithProgress('nginx-add-stream', data,
        (domain.split(/[\s,]+/)[0] || 'Site') + ' erstellen...',
        function() { loadNginx(); loadStats(); }
    );
}

function editSite(index) {
    const s = sitesData[index];
    document.getElementById('editSiteFile').value = s.file;
    document.getElementById('editSiteTitle').textContent = s.domains.join(', ') || s.file;
    document.getElementById('editDomains').value = s.domains.join(', ');
    const m = (s.target || '').match(/https?:\/\/([\d.]+):?(\d*)/);
    document.getElementById('editTargetIp').value = m ? m[1] : '';
    document.getElementById('editTargetPort').value = m && m[2] ? m[2] : '80';
    document.getElementById('editWs').checked = /proxy_set_header Upgrade/.test(s.content);
    document.getElementById('editForceSsl').checked = /return 301 https/.test(s.content);
    // Parse max_upload from content
    const uploadM = s.content.match(/client_max_body_size\s+(\d+)m/i);
    document.getElementById('editMaxUpload').value = uploadM ? uploadM[1] : '';
    // Parse timeout from content
    const timeoutM = s.content.match(/proxy_read_timeout\s+(\d+)/);
    document.getElementById('editTimeout').value = timeoutM ? timeoutM[1] : '';
    document.getElementById('editSiteContent').value = s.content;
    document.getElementById('editConfigWrap').style.display = 'none';
    openModal('editSiteModal');
}

async function saveSite() {
    const file = document.getElementById('editSiteFile').value;
    const configWrap = document.getElementById('editConfigWrap');

    if (configWrap.style.display !== 'none') {
        const content = document.getElementById('editSiteContent').value;
        try {
            const res = await api('nginx-save', 'POST', { file, content });
            if (res.ok) { toast('OK'); closeModal('editSiteModal'); loadNginx(); }
            else { toast(res.error || 'Fehler', 'error'); }
        } catch (e) { toast('Fehler: ' + e.message, 'error'); }
        return;
    }

    const domains = document.getElementById('editDomains').value.trim();
    const ip = document.getElementById('editTargetIp').value.trim();
    const port = document.getElementById('editTargetPort').value.trim() || '80';
    const ws = document.getElementById('editWs').checked ? '1' : '0';
    const forceSsl = document.getElementById('editForceSsl').checked ? '1' : '0';
    const maxUpload = document.getElementById('editMaxUpload').value;
    const timeout = document.getElementById('editTimeout').value;

    if (!domains || !ip) { toast(T.domain_ip_required, 'error'); return; }

    try {
        const res = await api('nginx-update', 'POST', { file, domains, ip, port, ws, force_ssl: forceSsl, max_upload: maxUpload, timeout });
        if (res.ok) { toast('OK'); closeModal('editSiteModal'); loadNginx(); }
        else { toast(res.error || 'Fehler', 'error'); }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function deleteSite(file) {
    if (!await appConfirm('Site löschen', 'Site <strong>' + file + '</strong> wirklich löschen?')) return;
    try {
        const res = await api('nginx-delete', 'POST', { file });
        if (res.ok) {
            toast(T.site_deleted);
            loadNginx();
            loadStats();
        } else {
            toast(res.error || 'Fehler', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

function renewCert(domain) {
    runWithProgress('nginx-renew-stream', { domain },
        'SSL erneuern: ' + domain + '...',
        function() { loadNginx(); }
    );
}

function runWithProgress(endpoint, data, title, onSuccess) {
    const logEl = document.getElementById('sslProgressLog');
    const titleEl = document.getElementById('sslProgressTitle');
    const footEl = document.getElementById('sslProgressFoot');
    logEl.innerHTML = '';
    footEl.style.display = 'none';
    titleEl.innerHTML = '<span class="loading-spinner" style="width:14px;height:14px;border-width:2px;margin-right:8px;vertical-align:middle"></span>' + title;
    openModal('sslProgressModal');

    const fd = new FormData();
    fd.append('_csrf', CSRF);
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));

    fetch('?api=' + endpoint, { method: 'POST', body: fd }).then(function(response) {
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        function read() {
            reader.read().then(function(result) {
                if (result.done) return;
                buffer += decoder.decode(result.value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop();
                lines.forEach(function(line) {
                    if (!line.startsWith('data: ')) return;
                    let d;
                    try { d = JSON.parse(line.slice(6)); } catch(e) { return; }

                    if (d.step && d.message) {
                        const icon = d.ok === false
                            ? '<span style="color:var(--red)">✗</span> '
                            : (d.step === 'done' || d.step === 'ssl-done')
                                ? '<span style="color:var(--green)">✓</span> '
                                : '<span style="color:var(--text3)">›</span> ';
                        logEl.innerHTML += '<div>' + icon + d.message + '</div>';
                        logEl.scrollTop = logEl.scrollHeight;
                    }

                    if (d.done) {
                        footEl.style.display = '';
                        if (d.ok) {
                            titleEl.innerHTML = '<span style="color:var(--green)">✓</span> ' + (d.message || 'Fertig');
                            if (onSuccess) onSuccess();
                        } else {
                            titleEl.innerHTML = '<span style="color:var(--red)">✗</span> Fehlgeschlagen';
                        }
                    }
                });
                read();
            });
        }
        read();
    }).catch(function(e) {
        footEl.style.display = '';
        titleEl.innerHTML = '<span style="color:var(--red)">✗</span> Fehler';
        logEl.innerHTML += '<div style="color:var(--red)">' + e.message + '</div>';
    });
}

async function reloadNginx() {
    try {
        const res = await api('nginx-reload', 'POST', {});
        toast(res.ok ? 'Nginx neu geladen' : (res.error || 'Fehler'), res.ok ? 'success' : 'error');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

// ┌──────────────────────────────────────────────────────────┐
// │              VMs & Container: Liste, Clone, Control       │
// └──────────────────────────────────────────────────────────┘
let _pveVms = [];
let _pveNode = '';

async function loadDashboardVms() {
    try {
        const d = await api('pve-vms');
        if (!d.ok) return;
        document.getElementById('pveVmCount').textContent = d.vms.length;
        const el = document.getElementById('dashVmList');
        if (!d.vms.length) { el.innerHTML = '<span style="color:var(--text3)">Keine VMs/CTs</span>'; return; }

        const running = d.vms.filter(v => v.status === 'running').length;
        const stopped = d.vms.length - running;

        let html = '<div style="display:flex;gap:12px;margin-bottom:8px;font-size:.68rem">';
        html += '<span style="color:var(--green)">' + running + ' running</span>';
        html += '<span style="color:var(--text3)">' + stopped + ' stopped</span>';
        html += '</div>';
        html += '<table style="width:100%;border-collapse:collapse;font-size:.72rem">';
        html += '<thead><tr style="border-bottom:1px solid var(--border-subtle)">';
        html += '<th style="text-align:left;padding:4px 6px;font-size:.6rem;color:var(--text3);font-weight:600">Status</th>';
        html += '<th style="text-align:left;padding:4px 6px;font-size:.6rem;color:var(--text3);font-weight:600">VMID</th>';
        html += '<th style="text-align:left;padding:4px 6px;font-size:.6rem;color:var(--text3);font-weight:600">Name</th>';
        html += '<th style="text-align:left;padding:4px 6px;font-size:.6rem;color:var(--text3);font-weight:600">Typ</th>';
        html += '<th style="text-align:left;padding:4px 6px;font-size:.6rem;color:var(--text3);font-weight:600">vCPU</th>';
        html += '<th style="text-align:left;padding:4px 6px;font-size:.6rem;color:var(--text3);font-weight:600">RAM</th>';
        html += '<th style="text-align:right;padding:4px 6px;font-size:.6rem;color:var(--text3);font-weight:600"></th>';
        html += '</tr></thead><tbody>';
        d.vms.sort((a, b) => a.vmid - b.vmid).forEach(v => {
            const isUp = v.status === 'running';
            const dot = isUp ? 'var(--green)' : 'var(--text3)';
            const typeC = v.type === 'qemu' ? '#a855f7' : 'var(--blue)';
            const typeL = v.type === 'qemu' ? 'VM' : 'CT';
            html += '<tr style="border-bottom:1px solid var(--border-subtle)">';
            html += '<td style="padding:5px 6px"><span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:' + dot + '"></span></td>';
            html += '<td style="padding:5px 6px;font-family:var(--mono);font-weight:600">' + v.vmid + '</td>';
            html += '<td style="padding:5px 6px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + (v.name || '—') + '</td>';
            html += '<td style="padding:5px 6px"><span style="font-size:.58rem;padding:1px 5px;border-radius:3px;background:rgba(' + (v.type==='qemu'?'168,85,247':'64,196,255') + ',.08);color:' + typeC + '">' + typeL + '</span></td>';
            html += '<td style="padding:5px 6px;font-family:var(--mono);font-size:.68rem;color:var(--text2)">' + v.cpus + '</td>';
            html += '<td style="padding:5px 6px;font-family:var(--mono);font-size:.68rem;color:var(--text2)">' + (isUp ? fmtBytes(v.mem_used) + ' <span style="color:var(--text3)">/ ' + fmtBytes(v.mem) + '</span>' : fmtBytes(v.mem)) + '</td>';
            html += '<td style="padding:5px 6px;text-align:right;white-space:nowrap">';
            if (isUp) {
                html += '<button class="btn btn-sm btn-red" onclick="pveVmAction(' + v.vmid + ',\'' + v.type + '\',\'stop\')" style="padding:1px 5px;font-size:.5rem" title="Stop">Stop</button> ';
                html += '<button class="btn btn-sm" onclick="pveVmAction(' + v.vmid + ',\'' + v.type + '\',\'restart\')" style="padding:1px 5px;font-size:.5rem" title="Restart">Restart</button>';
            } else {
                html += '<button class="btn btn-sm btn-green" onclick="pveVmAction(' + v.vmid + ',\'' + v.type + '\',\'start\')" style="padding:1px 5px;font-size:.5rem" title="Start">Start</button>';
            }
            html += '</td></tr>';
        });
        html += '</tbody></table>';
        el.innerHTML = html;
    } catch (e) { }
}

async function pveVmAction(vmid, type, action) {
    toast(action + ' ' + vmid + '...');
    try {
        const res = await api('pve-control', 'POST', { vmid, type, action });
        if (res.ok) {
            toast(vmid + ' → ' + action + ' OK');
            setTimeout(loadDashboardVms, 2000);
        } else {
            toast(res.error || 'Fehler', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function loadPveVms() {
    try {
        const d = await api('pve-vms');
        if (!d.ok) return;
        _pveVms = d.vms;
        _pveNode = d.node;
        document.getElementById('pveVmCount').textContent = d.vms.length;

        const list = document.getElementById('pveVmList');
        if (!d.vms.length) { list.innerHTML = '<div class="empty">Keine VMs oder CTs gefunden</div>'; return; }

        let html = '<table class="data-table"><thead><tr><th style="width:60px">VMID</th><th>Name</th><th style="width:50px">Typ</th><th style="width:70px">Status</th><th>CPU</th><th>RAM</th><th>Disk</th><th style="width:90px"></th></tr></thead><tbody>';
        d.vms.forEach(v => {
            const isUp = v.status === 'running';
            const statusTag = isUp ? '<span class="tag tag-green" style="font-size:.46rem">Running</span>' : '<span class="tag tag-muted" style="font-size:.46rem">Stopped</span>';
            const typeTag = v.type === 'qemu' ? '<span style="font-size:.58rem;padding:1px 5px;border-radius:3px;background:rgba(168,85,247,.08);color:#a855f7">VM</span>' : '<span style="font-size:.58rem;padding:1px 5px;border-radius:3px;background:rgba(64,196,255,.08);color:var(--blue)">CT</span>';

            html += '<tr>' +
                '<td style="font-family:var(--mono);font-size:.78rem;font-weight:600">' + v.vmid + '</td>' +
                '<td style="font-size:.78rem;font-weight:500">' + (v.name || '—') + '</td>' +
                '<td>' + typeTag + '</td>' +
                '<td>' + statusTag + '</td>' +
                '<td style="font-size:.72rem;color:var(--text2)">' + v.cpus + ' vCPU</td>' +
                '<td style="font-size:.72rem"><span style="color:var(--text2)">' + fmtBytes(v.mem_used) + '</span> <span style="color:var(--text3)">/ ' + fmtBytes(v.mem) + '</span></td>' +
                '<td style="font-size:.72rem"><span style="color:var(--text2)">' + fmtBytes(v.disk_used) + '</span> <span style="color:var(--text3)">/ ' + fmtBytes(v.disk) + '</span></td>' +
                '<td style="text-align:right"><button class="btn btn-sm" onclick="pveOpenClone(' + v.vmid + ',\'' + v.type + '\',\'' + (v.name || '').replace(/'/g, "\\'") + '\')" title="Clone"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> Clone</button></td>' +
            '</tr>';
        });
        html += '</tbody></table>';
        list.innerHTML = html;
    } catch (e) {
    }
}

async function pveOpenClone(vmid, type, name) {
    const typeLabel = type === 'qemu' ? 'VM' : 'CT';
    document.getElementById('pveCloneTitle').textContent = T.clone + ' ' + typeLabel + ' ' + vmid + ' (' + name + ')';

    document.getElementById('pveCloneBody').innerHTML = '<div style="text-align:center;padding:24px"><span class="loading-spinner" style="width:20px;height:20px;border-width:2px"></span></div>';
    openModal('pveCloneModal');

    // Fetch all data in parallel
    const [nextId, storages, config] = await Promise.all([
        api('pve-nextid'),
        api('pve-storages'),
        api('pve-config&vmid=' + vmid + '&type=' + type)
    ]);
    const newId = nextId.ok && nextId.vmid ? nextId.vmid : '';
    const cfg = config.ok ? config.config : {};
    const cores = cfg.cores || 1;
    const memory = cfg.memory || 2048;
    const swap = cfg.swap || 0;
    const onboot = cfg.onboot || 0;

    // Parse network interfaces
    let netInfo = '';
    for (let i = 0; i < 10; i++) {
        if (cfg['net' + i]) {
            const net = cfg['net' + i];
            const bridge = (net.match(/bridge=([^,]+)/) || [])[1] || '?';
            const ip = (net.match(/ip=([^,]+)/) || [])[1] || 'DHCP';
            netInfo += '<div style="font-size:.62rem;color:var(--text3);font-family:var(--mono)">net' + i + ': ' + bridge + ' &middot; ' + ip + '</div>';
        }
    }

    let storOpts = '<option value="">Wie Quelle</option>';
    if (storages.ok) {
        storages.storages.forEach(s => {
            const free = s.avail > 0 ? ' (' + fmtBytes(s.avail) + ' frei)' : '';
            storOpts += '<option value="' + s.name + '">' + s.name + free + '</option>';
        });
    }

    document.getElementById('pveCloneBody').innerHTML = `
        <div style="padding:8px 12px;background:rgba(255,255,255,.02);border:1px solid var(--border-subtle);border-radius:6px;margin-bottom:14px;display:flex;align-items:center;gap:8px">
            <span style="font-size:.58rem;padding:2px 6px;border-radius:3px;background:${type === 'qemu' ? 'rgba(168,85,247,.08);color:#a855f7' : 'rgba(64,196,255,.08);color:var(--blue)'}">${typeLabel}</span>
            <span style="font-size:.82rem;font-weight:600">${name || 'ID ' + vmid}</span>
            <span style="font-family:var(--mono);font-size:.68rem;color:var(--text3)">${cores} vCPU &middot; ${memory} MB RAM</span>
        </div>
        <input type="hidden" id="pveCloneVmid" value="${vmid}">
        <input type="hidden" id="pveCloneType" value="${type}">

        <div style="display:flex;gap:10px;margin-bottom:12px">
            <div style="flex:1">
                <label class="form-label">Neue VMID</label>
                <input class="form-input" id="pveCloneNewId" type="number" value="${newId}" min="100">
            </div>
            <div style="flex:2">
                <label class="form-label">Name</label>
                <input class="form-input" id="pveCloneName" value="${(name || '') + '-clone'}">
            </div>
        </div>

        <div style="display:flex;gap:8px;margin-bottom:12px">
            <label style="flex:1;display:flex;align-items:center;gap:8px;padding:8px 12px;border:2px solid var(--accent);border-radius:6px;cursor:pointer" id="pveCloneFullLabel">
                <input type="radio" name="pveCloneMode" value="1" checked style="accent-color:var(--accent)" onchange="pveCloneModeChange()">
                <div><div style="font-size:.76rem;font-weight:600">Full Clone</div><div style="font-size:.58rem;color:var(--text3)">Unabhängige Kopie</div></div>
            </label>
            <label style="flex:1;display:flex;align-items:center;gap:8px;padding:8px 12px;border:2px solid var(--border-subtle);border-radius:6px;cursor:pointer" id="pveCloneLinkedLabel">
                <input type="radio" name="pveCloneMode" value="0" style="accent-color:var(--accent)" onchange="pveCloneModeChange()">
                <div><div style="font-size:.76rem;font-weight:600">Linked Clone</div><div style="font-size:.58rem;color:var(--text3)">Schnell, abhängig</div></div>
            </label>
        </div>

        <!-- Hardware Settings -->
        <div style="border:1px solid var(--border-subtle);border-radius:8px;padding:10px 12px;margin-bottom:12px">
            <div style="font-size:.68rem;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">Hardware anpassen</div>
            <div style="display:flex;gap:8px;margin-bottom:8px">
                <div style="flex:1">
                    <label style="font-size:.65rem;color:var(--text3);display:block;margin-bottom:2px">CPU Cores</label>
                    <input class="form-input" id="pveCloneCores" type="number" value="${cores}" min="1" max="128" style="padding:4px 8px;font-size:.75rem">
                </div>
                <div style="flex:1">
                    <label style="font-size:.65rem;color:var(--text3);display:block;margin-bottom:2px">RAM (MB)</label>
                    <input class="form-input" id="pveCloneMemory" type="number" value="${memory}" min="128" step="128" style="padding:4px 8px;font-size:.75rem">
                </div>
                ${type === 'lxc' ? `<div style="flex:1">
                    <label style="font-size:.65rem;color:var(--text3);display:block;margin-bottom:2px">Swap (MB)</label>
                    <input class="form-input" id="pveCloneSwap" type="number" value="${swap}" min="0" step="128" style="padding:4px 8px;font-size:.75rem">
                </div>` : ''}
            </div>
            <div style="display:flex;gap:12px;align-items:center">
                <label style="display:flex;align-items:center;gap:5px;font-size:.72rem;cursor:pointer">
                    <input type="checkbox" id="pveCloneNetDisconnect" style="accent-color:var(--accent);width:13px;height:13px">
                    Netzwerk trennen
                </label>
                <label style="display:flex;align-items:center;gap:5px;font-size:.72rem;cursor:pointer">
                    <input type="checkbox" id="pveCloneAutoStart" style="accent-color:var(--accent);width:13px;height:13px">
                    Nach Clone starten
                </label>
                <label style="display:flex;align-items:center;gap:5px;font-size:.72rem;cursor:pointer">
                    <input type="checkbox" id="pveCloneOnboot" ${onboot ? 'checked' : ''} style="accent-color:var(--accent);width:13px;height:13px">
                    Autostart (Boot)
                </label>
            </div>
            ${netInfo ? '<div style="margin-top:6px;padding-top:6px;border-top:1px solid var(--border-subtle)">' + netInfo + '</div>' : ''}
        </div>

        <div id="pveCloneStorageRow" style="margin-bottom:12px">
            <label class="form-label">Ziel-Storage</label>
            <select class="form-input" id="pveCloneStorage">${storOpts}</select>
        </div>
        <div>
            <label class="form-label">Beschreibung <span style="color:var(--text3);font-size:.55rem">(optional)</span></label>
            <input class="form-input" id="pveCloneDesc" value="Clone von ${name || typeLabel + ' ' + vmid}" style="font-size:.75rem">
        </div>
    `;

    const btn = document.getElementById('pveCloneBtn');
    btn.disabled = false;
    btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>Clone starten';
}

function pveCloneModeChange() {
    const full = document.querySelector('input[name="pveCloneMode"]:checked')?.value === '1';
    document.getElementById('pveCloneStorageRow').style.display = full ? '' : 'none';
    document.getElementById('pveCloneFullLabel').style.borderColor = full ? 'var(--accent)' : 'var(--border-subtle)';
    document.getElementById('pveCloneLinkedLabel').style.borderColor = !full ? 'var(--accent)' : 'var(--border-subtle)';
}

async function pveDoClone() {
    const vmid = document.getElementById('pveCloneVmid').value;
    const type = document.getElementById('pveCloneType').value;
    const newid = document.getElementById('pveCloneNewId').value;
    const name = document.getElementById('pveCloneName').value.trim();
    const full = document.querySelector('input[name="pveCloneMode"]:checked')?.value || '1';
    const storage = document.getElementById('pveCloneStorage')?.value || '';
    const description = document.getElementById('pveCloneDesc')?.value?.trim() || '';
    const cores = document.getElementById('pveCloneCores')?.value || '';
    const memory = document.getElementById('pveCloneMemory')?.value || '';
    const swap = document.getElementById('pveCloneSwap')?.value || '';
    const netDisconnect = document.getElementById('pveCloneNetDisconnect')?.checked ? '1' : '0';
    const autoStart = document.getElementById('pveCloneAutoStart')?.checked;
    const onboot = document.getElementById('pveCloneOnboot')?.checked ? '1' : '0';

    if (!newid || !name) { toast(T.vmid_name_required, 'error'); return; }

    const btn = document.getElementById('pveCloneBtn');
    btn.disabled = true;

    // Step 1: Clone
    btn.innerHTML = '<span class="loading-spinner" style="width:12px;height:12px;border-width:1.5px;margin-right:4px"></span>1/3 Cloning...';
    try {
        const res = await api('pve-clone', 'POST', { vmid, type, newid, name, full, storage, description });
        if (!res.ok) {
            toast(res.output || res.error || T.clone_failed, 'error');
            btn.disabled = false; btn.innerHTML = T.clone_start;
            return;
        }
        toast(T.clone_started);

        // Step 2: Wait for clone to finish (poll every 3s, max 120s)
        btn.innerHTML = '<span class="loading-spinner" style="width:12px;height:12px;border-width:1.5px;margin-right:4px"></span>2/3 Warte...';
        let ready = false;
        for (let i = 0; i < 40; i++) {
            await new Promise(r => setTimeout(r, 3000));
            const vms = await api('pve-vms');
            if (vms.ok && vms.vms.some(v => v.vmid == newid)) { ready = true; break; }
        }

        if (!ready) {
            toast(T.clone_background, 'success');
            closeModal('pveCloneModal');
            setTimeout(loadPveVms, 5000);
            return;
        }

        // Step 3: Apply hardware changes
        btn.innerHTML = '<span class="loading-spinner" style="width:12px;height:12px;border-width:1.5px;margin-right:4px"></span>3/3 Config...';
        const cfgRes = await api('pve-setconfig', 'POST', {
            vmid: newid, type, cores, memory, swap, onboot,
            net_disconnect: netDisconnect
        });
        if (cfgRes.ok) {
            toast(T.hw_config_saved);
        }

        // Optional: Start
        if (autoStart) {
            toast((type === 'qemu' ? 'VM' : 'CT') + ' ' + newid + ' wird gestartet...');
            // Start via pvesh
            await api('pve-control', 'POST', { vmid: newid, type, action: 'start' });
        }

        toast(name + ' (ID ' + newid + ') erfolgreich geclont!');
        closeModal('pveCloneModal');
        setTimeout(loadPveVms, 2000);

    } catch (e) {
        toast(T.error + ': ' + e.message, 'error');
        btn.disabled = false; btn.innerHTML = T.clone_start;
    }
}

// ┌──────────────────────────────────────────────────────────┐
// │              ZFS: Pools, Datasets, Snapshots              │
// └──────────────────────────────────────────────────────────┘
let _zfsData = null;

function zfsSwitchTab(tab, btn) {
    document.querySelectorAll('#panel-zfs [id^="zfsTab"]').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.zfs-sub').forEach(b => { b.style.background = ''; b.style.color = ''; b.classList.remove('active'); });
    const panel = document.getElementById('zfsTab' + tab.charAt(0).toUpperCase() + tab.slice(1));
    if (panel) panel.style.display = '';
    if (btn) { btn.style.background = 'var(--accent)'; btn.style.color = '#fff'; btn.classList.add('active'); }
}

async function loadZfs() {
    // Set active sub-tab button
    const poolsBtn = document.querySelector('.zfs-sub[data-zfstab="pools"]');
    if (poolsBtn && !document.querySelector('.zfs-sub[style*="var(--accent)"]')) {
        zfsSwitchTab('pools', poolsBtn);
    }

    // Show loading state
    const poolsEl = document.getElementById('zfsPools');
    if (poolsEl && !poolsEl.innerHTML) poolsEl.innerHTML = '<div style="padding:12px;color:var(--text3);font-size:.75rem"><span class="loading-spinner" style="width:12px;height:12px;border-width:1.5px;margin-right:6px"></span>' + T.loading + '</div>';

    try {
        const d = await api('zfs-status');
        if (!d.ok) return;
        _zfsData = d;

        // Pools
        const poolsEl = document.getElementById('zfsPools');
        if (d.pools.length) {
            poolsEl.innerHTML = '<div style="display:flex;gap:10px;flex-wrap:wrap">' + d.pools.map(p => {
                const cap = parseInt(p.cap) || 0;
                const barClass = cap > 85 ? 'red' : cap > 70 ? '' : 'green';
                const hc = p.health === 'ONLINE' ? 'var(--green)' : 'var(--red)';
                return '<div class="stat-card" style="flex:1;min-width:200px">' +
                    '<div class="stat-label"><span class="indicator" style="background:' + hc + ';box-shadow:0 0 6px ' + hc + '"></span>' + p.name + '</div>' +
                    '<div class="stat-value">' + cap + '%</div>' +
                    '<div class="stat-sub">' + fmtBytes(p.alloc) + ' / ' + fmtBytes(p.size) + ' &middot; ' + p.health + (p.frag !== '0' ? ' &middot; Frag: ' + p.frag + '%' : '') + '</div>' +
                    '<div class="progress-wrap"><div class="progress-bar ' + barClass + '" style="width:' + cap + '%"></div></div>' +
                '</div>';
            }).join('') + '</div>';
        } else {
            poolsEl.innerHTML = '';
        }

        // Datasets
        document.getElementById('zfsDsBody').innerHTML = d.datasets.map(ds => {
            const p = pct(ds.used, ds.total);
            const barClass = p > 85 ? 'red' : p > 70 ? '' : 'green';
            const isSubvol = ds.name.includes('subvol-');
            const vmMatch = ds.name.match(/subvol-(\d+)-disk/);
            const vmLabel = vmMatch ? ' <span style="font-size:.58rem;padding:1px 5px;border-radius:3px;background:rgba(64,196,255,.08);color:var(--blue)">CT ' + vmMatch[1] + '</span>' : '';
            return '<tr><td style="font-family:var(--mono);font-size:.75rem">' + ds.name + vmLabel + '</td>' +
                '<td style="font-size:.75rem">' + fmtBytes(ds.used) + '</td>' +
                '<td style="font-size:.75rem">' + fmtBytes(ds.avail) + '</td>' +
                '<td style="font-size:.7rem;color:var(--text3)">' + (ds.mount || '-') + '</td>' +
                '<td><div style="display:flex;align-items:center;gap:6px"><span style="font-family:var(--mono);font-size:.7rem;min-width:28px">' + p + '%</span><div class="progress-wrap" style="flex:1;margin:0"><div class="progress-bar ' + barClass + '" style="width:' + p + '%"></div></div></div></td></tr>';
        }).join('');

        // Auto-Snapshots
        const autoStatus = document.getElementById('zfsAutoStatus');
        const autoBody = document.getElementById('zfsAutoBody');
        if (!d.auto_installed) {
            autoStatus.innerHTML = '<span class="tag tag-red">' + T.zfs_not_installed + '</span>';
            autoBody.innerHTML = '<div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:16px;text-align:center">' +
                '<div style="font-size:.8rem;color:var(--text2);margin-bottom:10px">' + T.zfs_auto_not_installed_desc + '</div>' +
                '<button class="btn btn-accent" onclick="zfsInstallAuto()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> ' + T.zfs_install_now + '</button></div>';
        } else {
            autoStatus.innerHTML = '<span class="tag tag-green">' + T.installed + '</span>';
            let autoHtml = '<table class="data-table" style="margin-bottom:10px"><thead><tr><th>' + T.interval + '</th><th>' + T.status + '</th><th>' + T.keep + '</th><th>' + T.timespan + '</th></tr></thead><tbody>';
            const intervals = {frequent:T.interval_frequent, hourly:T.interval_hourly, daily:T.interval_daily, weekly:T.interval_weekly, monthly:T.interval_monthly};
            d.auto_crons.forEach(c => {
                const desc = intervals[c.label] || c.label;
                let timespan = '';
                if (c.label === 'frequent') timespan = c.keep * 15 + ' ' + T.unit_min;
                else if (c.label === 'hourly') timespan = c.keep + ' ' + T.unit_hours;
                else if (c.label === 'daily') timespan = c.keep + ' ' + T.unit_days;
                else if (c.label === 'weekly') timespan = c.keep + ' ' + T.unit_weeks;
                else if (c.label === 'monthly') timespan = c.keep + ' ' + T.unit_months;
                autoHtml += '<tr>' +
                    '<td style="font-size:.75rem;font-weight:500">' + desc + ' <span style="font-size:.58rem;color:var(--text3)">(' + c.label + ')</span></td>' +
                    '<td>' + (c.exists ? '<span class="tag tag-green" style="font-size:.46rem">' + T.active + '</span>' : '<span class="tag tag-muted" style="font-size:.46rem">' + T.off + '</span>') + '</td>' +
                    '<td><input type="number" min="1" max="999" value="' + c.keep + '" style="width:60px;font-family:var(--mono);font-size:.72rem;padding:1px 4px;background:var(--surface);border:1px solid var(--border-subtle);border-radius:4px;color:var(--text);text-align:center" onchange="zfsSetRetention(\'' + c.label + '\',this.value);zfsUpdateTimespan(this)" data-label="' + c.label + '" data-orig="' + c.keep + '"></td>' +
                    '<td class="zfs-timespan" style="font-size:.72rem;color:var(--text3)">' + timespan + '</td>' +
                '</tr>';
            });
            autoHtml += '</tbody></table>';

            // Per-dataset toggles
            autoHtml += '<div style="font-size:.65rem;color:var(--text3);margin-bottom:4px">' + T.per_dataset + ':</div>';
            autoHtml += '<div style="display:flex;flex-wrap:wrap;gap:4px">';
            d.datasets.forEach(ds => {
                const short = ds.name.split('/').pop();
                const vmMatch = ds.name.match(/subvol-(\d+)/);
                const label = vmMatch ? 'CT ' + vmMatch[1] : short;
                autoHtml += '<label style="display:flex;align-items:center;gap:5px;padding:4px 8px;background:var(--surface);border:1px solid var(--border-subtle);border-radius:4px;cursor:pointer;font-size:.68rem">' +
                    '<input type="checkbox" onchange="zfsToggleAuto(\'' + ds.name + '\',this.checked)" checked style="accent-color:var(--accent);width:13px;height:13px">' +
                    '<span>' + label + '</span></label>';
            });
            autoHtml += '</div>';
            autoBody.innerHTML = autoHtml;
        }

        // Populate filter dropdown
        const filterSel = document.getElementById('zfsSnapFilter');
        const curFilter = filterSel.value;
        filterSel.innerHTML = '<option value="">' + T.all_datasets + '</option>';
        const dsNames = [...new Set(d.snapshots.map(s => s.dataset))];
        dsNames.forEach(n => {
            const short = n.includes('subvol-') ? n.match(/subvol-(\d+)/)?.[0] || n : n.split('/').pop();
            filterSel.innerHTML += '<option value="' + n + '"' + (curFilter === n ? ' selected' : '') + '>' + short + '</option>';
        });

        zfsRenderSnaps();
    } catch (e) {
    }
}

function zfsRenderSnaps() {
    if (!_zfsData) return;
    const sort = document.getElementById('zfsSnapSort')?.value || 'date-desc';
    const filter = document.getElementById('zfsSnapFilter')?.value || '';
    const SHOW_LAST = 5;

    let snaps = [..._zfsData.snapshots];
    if (filter) snaps = snaps.filter(s => s.dataset === filter);

    if (sort === 'date-desc') snaps.sort((a, b) => b.created_ts - a.created_ts);
    else if (sort === 'date-asc') snaps.sort((a, b) => a.created_ts - b.created_ts);
    else if (sort === 'size-desc') snaps.sort((a, b) => b.used - a.used);
    else if (sort === 'name-asc') snaps.sort((a, b) => a.name.localeCompare(b.name));

    document.getElementById('zfsSnapCount').textContent = snaps.length;
    const body = document.getElementById('zfsSnapBody');

    if (!snaps.length) {
        body.innerHTML = '<div class="empty" style="padding:16px">Keine Snapshots' + (filter ? ' für dieses Dataset' : '') + '</div>';
        return;
    }

    // VM/CT name lookup from cached PVE data
    const vmNames = {};
    if (_pveVms && _pveVms.length) {
        _pveVms.forEach(v => { vmNames[String(v.vmid)] = v.name; });
    }

    // Group by dataset
    const groups = {};
    snaps.forEach(s => {
        if (!groups[s.dataset]) groups[s.dataset] = [];
        groups[s.dataset].push(s);
    });

    let html = '';
    Object.entries(groups).forEach(([ds, items], gi) => {
        const vmMatch = ds.match(/subvol-(\d+)/);
        const vmid = vmMatch ? vmMatch[1] : '';
        const vmName = vmid && vmNames[vmid] ? vmNames[vmid] : '';
        const typeLabel = vmMatch ? 'CT' : '';
        const label = vmMatch ? typeLabel + ' ' + vmid : ds.split('/').pop() || ds;
        const totalUsed = items.reduce((sum, s) => sum + s.used, 0);
        const hasMore = items.length > SHOW_LAST;
        const groupId = 'zsg_' + gi;

        // Group header
        html += '<div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:8px;margin-bottom:8px;overflow:hidden">';
        html += '<div style="display:flex;align-items:center;gap:8px;padding:8px 12px;border-bottom:1px solid var(--border-subtle)">' +
            '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" style="flex-shrink:0"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>' +
            '<span style="font-size:.78rem;font-weight:600">' + label + '</span>' +
            (vmName ? '<span style="font-size:.68rem;color:var(--text2)">' + vmName + '</span>' : '') +
            '<span style="font-size:.55rem;padding:1px 6px;border-radius:10px;background:rgba(255,255,255,.04);color:var(--text3);font-family:var(--mono)">' + items.length + '</span>' +
            '<span style="flex:1"></span>' +
            '<span style="font-size:.62rem;color:var(--text3);font-family:var(--mono)">' + fmtBytes(totalUsed) + '</span>' +
        '</div>';

        // Snapshot rows
        html += '<table class="data-table" style="border:none;border-radius:0;margin:0"><tbody>';

        items.forEach((s, si) => {
            const isAuto = s.snap.startsWith('zfs-auto-snap');
            const esc = s.name.replace(/'/g, "\\'");
            const hidden = hasMore && si >= SHOW_LAST;
            const snapShort = isAuto ? s.snap.replace('zfs-auto-snap_', '') : s.snap;

            html += '<tr' + (hidden ? ' class="zsg-hidden-' + groupId + '" style="display:none"' : '') + '>' +
                '<td style="font-family:var(--mono);font-size:.65rem;padding:4px 12px;color:' + (isAuto ? 'var(--text3)' : 'var(--text2)') + '"><span style="color:var(--text3)">@</span>' + snapShort + '</td>' +
                '<td style="font-size:.62rem;color:var(--text3);width:65px">' + fmtBytes(s.used) + '</td>' +
                '<td style="font-size:.6rem;color:var(--text3);width:100px">' + s.created + '</td>' +
                '<td style="text-align:right;padding:2px 8px;width:80px"><div style="display:flex;gap:2px;justify-content:flex-end">' +
                    '<button class="btn btn-sm" onclick="zfsRollback(\'' + esc + '\')" title="Rollback" style="padding:1px 4px;font-size:.5rem"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg></button>' +
                    (s.dataset.match(/subvol-|vm-|base-/) ?
                        '<button class="btn btn-sm btn-green" onclick="zfsSnapCloneVm(\'' + esc + '\')" title="Als VM/CT clonen" style="padding:1px 4px;font-size:.5rem"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg></button>' :
                        '<button class="btn btn-sm" onclick="zfsClone(\'' + esc + '\')" title="Dataset Clone" style="padding:1px 4px;font-size:.5rem"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>') +
                    '<button class="btn btn-sm btn-red" onclick="zfsDeleteSnap(\'' + esc + '\')" title="' + T.delete + '" style="padding:1px 4px;font-size:.5rem"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg></button>' +
                '</div></td></tr>';
        });

        html += '</tbody></table>';

        // "Show more" button
        if (hasMore) {
            html += '<div style="text-align:center;padding:4px"><button class="btn btn-sm" style="font-size:.58rem;padding:2px 12px" onclick="document.querySelectorAll(\'.zsg-hidden-' + groupId + '\').forEach(r=>r.style.display=\'\');this.remove()">+ ' + (items.length - SHOW_LAST) + ' ' + T.show_more + '</button></div>';
        }

        html += '</div>';
    });
    body.innerHTML = html;
}

async function zfsInstallAuto() {
    toast(T.zfs_installing);
    try {
        const res = await api('zfs-install-auto', 'POST', {});
        if (res.ok) { toast(T.zfs_installed_msg); loadZfs(); }
        else toast(res.output || 'Fehler', 'error');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function zfsToggleAuto(dataset, enabled) {
    try {
        const res = await api('zfs-auto-toggle', 'POST', { dataset, enabled: enabled ? '1' : '0' });
        if (res.ok) toast((enabled ? T.auto_snap_enabled : T.auto_snap_disabled) + ': ' + dataset.split('/').pop());
        else toast(res.output || 'Fehler', 'error');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function zfsSetRetention(label, value) {
    const keep = parseInt(value);
    if (!keep || keep < 1 || keep > 999) { toast(T.retention_range_error, 'error'); return; }
    try {
        const res = await api('zfs-set-retention', 'POST', { label, keep });
        if (res.ok) toast(T.retention_saved.replace('%label%', label).replace('%keep%', keep));
        else toast(res.error || 'Fehler', 'error');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

function zfsUpdateTimespan(input) {
    const label = input.dataset.label;
    const keep = parseInt(input.value) || 0;
    const td = input.closest('tr').querySelector('.zfs-timespan');
    if (!td) return;
    let text = '';
    if (label === 'frequent') text = (keep * 15) + ' ' + T.unit_min;
    else if (label === 'hourly') text = keep + ' ' + T.unit_hours;
    else if (label === 'daily') text = keep + ' ' + T.unit_days;
    else if (label === 'weekly') text = keep + ' ' + T.unit_weeks;
    else if (label === 'monthly') text = keep + ' ' + T.unit_months;
    td.textContent = text;
}

function zfsCreateSnapModal() {
    if (!_zfsData || !_zfsData.datasets.length) { toast(T.no_datasets, 'error'); return; }
    const ds = _zfsData.datasets;
    const defaultName = 'manual-' + new Date().toISOString().slice(0,19).replace(/[T:]/g, '-');
    let body = '<div class="form-group"><label class="form-label">Dataset</label>' +
        '<select id="zfsSnapDs" class="form-input" style="font-size:.75rem">' +
        ds.map(d => '<option value="' + d.name + '">' + d.name + '</option>').join('') +
        '</select></div>' +
        '<div class="form-group"><label class="form-label">Snapshot-Name</label>' +
        '<input class="form-input" id="zfsSnapName" value="' + defaultName + '" style="font-family:var(--mono);font-size:.75rem"></div>';

    let modal = document.getElementById('zfsSnapModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'zfsSnapModal';
        modal.className = 'modal-overlay';
        modal.innerHTML = '<div class="modal" style="max-width:450px"><div class="modal-head"><div class="modal-title">Neuer Snapshot</div><button class="modal-close" onclick="closeModal(\'zfsSnapModal\')">&times;</button></div><div class="modal-body" id="zfsSnapModalBody"></div><div class="modal-foot"><button class="btn" onclick="closeModal(\'zfsSnapModal\')">Abbrechen</button><button class="btn btn-accent" onclick="zfsDoSnap()">Erstellen</button></div></div>';
        document.body.appendChild(modal);
    }
    document.getElementById('zfsSnapModalBody').innerHTML = body;
    openModal('zfsSnapModal');
}

async function zfsDoSnap() {
    const dataset = document.getElementById('zfsSnapDs')?.value;
    const name = document.getElementById('zfsSnapName')?.value?.trim();
    if (!dataset || !name) { toast(T.dataset_name_required, 'error'); return; }
    closeModal('zfsSnapModal');
    try {
        const res = await api('zfs-snapshot', 'POST', { dataset, name });
        if (res.ok) { toast('Snapshot erstellt: ' + res.snapshot); loadZfs(); }
        else toast(res.error || res.output || 'Fehler', 'error');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function zfsDeleteSnap(snap) {
    if (!await appConfirm('Snapshot löschen', 'Snapshot <strong>' + snap.split('@')[1] + '</strong> löschen?')) return;
    try {
        const res = await api('zfs-destroy-snap', 'POST', { snapshot: snap });
        if (res.ok) { toast(T.snap_deleted); loadZfs(); }
        else toast(res.output || 'Fehler', 'error');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function zfsRollback(snap) {
    const parts = snap.split('@');
    if (!await appConfirm('Rollback', '<strong>ACHTUNG:</strong> Rollback auf <strong>' + parts[1] + '</strong>?<br><br>Alle neueren Snapshots werden gelöscht!<br>Dataset: <code>' + parts[0] + '</code>')) return;
    try {
        const res = await api('zfs-rollback', 'POST', { snapshot: snap });
        if (res.ok) { toast('Rollback erfolgreich auf ' + parts[1]); loadZfs(); }
        else toast(res.output || 'Fehler', 'error');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function zfsSnapCloneVm(snap) {
    const parts = snap.split('@');
    const dataset = parts[0];
    const snapName = parts[1];
    const vmMatch = dataset.match(/(subvol|vm|base)-(\d+)/);
    const sourceVmid = vmMatch ? vmMatch[2] : '?';
    const isLxc = vmMatch && (vmMatch[1] === 'subvol' || vmMatch[1] === 'base');
    const typeLabel = isLxc ? 'CT' : 'VM';

    // Get next free VMID
    const nextId = await api('pve-nextid');
    const newId = nextId.ok && nextId.vmid ? nextId.vmid : '';

    // Get source config for defaults
    const srcType = isLxc ? 'lxc' : 'qemu';
    const config = await api('pve-config&vmid=' + sourceVmid + '&type=' + srcType);
    const cfg = config.ok ? config.config : {};
    const cores = cfg.cores || 1;
    const memory = cfg.memory || 2048;
    const swap = cfg.swap || 0;
    const onboot = cfg.onboot || 0;
    const srcName = cfg.hostname || cfg.name || typeLabel + '-' + sourceVmid;

    let modal = document.getElementById('zfsSnapCloneModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'zfsSnapCloneModal';
        modal.className = 'modal-overlay';
        modal.innerHTML = '<div class="modal" style="max-width:500px"><div class="modal-head"><div class="modal-title" id="zfsSnapCloneTitle"></div><button class="modal-close" onclick="closeModal(\'zfsSnapCloneModal\')">&times;</button></div><div class="modal-body" id="zfsSnapCloneBody"></div><div class="modal-foot"><button class="btn" onclick="closeModal(\'zfsSnapCloneModal\')">Abbrechen</button><button class="btn btn-accent" id="zfsSnapCloneBtn" onclick="zfsSnapCloneSubmit()">Clone starten</button></div></div>';
        document.body.appendChild(modal);
    }

    // Parse network from source config
    let srcIp = '', srcGw = '', srcBridge = '', srcDns = cfg.nameserver || '', srcIp6 = '', srcGw6 = '';
    const net0 = cfg.net0 || '';
    if (net0) {
        srcIp = (net0.match(/ip=([^,]+)/) || [])[1] || '';
        srcGw = (net0.match(/gw=([^,]+)/) || [])[1] || '';
        srcBridge = (net0.match(/bridge=([^,]+)/) || [])[1] || '';
        srcIp6 = (net0.match(/ip6=([^,]+)/) || [])[1] || '';
        srcGw6 = (net0.match(/gw6=([^,]+)/) || [])[1] || '';
    }

    document.getElementById('zfsSnapCloneTitle').innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2" style="margin-right:6px"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>' + typeLabel + ' ' + sourceVmid + ' aus Snapshot clonen';
    document.getElementById('zfsSnapCloneBody').innerHTML = `
        <div style="padding:10px 14px;background:rgba(255,255,255,.02);border:1px solid var(--border-subtle);border-radius:8px;margin-bottom:14px;display:flex;align-items:center;gap:10px">
            <div style="width:36px;height:36px;border-radius:8px;background:${isLxc ? 'rgba(64,196,255,.08)' : 'rgba(168,85,247,.08)'};display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="${isLxc ? 'var(--blue)' : '#a855f7'}" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
            </div>
            <div style="flex:1">
                <div style="font-size:.82rem;font-weight:600">${srcName} <span style="font-size:.62rem;font-weight:400;color:var(--text3)">${typeLabel} ${sourceVmid}</span></div>
                <div style="font-family:var(--mono);font-size:.6rem;color:var(--text3)">@${snapName}</div>
            </div>
        </div>
        <input type="hidden" id="zscSnap" value="${snap}">
        <input type="hidden" id="zscType" value="${srcType}">

        <!-- Basics -->
        <div style="display:flex;gap:10px;margin-bottom:14px">
            <div style="flex:1">
                <label class="form-label">Neue VMID</label>
                <input class="form-input" id="zscNewId" type="number" value="${newId}" min="100">
            </div>
            <div style="flex:2">
                <label class="form-label">${isLxc ? 'Hostname' : 'Name'}</label>
                <input class="form-input" id="zscName" value="${srcName}-clone">
            </div>
        </div>

        <!-- Hardware -->
        <div style="border:1px solid var(--border-subtle);border-radius:8px;padding:12px 14px;margin-bottom:14px">
            <div style="font-size:.65rem;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">Hardware</div>
            <div style="display:flex;gap:10px">
                <div style="flex:1">
                    <label style="font-size:.65rem;color:var(--text3);display:block;margin-bottom:3px">CPU Cores</label>
                    <input class="form-input" id="zscCores" type="number" value="${cores}" min="1" style="padding:5px 8px;font-size:.75rem">
                </div>
                <div style="flex:1">
                    <label style="font-size:.65rem;color:var(--text3);display:block;margin-bottom:3px">RAM (MB)</label>
                    <input class="form-input" id="zscMem" type="number" value="${memory}" min="128" step="128" style="padding:5px 8px;font-size:.75rem">
                </div>
                <div style="flex:1${!isLxc ? ';opacity:.35' : ''}">
                    <label style="font-size:.65rem;color:var(--text3);display:block;margin-bottom:3px">Swap (MB)</label>
                    <input class="form-input" id="zscSwap" type="number" value="${swap}" min="0" step="128" style="padding:5px 8px;font-size:.75rem" ${!isLxc ? 'disabled' : ''}>
                </div>
            </div>
        </div>

        <!-- Netzwerk -->
        <div style="border:1px solid var(--border-subtle);border-radius:8px;padding:12px 14px;margin-bottom:14px">
            <div style="font-size:.65rem;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">Netzwerk</div>
            <div style="display:flex;gap:8px;margin-bottom:10px">
                <label style="flex:1;display:flex;align-items:center;gap:8px;padding:6px 10px;border:2px solid var(--accent);border-radius:6px;cursor:pointer;font-size:.72rem" id="zscNetKeepLabel">
                    <input type="radio" name="zscNetMode" value="keep" checked style="accent-color:var(--accent)" onchange="zscNetModeChange()"> Beibehalten
                </label>
                <label style="flex:1;display:flex;align-items:center;gap:8px;padding:6px 10px;border:2px solid var(--border-subtle);border-radius:6px;cursor:pointer;font-size:.72rem" id="zscNetCustomLabel">
                    <input type="radio" name="zscNetMode" value="custom" style="accent-color:var(--accent)" onchange="zscNetModeChange()"> Anpassen
                </label>
                <label style="flex:1;display:flex;align-items:center;gap:8px;padding:6px 10px;border:2px solid var(--border-subtle);border-radius:6px;cursor:pointer;font-size:.72rem" id="zscNetDiscLabel">
                    <input type="radio" name="zscNetMode" value="disconnect" style="accent-color:var(--accent)" onchange="zscNetModeChange()"> Getrennt
                </label>
            </div>
            <div id="zscNetCustomFields" style="opacity:.35">
                <div style="font-size:.58rem;font-weight:600;color:var(--text3);margin-bottom:4px">IPv4</div>
                <div style="display:flex;gap:8px;margin-bottom:8px">
                    <div style="flex:2">
                        <label style="font-size:.62rem;color:var(--text3);display:block;margin-bottom:2px">IP-Adresse (CIDR)</label>
                        <input class="form-input" id="zscIp" value="${srcIp}" placeholder="10.10.10.200/24" style="padding:4px 8px;font-size:.72rem;font-family:var(--mono)" disabled>
                    </div>
                    <div style="flex:1">
                        <label style="font-size:.62rem;color:var(--text3);display:block;margin-bottom:2px">Gateway</label>
                        <input class="form-input" id="zscGw" value="${srcGw}" placeholder="10.10.10.1" style="padding:4px 8px;font-size:.72rem;font-family:var(--mono)" disabled>
                    </div>
                </div>
                <div style="font-size:.58rem;font-weight:600;color:var(--text3);margin-bottom:4px;margin-top:8px">IPv6</div>
                <div style="display:flex;gap:8px;margin-bottom:8px">
                    <div style="flex:2">
                        <label style="font-size:.62rem;color:var(--text3);display:block;margin-bottom:2px">IPv6-Adresse (CIDR)</label>
                        <input class="form-input" id="zscIp6" value="${srcIp6}" placeholder="2a01:4f9::100/64 oder dhcp" style="padding:4px 8px;font-size:.72rem;font-family:var(--mono)" disabled>
                    </div>
                    <div style="flex:1">
                        <label style="font-size:.62rem;color:var(--text3);display:block;margin-bottom:2px">IPv6 Gateway</label>
                        <input class="form-input" id="zscGw6" value="${srcGw6}" placeholder="fe80::1" style="padding:4px 8px;font-size:.72rem;font-family:var(--mono)" disabled>
                    </div>
                </div>
                <div style="display:flex;gap:8px">
                    <div style="flex:1">
                        <label style="font-size:.62rem;color:var(--text3);display:block;margin-bottom:2px">Bridge</label>
                        <input class="form-input" id="zscBridge" value="${srcBridge}" placeholder="vmbr0" style="padding:4px 8px;font-size:.72rem;font-family:var(--mono)" disabled>
                    </div>
                    <div style="flex:1">
                        <label style="font-size:.62rem;color:var(--text3);display:block;margin-bottom:2px">DNS</label>
                        <input class="form-input" id="zscDns" value="${srcDns}" placeholder="1.1.1.1" style="padding:4px 8px;font-size:.72rem;font-family:var(--mono)" disabled>
                    </div>
                </div>
            </div>
        </div>

        <!-- Optionen -->
        <div style="display:flex;gap:16px">
            <label style="display:flex;align-items:center;gap:6px;font-size:.75rem;cursor:pointer;padding:6px 10px;background:rgba(255,255,255,.02);border:1px solid var(--border-subtle);border-radius:6px">
                <input type="checkbox" id="zscStart" style="accent-color:var(--accent);width:14px;height:14px"> Nach Clone starten
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:.75rem;cursor:pointer;padding:6px 10px;background:rgba(255,255,255,.02);border:1px solid var(--border-subtle);border-radius:6px">
                <input type="checkbox" id="zscOnboot" ${onboot ? 'checked' : ''} style="accent-color:var(--accent);width:14px;height:14px"> Autostart (Boot)
            </label>
        </div>
    `;

    // Always re-assign handler (modal body is rebuilt each time)
    window.zscNetModeChange = function() {
        const mode = document.querySelector('input[name="zscNetMode"]:checked')?.value || 'keep';
        const fields = document.getElementById('zscNetCustomFields');
        const enabled = mode === 'custom';
        fields.style.opacity = enabled ? '1' : '.35';
        fields.querySelectorAll('input').forEach(i => i.disabled = !enabled);
        document.getElementById('zscNetKeepLabel').style.borderColor = mode === 'keep' ? 'var(--accent)' : 'var(--border-subtle)';
        document.getElementById('zscNetCustomLabel').style.borderColor = mode === 'custom' ? 'var(--accent)' : 'var(--border-subtle)';
        document.getElementById('zscNetDiscLabel').style.borderColor = mode === 'disconnect' ? 'var(--accent)' : 'var(--border-subtle)';
    };

    const btn = document.getElementById('zfsSnapCloneBtn');
    btn.disabled = false; btn.textContent = T.clone_start;
    openModal('zfsSnapCloneModal');
}

async function zfsSnapCloneSubmit() {
    const snap = document.getElementById('zscSnap').value;
    const newVmid = document.getElementById('zscNewId').value;
    const name = document.getElementById('zscName').value.trim();
    const cores = document.getElementById('zscCores').value;
    const memory = document.getElementById('zscMem').value;
    const swap = document.getElementById('zscSwap')?.value || '';
    const onboot = document.getElementById('zscOnboot')?.checked ? '1' : '0';
    const autoStart = document.getElementById('zscStart').checked ? '1' : '0';
    const netMode = document.querySelector('input[name="zscNetMode"]:checked')?.value || 'keep';

    if (!newVmid || !name) { toast(T.vmid_name_required, 'error'); return; }

    const data = {
        snapshot: snap, new_vmid: newVmid, new_name: name,
        cores, memory, swap, onboot, auto_start: autoStart,
        net_disconnect: netMode === 'disconnect' ? '1' : '0',
    };

    // Custom network
    if (netMode === 'custom') {
        data.new_ip = document.getElementById('zscIp')?.value?.trim() || '';
        data.new_gw = document.getElementById('zscGw')?.value?.trim() || '';
        data.new_ip6 = document.getElementById('zscIp6')?.value?.trim() || '';
        data.new_gw6 = document.getElementById('zscGw6')?.value?.trim() || '';
        data.new_bridge = document.getElementById('zscBridge')?.value?.trim() || '';
        data.new_dns = document.getElementById('zscDns')?.value?.trim() || '';
    }

    const btn = document.getElementById('zfsSnapCloneBtn');
    btn.disabled = true; btn.innerHTML = '<span class="loading-spinner" style="width:12px;height:12px;border-width:1.5px;margin-right:4px"></span>Cloning...';

    try {
        const res = await api('pve-snap-clone', 'POST', data);
        if (res.ok) {
            toast(res.message || 'Clone erstellt');
            closeModal('zfsSnapCloneModal');
            loadPveVms && loadPveVms();
        } else {
            toast(res.error || T.error, 'error');
            btn.disabled = false; btn.textContent = T.clone_start;
        }
    } catch (e) {
        toast(T.error + ': ' + e.message, 'error');
        btn.disabled = false; btn.textContent = T.clone_start;
    }
}

async function zfsClone(snap) {
    const parts = snap.split('@');
    const pool = parts[0].split('/')[0];
    const defaultTarget = pool + '/clone-' + parts[1].replace(/[^a-zA-Z0-9-]/g, '');
    const target = await appPrompt('ZFS Clone', 'Ziel-Dataset:', defaultTarget);
    if (!target) return;
    try {
        const res = await api('zfs-clone', 'POST', { snapshot: snap, target });
        if (res.ok) { toast('Clone erstellt: ' + target); loadZfs(); }
        else toast(res.output || res.error || 'Fehler', 'error');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

// ┌──────────────────────────────────────────────────────────┐
// │              WireGuard: VPN Tunnels + Traffic Graph       │
// └──────────────────────────────────────────────────────────┘

// ── WireGuard Live-Traffic Chart ─────────────────────
const WG_MAX_POINTS = 60;
let wgChart = null;
let wgLastBytes = null;
let wgGraphTimer = null;
let wgPollCount = 0;
let wgStatusTimer = null;

function fmtSpeed(b) {
    if (b < 1024) return b.toFixed(0) + ' B/s';
    if (b < 1048576) return (b / 1024).toFixed(1) + ' KB/s';
    return (b / 1048576).toFixed(2) + ' MB/s';
}

function initWgChart() {
    if (wgChart) return;
    const canvas = document.getElementById('wgCanvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

    const rxGrad = ctx.createLinearGradient(0, 0, 0, 100);
    rxGrad.addColorStop(0, 'rgba(0,230,118,.25)');
    rxGrad.addColorStop(1, 'rgba(0,230,118,0)');
    const txGrad = ctx.createLinearGradient(0, 0, 0, 100);
    txGrad.addColorStop(0, 'rgba(64,196,255,.2)');
    txGrad.addColorStop(1, 'rgba(64,196,255,0)');

    wgChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: Array(WG_MAX_POINTS).fill(''),
            datasets: [
                {
                    label: 'RX',
                    data: Array(WG_MAX_POINTS).fill(0),
                    borderColor: '#00e676',
                    backgroundColor: rxGrad,
                    borderWidth: 1.5,
                    fill: true,
                    tension: .35,
                    pointRadius: 0,
                    pointHitRadius: 0,
                },
                {
                    label: 'TX',
                    data: Array(WG_MAX_POINTS).fill(0),
                    borderColor: '#40c4ff',
                    backgroundColor: txGrad,
                    borderWidth: 1.5,
                    fill: true,
                    tension: .35,
                    pointRadius: 0,
                    pointHitRadius: 0,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 400, easing: 'easeOutQuart' },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(17,24,39,.9)',
                    titleColor: '#9aa0a6',
                    bodyColor: '#e8eaed',
                    borderColor: 'rgba(255,255,255,.06)',
                    borderWidth: 1,
                    titleFont: { family: 'JetBrains Mono', size: 10 },
                    bodyFont: { family: 'JetBrains Mono', size: 11 },
                    padding: 8,
                    displayColors: true,
                    callbacks: {
                        title: () => '',
                        label: (c) => c.dataset.label + ': ' + fmtSpeed(c.raw)
                    }
                }
            },
            scales: {
                x: { display: false },
                y: {
                    display: true,
                    position: 'right',
                    beginAtZero: true,
                    grid: { color: 'rgba(255,255,255,.03)', drawBorder: false },
                    border: { display: false },
                    ticks: {
                        color: 'rgba(255,255,255,.2)',
                        font: { family: 'JetBrains Mono', size: 9 },
                        maxTicksLimit: 4,
                        callback: (v) => fmtSpeed(v)
                    }
                }
            }
        }
    });
}

async function pollWgTraffic() {
    try {
        const data = await api('wg-status');
        if (!data.length) return;

        let totalRx = 0, totalTx = 0;
        data.forEach(iface => {
            iface.peers.forEach(p => { totalRx += p.rx_bytes; totalTx += p.tx_bytes; });
        });

        if (wgLastBytes !== null) {
            const elapsed = wgPollCount <= 1 ? 2 : 5;
            const dRx = Math.max(0, totalRx - wgLastBytes.rx) / elapsed;
            const dTx = Math.max(0, totalTx - wgLastBytes.tx) / elapsed;

            document.getElementById('wgGraphRx').textContent = fmtSpeed(dRx);
            document.getElementById('wgGraphTx').textContent = fmtSpeed(dTx);

            if (wgChart) {
                wgChart.data.datasets[0].data.push(dRx);
                wgChart.data.datasets[1].data.push(dTx);
                wgChart.data.labels.push('');
                if (wgChart.data.labels.length > WG_MAX_POINTS) {
                    wgChart.data.labels.shift();
                    wgChart.data.datasets[0].data.shift();
                    wgChart.data.datasets[1].data.shift();
                }
                wgChart.update('none');
                // Re-enable animation after first fast polls
                if (wgPollCount > 2) wgChart.options.animation.duration = 400;
            }
        }
        wgLastBytes = { rx: totalRx, tx: totalTx };
        wgPollCount++;
    } catch (e) {
    }
}

function startWgGraph() {
    if (wgGraphTimer) return;
    initWgChart();
    wgPollCount = 0;
    wgLastBytes = null;
    // Fast start: poll immediately, then at 2s, then every 5s
    pollWgTraffic();
    setTimeout(() => {
        pollWgTraffic();
        wgGraphTimer = setInterval(pollWgTraffic, 5000);
    }, 2000);
    // Auto-refresh peer status every 10s
    if (!wgStatusTimer) {
        wgStatusTimer = setInterval(loadWg, 10000);
    }
}

function stopWgGraph() {
    if (wgGraphTimer) { clearInterval(wgGraphTimer); wgGraphTimer = null; }
    if (wgStatusTimer) { clearInterval(wgStatusTimer); wgStatusTimer = null; }
}

// ── WireGuard Tunnel Management ─────────────────────
async function loadWg() {
    try {
        const data = await api('wg-status');
        document.getElementById('wgCount').textContent = data.length;
        const grid = document.getElementById('wgGrid');
        grid.innerHTML = '';

        if (data.length === 0) {
            grid.innerHTML = '<div class="empty">Keine WireGuard-Interfaces gefunden</div>';
            return;
        }

        data.forEach(iface => {
            const statusTag = iface.active
                ? '<span class="tag tag-green">AKTIV</span>'
                : '<span class="tag tag-red">INAKTIV</span>';

            let peersHtml = '';
            if (iface.peers.length > 0) {
                peersHtml = iface.peers.map(p => {
                    let handshakeText = 'Nie';
                    let handshakeTag = 'tag-red';
                    if (p.from_config) {
                        handshakeText = 'Config';
                        handshakeTag = 'tag-muted';
                    } else if (p.handshake_ago !== null) {
                        if (p.handshake_ago < 180) {
                            handshakeText = p.handshake_ago + 's';
                            handshakeTag = 'tag-green';
                        } else if (p.handshake_ago < 600) {
                            handshakeText = Math.round(p.handshake_ago / 60) + 'min';
                            handshakeTag = 'tag-yellow';
                        } else {
                            handshakeText = Math.round(p.handshake_ago / 60) + 'min';
                            handshakeTag = 'tag-red';
                        }
                    }
                    const vpnIp = (p.allowed_ips || '').split(',').map(s => s.trim()).find(s => s.endsWith('/32'))?.replace('/32','')
                        || (p.allowed_ips || '').split(',')[0]?.trim().split('/')[0] || '';
                    return `
                    <div style="display:grid;grid-template-columns:100px 110px 140px 160px 70px 110px 160px auto;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid var(--border-subtle)">
                        <div>
                            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">Peer</div>
                            <div style="font-size:.82rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${p.name || ''}">${p.name || '<span style="color:var(--text3)">---</span>'}</div>
                        </div>
                        <div>
                            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">VPN IP</div>
                            <div style="font-family:var(--mono);font-size:.78rem;color:var(--accent)">${vpnIp || '---'}</div>
                        </div>
                        <div>
                            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">Endpoint</div>
                            <div style="font-family:var(--mono);font-size:.74rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${p.endpoint || ''}">${p.endpoint || '<span style="color:var(--text3)">---</span>'}</div>
                        </div>
                        <div>
                            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">Allowed IPs</div>
                            <div style="font-family:var(--mono);font-size:.74rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${p.allowed_ips}">${p.allowed_ips}</div>
                        </div>
                        <div>
                            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">Status</div>
                            <span class="tag ${handshakeTag}">${handshakeText}</span>
                        </div>
                        <div>
                            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">Transfer</div>
                            <div style="font-family:var(--mono);font-size:.72rem;color:var(--text2)">
                                <span style="color:var(--green)">&darr;</span>${fmtBytes(p.rx_bytes)}
                                <span style="color:var(--blue)">&uarr;</span>${fmtBytes(p.tx_bytes)}
                            </div>
                        </div>
                        <div>
                            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:2px">Public Key</div>
                            <div style="font-family:var(--mono);font-size:.62rem;color:var(--text3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer" title="${p.public_key}" onclick="navigator.clipboard.writeText('${p.public_key}');toast('Key kopiert!')">${p.public_key.substring(0,20)}...</div>
                        </div>
                        <div style="display:flex;gap:4px;justify-content:flex-end">
                            <button class="btn btn-sm" onclick="wgEditPeerOpen('${iface.name}','${p.public_key}')" title="Peer bearbeiten" style="padding:2px 6px">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                            <button class="btn btn-sm" onclick="wgDownloadConf('${iface.name}')" title=".conf" style="padding:2px 6px;font-size:.55rem">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                .conf
                            </button>
                            <button class="btn btn-sm" onclick="wgDownloadPeerScript('${iface.name}')" title=".sh" style="padding:2px 6px;font-size:.55rem">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                .sh
                            </button>
                            <button class="btn btn-sm btn-red" onclick="wgRemovePeer('${iface.name}','${p.public_key}')" title="${T.remove_peer}" style="padding:2px 6px">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                            </button>
                        </div>
                    </div>`;
                }).join('');
            } else {
                peersHtml = '<div style="color:var(--text3);font-size:.82rem;padding:8px 0">Keine Peers konfiguriert</div>';
            }

            const addr = iface.address || '';
            const gateway = addr ? addr.split('/')[0] : '';
            const subnet = addr || '';
            const pubShort = iface.public_key ? iface.public_key.substring(0,16) + '...' : '---';

            grid.innerHTML += `
                <div class="jail-card">
                    <div class="jail-header">
                        <div class="jail-name">
                            ${statusTag}
                            <span style="font-family:var(--mono);font-size:.95rem">${iface.name}</span>
                        </div>
                        <div style="display:flex;gap:6px;align-items:center">
                            <button class="btn btn-sm btn-green" onclick="wgAddPeerOpen('${iface.name}')" title="${T.add_peer}">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                                + Peer
                            </button>
                            <button class="btn btn-sm" onclick="showWgConfig('${iface.name}')" title="${T.show_config}">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                Config
                            </button>
                            <button class="btn btn-sm" onclick="wgShowLogs('${iface.name}')" title="Logs">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                Logs
                            </button>
                            ${iface.active ? `
                                <button class="btn btn-sm btn-red" onclick="wgControl('${iface.name}','stop')">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="6" width="12" height="12" rx="1"/></svg>
                                    Stop
                                </button>
                                <button class="btn btn-sm" onclick="wgControl('${iface.name}','restart')">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                                    Restart
                                </button>
                            ` : `
                                <button class="btn btn-sm btn-green" onclick="wgControl('${iface.name}','start')">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                    Start
                                </button>
                            `}
                        </div>
                    </div>
                    <div style="display:flex;gap:20px;padding:8px 20px;background:rgba(0,0,0,.15);border-bottom:1px solid var(--border-subtle);font-size:.7rem;flex-wrap:wrap">
                        <div><span style="color:var(--text3)">VPN-Netz:</span> <span style="font-family:var(--mono);color:var(--accent)">${subnet || '---'}</span></div>
                        <div><span style="color:var(--text3)">Gateway:</span> <span style="font-family:var(--mono)">${gateway || '---'}</span></div>
                        <div><span style="color:var(--text3)">Port:</span> <span style="font-family:var(--mono)">${iface.listen_port || 'random'}</span></div>
                        <div><span style="color:var(--text3)">Public Key:</span> <span style="font-family:var(--mono);font-size:.62rem;cursor:pointer;color:var(--text3)" title="${iface.public_key || ''}" onclick="navigator.clipboard.writeText('${iface.public_key || ''}');toast('Key kopiert!')">${pubShort}</span></div>
                        <div><span style="color:var(--text3)">Peers:</span> <span style="font-family:var(--mono)">${iface.peers.length}</span></div>
                    </div>
                    <div class="jail-body">
                        ${peersHtml}
                    </div>
                </div>`;
        });

        // Show restart banner if any interface has pending config changes
        const needsRestart = data.find(i => i.needs_restart);
        if (needsRestart) {
            wgShowRestartBanner(needsRestart.name, needsRestart.name + ' — Config geändert seit letztem Start. Restart empfohlen.');
        } else {
            const banner = document.getElementById('wgRestartBanner');
            if (banner) banner.style.display = 'none';
        }
    } catch (e) {
    }
}

async function showWgConfig(iface) {
    try {
        const res = await api('wg-config&iface=' + iface);
        if (!res.ok) { toast(res.error || 'Fehler', 'error'); return; }
        const conf = res.config;
        const get = (key) => { const m = conf.match(new RegExp(key + '\\s*=\\s*(.+)')); return m ? m[1].trim() : ''; };

        document.getElementById('wgEditIfaceTitle').textContent = iface + ' — Interface';
        document.getElementById('wgEditIfaceBody').innerHTML = `
            <input type="hidden" id="wgeiIface" value="${iface}">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Address (Tunnel-IP)</label>
                    <input class="form-input" id="wgeiAddr" value="${get('Address')}" placeholder="10.10.20.1/24">
                </div>
                <div class="form-group">
                    <label class="form-label">ListenPort</label>
                    <input class="form-input" id="wgeiPort" type="number" value="${get('ListenPort')}" placeholder="51820">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">PostUp</label>
                <input class="form-input" id="wgeiPostUp" value="${get('PostUp')}" style="font-size:.7rem" placeholder="iptables Regeln etc.">
            </div>
            <div class="form-group">
                <label class="form-label">PostDown</label>
                <input class="form-input" id="wgeiPostDown" value="${get('PostDown')}" style="font-size:.7rem" placeholder="iptables Cleanup etc.">
            </div>
            <div class="form-group">
                <label class="form-label">Private Key</label>
                <input class="form-input" id="wgeiPriv" value="${get('PrivateKey')}" style="font-size:.68rem" readonly>
                <div class="form-hint">Kann nicht geändert werden (Public Key hängt davon ab)</div>
            </div>
        `;
        openModal('wgEditIfaceModal');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function wgEditIfaceSave() {
    const iface = document.getElementById('wgeiIface').value;
    const addr = document.getElementById('wgeiAddr').value.trim();
    const port = document.getElementById('wgeiPort').value.trim();
    const postUp = document.getElementById('wgeiPostUp').value.trim();
    const postDown = document.getElementById('wgeiPostDown').value.trim();
    const privKey = document.getElementById('wgeiPriv').value.trim();

    if (!addr || !privKey) { toast(T.wg_fields_required, 'error'); return; }

    const btn = document.getElementById('wgEditIfaceSaveBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="loading-spinner" style="width:12px;height:12px;border-width:1.5px"></span>'; }

    try {
        // Read current config, keep all [Peer] blocks, replace [Interface]
        const cur = await api('wg-config&iface=' + iface);
        if (!cur.ok) { toast(cur.error || 'Fehler', 'error'); return; }

        const peers = cur.config.split(/(?=\[Peer\])/i).slice(1).join('');
        let newIface = '[Interface]\n';
        newIface += 'PrivateKey = ' + privKey + '\n';
        newIface += 'Address = ' + addr + '\n';
        if (port) newIface += 'ListenPort = ' + port + '\n';
        if (postUp) newIface += 'PostUp = ' + postUp + '\n';
        if (postDown) newIface += 'PostDown = ' + postDown + '\n';

        const res = await api('wg-save', 'POST', { iface, content: newIface + '\n' + peers });
        if (res.ok) {
            toast(T.iface_saved);
            closeModal('wgEditIfaceModal');
            loadWg();
            wgShowRestartBanner(iface, iface + ' — Interface geändert. Restart empfohlen.');
        } else {
            toast(res.error || T.error, 'error');
        }
    } catch (e) { toast(T.error + ': ' + e.message, 'error'); }
    if (btn) { btn.disabled = false; btn.textContent = T.save; }
}

async function saveWgConfig() {
    const iface = document.getElementById('wgConfigIface').value;
    const content = document.getElementById('wgConfigContent').value;
    try {
        const res = await api('wg-save', 'POST', { iface, content });
        if (res.ok) {
            toast(T.config_saved);
            closeModal('wgConfigModal');
        } else {
            toast(res.error || T.error, 'error');
        }
    } catch (e) { toast(T.error + ': ' + e.message, 'error'); }
}

async function wgControl(iface, cmd) {
    const labels = { start: 'Starte', stop: 'Stoppe', restart: 'Restarte' };
    toast(labels[cmd] + ' ' + iface + '...', 'success');
    try {
        const res = await api('wg-control', 'POST', { iface, cmd });
        if (res.ok) {
            toast(iface + ' → ' + res.status);
            setTimeout(loadWg, 1000);
        } else {
            toast(res.error || res.output || 'Fehler', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

// ── WireGuard Wizard (step-by-step tunnel creation) ──
let _wgWizData = {};

async function wgWizardOpen() {
    _wgWizData = {};

    // Generate keys + find next free interface name
    const [keys, ifaces] = await Promise.all([
        api('wg-genkeys'),
        api('wg-list-ifaces')
    ]);

    _wgWizData.privateKey = keys.private_key || '';
    _wgWizData.publicKey = keys.public_key || '';
    _wgWizData.psk = keys.preshared_key || '';

    // Find next free wgN
    const existing = ifaces.interfaces || [];
    let nextNum = 0;
    while (existing.includes('wg' + nextNum)) nextNum++;
    _wgWizData.iface = 'wg' + nextNum;

    wgWizStep1();
    openModal('wgWizardModal');
}

function wgWizStep1() {
    document.getElementById('wgWizardTitle').textContent = T.step1_title;
    document.getElementById('wgWizardBody').innerHTML = `
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Interface-Name</label>
                <input class="form-input" id="wgWizIface" value="${_wgWizData.iface || 'wg1'}" placeholder="wg1">
            </div>
            <div class="form-group">
                <label class="form-label">Listen Port</label>
                <input class="form-input" id="wgWizPort" type="number" value="${_wgWizData.port || ''}" placeholder="51820 (leer = random)">
                <div class="form-hint">Leer lassen wenn dieser Server sich zum Peer verbindet</div>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Tunnel-IP (dieses Servers)</label>
            <input class="form-input" id="wgWizAddr" value="${_wgWizData.address || '10.10.30.1/24'}" placeholder="10.10.30.1/24">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Private Key <span style="font-size:.55rem;color:var(--green)">(auto-generiert)</span></label>
                <input class="form-input" id="wgWizPriv" value="${_wgWizData.privateKey}" style="font-size:.7rem">
            </div>
            <div class="form-group">
                <label class="form-label">Public Key</label>
                <input class="form-input" id="wgWizPub" value="${_wgWizData.publicKey}" readonly style="font-size:.7rem;opacity:.7">
                <div class="form-hint">Wird aus dem Private Key abgeleitet</div>
            </div>
        </div>
        <div style="border:1px solid var(--border-subtle);border-radius:8px;padding:12px 14px">
            <div class="form-label" style="margin-bottom:10px">Firewall-Regeln (PostUp/PostDown)</div>

            <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:10px">
                <label style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:rgba(255,255,255,.02);border:1px solid var(--border-subtle);border-radius:6px;cursor:pointer">
                    <input type="checkbox" id="wgWizIpFwd" onchange="wgWizUpdatePostUp()" ${_wgWizData._ipfwd !== false ? 'checked' : ''} style="accent-color:var(--accent);width:15px;height:15px;flex-shrink:0">
                    <div style="flex:1">
                        <div style="font-size:.76rem;font-weight:500">IP-Forwarding (IPv4 + IPv6)</div>
                    </div>
                </label>

                <label style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:rgba(255,255,255,.02);border:1px solid var(--border-subtle);border-radius:6px;cursor:pointer">
                    <input type="checkbox" id="wgWizNat" onchange="wgWizUpdatePostUp()" ${_wgWizData._nat ? 'checked' : ''} style="accent-color:var(--accent);width:15px;height:15px;flex-shrink:0">
                    <div style="flex:1">
                        <div style="font-size:.76rem;font-weight:500">NAT / Masquerading</div>
                    </div>
                    <select id="wgWizNatIface" onchange="wgWizUpdatePostUp()" style="width:140px;background:var(--surface-solid);border:1px solid var(--border-subtle);border-radius:4px;padding:4px 8px;font-size:.72rem;color:var(--text);flex-shrink:0">
                        <option value="">${T.loading}</option>
                    </select>
                </label>

                <label style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:rgba(255,255,255,.02);border:1px solid var(--border-subtle);border-radius:6px;cursor:pointer">
                    <input type="checkbox" id="wgWizFwd" onchange="wgWizUpdatePostUp()" ${_wgWizData._fwd ? 'checked' : ''} style="accent-color:var(--accent);width:15px;height:15px;flex-shrink:0">
                    <div style="flex:1">
                        <div style="font-size:.76rem;font-weight:500">Forwarding zu Bridge</div>
                    </div>
                    <select id="wgWizFwdIface" onchange="wgWizUpdatePostUp()" style="width:140px;background:var(--surface-solid);border:1px solid var(--border-subtle);border-radius:4px;padding:4px 8px;font-size:.72rem;color:var(--text);flex-shrink:0">
                        <option value="">${T.loading}</option>
                    </select>
                </label>
            </div>

            <div id="wgWizPostUpPreview" style="display:none;background:rgba(0,0,0,.2);border:1px solid var(--border-subtle);border-radius:6px;padding:8px 10px">
                <div style="font-size:.6rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px">Generierte Regeln</div>
                <pre id="wgWizPostUpPre" style="font-family:var(--mono);font-size:.68rem;color:var(--text2);margin:0;white-space:pre-wrap;line-height:1.6"></pre>
            </div>
            <input type="hidden" id="wgWizPostUp" value="${_wgWizData.postUp || ''}">
        </div>
    `;
    document.getElementById('wgWizardFoot').innerHTML = `
        <button class="btn" onclick="closeModal('wgWizardModal')">Abbrechen</button>
        <button class="btn btn-accent" onclick="wgWizStep2()">Weiter &rarr;</button>
    `;

    // Load network interfaces for dropdowns
    api('wg-net-ifaces').then(d => {
        if (!d.ok) return;
        const ifaces = d.interfaces || [];
        ['wgWizNatIface', 'wgWizFwdIface'].forEach(selId => {
            const sel = document.getElementById(selId);
            if (!sel) return;
            sel.innerHTML = '';
            ifaces.forEach(i => {
                const label = i.name + (i.ip ? ' (' + i.ip + ')' : '');
                const selected = (selId === 'wgWizNatIface' && (i.name === (_wgWizData._natIface || 'vmbr0') || i.name.startsWith('vmbr0') || i.name.startsWith('eth')))
                    || (selId === 'wgWizFwdIface' && (i.name === (_wgWizData._fwdIface || 'vmbr1') || i.name === 'vmbr1'));
                sel.innerHTML += '<option value="' + i.name + '"' + (selected ? ' selected' : '') + '>' + label + '</option>';
            });
        });
        wgWizUpdatePostUp();
    });
}

function wgWizUpdatePostUp() {
    const nat = document.getElementById('wgWizNat')?.checked;
    const fwd = document.getElementById('wgWizFwd')?.checked;
    const ipfwd = document.getElementById('wgWizIpFwd')?.checked;
    const natIface = document.getElementById('wgWizNatIface')?.value || 'vmbr0';
    const fwdIface = document.getElementById('wgWizFwdIface')?.value || 'vmbr1';

    _wgWizData._nat = nat;
    _wgWizData._fwd = fwd;
    _wgWizData._ipfwd = ipfwd;
    _wgWizData._natIface = natIface;
    _wgWizData._fwdIface = fwdIface;

    let rules = [];
    if (ipfwd) {
        rules.push('echo 1 > /proc/sys/net/ipv4/ip_forward');
        rules.push('echo 1 > /proc/sys/net/ipv6/conf/all/forwarding');
    }
    if (nat) rules.push('iptables -t nat -A POSTROUTING -o ' + natIface + ' -j MASQUERADE');
    if (fwd) {
        rules.push('iptables -A FORWARD -i %i -o ' + fwdIface + ' -j ACCEPT');
        rules.push('iptables -A FORWARD -i ' + fwdIface + ' -o %i -j ACCEPT');
    }

    const joined = rules.join('; ');
    const hidden = document.getElementById('wgWizPostUp');
    if (hidden) hidden.value = joined;

    const preview = document.getElementById('wgWizPostUpPreview');
    const pre = document.getElementById('wgWizPostUpPre');
    if (preview && pre) {
        if (rules.length > 0) {
            preview.style.display = '';
            pre.textContent = rules.join('\n');
        } else {
            preview.style.display = 'none';
        }
    }
}

function wgWizStep2() {
    // Save step 1 values
    _wgWizData.iface = document.getElementById('wgWizIface').value.trim();
    _wgWizData.port = document.getElementById('wgWizPort').value.trim();
    _wgWizData.address = document.getElementById('wgWizAddr').value.trim();
    _wgWizData.privateKey = document.getElementById('wgWizPriv').value.trim();
    _wgWizData.publicKey = document.getElementById('wgWizPub').value.trim();
    _wgWizData.postUp = document.getElementById('wgWizPostUp').value.trim();

    if (!_wgWizData.iface || !_wgWizData.address || !_wgWizData.privateKey) {
        toast(T.wg_fields_required, 'error');
        return;
    }

    document.getElementById('wgWizardTitle').textContent = T.step2_title;
    document.getElementById('wgWizardBody').innerHTML = `
        <div class="form-group">
            <label class="form-label">Peer Endpoint <span style="font-size:.55rem;color:var(--text3)">(IP:Port der Gegenstelle)</span></label>
            <input class="form-input" id="wgWizPeerEp" value="${_wgWizData.peerEndpoint || ''}" placeholder="203.0.113.1:51820">
            <div class="form-hint">Leer lassen wenn der Peer sich hierher verbindet</div>
        </div>
        <div class="form-group">
            <label class="form-label">Peer Public Key</label>
            <input class="form-input" id="wgWizPeerPub" value="${_wgWizData.peerPublicKey || ''}" placeholder="Public Key der Gegenstelle" style="font-size:.7rem">
            <div class="form-hint">Muss vom Admin der Gegenstelle mitgeteilt werden</div>
        </div>
        <div class="form-group">
            <label class="form-label">Allowed IPs</label>
            <input class="form-input" id="wgWizPeerIps" value="${_wgWizData.peerAllowedIps || '10.10.30.0/24'}" placeholder="10.10.30.0/24, 192.168.1.0/24">
            <div class="form-hint">Netzwerke die ueber den Tunnel erreichbar sein sollen</div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">PresharedKey <span style="font-size:.55rem;color:var(--green)">(auto)</span></label>
                <input class="form-input" id="wgWizPsk" value="${_wgWizData.psk}" style="font-size:.7rem">
            </div>
            <div class="form-group">
                <label class="form-label">Keepalive (Sekunden)</label>
                <input class="form-input" id="wgWizKeepalive" type="number" value="${_wgWizData.keepalive || 25}" placeholder="25">
            </div>
        </div>
        <label class="form-check" style="margin-top:8px">
            <input type="checkbox" id="wgWizAutoStart" checked>
            Tunnel nach Erstellung automatisch starten + beim Boot aktivieren
        </label>
        <label class="form-check" style="margin-top:4px">
            <input type="checkbox" id="wgWizAddFw" checked>
            UDP-Port in PVE-Firewall freigeben (ACCEPT-Regel)
        </label>
    `;
    document.getElementById('wgWizardFoot').innerHTML = `
        <button class="btn" onclick="wgWizStep1()">&larr; Zurück</button>
        <button class="btn btn-accent" onclick="wgWizStep3()">Vorschau &rarr;</button>
    `;
}

function wgWizStep3() {
    // Save step 2
    _wgWizData.peerEndpoint = document.getElementById('wgWizPeerEp').value.trim();
    _wgWizData.peerPublicKey = document.getElementById('wgWizPeerPub').value.trim();
    _wgWizData.peerAllowedIps = document.getElementById('wgWizPeerIps').value.trim();
    _wgWizData.psk = document.getElementById('wgWizPsk').value.trim();
    _wgWizData.keepalive = document.getElementById('wgWizKeepalive').value.trim() || '25';
    _wgWizData.autoStart = document.getElementById('wgWizAutoStart').checked;
    _wgWizData.addFirewall = document.getElementById('wgWizAddFw').checked;

    if (!_wgWizData.peerPublicKey) {
        toast(T.wg_peer_key_required, 'error');
        return;
    }

    // Build local config preview
    let localConf = '[Interface]\n';
    localConf += 'PrivateKey = ' + _wgWizData.privateKey + '\n';
    localConf += 'Address = ' + _wgWizData.address + '\n';
    if (_wgWizData.port) localConf += 'ListenPort = ' + _wgWizData.port + '\n';
    if (_wgWizData.postUp) {
        localConf += 'PostUp = ' + _wgWizData.postUp + '\n';
        // PostDown: replace -A with -D, remove echo commands
        const postDown = _wgWizData.postUp.split('; ')
            .filter(r => !r.startsWith('echo '))
            .map(r => r.replace(/-A /g, '-D ').replace(/ -A /g, ' -D '))
            .join('; ');
        if (postDown) localConf += 'PostDown = ' + postDown + '\n';
    }
    localConf += '\n[Peer]\n';
    localConf += 'PublicKey = ' + _wgWizData.peerPublicKey + '\n';
    if (_wgWizData.psk) localConf += 'PresharedKey = ' + _wgWizData.psk + '\n';
    if (_wgWizData.peerEndpoint) localConf += 'Endpoint = ' + _wgWizData.peerEndpoint + '\n';
    localConf += 'AllowedIPs = ' + _wgWizData.peerAllowedIps + '\n';
    localConf += 'PersistentKeepalive = ' + _wgWizData.keepalive + '\n';

    // Build remote peer config (what the other side needs to add)
    const localIp = _wgWizData.address.split('/')[0];
    const peerSubnet = _wgWizData.address; // the peer needs to route to our address
    let remoteConf = '# === Add on the remote side ===\n\n';
    remoteConf += '[Peer]\n';
    remoteConf += '# ' + (_wgWizData.iface) + ' on this server\n';
    remoteConf += 'PublicKey = ' + _wgWizData.publicKey + '\n';
    if (_wgWizData.psk) remoteConf += 'PresharedKey = ' + _wgWizData.psk + '\n';
    if (_wgWizData.port) remoteConf += 'Endpoint = YOUR-SERVER-IP:' + _wgWizData.port + '\n';
    remoteConf += 'AllowedIPs = ' + localIp + '/32\n';
    remoteConf += 'PersistentKeepalive = ' + _wgWizData.keepalive + '\n';

    document.getElementById('wgWizardTitle').textContent = T.step3_title;
    document.getElementById('wgWizardBody').innerHTML = `
        <div style="margin-bottom:12px">
            <div style="font-size:.72rem;font-weight:600;margin-bottom:4px;display:flex;align-items:center;gap:6px">
                <span style="color:var(--green)">&#9679;</span> Lokale Config: /etc/wireguard/${_wgWizData.iface}.conf
            </div>
            <pre style="background:rgba(0,0,0,.3);border:1px solid var(--border-subtle);border-radius:8px;padding:10px 12px;font-family:var(--mono);font-size:.7rem;line-height:1.6;overflow-x:auto;margin:0;color:var(--text2)">${localConf}</pre>
        </div>
        <div>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
                <div style="font-size:.72rem;font-weight:600;display:flex;align-items:center;gap:6px">
                    <span style="color:var(--blue)">&#9679;</span> Remote-Config (für die Gegenstelle)
                </div>
                <div style="display:flex;gap:4px">
                    <button class="btn btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('wgRemoteConf').textContent);toast('Kopiert!')">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    </button>
                    <button class="btn btn-sm btn-green" onclick="wgDownloadFile('${_wgWizData.iface}-remote.conf', document.getElementById('wgRemoteConf').textContent)">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        .conf
                    </button>
                    <button class="btn btn-sm btn-green" onclick="wgDownloadRemoteScript('${_wgWizData.iface}')">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        .sh
                    </button>
                </div>
            </div>
            <pre id="wgRemoteConf" style="background:rgba(64,196,255,.04);border:1px solid rgba(64,196,255,.12);border-radius:8px;padding:10px 12px;font-family:var(--mono);font-size:.7rem;line-height:1.6;overflow-x:auto;margin:0;color:var(--blue)">${remoteConf}</pre>
        </div>
        ${_wgWizData.addFirewall && _wgWizData.port ? `
        <div style="margin-top:12px;padding:8px 12px;background:rgba(0,230,118,.04);border:1px solid rgba(0,230,118,.12);border-radius:6px;font-size:.68rem;color:var(--text2);display:flex;align-items:center;gap:8px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <span>PVE-Firewall: <strong>UDP ${_wgWizData.port}</strong> wird automatisch freigegeben (ACCEPT-Regel)</span>
        </div>` : ''}
    `;
    document.getElementById('wgWizardFoot').innerHTML = `
        <button class="btn" onclick="wgWizStep2()">&larr; Zurück</button>
        <button class="btn btn-accent" id="wgWizCreateBtn" onclick="wgWizCreate()">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            Tunnel erstellen
        </button>
    `;
}

async function wgWizCreate() {
    const btn = document.getElementById('wgWizCreateBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="loading-spinner" style="width:12px;height:12px;border-width:1.5px"></span> Erstelle...'; }

    try {
        const res = await api('wg-create', 'POST', {
            iface: _wgWizData.iface,
            listen_port: _wgWizData.port || '0',
            address: _wgWizData.address,
            private_key: _wgWizData.privateKey,
            peer_public_key: _wgWizData.peerPublicKey,
            peer_endpoint: _wgWizData.peerEndpoint,
            peer_allowed_ips: _wgWizData.peerAllowedIps,
            peer_psk: _wgWizData.psk,
            keepalive: _wgWizData.keepalive,
            post_up: _wgWizData.postUp,
            post_down: _wgWizData.postUp ? _wgWizData.postUp.split('; ').filter(r => !r.startsWith('echo ')).map(r => r.replace(/-A /g, '-D ')).join('; ') : '',
            auto_start: _wgWizData.autoStart ? '1' : '0',
            add_firewall: _wgWizData.addFirewall ? '1' : '0',
        });

        if (res.ok) {
            toast('Tunnel ' + _wgWizData.iface + ' erstellt' + (res.started ? ' und gestartet' : '') + (res.fw_added ? ' + Firewall-Regel' : ''));
            closeModal('wgWizardModal');
            loadWg();
            if (!res.started) {
                wgShowRestartBanner(_wgWizData.iface, _wgWizData.iface + ' wurde erstellt — Tunnel starten?');
            }
        } else {
            toast(res.error || 'Fehler', 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = 'Tunnel erstellen'; }
        }
    } catch (e) {
        toast('Fehler: ' + e.message, 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = 'Tunnel erstellen'; }
    }
}

// ── WireGuard: Restart Banner ────────────────────────
function wgShowRestartBanner(iface, msg) {
    const banner = document.getElementById('wgRestartBanner');
    const msgEl = document.getElementById('wgRestartMsg');
    const btn = document.getElementById('wgRestartBtn');
    if (!banner || !msgEl || !btn) return;
    msgEl.textContent = msg;
    btn.onclick = async () => {
        btn.disabled = true;
        btn.innerHTML = '<span class="loading-spinner" style="width:12px;height:12px;border-width:1.5px"></span>';
        await wgControl(iface, 'restart');
        banner.style.display = 'none';
    };
    banner.style.display = '';
}

// ── WireGuard: Logs ──────────────────────────────────
let _wgLogsIface = '';

async function wgShowLogs(iface) {
    _wgLogsIface = iface;
    document.getElementById('wgLogsTitle').textContent = iface + ' — Logs';
    document.getElementById('wgLogsContent').textContent = T.loading;
    openModal('wgLogsModal');
    wgRefreshLogs();
}

async function wgRefreshLogs() {
    const lines = document.getElementById('wgLogsLines')?.value || 50;
    const btn = document.getElementById('wgLogsRefreshBtn');
    if (btn) btn.disabled = true;
    try {
        const res = await api('wg-logs&iface=' + _wgLogsIface + '&lines=' + lines);
        if (res.ok) {
            let output = res.log || 'Keine Logs vorhanden.';
            if (res.dmesg) output += '\n\n── dmesg (WireGuard) ──\n' + res.dmesg;
            const pre = document.getElementById('wgLogsContent');
            pre.textContent = output;
            pre.scrollTop = pre.scrollHeight;
        } else {
            document.getElementById('wgLogsContent').textContent = res.error || T.error;
        }
    } catch (e) {
        document.getElementById('wgLogsContent').textContent = T.error + ': ' + e.message;
    }
    if (btn) btn.disabled = false;
}

// ── WireGuard: Import Config ─────────────────────────
async function wgImportOpen() {
    // Suggest next free interface name
    const d = await api('wg-list-ifaces');
    const existing = d.interfaces || [];
    let n = 0;
    while (existing.includes('wg' + n)) n++;
    document.getElementById('wgImportIface').value = 'wg' + n;
    document.getElementById('wgImportContent').value = '';
    document.getElementById('wgImportAutoStart').checked = true;
    document.getElementById('wgImportAddFw').checked = false;
    openModal('wgImportModal');
}

function wgImportFileLoad(input) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (e) => {
        document.getElementById('wgImportContent').value = e.target.result;
        // Auto-set interface name from filename
        const name = file.name.replace(/\.conf$/i, '').replace(/[^a-zA-Z0-9]/g, '');
        if (name) document.getElementById('wgImportIface').value = name;
        // Auto-check firewall if ListenPort found
        if (/ListenPort\s*=\s*\d+/.test(e.target.result)) {
            document.getElementById('wgImportAddFw').checked = true;
        }
    };
    reader.readAsText(file);
}

async function wgImportSave() {
    const iface = document.getElementById('wgImportIface').value.trim();
    const content = document.getElementById('wgImportContent').value.trim();
    const autoStart = document.getElementById('wgImportAutoStart').checked;
    const addFw = document.getElementById('wgImportAddFw').checked;

    if (!iface || !content) { toast(T.wg_import_required, 'error'); return; }

    const btn = document.getElementById('wgImportBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="loading-spinner" style="width:12px;height:12px;border-width:1.5px"></span>'; }

    try {
        const res = await api('wg-import', 'POST', {
            iface,
            content,
            auto_start: autoStart ? '1' : '0',
            add_firewall: addFw ? '1' : '0',
        });
        if (res.ok) {
            let msg = 'Config ' + iface + ' importiert';
            if (res.started) msg += ' + gestartet';
            if (res.fw_added) msg += ' + Firewall';
            toast(msg);
            closeModal('wgImportModal');
            loadWg();
            if (!res.started) {
                wgShowRestartBanner(iface, iface + ' importiert — Tunnel starten?');
            }
        } else {
            toast(res.error || T.error, 'error');
            if (btn) { btn.disabled = false; btn.textContent = T.import_btn; }
        }
    } catch (e) {
        toast(T.error + ': ' + e.message, 'error');
        if (btn) { btn.disabled = false; btn.textContent = T.import_btn; }
    }
}

// ── WireGuard: Download Helper ───────────────────────
function wgDownloadFile(filename, content) {
    const blob = new Blob([content], { type: 'text/plain' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    a.click();
    URL.revokeObjectURL(a.href);
}

function wgDownloadRemoteScript(iface) {
    const conf = document.getElementById('wgRemoteConf')?.textContent
        || document.getElementById('wgPeerConfPre')?.textContent || '';
    if (!conf) { toast(T.no_config, 'error'); return; }
    let s = '#!/bin/bash\n';
    s += '# WireGuard Peer Setup — Generated by FloppyOps Lite\n';
    s += 'set -e\n';
    s += 'IFACE="' + iface + '"\n';
    s += 'CONF="/etc/wireguard/${IFACE}.conf"\n\n';
    s += 'if [ "$(id -u)" -ne 0 ]; then echo "Bitte als root ausfuehren!"; exit 1; fi\n\n';
    s += 'EXISTING=$(ls /etc/wireguard/*.conf 2>/dev/null)\n';
    s += 'if [ -n "$EXISTING" ]; then\n';
    s += '    echo ""\n';
    s += '    echo "Bestehende WireGuard-Configs:"\n';
    s += '    for f in $EXISTING; do\n';
    s += '        NAME=$(basename "$f" .conf)\n';
    s += '        STATUS=$(systemctl is-active "wg-quick@${NAME}" 2>/dev/null || echo "inactive")\n';
    s += '        echo "   - ${NAME}.conf  [${STATUS}]"\n';
    s += '    done\n';
    s += '    echo ""\n';
    s += '    if [ -f "$CONF" ]; then\n';
    s += '        read -p "${CONF} existiert. Ueberschreiben? (j/N): " OW\n';
    s += '        if [ "$OW" != "j" ] && [ "$OW" != "J" ]; then echo "Abgebrochen."; exit 0; fi\n';
    s += '        systemctl stop "wg-quick@${IFACE}" 2>/dev/null || true\n';
    s += '    fi\n';
    s += 'fi\n\n';
    s += 'if ! command -v wg &>/dev/null; then\n';
    s += '    echo ">> WireGuard wird installiert..."\n';
    s += '    apt-get update -qq && apt-get install -y -qq wireguard >/dev/null\n';
    s += 'fi\n\n';
    s += 'cat > "$CONF" << \'WGEOF\'\n';
    s += conf + '\n';
    s += 'WGEOF\n\n';
    s += 'chmod 600 "$CONF"\n';
    s += 'systemctl enable "wg-quick@${IFACE}"\n';
    s += 'systemctl start "wg-quick@${IFACE}"\n\n';
    s += 'echo ""\n';
    s += 'echo "WireGuard ${IFACE} konfiguriert und gestartet!"\n';
    s += 'wg show "${IFACE}"\n';
    wgDownloadFile('wg-setup-' + iface + '.sh', s);
}

async function wgEditPeerOpen(iface, pubkey) {
    try {
        const res = await api('wg-config&iface=' + iface);
        if (!res.ok) { toast(res.error || 'Fehler', 'error'); return; }
        // Extract the [Peer] block matching this public key
        const blocks = res.config.split(/(?=\[Peer\])/i);
        let block = '';
        for (const b of blocks) { if (b.includes(pubkey)) { block = b; break; } }

        const get = (key) => { const m = block.match(new RegExp(key + '\\s*=\\s*(.+)')); return m ? m[1].trim() : ''; };
        const nameMatch = block.match(/^#\s*(.+)/m);

        const body = document.getElementById('wgEditPeerBody');
        body.innerHTML = `
            <input type="hidden" id="wgepIface" value="${iface}">
            <input type="hidden" id="wgepOldPub" value="${pubkey}">
            <div class="form-group">
                <label class="form-label">Peer-Name</label>
                <input class="form-input" id="wgepName" value="${nameMatch ? nameMatch[1].trim() : ''}" placeholder="z.B. Laptop, Büro-Router">
            </div>
            <div class="form-group">
                <label class="form-label">Public Key</label>
                <input class="form-input" id="wgepPub" value="${pubkey}" style="font-size:.7rem" readonly>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Endpoint</label>
                    <input class="form-input" id="wgepEndpoint" value="${get('Endpoint')}" placeholder="IP:Port (leer = kein)">
                </div>
                <div class="form-group">
                    <label class="form-label">PersistentKeepalive</label>
                    <input class="form-input" id="wgepKeepalive" type="number" value="${get('PersistentKeepalive') || '25'}">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">AllowedIPs</label>
                <input class="form-input" id="wgepAllowed" value="${get('AllowedIPs')}" placeholder="10.10.30.0/24">
            </div>
            <div class="form-group">
                <label class="form-label">PresharedKey</label>
                <input class="form-input" id="wgepPsk" value="${get('PresharedKey')}" style="font-size:.68rem" placeholder="(optional)">
            </div>
        `;
        document.getElementById('wgEditPeerTitle').textContent = (nameMatch ? nameMatch[1].trim() : 'Peer') + ' bearbeiten';
        openModal('wgEditPeerModal');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function wgEditPeerSave() {
    const iface = document.getElementById('wgepIface').value;
    const oldPub = document.getElementById('wgepOldPub').value;
    const name = document.getElementById('wgepName').value.trim();
    const endpoint = document.getElementById('wgepEndpoint').value.trim();
    const keepalive = document.getElementById('wgepKeepalive').value.trim();
    const allowed = document.getElementById('wgepAllowed').value.trim();
    const psk = document.getElementById('wgepPsk').value.trim();

    if (!allowed) { toast(T.allowed_ips_required, 'error'); return; }

    const btn = document.getElementById('wgEditPeerSaveBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="loading-spinner" style="width:12px;height:12px;border-width:1.5px"></span>'; }

    try {
        const res = await api('wg-update-peer', 'POST', {
            iface, public_key: oldPub, name, endpoint, keepalive, allowed_ips: allowed, psk
        });
        if (res.ok) {
            toast(T.peer_updated);
            closeModal('wgEditPeerModal');
            loadWg();
            wgShowRestartBanner(iface, iface + ' — Peer geändert. Restart empfohlen.');
        } else {
            toast(res.error || T.error, 'error');
            if (btn) { btn.disabled = false; btn.textContent = T.save; }
        }
    } catch (e) {
        toast(T.error + ': ' + e.message, 'error');
        if (btn) { btn.disabled = false; btn.textContent = T.save; }
    }
}

async function wgDownloadPeerScript(iface) {
    try {
        const res = await api('wg-config&iface=' + iface);
        if (!res.ok) { toast(res.error || 'Config nicht gefunden', 'error'); return; }
        let s = '#!/bin/bash\n';
        s += '# WireGuard Setup — Generated by FloppyOps Lite\n';
        s += 'set -e\n';
        s += 'IFACE="' + iface + '"\n';
        s += 'CONF="/etc/wireguard/${IFACE}.conf"\n\n';
        s += 'if [ "$(id -u)" -ne 0 ]; then echo "Bitte als root ausfuehren!"; exit 1; fi\n\n';
        s += 'EXISTING=$(ls /etc/wireguard/*.conf 2>/dev/null)\n';
        s += 'if [ -n "$EXISTING" ]; then\n';
        s += '    echo ""\n';
        s += '    echo "Bestehende WireGuard-Configs:"\n';
        s += '    for f in $EXISTING; do\n';
        s += '        NAME=$(basename "$f" .conf)\n';
        s += '        STATUS=$(systemctl is-active "wg-quick@${NAME}" 2>/dev/null || echo "inactive")\n';
        s += '        echo "   - ${NAME}.conf  [${STATUS}]"\n';
        s += '    done\n';
        s += '    echo ""\n';
        s += '    if [ -f "$CONF" ]; then\n';
        s += '        read -p "${CONF} existiert. Ueberschreiben? (j/N): " OW\n';
        s += '        if [ "$OW" != "j" ] && [ "$OW" != "J" ]; then echo "Abgebrochen."; exit 0; fi\n';
        s += '        systemctl stop "wg-quick@${IFACE}" 2>/dev/null || true\n';
        s += '    fi\n';
        s += 'fi\n\n';
        s += 'if ! command -v wg &>/dev/null; then\n';
        s += '    echo ">> WireGuard wird installiert..."\n';
        s += '    apt-get update -qq && apt-get install -y -qq wireguard >/dev/null\n';
        s += 'fi\n\n';
        s += 'cat > "$CONF" << \'WGEOF\'\n';
        s += res.config + '\n';
        s += 'WGEOF\n\n';
        s += 'chmod 600 "$CONF"\n';
        s += 'systemctl enable "wg-quick@${IFACE}"\n';
        s += 'systemctl start "wg-quick@${IFACE}"\n\n';
        s += 'echo ""\n';
        s += 'echo "WireGuard ${IFACE} konfiguriert und gestartet!"\n';
        s += 'wg show "${IFACE}"\n';
        wgDownloadFile('wg-setup-' + iface + '.sh', s);
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function wgDownloadConf(iface) {
    try {
        const res = await api('wg-config&iface=' + iface);
        if (res.ok) {
            wgDownloadFile(iface + '.conf', res.config);
        } else {
            toast(res.error || 'Config nicht gefunden', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

// ── WireGuard: Add Peer Wizard ───────────────────────
let _wgPeerData = {};

async function wgAddPeerOpen(iface) {
    _wgPeerData = { iface };

    // Fetch server info + generate keys in parallel
    const [info, keys] = await Promise.all([
        api('wg-server-info&iface=' + iface),
        api('wg-genkeys')
    ]);

    if (!info.ok) { toast(info.error || 'Server-Info nicht verfügbar', 'error'); return; }

    _wgPeerData.serverPubKey = info.public_key || '';
    _wgPeerData.serverPort = info.listen_port || 0;
    _wgPeerData.serverAddress = info.address || '';
    _wgPeerData.publicIp = info.public_ip || '';
    _wgPeerData.suggestedIp = info.suggested_ip || '';
    _wgPeerData.peerPrivKey = keys.private_key || '';
    _wgPeerData.peerPubKey = keys.public_key || '';
    _wgPeerData.psk = keys.preshared_key || '';

    wgAddPeerStep1();
    openModal('wgAddPeerModal');
}

function _wgSubnetFromAddr(addr) {
    return addr ? addr.replace(/\.\d+\//, '.0/') : '';
}

function wgAddPeerStep1() {
    const d = _wgPeerData;
    document.getElementById('wgAddPeerTitle').textContent = T.step1of2;
    document.getElementById('wgAddPeerBody').innerHTML = `
        <div style="background:rgba(0,230,118,.04);border:1px solid rgba(0,230,118,.12);border-radius:6px;padding:8px 12px;margin-bottom:14px;font-size:.68rem;color:var(--text2)">
            <strong>${d.iface}</strong> &mdash; Server: ${d.serverAddress || '?'} | Port: ${d.serverPort || 'random'}
        </div>
        <div class="form-group">
            <label class="form-label">${T.peer_name}</label>
            <input class="form-input" id="wgPeerName" value="${d.peerName || ''}" placeholder="z.B. Laptop, Büro-Router, Handy">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">${T.peer_tunnel_ip}</label>
                <input class="form-input" id="wgPeerIp" value="${d.peerIp || d.suggestedIp}" placeholder="10.10.30.2/24">
                <div class="form-hint">Tunnel-IP die der Peer bekommt</div>
            </div>
            <div class="form-group">
                <label class="form-label">${T.peer_dns}</label>
                <input class="form-input" id="wgPeerDns" value="${d.peerDns || '1.1.1.1, 8.8.8.8'}" placeholder="1.1.1.1">
                <div class="form-hint">DNS-Server für den Peer (optional)</div>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">${T.peer_routes}</label>
            <input class="form-input" id="wgPeerRoutes" value="${d.peerRoutes || _wgSubnetFromAddr(d.serverAddress)}" placeholder="10.10.30.0/24, 10.10.10.0/24">
            <div class="form-hint">Netzwerke die der Peer über den Tunnel erreichen soll (AllowedIPs in der Peer-Config)</div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">${T.server_endpoint}</label>
                <input class="form-input" id="wgPeerEndpoint" value="${d.peerEndpoint || (d.publicIp ? d.publicIp + ':' + (d.serverPort || 51820) : '')}" placeholder="203.0.113.1:51820">
                <div class="form-hint">Öffentliche IP:Port dieses Servers</div>
            </div>
            <div class="form-group">
                <label class="form-label">${T.keepalive}</label>
                <input class="form-input" id="wgPeerKeepalive" type="number" value="${d.keepalive || 25}" placeholder="25">
            </div>
        </div>
        <div style="border:1px solid var(--border-subtle);border-radius:6px;padding:10px 12px;margin-top:4px">
            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">Keys (auto-generiert)</div>
            <div class="form-row" style="gap:8px">
                <div class="form-group" style="flex:1">
                    <label class="form-label" style="font-size:.62rem">${T.private_key}</label>
                    <input class="form-input" id="wgPeerPrivKey" value="${d.peerPrivKey}" style="font-size:.65rem" readonly>
                </div>
                <div class="form-group" style="flex:1">
                    <label class="form-label" style="font-size:.62rem">${T.public_key}</label>
                    <input class="form-input" id="wgPeerPubKey" value="${d.peerPubKey}" style="font-size:.65rem" readonly>
                </div>
            </div>
        </div>
    `;
    document.getElementById('wgAddPeerFoot').innerHTML = `
        <button class="btn" onclick="closeModal('wgAddPeerModal')">${T.back}</button>
        <button class="btn btn-accent" onclick="wgAddPeerStep2()">${T.next} &rarr;</button>
    `;
}

function wgAddPeerStep2() {
    const d = _wgPeerData;
    d.peerName = document.getElementById('wgPeerName').value.trim();
    d.peerIp = document.getElementById('wgPeerIp').value.trim();
    d.peerDns = document.getElementById('wgPeerDns').value.trim();
    d.peerRoutes = document.getElementById('wgPeerRoutes').value.trim();
    d.peerEndpoint = document.getElementById('wgPeerEndpoint').value.trim();
    d.keepalive = document.getElementById('wgPeerKeepalive').value.trim() || '25';
    d.peerPrivKey = document.getElementById('wgPeerPrivKey').value.trim();
    d.peerPubKey = document.getElementById('wgPeerPubKey').value.trim();

    if (!d.peerIp || !d.peerPubKey) {
        toast(T.wg_tunnel_fields_required, 'error');
        return;
    }

    // Build peer WireGuard config
    let peerConf = '[Interface]\n';
    if (d.peerName) peerConf += '# ' + d.peerName + '\n';
    peerConf += 'PrivateKey = ' + d.peerPrivKey + '\n';
    peerConf += 'Address = ' + d.peerIp + '\n';
    if (d.peerDns) peerConf += 'DNS = ' + d.peerDns + '\n';
    peerConf += '\n[Peer]\n';
    peerConf += 'PublicKey = ' + d.serverPubKey + '\n';
    if (d.psk) peerConf += 'PresharedKey = ' + d.psk + '\n';
    if (d.peerEndpoint) peerConf += 'Endpoint = ' + d.peerEndpoint + '\n';
    peerConf += 'AllowedIPs = ' + d.peerRoutes + '\n';
    peerConf += 'PersistentKeepalive = ' + d.keepalive + '\n';

    // Build setup script
    const peerIfaceName = d.iface;
    let script = '#!/bin/bash\n';
    script += '# ─── WireGuard Peer Setup ───────────────────────\n';
    script += '# Generated by FloppyOps Lite\n';
    if (d.peerName) script += '# Peer: ' + d.peerName + '\n';
    script += '# Server: ' + d.iface + ' @ ' + (d.peerEndpoint || 'unknown') + '\n';
    script += '# ────────────────────────────────────────────────\n\n';
    script += 'set -e\n';
    script += 'IFACE="' + peerIfaceName + '"\n';
    script += 'CONF="/etc/wireguard/${IFACE}.conf"\n\n';
    script += '# 0. Root-Check\n';
    script += 'if [ "$(id -u)" -ne 0 ]; then echo "Bitte als root ausfuehren!"; exit 1; fi\n\n';
    script += '# 1. Bestehende WireGuard-Configs pruefen\n';
    script += 'EXISTING=$(ls /etc/wireguard/*.conf 2>/dev/null)\n';
    script += 'if [ -n "$EXISTING" ]; then\n';
    script += '    echo ""\n';
    script += '    echo "⚠  Bestehende WireGuard-Configs gefunden:"\n';
    script += '    for f in $EXISTING; do\n';
    script += '        NAME=$(basename "$f" .conf)\n';
    script += '        STATUS=$(systemctl is-active "wg-quick@${NAME}" 2>/dev/null || echo "inactive")\n';
    script += '        echo "   - ${NAME}.conf  [${STATUS}]"\n';
    script += '    done\n';
    script += '    echo ""\n';
    script += '    if [ -f "$CONF" ]; then\n';
    script += '        echo "ACHTUNG: ${CONF} existiert bereits!"\n';
    script += '        read -p "Ueberschreiben? (j/N): " OVERWRITE\n';
    script += '        if [ "$OVERWRITE" != "j" ] && [ "$OVERWRITE" != "J" ]; then\n';
    script += '            echo "Abgebrochen."; exit 0\n';
    script += '        fi\n';
    script += '        echo ">> Stoppe bestehenden Tunnel..."\n';
    script += '        systemctl stop "wg-quick@${IFACE}" 2>/dev/null || true\n';
    script += '    fi\n';
    script += 'fi\n\n';
    script += '# 2. WireGuard installieren\n';
    script += 'if ! command -v wg &>/dev/null; then\n';
    script += '    echo ">> WireGuard wird installiert..."\n';
    script += '    apt-get update -qq && apt-get install -y -qq wireguard >/dev/null\n';
    script += 'fi\n\n';
    script += '# 3. Config schreiben\n';
    script += 'cat > "$CONF" << \'WGEOF\'\n';
    script += peerConf;
    script += 'WGEOF\n\n';
    script += 'chmod 600 "$CONF"\n\n';
    script += '# 4. Tunnel starten + Autostart\n';
    script += 'systemctl enable "wg-quick@${IFACE}"\n';
    script += 'systemctl start "wg-quick@${IFACE}"\n\n';
    script += 'echo ""\n';
    script += 'echo "✓ WireGuard Peer konfiguriert und gestartet!"\n';
    script += 'echo "  Interface: ${IFACE}"\n';
    script += 'echo "  Tunnel-IP: ' + d.peerIp + '"\n';
    script += 'echo ""\n';
    script += 'wg show "${IFACE}"\n';

    // Server-side allowed IPs: just the peer's tunnel IP /32
    const peerIpOnly = d.peerIp.split('/')[0];
    d._serverAllowedIps = peerIpOnly + '/32';

    _wgPeerData._peerConf = peerConf;
    _wgPeerData._script = script;

    document.getElementById('wgAddPeerTitle').textContent = T.step2of2;
    document.getElementById('wgAddPeerBody').innerHTML = `
        <div style="margin-bottom:14px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
                <div style="font-size:.72rem;font-weight:600;display:flex;align-items:center;gap:6px">
                    <span style="color:var(--green)">&#9679;</span> ${T.peer_config}: ${peerIfaceName}.conf
                </div>
                <div style="display:flex;gap:4px">
                    <button class="btn btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('wgPeerConfPre').textContent);toast('${T.copy_config}!')">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    </button>
                    <button class="btn btn-sm btn-green" onclick="wgDownloadFile('${peerIfaceName}.conf', document.getElementById('wgPeerConfPre').textContent)">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        .conf
                    </button>
                </div>
            </div>
            <pre id="wgPeerConfPre" style="background:rgba(0,0,0,.3);border:1px solid var(--border-subtle);border-radius:8px;padding:10px 12px;font-family:var(--mono);font-size:.68rem;line-height:1.6;overflow-x:auto;margin:0;color:var(--text2);max-height:180px">${peerConf}</pre>
        </div>
        <div>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
                <div style="font-size:.72rem;font-weight:600;display:flex;align-items:center;gap:6px">
                    <span style="color:var(--blue)">&#9679;</span> ${T.setup_script}
                </div>
                <div style="display:flex;gap:4px">
                    <button class="btn btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('wgScriptPre').textContent);toast('${T.copy_script}!')">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    </button>
                    <button class="btn btn-sm btn-green" onclick="wgDownloadFile('wg-peer-setup.sh', document.getElementById('wgScriptPre').textContent)">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        .sh
                    </button>
                </div>
            </div>
            <pre id="wgScriptPre" style="background:rgba(64,196,255,.04);border:1px solid rgba(64,196,255,.12);border-radius:8px;padding:10px 12px;font-family:var(--mono);font-size:.65rem;line-height:1.5;overflow-x:auto;margin:0;color:var(--blue);max-height:220px">${script}</pre>
            <div style="font-size:.62rem;color:var(--text3);margin-top:6px">${T.setup_script_hint}</div>
        </div>
    `;
    document.getElementById('wgAddPeerFoot').innerHTML = `
        <button class="btn" onclick="wgAddPeerStep1()">&larr; ${T.back}</button>
        <button class="btn btn-accent" id="wgAddPeerBtn" onclick="wgAddPeerCreate()">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
            ${T.add_peer}
        </button>
    `;
}

async function wgAddPeerCreate() {
    const d = _wgPeerData;
    const btn = document.getElementById('wgAddPeerBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="loading-spinner" style="width:12px;height:12px;border-width:1.5px"></span>'; }

    try {
        const res = await api('wg-add-peer', 'POST', {
            iface: d.iface,
            peer_public_key: d.peerPubKey,
            peer_name: d.peerName || '',
            allowed_ips: d._serverAllowedIps,
            psk: d.psk,
            keepalive: d.keepalive,
        });

        if (res.ok) {
            toast(T.peer_added + (res.live ? ' (live)' : ''));
            closeModal('wgAddPeerModal');
            loadWg();
            wgShowRestartBanner(d.iface, d.iface + ' — Peer hinzugefügt. Restart empfohlen.');
        } else {
            toast(res.error || 'Fehler', 'error');
            if (btn) { btn.disabled = false; btn.textContent = T.add_peer; }
        }
    } catch (e) {
        toast('Fehler: ' + e.message, 'error');
        if (btn) { btn.disabled = false; btn.textContent = T.add_peer; }
    }
}

async function wgRemovePeer(iface, pubkey) {
    if (!await appConfirm(T.confirm_remove_peer, T.confirm_remove_peer)) return;
    try {
        const res = await api('wg-remove-peer', 'POST', { iface, public_key: pubkey });
        if (res.ok) {
            toast(T.peer_removed);
            loadWg();
        } else {
            toast(res.error || 'Fehler', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

// ┌──────────────────────────────────────────────────────────┐
// │              Security: Port-Scan, Host-Firewall           │
// └──────────────────────────────────────────────────────────┘
const SEC_RISK_COLORS = { critical: 'var(--red)', high: '#f97316', medium: 'var(--yellow)', low: 'var(--blue)' };

async function loadSecScan() {
    const sp = `<div style="display:flex;align-items:center;justify-content:center;gap:8px;padding:14px"><span style="width:14px;height:14px;border:2px solid var(--border-subtle);border-top-color:var(--accent);border-radius:50%;animation:spin .6s linear infinite;flex-shrink:0"></span><span style="color:var(--text3)">${T.sec_scanning}</span></div>`;
    ['secSummary','secPortList','secFwStatus'].forEach(id => { const e = document.getElementById(id); if (e) e.innerHTML = sp; });
    const d = await api('sec-scan');
    if (!d.ok) return;
    const s = d.summary;

    // Badge
    const badge = document.getElementById('secBadge');
    if (s.risky_ports > 0) { badge.textContent = s.risky_ports; badge.style.display = ''; }
    else badge.style.display = 'none';
    document.getElementById('secRiskCount').textContent = s.risky_ports;

    // Summary cards
    const fwColor = s.fw_active ? 'var(--green)' : 'var(--red)';
    const fwText = s.fw_active ? T.sec_fw_enabled : T.sec_fw_disabled;
    document.getElementById('secSummary').innerHTML = `
        <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:12px 14px;text-align:center">
            <div style="font-size:1.3rem;font-weight:800;color:var(--text1)">${s.total_ports}</div>
            <div style="font-size:.65rem;color:var(--text3)">${T.sec_total_ports}</div>
        </div>
        <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:12px 14px;text-align:center">
            <div style="font-size:1.3rem;font-weight:800;color:var(--yellow)">${s.external_ports}</div>
            <div style="font-size:.65rem;color:var(--text3)">${T.sec_external}</div>
        </div>
        <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:12px 14px;text-align:center">
            <div style="font-size:1.3rem;font-weight:800;color:${s.risky_ports > 0 ? 'var(--red)' : 'var(--green)'}">${s.risky_ports}</div>
            <div style="font-size:.65rem;color:var(--text3)">${T.sec_risky_ports}</div>
        </div>
        <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:12px 14px;text-align:center">
            <div style="font-size:1.3rem;font-weight:800;color:${fwColor}">${fwText}</div>
            <div style="font-size:.65rem;color:var(--text3)">${T.sec_pve_firewall}</div>
        </div>`;

    // Port list
    if (d.ports.length === 0) {
        document.getElementById('secPortList').innerHTML = `<div style="padding:14px;color:var(--text3);text-align:center">${T.sec_no_risks}</div>`;
    } else {
        let html = '<table style="width:100%;border-collapse:collapse"><thead><tr style="border-bottom:1px solid var(--border-subtle)">'
            + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_port}</th>`
            + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_service}</th>`
            + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_process}</th>`
            + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_address}</th>`
            + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_risk}</th>`
            + `<th style="padding:8px 12px;text-align:right;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_action}</th>`
            + '</tr></thead><tbody>';
        d.ports.forEach(p => {
            const riskBadge = p.risk
                ? `<span style="background:${SEC_RISK_COLORS[p.risk]};color:#fff;padding:1px 7px;border-radius:4px;font-size:.6rem;font-weight:600">${T['sec_risk_' + p.risk]}</span>`
                : (p.external ? `<span style="color:var(--text3);font-size:.65rem">—</span>` : `<span style="color:var(--green);font-size:.65rem">${T.sec_safe}</span>`);
            const addrBadge = p.external
                ? `<span style="color:var(--yellow);font-size:.7rem">${p.addr}</span>`
                : `<span style="color:var(--green);font-size:.7rem">${p.addr}</span>`;
            const blockBtn = p.risk
                ? `<button class="btn btn-sm btn-red" onclick="secBlockPort(${p.port},'${p.service}')" style="padding:2px 8px;font-size:.6rem">${T.sec_blocked}</button>`
                : '';
            html += `<tr style="border-bottom:1px solid var(--border-subtle)">
                <td style="padding:6px 12px;font-family:var(--mono);font-weight:600">${p.port}</td>
                <td style="padding:6px 12px">${p.service}</td>
                <td style="padding:6px 12px;color:var(--text3)">${p.process}</td>
                <td style="padding:6px 12px">${addrBadge}</td>
                <td style="padding:6px 12px">${riskBadge}</td>
                <td style="padding:6px 12px;text-align:right">${blockBtn}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        document.getElementById('secPortList').innerHTML = html;
    }

    // Firewall status
    const fw = d.firewall;
    const dot = (on) => `<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${on ? 'var(--green)' : 'var(--red)'};margin-right:6px"></span>`;
    const policyBadge = fw.dc_enabled ? `<span style="background:rgba(255,255,255,.06);padding:1px 8px;border-radius:4px;font-size:.6rem;font-family:var(--mono);margin-left:6px">Input: ${fw.dc_policy_in || 'ACCEPT'}</span>` : '';
    document.getElementById('secFwStatus').innerHTML = `
        <div style="display:flex;gap:24px;align-items:center;flex-wrap:wrap">
            <div style="display:flex;align-items:center;gap:12px">
                <div>${dot(fw.dc_enabled)}${T.sec_dc_level}: <strong>${fw.dc_enabled ? T.sec_fw_enabled : T.sec_fw_disabled}</strong>${policyBadge}</div>
                ${!fw.dc_enabled ? `<button class="btn btn-sm btn-green" onclick="secEnableFw('dc')" style="padding:2px 10px;font-size:.6rem">${T.sec_fw_enable}</button>` : ''}
            </div>
            <div style="display:flex;align-items:center;gap:12px">
                <div>${dot(fw.node_enabled)}${T.sec_node_level} (${fw.node}): <strong>${fw.node_enabled ? T.sec_fw_enabled : T.sec_fw_disabled}</strong></div>
                ${!fw.node_enabled ? `<button class="btn btn-sm btn-green" onclick="secEnableFw('node')" style="padding:2px 10px;font-size:.6rem">${T.sec_fw_enable}</button>` : ''}
            </div>
        </div>`;
}

async function loadSecFwRules() {
    const rl = document.getElementById('secRuleList');
    if (rl) rl.innerHTML = `<div style="display:flex;align-items:center;justify-content:center;gap:8px;padding:14px"><span style="width:14px;height:14px;border:2px solid var(--border-subtle);border-top-color:var(--accent);border-radius:50%;animation:spin .6s linear infinite;flex-shrink:0"></span><span style="color:var(--text3)">${T.loading}</span></div>`;
    const d = await api('sec-fw-rules');
    if (!d.ok) return;
    const all = [
        ...d.node_rules.map(r => ({...r, _level: 'node'})),
        ...d.cluster_rules.map(r => ({...r, _level: 'dc'}))
    ];
    if (all.length === 0) {
        document.getElementById('secRuleList').innerHTML = `<div style="padding:14px;color:var(--text3);text-align:center;font-size:.78rem">${T.sec_no_rules}</div>`;
        return;
    }
    let html = '<table style="width:100%;border-collapse:collapse"><thead><tr style="border-bottom:1px solid var(--border-subtle)">'
        + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">#</th>`
        + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_action}</th>`
        + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_rule_type}</th>`
        + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_rule_dport}</th>`
        + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_rule_source}</th>`
        + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">${T.sec_rule_comment}</th>`
        + `<th style="padding:8px 12px;text-align:left;font-size:.7rem;color:var(--text3);font-weight:600">Level</th>`
        + `<th style="padding:8px 12px;text-align:right;font-size:.7rem;color:var(--text3);font-weight:600"></th>`
        + '</tr></thead><tbody>';
    all.forEach(r => {
        const actionColor = r.action === 'ACCEPT' ? 'var(--green)' : r.action === 'DROP' ? 'var(--red)' : 'var(--yellow)';
        const levelBadge = r._level === 'dc'
            ? '<span style="background:rgba(96,165,250,.15);color:#60a5fa;padding:1px 6px;border-radius:3px;font-size:.6rem">DC</span>'
            : '<span style="background:rgba(255,255,255,.06);color:var(--text3);padding:1px 6px;border-radius:3px;font-size:.6rem">Node</span>';
        html += `<tr style="border-bottom:1px solid var(--border-subtle)">
            <td style="padding:6px 12px;color:var(--text3)">${r.pos ?? ''}</td>
            <td style="padding:6px 12px;font-weight:600;color:${actionColor}">${r.action ?? ''}</td>
            <td style="padding:6px 12px">${r.type ?? ''}</td>
            <td style="padding:6px 12px;font-family:var(--mono)">${r.dport ?? '*'}</td>
            <td style="padding:6px 12px;font-family:var(--mono)">${r.source ?? '*'}</td>
            <td style="padding:6px 12px;color:var(--text3);font-size:.7rem">${r.comment ?? ''}</td>
            <td style="padding:6px 12px">${levelBadge}</td>
            <td style="padding:6px 12px;text-align:right">
                <button class="btn btn-sm btn-red" onclick="secDeleteRule(${r.pos},'${r._level}')" style="padding:1px 6px;font-size:.55rem" title="${T.sec_delete_rule}">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                </button>
            </td>
        </tr>`;
    });
    html += '</tbody></table>';
    document.getElementById('secRuleList').innerHTML = html;
}

async function secBlockPort(port, service) {
    const msg = T.sec_block_confirm.replace('%d', port).replace('%s', service);
    if (!await appConfirm(T.sec_block_port, msg)) return;
    const d = await api('sec-fw-block', 'POST', { port });
    if (d.ok) { loadSecScan(); loadSecFwRules(); }
}

async function secEnableFw(level) {
    if (!await appConfirm(T.sec_fw_enable, T.sec_fw_enable_warn, 'warning')) return;
    const d = await api('sec-fw-enable', 'POST', { level });
    if (d.ok) { loadSecScan(); loadSecFwRules(); }
}

async function secDeleteRule(pos, level) {
    if (!await appConfirm(T.sec_delete_rule, T.sec_delete_rule_confirm)) return;
    const d = await api('sec-fw-delete-rule', 'POST', { pos, level });
    if (d.ok) loadSecFwRules();
}

function secApplyDefaults() { openModal('secDefaultsModal'); }
async function secApplyDefaultsConfirm() {
    const selected = [];
    document.querySelectorAll('.sec-def-cb').forEach(cb => {
        if (cb.checked) selected.push(parseInt(cb.dataset.idx));
    });
    if (selected.length === 0) { closeModal('secDefaultsModal'); return; }
    closeModal('secDefaultsModal');
    const d = await api('sec-fw-defaults', 'POST', { selected: JSON.stringify(selected) });
    if (d.ok) { loadSecScan(); loadSecFwRules(); }
}

function secAddRuleModal() { openModal('secRuleModal'); }
async function secSaveRule() {
    const d = await api('sec-fw-add-rule', 'POST', {
        rule_action: document.getElementById('sarAction').value,
        type: document.getElementById('sarType').value,
        dport: document.getElementById('sarDport').value,
        source: document.getElementById('sarSource').value,
        comment: document.getElementById('sarComment').value,
        level: document.getElementById('sarLevel').value
    });
    if (d.ok) { closeModal('secRuleModal'); loadSecScan(); loadSecFwRules(); }
}

// ┌──────────────────────────────────────────────────────────┐
// │              Firewall Templates: VM/CT Rule Sets           │
// └──────────────────────────────────────────────────────────┘
const FW_ICONS = {
    mail: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
    globe: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
    database: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>',
    server: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>',
    box: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>',
    zap: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
    shield: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
};

let _fwTemplates = null;
let _fwVmCache = [];

async function loadFwTemplates() {
    const grid = document.getElementById('fwTemplateGrid');
    if (grid) grid.innerHTML = `<div style="display:flex;align-items:center;gap:8px;padding:8px;grid-column:1/-1"><span style="width:14px;height:14px;border:2px solid var(--border-subtle);border-top-color:var(--accent);border-radius:50%;animation:spin .6s linear infinite;flex-shrink:0"></span><span style="color:var(--text3)">${T.loading}</span></div>`;
    const d = await api('fw-templates');
    if (!d.ok) return;
    _fwTemplates = [...d.builtin.map(t => ({...t, _type: 'builtin'})), ...d.custom.map(t => ({...t, _type: 'custom'}))];
    const assignments = d.assignments || {};
    // Build reverse map: template_id → [vmid, ...]
    const tplVms = {};
    Object.entries(assignments).forEach(([key, a]) => {
        if (!tplVms[a.template_id]) tplVms[a.template_id] = [];
        const [type, vmid] = key.split(':');
        tplVms[a.template_id].push({ vmid, type });
    });
    if (!grid) return;
    let html = '';
    _fwTemplates.forEach(t => {
        const icon = FW_ICONS[t.icon] || FW_ICONS.shield;
        const badge = t._type === 'builtin'
            ? `<span style="font-size:.55rem;background:rgba(96,165,250,.15);color:#60a5fa;padding:1px 5px;border-radius:3px">${T.fw_builtin}</span>`
            : `<span style="font-size:.55rem;background:rgba(139,92,246,.15);color:#8b5cf6;padding:1px 5px;border-radius:3px">${T.fw_custom}</span>`;
        const ruleCount = T.fw_rules_count.replace('%d', t.rules.length);
        const assigned = tplVms[t.id] || [];
        const assignedHtml = assigned.length > 0
            ? `<div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:3px">${assigned.map(a => `<span style="font-size:.55rem;background:rgba(34,197,94,.12);color:var(--green);padding:1px 5px;border-radius:3px">${a.type === 'qemu' ? 'VM' : 'CT'} ${a.vmid}</span>`).join('')}</div>`
            : '';
        const borderColor = assigned.length > 0 ? 'rgba(34,197,94,.25)' : 'var(--border-subtle)';
        html += `<div style="background:var(--bg);border:1px solid ${borderColor};border-radius:var(--radius);padding:10px 12px;cursor:pointer;transition:border-color .15s" onmouseenter="this.style.borderColor='var(--accent)'" onmouseleave="this.style.borderColor='${borderColor}'" onclick="fwShowTemplate('${t.id}')">
            <div style="display:flex;align-items:center;gap:8px">
                <span style="color:var(--accent);flex-shrink:0">${icon}</span>
                <div style="flex:1;min-width:0">
                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px">
                        <span style="font-size:.78rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${t.name}</span>
                        ${badge}
                        <span style="font-size:.52rem;color:var(--text3);font-family:var(--mono);margin-left:auto;flex-shrink:0">${ruleCount}</span>
                    </div>
                    <div style="font-size:.64rem;color:var(--text3);line-height:1.3;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${t.description}</div>
                </div>
            </div>
            ${assignedHtml}
        </div>`;
    });
    grid.innerHTML = html;
}

async function fwShowTemplate(id) {
    const tpl = _fwTemplates?.find(t => t.id === id);
    if (!tpl) return;
    document.getElementById('fwTplTitle').textContent = tpl.name;
    document.getElementById('fwTplDesc').textContent = tpl.description;

    // Editable rules table
    const inputStyle = 'background:rgba(255,255,255,.04);border:1px solid var(--border-subtle);border-radius:3px;color:var(--text);font-family:var(--mono);font-size:.68rem;padding:2px 6px;width:100%';
    let rhtml = '<table style="width:100%;border-collapse:collapse;font-size:.72rem"><thead><tr style="border-bottom:1px solid var(--border-subtle)">'
        + '<th style="padding:4px 6px;text-align:left;color:var(--text3);width:24px"></th>'
        + '<th style="padding:4px 6px;text-align:left;color:var(--text3)">Action</th>'
        + '<th style="padding:4px 6px;text-align:left;color:var(--text3)">Port</th>'
        + '<th style="padding:4px 6px;text-align:left;color:var(--text3)">Proto</th>'
        + '<th style="padding:4px 6px;text-align:left;color:var(--text3)">Source</th>'
        + '<th style="padding:4px 6px;text-align:left;color:var(--text3)">Comment</th>'
        + '</tr></thead><tbody>';
    tpl.rules.forEach((r, i) => {
        const ac = r.action === 'ACCEPT' ? 'var(--green)' : 'var(--red)';
        const isMacro = !!r.macro;
        rhtml += `<tr class="fwTplRow" style="border-bottom:1px solid var(--border-subtle)" data-idx="${i}">
            <td style="padding:3px 6px"><input type="checkbox" checked class="fwTplCb" data-idx="${i}" style="accent-color:var(--accent)"></td>
            <td style="padding:3px 6px;color:${ac};font-weight:600;font-size:.68rem">${r.action}</td>
            <td style="padding:3px 6px">${isMacro ? '<span style="color:var(--text3);font-size:.65rem">—</span>' : `<input style="${inputStyle}" class="fwTplPort" data-idx="${i}" value="${r.dport || ''}">`}</td>
            <td style="padding:3px 6px;font-size:.68rem">${r.macro || r.proto || 'tcp'}</td>
            <td style="padding:3px 6px"><input style="${inputStyle}" class="fwTplSrc" data-idx="${i}" value="${r.source || ''}"></td>
            <td style="padding:3px 6px;color:var(--text3);font-size:.68rem">${r.comment || ''}</td>
        </tr>`;
    });
    rhtml += '</tbody></table>';
    document.getElementById('fwTplRules').innerHTML = rhtml;
    // Store original rules for apply
    document.getElementById('fwTemplateModal')._rules = JSON.parse(JSON.stringify(tpl.rules));

    // VM/CT dropdown
    if (!_fwVmCache.length) {
        const vd = await api('fw-vm-list');
        if (vd.ok) _fwVmCache = vd.guests;
    }
    const sel = document.getElementById('fwTplTarget');
    sel.innerHTML = `<option value="">${T.fw_select_vm}</option>`;
    _fwVmCache.forEach(g => {
        const label = `${g.vmid} — ${g.name} (${g.type === 'qemu' ? 'VM' : 'CT'})`;
        sel.innerHTML += `<option value="${g.vmid}:${g.type}">${label}</option>`;
    });

    document.getElementById('fwTplClear').checked = false;
    document.getElementById('fwTplClear').onchange = function() {
        document.getElementById('fwTplClearWarn').style.display = this.checked ? '' : 'none';
    };
    document.getElementById('fwTplClearWarn').style.display = 'none';

    // Delete button (custom only)
    const delBtn = document.getElementById('fwTplDeleteBtn');
    if (tpl._type === 'custom') {
        delBtn.innerHTML = `<button class="btn btn-red" onclick="fwDeleteTemplate('${tpl.id}')" style="font-size:.7rem">${T.fw_delete_template}</button>`;
    } else { delBtn.innerHTML = ''; }

    document.getElementById('fwTemplateModal').dataset.tplId = id;
    openModal('fwTemplateModal');
}

async function fwApplyTemplate() {
    const modal = document.getElementById('fwTemplateModal');
    const id = modal.dataset.tplId;
    const target = document.getElementById('fwTplTarget').value;
    if (!target) return;
    const [vmid, type] = target.split(':');
    const clear = document.getElementById('fwTplClear').checked;

    // Collect edited rules (checked rows only, with edited ports/sources)
    const rules = modal._rules;
    const editedRules = [];
    rules.forEach((r, i) => {
        const cb = document.querySelector(`.fwTplCb[data-idx="${i}"]`);
        if (!cb || !cb.checked) return;
        const rule = {...r};
        const portInput = document.querySelector(`.fwTplPort[data-idx="${i}"]`);
        const srcInput = document.querySelector(`.fwTplSrc[data-idx="${i}"]`);
        if (portInput && portInput.value.trim()) rule.dport = portInput.value.trim();
        if (srcInput) rule.source = srcInput.value.trim() || undefined;
        editedRules.push(rule);
    });
    if (editedRules.length === 0) return;

    closeModal('fwTemplateModal');
    const d = await api('fw-vm-apply-template', 'POST', { vmid, type, template_id: id, clear_existing: clear ? '1' : '', rules_override: JSON.stringify(editedRules) });
    if (d.ok) {
        _fwVmCache = [];
        loadFwTemplates();
        loadFwVmList();
    }
}

async function fwDeleteTemplate(id) {
    if (!await appConfirm(T.fw_delete_template, T.fw_delete_template_confirm)) return;
    closeModal('fwTemplateModal');
    const d = await api('fw-template-delete', 'POST', { id });
    if (d.ok) loadFwTemplates();
}

// ── VM/CT Firewall list and status ─────────────────
async function loadFwVmList() {
    const el = document.getElementById('fwVmList');
    if (!el) return;
    el.innerHTML = `<div style="display:flex;align-items:center;justify-content:center;gap:8px;padding:14px"><span style="width:14px;height:14px;border:2px solid var(--border-subtle);border-top-color:var(--accent);border-radius:50%;animation:spin .6s linear infinite;flex-shrink:0"></span><span style="color:var(--text3)">${T.loading}</span></div>`;
    const d = await api('fw-vm-list');
    if (!d.ok) return;
    _fwVmCache = d.guests;
    if (d.guests.length === 0) {
        el.innerHTML = `<div style="padding:14px;text-align:center;color:var(--text3)">${T.fw_vm_no_guests}</div>`;
        return;
    }
    const thStyle = 'padding:8px 12px;font-size:.68rem;color:var(--text3);font-weight:600;white-space:nowrap';
    const tdStyle = 'padding:6px 12px;font-size:.75rem;vertical-align:middle';
    let html = `<table style="width:100%;border-collapse:collapse;table-layout:fixed">
        <colgroup><col style="width:50px"><col><col style="width:38px"><col style="width:120px"><col style="width:60px"><col style="width:75px"><col style="width:48px"><col style="width:38px"><col style="width:115px"><col style="width:110px"></colgroup>
        <thead><tr style="border-bottom:1px solid var(--border-subtle)">
            <th style="${thStyle}">VMID</th><th style="${thStyle}">Name</th><th style="${thStyle}">Type</th><th style="${thStyle}">IP</th><th style="${thStyle}">Status</th>
            <th style="${thStyle}">Firewall</th><th style="${thStyle}">Policy</th><th style="${thStyle}">${T.fw_vm_rules}</th><th style="${thStyle}">Template</th><th style="${thStyle};text-align:right"></th>
        </tr></thead><tbody>`;
    d.guests.forEach(g => {
        const dot = (color) => `<span style="width:7px;height:7px;border-radius:50%;background:${color};flex-shrink:0"></span>`;
        const fwDot = dot(g.fw_enabled ? 'var(--green)' : 'var(--red)');
        const fwLabel = g.fw_enabled ? T.fw_vm_enabled : T.fw_vm_disabled;
        const statusDot = dot(g.status === 'running' ? 'var(--green)' : 'var(--text3)');
        const typeBadge = g.type === 'qemu'
            ? '<span style="background:rgba(59,130,246,.15);color:#3b82f6;padding:1px 5px;border-radius:3px;font-size:.6rem;font-weight:600">VM</span>'
            : '<span style="background:rgba(139,92,246,.15);color:#8b5cf6;padding:1px 5px;border-radius:3px;font-size:.6rem;font-weight:600">CT</span>';
        const policyBadge = g.fw_enabled ? `<span style="font-family:var(--mono);font-size:.65rem;background:rgba(255,255,255,.06);padding:2px 6px;border-radius:3px">${g.fw_policy_in}</span>` : '<span style="color:var(--text3)">—</span>';
        const toggleColor = g.fw_enabled ? 'var(--green)' : 'var(--text3)';
        const toggleLabel = g.fw_enabled ? 'ON' : 'OFF';
        const toggleNext = g.fw_enabled ? 0 : 1;
        html += `<tr style="border-bottom:1px solid var(--border-subtle)">
            <td style="${tdStyle};font-family:var(--mono);font-weight:600;color:var(--text2)">${g.vmid}</td>
            <td style="${tdStyle};overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${g.name}</td>
            <td style="${tdStyle}">${typeBadge}</td>
            <td style="${tdStyle};font-family:var(--mono);font-size:.65rem">${g.ips && g.ips.length ? g.ips.map(ip => `<div style="display:flex;align-items:center;gap:4px">${g.is_public ? '<span style="width:6px;height:6px;border-radius:50%;background:var(--yellow);flex-shrink:0" title="Public"></span>' : '<span style="width:6px;height:6px;border-radius:50%;background:var(--text3);flex-shrink:0" title="Intern"></span>'}${ip}</div>`).join('') : '<span style="color:var(--text3)">—</span>'}</td>
            <td style="${tdStyle}"><div style="display:flex;align-items:center;gap:5px">${statusDot}<span style="font-size:.7rem">${g.status}</span></div></td>
            <td style="${tdStyle}"><div style="display:flex;align-items:center;gap:5px">${fwDot}<span style="font-size:.7rem">${fwLabel}</span></div></td>
            <td style="${tdStyle}">${policyBadge}</td>
            <td style="${tdStyle};font-family:var(--mono);text-align:center">${g.rule_count}</td>
            <td style="${tdStyle};font-size:.65rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${g.template ? `<span style="color:var(--accent)">${g.template.template_name}</span>` : '<span style="color:var(--text3)">—</span>'}</td>
            <td style="${tdStyle};text-align:right;white-space:nowrap">
                <button class="btn btn-sm" onclick="fwViewVmRules(${g.vmid},'${g.type}','${g.name}')" style="padding:2px 8px;font-size:.55rem">${T.fw_view_rules}</button>
                <button class="btn btn-sm" onclick="fwToggleVm(${g.vmid},'${g.type}',${toggleNext})" style="padding:2px 6px;font-size:.55rem;min-width:32px;color:${toggleColor};border-color:${toggleColor}">${toggleLabel}</button>
            </td>
        </tr>`;
    });
    html += '</tbody></table>';
    el.innerHTML = html;
}

async function fwToggleVm(vmid, type, enable) {
    const msg = enable ? T.fw_vm_enable_confirm : T.fw_vm_disable_confirm;
    if (!await appConfirm(T.fw_vm_firewall, msg, enable ? 'warning' : 'danger')) return;
    await api('fw-vm-toggle', 'POST', { vmid, type, enable });
    _fwVmCache = [];
    loadFwVmList();
}

// ── VM/CT Rule viewer and editor ──────────────
let _fwVmRulesCtx = {};
async function fwViewVmRules(vmid, type, name) {
    _fwVmRulesCtx = { vmid, type };
    document.getElementById('fwVmRulesTitle').textContent = `${name} (${type === 'qemu' ? 'VM' : 'CT'} ${vmid}) — Firewall`;
    const d = await api('fw-vm-rules&vmid=' + vmid + '&type=' + type);
    if (!d.ok) return;
    const el = document.getElementById('fwVmRulesContent');
    if (d.rules.length === 0) {
        el.innerHTML = `<div style="text-align:center;color:var(--text3);padding:14px;font-size:.75rem">${T.sec_no_rules}</div>`;
    } else {
        let html = '<table style="width:100%;border-collapse:collapse;font-size:.72rem"><thead><tr style="border-bottom:1px solid var(--border-subtle)">'
            + '<th style="padding:4px 8px;text-align:left;color:var(--text3)">#</th>'
            + '<th style="padding:4px 8px;text-align:left;color:var(--text3)">Action</th>'
            + '<th style="padding:4px 8px;text-align:left;color:var(--text3)">Port</th>'
            + '<th style="padding:4px 8px;text-align:left;color:var(--text3)">Proto</th>'
            + '<th style="padding:4px 8px;text-align:left;color:var(--text3)">Source</th>'
            + '<th style="padding:4px 8px;text-align:left;color:var(--text3)">Comment</th>'
            + '<th style="padding:4px 8px;text-align:right;color:var(--text3)"></th>'
            + '</tr></thead><tbody>';
        d.rules.forEach(r => {
            const ac = r.action === 'ACCEPT' ? 'var(--green)' : 'var(--red)';
            html += `<tr style="border-bottom:1px solid var(--border-subtle)">
                <td style="padding:4px 8px;color:var(--text3)">${r.pos ?? ''}</td>
                <td style="padding:4px 8px;color:${ac};font-weight:600">${r.action}</td>
                <td style="padding:4px 8px;font-family:var(--mono)">${r.dport || (r.macro ? '—' : '*')}</td>
                <td style="padding:4px 8px">${r.macro || r.proto || ''}</td>
                <td style="padding:4px 8px;font-family:var(--mono)">${r.source || '*'}</td>
                <td style="padding:4px 8px;color:var(--text3)">${r.comment || ''}</td>
                <td style="padding:4px 8px;text-align:right">
                    <button class="btn btn-sm btn-red" onclick="fwVmDeleteRule(${r.pos})" style="padding:1px 5px;font-size:.5rem" title="${T.sec_delete_rule}">✕</button>
                </td>
            </tr>`;
        });
        html += '</tbody></table>';
        el.innerHTML = html;
    }
    openModal('fwVmRulesModal');
}

async function fwVmDeleteRule(pos) {
    if (!await appConfirm(T.sec_delete_rule, T.sec_delete_rule_confirm)) return;
    await api('fw-vm-delete-rule', 'POST', { vmid: _fwVmRulesCtx.vmid, type: _fwVmRulesCtx.type, pos });
    fwViewVmRules(_fwVmRulesCtx.vmid, _fwVmRulesCtx.type, document.getElementById('fwVmRulesTitle').textContent.split(' —')[0]);
    _fwVmCache = [];
}

async function fwVmAddRule() {
    const d = await api('fw-vm-add-rule', 'POST', {
        vmid: _fwVmRulesCtx.vmid, type: _fwVmRulesCtx.type,
        rule_action: document.getElementById('fwVmrAction').value,
        rule_type: 'in',
        dport: document.getElementById('fwVmrPort').value,
        proto: document.getElementById('fwVmrProto').value,
        source: document.getElementById('fwVmrSource').value,
        comment: document.getElementById('fwVmrComment').value,
    });
    if (d.ok) {
        document.getElementById('fwVmrPort').value = '';
        document.getElementById('fwVmrComment').value = '';
        document.getElementById('fwVmrSource').value = '';
        fwViewVmRules(_fwVmRulesCtx.vmid, _fwVmRulesCtx.type, document.getElementById('fwVmRulesTitle').textContent.split(' —')[0]);
        _fwVmCache = [];
    }
}

// ── Custom Template Builder (Modal) ─────────────────
function fwOpenBuilder(editId) {
    document.getElementById('fwbEditId').value = editId || '';
    document.getElementById('fwbName').value = '';
    document.getElementById('fwbDesc').value = '';
    const rules = document.getElementById('fwbRules');
    rules.innerHTML = '';

    if (editId) {
        const tpl = _fwTemplates?.find(t => t.id === editId);
        if (tpl) {
            document.getElementById('fwbName').value = tpl.name;
            document.getElementById('fwbDesc').value = tpl.description;
            tpl.rules.forEach(r => fwbAddRow(r));
        }
    } else {
        // Start with SSH rule
        fwbAddRow({ action: 'ACCEPT', type: 'in', dport: '22', proto: 'tcp', comment: 'SSH' });
    }
    openModal('fwBuilderModal');
}

let _fwbRowIdx = 0;
function fwbAddRow(rule) {
    const r = rule || { action: 'ACCEPT', type: 'in', dport: '', proto: 'tcp', comment: '' };
    const idx = _fwbRowIdx++;
    const row = document.createElement('div');
    row.style.cssText = 'display:grid;grid-template-columns:80px 70px 80px 70px 100px 1fr 24px;gap:4px;margin-bottom:4px;align-items:center';
    row.innerHTML = `
        <select class="form-input" style="font-size:.65rem;padding:3px 4px" data-f="action"><option ${r.action==='ACCEPT'?'selected':''}>ACCEPT</option><option ${r.action==='DROP'?'selected':''}>DROP</option><option ${r.action==='REJECT'?'selected':''}>REJECT</option></select>
        <select class="form-input" style="font-size:.65rem;padding:3px 4px" data-f="type"><option value="in" ${r.type==='in'?'selected':''}>IN</option><option value="out" ${r.type==='out'?'selected':''}>OUT</option></select>
        <input class="form-input" style="font-size:.65rem;padding:3px 4px" data-f="dport" placeholder="Port" value="${r.dport||''}">
        <select class="form-input" style="font-size:.65rem;padding:3px 4px" data-f="proto"><option value="tcp" ${r.proto==='tcp'?'selected':''}>TCP</option><option value="udp" ${r.proto==='udp'?'selected':''}>UDP</option></select>
        <input class="form-input" style="font-size:.65rem;padding:3px 4px" data-f="source" placeholder="Source" value="${r.source||''}">
        <input class="form-input" style="font-size:.65rem;padding:3px 4px" data-f="comment" placeholder="Comment" value="${r.comment||''}">
        <button class="btn btn-sm btn-red" onclick="this.parentElement.remove()" style="padding:1px 4px;font-size:.5rem;line-height:1">✕</button>`;
    document.getElementById('fwbRules').appendChild(row);
}

async function fwSaveCustom() {
    const name = document.getElementById('fwbName').value.trim();
    const desc = document.getElementById('fwbDesc').value.trim();
    const editId = document.getElementById('fwbEditId').value;
    if (!name) return;

    const rules = [];
    document.querySelectorAll('#fwbRules > div').forEach(row => {
        const r = {};
        row.querySelectorAll('[data-f]').forEach(el => { r[el.dataset.f] = el.value; });
        if (r.dport || r.action) rules.push(r);
    });
    if (rules.length === 0) return;

    const payload = { name, description: desc, icon: 'shield', rules: JSON.stringify(rules) };
    if (editId) payload.id = editId;

    const d = await api('fw-template-save', 'POST', payload);
    if (d.ok) {
        closeModal('fwBuilderModal');
        loadFwTemplates();
    }
}

// ┌──────────────────────────────────────────────────────────┐
// │              SSL Health Check                             │
// └──────────────────────────────────────────────────────────┘
async function loadSslHealth() {
    const el = document.getElementById('sslHealthResult');
    const badge = document.getElementById('sslIssueCount');
    el.innerHTML = `<div style="display:flex;align-items:center;gap:8px;padding:4px 0"><span style="width:14px;height:14px;border:2px solid var(--border-subtle);border-top-color:var(--accent);border-radius:50%;animation:spin .6s linear infinite;flex-shrink:0"></span>${T.ssl_scanning}</div>`;
    badge.style.display = 'none';

    const d = await api('ssl-health');
    if (!d.ok) { el.innerHTML = T.error; return; }

    const results = d.results;
    let issueCount = 0;
    results.forEach(r => {
        if (!r.dns_a || (r.has_aaaa && !r.dns_aaaa) || !r.ssl_valid || !r.cert_match || !r.v4v6_match) issueCount++;
        if (r.issues?.length) issueCount += r.issues.length;
    });

    if (issueCount > 0) {
        badge.textContent = issueCount;
        badge.style.display = '';
    }

    if (results.length === 0) {
        el.innerHTML = `<div style="text-align:center;color:var(--text3)">${T.ssl_all_ok}</div>`;
        return;
    }

    const ok = (v) => v ? `<span style="color:var(--green);font-weight:600">✓</span>` : `<span style="color:var(--red);font-weight:600">✗</span>`;
    const na = `<span style="color:var(--text3)">—</span>`;

    let html = '<table style="width:100%;border-collapse:collapse;font-size:.72rem"><thead><tr style="border-bottom:1px solid var(--border-subtle)">'
        + `<th style="padding:6px 8px;text-align:left;color:var(--text3);font-weight:600">${T.ssl_domain}</th>`
        + `<th style="padding:6px 8px;text-align:center;color:var(--text3);font-weight:600">${T.ssl_dns_v4}</th>`
        + `<th style="padding:6px 8px;text-align:center;color:var(--text3);font-weight:600">${T.ssl_dns_v6}</th>`
        + `<th style="padding:6px 8px;text-align:center;color:var(--text3);font-weight:600">${T.ssl_cert}</th>`
        + `<th style="padding:6px 8px;text-align:center;color:var(--text3);font-weight:600">${T.ssl_cert_match}</th>`
        + `<th style="padding:6px 8px;text-align:center;color:var(--text3);font-weight:600">${T.ssl_v4v6}</th>`
        + `<th style="padding:6px 8px;text-align:center;color:var(--text3);font-weight:600">${T.ssl_expiry}</th>`
        + `<th style="padding:6px 8px;text-align:right;color:var(--text3);font-weight:600">${T.ssl_fix}</th>`
        + '</tr></thead><tbody>';

    results.forEach(r => {
        const hasIssue = !r.dns_a || (r.has_aaaa && !r.dns_aaaa) || !r.ssl_valid || !r.cert_match || !r.v4v6_match || r.issues?.length;
        const rowBg = hasIssue ? 'background:rgba(239,68,68,.04)' : '';

        // DNS tooltips
        const dnsATitle = r.dns_a_ip ? `title="${r.dns_a_ip}"` : '';
        const dnsAAAATitle = r.dns_aaaa_ip ? `title="${r.dns_aaaa_ip}"` : '';

        // Expiry
        let expiryHtml = na;
        if (r.ssl_days !== null) {
            const color = r.ssl_days < 7 ? 'var(--red)' : r.ssl_days < 30 ? 'var(--yellow)' : 'var(--green)';
            expiryHtml = `<span style="color:${color};font-family:var(--mono)">${T.ssl_days.replace('%d', r.ssl_days)}</span>`;
        }

        // Fix buttons
        let fixHtml = '';
        if (r.issues?.includes('ipv6only_on')) {
            fixHtml = `<button class="btn btn-sm btn-yellow" onclick="sslFixIpv6only('${r.file}')" style="padding:1px 6px;font-size:.55rem">${T.ssl_fix_ipv6only}</button>`;
        }

        html += `<tr style="border-bottom:1px solid var(--border-subtle);${rowBg}">
            <td style="padding:5px 8px;font-size:.68rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${r.domain}">${r.domain}</td>
            <td style="padding:5px 8px;text-align:center" ${dnsATitle}>${ok(r.dns_a)}</td>
            <td style="padding:5px 8px;text-align:center" ${dnsAAAATitle}>${r.has_aaaa ? ok(r.dns_aaaa) : na}</td>
            <td style="padding:5px 8px;text-align:center">${ok(r.ssl_valid)}</td>
            <td style="padding:5px 8px;text-align:center">${ok(r.cert_match)}</td>
            <td style="padding:5px 8px;text-align:center">${r.has_aaaa ? ok(r.v4v6_match) : na}</td>
            <td style="padding:5px 8px;text-align:center">${expiryHtml}</td>
            <td style="padding:5px 8px;text-align:right">${fixHtml}</td>
        </tr>`;
    });
    html += '</tbody></table>';

    if (issueCount === 0) {
        html = `<div style="text-align:center;color:var(--green);padding:8px;font-size:.78rem;margin-bottom:8px">✓ ${T.ssl_all_ok}</div>` + html;
    }

    el.innerHTML = html;
}

async function sslFixIpv6only(file) {
    if (!await appConfirm(T.ssl_fix_ipv6only, T.ssl_fix_ipv6only_desc, 'warning')) return;
    const d = await api('ssl-fix-ipv6only', 'POST', { file });
    if (d.ok) loadSslHealth();
}

// ┌──────────────────────────────────────────────────────────┐
// │              Hilfe-Seite + Suche                          │
// └──────────────────────────────────────────────────────────┘
function toggleHelp(id) {
    const sec = document.getElementById(id);
    if (!sec) return;
    const body = sec.querySelector('.help-body');
    const isOpen = body.style.display !== 'none';
    body.style.display = isOpen ? 'none' : '';
    sec.classList.toggle('open', !isOpen);
}

function filterHelp(query) {
    const q = query.toLowerCase().trim();
    const sections = document.querySelectorAll('.help-section');
    let found = 0;
    sections.forEach(sec => {
        const title = sec.querySelector('[style*="font-weight:600"]')?.textContent || '';
        const body = sec.querySelector('.help-body');
        if (!body) return;
        // Remove old highlights
        body.querySelectorAll('mark').forEach(m => { m.outerHTML = m.textContent; });
        if (!q) {
            sec.style.display = '';
            body.style.display = 'none';
            sec.classList.remove('open');
            found++;
            return;
        }
        const text = (title + ' ' + body.textContent).toLowerCase();
        if (text.includes(q)) {
            sec.style.display = '';
            body.style.display = '';
            sec.classList.add('open');
            // Highlight matches in body
            const walker = document.createTreeWalker(body, NodeFilter.SHOW_TEXT);
            const nodes = [];
            while (walker.nextNode()) nodes.push(walker.currentNode);
            nodes.forEach(node => {
                const idx = node.textContent.toLowerCase().indexOf(q);
                if (idx === -1) return;
                const span = document.createElement('span');
                span.innerHTML = node.textContent.substring(0, idx)
                    + '<mark>' + node.textContent.substring(idx, idx + q.length) + '</mark>'
                    + node.textContent.substring(idx + q.length);
                node.parentNode.replaceChild(span, node);
            });
            found++;
        } else {
            sec.style.display = 'none';
        }
    });
    const noRes = document.getElementById('helpNoResults');
    if (noRes) noRes.style.display = found === 0 && q ? '' : 'none';
}

// ── App-eigene Confirm/Prompt Dialoge (ersetzt browser-native) ──
function appConfirm(title, message, type = 'danger') {
    return new Promise(resolve => {
        let modal = document.getElementById('appConfirmModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'appConfirmModal';
            modal.className = 'modal-overlay';
            modal.innerHTML = '<div class="modal" style="max-width:400px"><div class="modal-head"><div class="modal-title" id="appConfirmTitle"></div><button class="modal-close" id="appConfirmClose">&times;</button></div><div class="modal-body" id="appConfirmBody" style="font-size:.82rem"></div><div class="modal-foot"><button class="btn" id="appConfirmNo">Abbrechen</button><button class="btn" id="appConfirmYes">OK</button></div></div>';
            document.body.appendChild(modal);
        }
        document.getElementById('appConfirmTitle').textContent = title;
        document.getElementById('appConfirmBody').innerHTML = message;
        const yesBtn = document.getElementById('appConfirmYes');
        yesBtn.className = type === 'danger' ? 'btn btn-red' : 'btn btn-accent';
        yesBtn.textContent = type === 'danger' ? 'Ja, fortfahren' : 'OK';
        modal.classList.add('active');
        const cleanup = (val) => { modal.classList.remove('active'); resolve(val); };
        document.getElementById('appConfirmYes').onclick = () => cleanup(true);
        document.getElementById('appConfirmNo').onclick = () => cleanup(false);
        document.getElementById('appConfirmClose').onclick = () => cleanup(false);
    });
}

function appPrompt(title, label, defaultVal = '') {
    return new Promise(resolve => {
        let modal = document.getElementById('appPromptModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'appPromptModal';
            modal.className = 'modal-overlay';
            modal.innerHTML = '<div class="modal" style="max-width:420px"><div class="modal-head"><div class="modal-title" id="appPromptTitle"></div><button class="modal-close" id="appPromptClose">&times;</button></div><div class="modal-body"><div style="font-size:.78rem;margin-bottom:8px" id="appPromptLabel"></div><input class="form-input" id="appPromptInput" style="font-family:var(--mono);font-size:.78rem"></div><div class="modal-foot"><button class="btn" id="appPromptNo">Abbrechen</button><button class="btn btn-accent" id="appPromptYes">OK</button></div></div>';
            document.body.appendChild(modal);
        }
        document.getElementById('appPromptTitle').textContent = title;
        document.getElementById('appPromptLabel').textContent = label;
        const input = document.getElementById('appPromptInput');
        input.value = defaultVal;
        modal.classList.add('active');
        setTimeout(() => input.focus(), 100);
        const cleanup = (val) => { modal.classList.remove('active'); resolve(val); };
        document.getElementById('appPromptYes').onclick = () => cleanup(input.value.trim());
        document.getElementById('appPromptNo').onclick = () => cleanup(null);
        document.getElementById('appPromptClose').onclick = () => cleanup(null);
        input.onkeydown = (e) => { if (e.key === 'Enter') cleanup(input.value.trim()); };
    });
}

// ── Modal-Management ────────────────────────────────
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => {
        if (e.target === m && m.id !== 'wgWizardModal') closeModal(m.id);
    });
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.active').forEach(m => closeModal(m.id));
});

// ┌──────────────────────────────────────────────────────────┐
// │              Init: App-Start + Updates-Tab                │
// └──────────────────────────────────────────────────────────┘
loadStats();
loadDashboardVms();
setInterval(loadStats, 4000);
setInterval(loadDashboardVms, 30000);
// Restore tab from URL hash (after all functions are defined)
if (location.hash && location.hash.length > 1) {
    const h = location.hash.substring(1);
    // Map old tab names to new grouped tabs + sub-tabs
    const tabMap = { fail2ban: ['security','fail2ban'], firewall: ['security','firewall'], portscan: ['security','portscan'],
        nginx: ['network','nginx'], wireguard: ['network','wireguard'], updates: 'system' };
    if (tabMap[h] && Array.isArray(tabMap[h])) { switchTab(tabMap[h][0]); switchSubTab(tabMap[h][0], tabMap[h][1]); }
    else if (tabMap[h]) { switchTab(tabMap[h]); }
    else if (document.querySelector('.nav-tab[data-tab="' + h + '"]')) { switchTab(h); }
    // Ensure sub-tab data loads for grouped tabs opened via hash
    if (h === 'network') switchSubTab('network', 'wireguard');
}

// ═══ Updates Tab ════════════════════════════════════
async function loadUpdates() {
    // Load all checks in parallel
    const [repo, app, sys] = await Promise.all([
        api('repo-check').catch(() => null),
        api('update-check').catch(() => null),
        api('apt-check').catch(() => null),
    ]);

    // Repo check
    try {
        const banner = document.getElementById('updRepoBanner');
        if (repo && repo.warning) banner.style.display = 'block';
        else banner.style.display = 'none';
    } catch(e) {}

    // App update check
    try {
        const el = document.getElementById('appUpdateInfo');
        if (app.ok) {
            let html = '<div style="display:flex;flex-direction:column;gap:6px">';
            html += '<div style="display:flex;justify-content:space-between"><span style="color:var(--text3)">' + T.installed_label + ':</span><span style="font-family:var(--mono);font-weight:600">v' + app.local_version + '</span></div>';
            html += '<div style="display:flex;justify-content:space-between"><span style="color:var(--text3)">' + T.available_label + ':</span><span style="font-family:var(--mono)">v' + app.remote_version + '</span></div>';
            html += '<div style="display:flex;justify-content:space-between"><span style="color:var(--text3)">' + T.update_method + ':</span><span>' + (app.is_git ? 'Git (git pull)' : 'Download (GitHub)') + '</span></div>';
            if (app.update_available) {
                html += '<div style="margin-top:6px;padding:8px 12px;background:rgba(64,196,255,.06);border:1px solid rgba(64,196,255,.15);border-radius:6px;display:flex;align-items:center;gap:8px">';
                html += '<span style="color:var(--blue);font-weight:600">v' + app.remote_version + ' verfügbar</span>';
                html += '<button class="btn btn-sm btn-accent" onclick="appUpdate()" style="margin-left:auto">Update</button></div>';
            } else {
                html += '<div style="margin-top:4px;display:flex;align-items:center;gap:6px;color:var(--green);font-size:.72rem"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Aktuell</div>';
            }
            html += '</div>';
            el.innerHTML = html;
        } else { el.innerHTML = '<span style="color:var(--text3)">Prüfung fehlgeschlagen</span>'; }
    } catch(e) { document.getElementById('appUpdateInfo').innerHTML = '<span style="color:var(--red)">Fehler</span>'; }

    // System updates — simple status
    try {
        if (!sys) throw new Error('no data');
        const countEl = document.getElementById('updCount');
        const iconEl = document.getElementById('updStatusIcon');
        const textEl = document.getElementById('updStatusText');
        const subEl = document.getElementById('updStatusSub');
        const rebootEl = document.getElementById('updRebootBanner');
        if (sys.reboot_required) rebootEl.style.display = '';
        else rebootEl.style.display = 'none';
        countEl.textContent = sys.count;
        countEl.style.background = sys.count > 0 ? 'rgba(255,89,0,.15)' : 'rgba(40,167,69,.1)';
        countEl.style.color = sys.count > 0 ? 'var(--accent)' : 'var(--green)';
        if (sys.count === 0) {
            iconEl.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
            iconEl.style.background = 'rgba(40,167,69,.1)';
            textEl.textContent = T.system_up_to_date; textEl.style.color = 'var(--green)';
            subEl.textContent = ''; document.getElementById('btnAptUpgrade').style.display = 'none';
        } else {
            iconEl.innerHTML = '<span style="font-size:1.1rem;font-weight:700;color:var(--accent)">' + sys.count + '</span>';
            iconEl.style.background = 'rgba(255,89,0,.1)';
            textEl.textContent = sys.count + ' ' + T.updates_available; textEl.style.color = 'var(--accent)';
            const pve = sys.updates.filter(u => u.name.startsWith('pve-') || u.name.startsWith('proxmox-') || u.name.startsWith('qemu'));
            subEl.textContent = (pve.length ? pve.length + ' PVE, ' : '') + (sys.count - pve.length) + ' System';
            document.getElementById('btnAptUpgrade').style.display = '';
        }
        // Dashboard update card
        const dashUpd = document.getElementById('sUpdates');
        if (dashUpd) { dashUpd.textContent = sys.count; dashUpd.style.color = sys.count > 0 ? 'var(--accent)' : 'var(--green)'; }
    } catch(e) { const t = document.getElementById('updStatusText'); if(t) t.textContent = T.error; }

    // Repos
    try {
        const el = document.getElementById('repoList');
        const warnEl = document.getElementById('repoWarning');
        const warnText = document.getElementById('repoWarningText');
        const subBadge = document.getElementById('repoSubBadge');

        // Subscription badge
        if (repo.has_subscription) {
            subBadge.style.display = ''; subBadge.textContent = T.sub_active;
            subBadge.style.background = 'rgba(40,167,69,.1)'; subBadge.style.color = 'var(--green)';
        } else {
            subBadge.style.display = ''; subBadge.textContent = T.no_subscription;
            subBadge.style.background = 'rgba(255,193,7,.1)'; subBadge.style.color = 'var(--yellow)';
        }

        // Warnings
        if (repo.enterprise_active && repo.no_sub_active) {
            warnEl.style.display = 'flex';
            warnText.textContent = T.repo_warn_both;
        } else if (repo.enterprise_active && !repo.has_subscription) {
            warnEl.style.display = 'flex';
            warnText.textContent = T.repo_warn_no_sub;
        } else if (repo.has_subscription && !repo.enterprise_active) {
            warnEl.style.display = 'flex';
            warnText.textContent = T.repo_warn_no_enterprise;
        } else if (!repo.no_sub_active && !repo.enterprise_active) {
            warnEl.style.display = 'flex';
            warnText.textContent = T.repo_warn_none;
        } else {
            warnEl.style.display = 'none';
        }

        function repoRow(r, hasSub) {
            const isEnt = r.components.includes('enterprise');
            const isTest = r.components.includes('pvetest');
            const label = r._label || r.components;
            const desc = r._desc || '';
            const missing = r._missing;

            let html = '<div style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border-subtle)">';
            // Toggle
            html += '<input type="checkbox" ' + (r.active ? 'checked' : '') + ' onchange="repoToggle(\'' + (r.file||'') + '\',this.checked,\'' + r.components + '\')" style="width:16px;height:16px;accent-color:var(--accent);cursor:pointer;flex-shrink:0">';
            // Info
            html += '<div style="flex:1;min-width:0">';
            html += '<div style="font-size:.76rem;font-weight:600;display:flex;align-items:center;gap:6px">' + label;
            if (r._standard) html += ' <span style="font-size:.5rem;padding:1px 5px;border-radius:3px;background:rgba(255,89,0,.08);color:var(--accent)">PVE</span>';
            if (isEnt && !hasSub && r.active) html += ' <span style="font-size:.5rem;padding:1px 5px;border-radius:3px;background:rgba(220,53,69,.1);color:var(--red)">' + T.no_license + '</span>';
            if (isTest && r.active) html += ' <span style="font-size:.5rem;padding:1px 5px;border-radius:3px;background:rgba(255,193,7,.1);color:var(--yellow)">' + T.caution + '</span>';
            if (missing) html += ' <span style="font-size:.5rem;padding:1px 5px;border-radius:3px;background:var(--surface-solid);color:var(--text3)">' + T.not_configured + '</span>';
            html += '</div>';
            if (desc) html += '<div style="font-size:.64rem;color:var(--text3)">' + desc + '</div>';
            if (r.url && !missing) html += '<div style="font-family:var(--mono);font-size:.58rem;color:var(--text3);margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + r.url + ' ' + r.suite + '</div>';
            html += '</div>';
            if (r.file) html += '<span style="font-size:.54rem;color:var(--text3)">' + r.file + '</span>';
            html += '</div>';
            return html;
        }

        let html = '';
        // PVE Standard Repos
        (repo.pve_repos || []).forEach(r => html += repoRow(r, repo.has_subscription));
        // Other repos (separator)
        const others = repo.other_repos || [];
        if (others.length) {
            html += '<div style="padding:6px 16px;font-size:.64rem;font-weight:600;color:var(--text3);background:rgba(0,0,0,.1)">' + T.other_repos + '</div>';
            others.forEach(r => { r._label = r.components; r._desc = ''; html += repoRow(r, repo.has_subscription); });
        }
        el.innerHTML = html;
    } catch(e) {}

    // App auto-update status
    try {
        const aau = await api('app-auto-update-status');
        document.getElementById('appAutoUpdateToggle').checked = aau.enabled;
        document.getElementById('appAutoSchedule').style.opacity = aau.enabled ? '1' : '.4';
        document.getElementById('appAutoSchedule').style.pointerEvents = aau.enabled ? '' : 'none';
        if (aau.enabled) {
            document.getElementById('appAutoDay').value = aau.day;
            document.getElementById('appAutoHour').value = aau.hour;
        }
    } catch(e) {}

    // System auto-update status
    try {
        const au = await api('auto-update-status');
        document.getElementById('autoUpdateToggle').checked = au.enabled;
        document.getElementById('autoUpdateSchedule').style.opacity = au.enabled ? '1' : '.4';
        document.getElementById('autoUpdateSchedule').style.pointerEvents = au.enabled ? '' : 'none';
        if (au.enabled) {
            document.getElementById('autoUpdateDay').value = au.day;
            document.getElementById('autoUpdateHour').value = au.hour;
        }
        document.getElementById('autoUpdateTz').textContent = au.timezone || '';
        document.getElementById('autoUpdateStatus').textContent = au.enabled ? (au.day === 0 ? 'täglich' : ['','Mo','Di','Mi','Do','Fr','Sa','So'][au.day]) + ' ' + String(au.hour).padStart(2,'0') + ':00' : '';
    } catch(e) {}
}

async function aptRefresh() {
    const btn = document.getElementById('btnAptRefresh');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-small"></span> ' + T.checking;
    try {
        await api('apt-refresh', 'POST');
        await loadUpdates();
    } catch(e) { toast(T.error + ': ' + e.message, 'error'); }
    btn.disabled = false; btn.innerHTML = T.check_btn;
}

async function aptUpgrade() {
    if (!await appConfirm(T.system_update, T.confirm_install_all)) return;
    const btn = document.getElementById('btnAptUpgrade');
    const outEl = document.getElementById('updOutput');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-small"></span> Installiere...';
    outEl.style.display = 'block'; outEl.textContent = T.apt_running;
    try {
        const res = await api('apt-upgrade', 'POST');
        outEl.textContent = res.output + (res.autoremove ? '\n\nautoremove:\n' + res.autoremove : '');
        if (res.ok) toast(T.updates_installed);
        else toast(T.update_failed, 'error');
        await loadUpdates();
    } catch(e) { toast(T.error + ': ' + e.message, 'error'); outEl.textContent = e.message; }
    btn.disabled = false; btn.innerHTML = 'Alle Updates installieren';
}

async function appUpdate() {
    try {
        const res = await api('update-pull', 'POST');
        if (res.ok) { toast(T.update_success_reload); setTimeout(() => location.reload(), 1500); }
        else toast(T.update_failed + ': ' + (res.output || ''), 'error');
    } catch(e) { toast(T.error + ': ' + e.message, 'error'); }
}

async function repoToggle(file, enable, component) {
    try {
        const data = { enable: enable ? '1' : '0' };
        if (file) data.file = file; else data.component = component;
        const res = await api('repo-toggle', 'POST', data);
        if (res.ok) { toast(res.output || (enable ? 'Aktiviert' : 'Deaktiviert')); loadUpdates(); }
        else toast(res.error || T.error, 'error');
    } catch(e) { toast(T.error + ': ' + e.message, 'error'); }
}

let _appAutoTimer = null;
function appAutoUpdateChanged() {
    const enabled = document.getElementById('appAutoUpdateToggle').checked;
    document.getElementById('appAutoSchedule').style.opacity = enabled ? '1' : '.4';
    document.getElementById('appAutoSchedule').style.pointerEvents = enabled ? '' : 'none';
    clearTimeout(_appAutoTimer);
    _appAutoTimer = setTimeout(() => saveAppAutoUpdate(), 500);
}

async function saveAppAutoUpdate() {
    const enabled = document.getElementById('appAutoUpdateToggle').checked;
    const day = document.getElementById('appAutoDay').value;
    const hour = document.getElementById('appAutoHour').value;
    try {
        const res = await api('app-auto-update-save', 'POST', { enabled: enabled ? '1' : '0', day, hour });
        if (res.ok) toast(enabled ? T.app_autoupdate_saved : T.app_autoupdate_disabled);
    } catch(e) { toast(T.error + ': ' + e.message, 'error'); }
}

let _autoUpdateTimer = null;
function autoUpdateChanged() {
    const enabled = document.getElementById('autoUpdateToggle').checked;
    document.getElementById('autoUpdateSchedule').style.opacity = enabled ? '1' : '.4';
    document.getElementById('autoUpdateSchedule').style.pointerEvents = enabled ? '' : 'none';
    // Debounce save
    clearTimeout(_autoUpdateTimer);
    _autoUpdateTimer = setTimeout(() => saveAutoUpdate(), 500);
}

async function saveAutoUpdate() {
    const enabled = document.getElementById('autoUpdateToggle').checked;
    const day = document.getElementById('autoUpdateDay').value;
    const hour = document.getElementById('autoUpdateHour').value;
    try {
        const res = await api('auto-update-save', 'POST', { enabled: enabled ? '1' : '0', day, hour });
        if (res.ok) {
            const dayNames = ['täglich','Mo','Di','Mi','Do','Fr','Sa','So'];
            document.getElementById('autoUpdateStatus').textContent = enabled ? dayNames[res.day] + ' ' + String(res.hour).padStart(2,'0') + ':00' : '';
            toast(enabled ? T.autoupdate_saved : T.autoupdate_disabled);
        }
    } catch(e) { toast(T.error + ': ' + e.message, 'error'); }
}
</script>

<div style="position:fixed;bottom:0;left:0;right:0;z-index:50;border-top:1px solid rgba(255,255,255,.04);background:rgba(5,8,16,.85);backdrop-filter:blur(12px)">
    <div style="max-width:1320px;margin:0 auto;padding:18px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <div style="display:flex;align-items:center;gap:10px">
            <div style="width:28px;height:28px;border-radius:6px;background:linear-gradient(135deg,var(--accent),#e04d00);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <div>
                <div style="font-size:.72rem;font-weight:700;color:rgba(255,255,255,.5);letter-spacing:-.01em;font-family:var(--sans)">FloppyOps Lite <span style="font-size:.58rem;font-weight:400;color:rgba(255,255,255,.25);font-family:var(--mono)">v<?= APP_VERSION ?></span></div>
                <div style="font-size:.65rem;color:rgba(255,255,255,.3);font-family:var(--mono);margin-top:4px">&copy; <?= date('Y') ?> Florian Hesse</div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:16px">
            <a href="https://comnic-it.de" target="_blank" style="font-size:.65rem;color:rgba(255,255,255,.3);text-decoration:none;font-family:var(--mono);transition:color .2s" onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='rgba(255,255,255,.3)'">comnic-it.de</a>
            <span style="width:1px;height:12px;background:rgba(255,255,255,.1)"></span>
            <a href="https://github.com/floppy007/floppyops-lite" target="_blank" style="color:rgba(255,255,255,.25);transition:color .2s;display:flex" onmouseover="this.style.color='rgba(255,255,255,.5)'" onmouseout="this.style.color='rgba(255,255,255,.25)'">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg>
            </a>
        </div>
    </div>
</div>

</body>
</html>
