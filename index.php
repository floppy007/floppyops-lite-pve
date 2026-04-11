<?php
// ╔══════════════════════════════════════════════════════════════════╗
// ║                    FloppyOps Lite  -  index.php                   ║
// ║  Server Management Panel für Proxmox VE                       ║
// ║                                                                ║
// ║  Aufbau:                                                       ║
// ║    1. PHP Konfiguration & Authentifizierung  (hier)            ║
// ║    2. API Handler Module                     (api/*.php)       ║
// ║    3. API Router (Dispatch)                  (hier)            ║
// ║    4. HTML Struktur + CSS Styling            (hier)            ║
// ║    5. JavaScript Module                      (js/*.js)         ║
// ╚══════════════════════════════════════════════════════════════════╝

define('APP_VERSION', '1.2.8');
require_once __DIR__ . '/config.php';
session_start();
require_once __DIR__ . '/lang.php';

// ╔══════════════════════════════════════════════════════════════════╗
// ║              AUTHENTIFIZIERUNG (PVE / PAM / Auto)               ║
// ╚══════════════════════════════════════════════════════════════════╝
$authMethod = defined('AUTH_METHOD') ? AUTH_METHOD : 'auto'; // auto, pve, pam, local
$loginError = '';

function authenticatePamUser(string $user, string $pass): array {
    if (str_contains($user, '@')) {
        $user = explode('@', $user, 2)[0];
    }

    if (!preg_match('/^[a-zA-Z0-9_.@-]{1,64}$/', $user)) {
        return ['ok' => false, 'error' => 'Linux-Authentifizierung fehlgeschlagen'];
    }

    $helper = '/usr/local/libexec/floppyops-lite/pam_auth.py';
    if (!is_file($helper) || !is_executable($helper)) {
        return ['ok' => false, 'error' => 'PAM-Authentifizierung ist nicht eingerichtet'];
    }

    $cmd = ['sudo', '-n', $helper, '--user', $user];
    $spec = [
        0 => ['pipe', 'w'],
        1 => ['pipe', 'r'],
        2 => ['pipe', 'r'],
    ];
    $proc = proc_open($cmd, $spec, $pipes);
    if (!is_resource($proc)) {
        return ['ok' => false, 'error' => 'PAM-Authentifizierung konnte nicht gestartet werden'];
    }

    fwrite($pipes[0], $pass . "\n");
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    $data = json_decode($stdout ?: '', true);
    if ($exitCode === 0 && is_array($data) && !empty($data['ok'])) {
        return ['ok' => true, 'user' => $user, 'method' => 'pam'];
    }

    if (is_array($data) && !empty($data['error'])) {
        return ['ok' => false, 'error' => (string)$data['error']];
    }

    if (str_contains(strtolower($stderr), 'sudo')) {
        return ['ok' => false, 'error' => 'PAM-Authentifizierung ist nicht freigeschaltet'];
    }

    return ['ok' => false, 'error' => 'Linux-Authentifizierung fehlgeschlagen'];
}

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

    // Linux PAM Auth (via root helper)
    if ($method === 'pam' || $method === 'auto') {
        $pamResult = authenticatePamUser($user, $pass);
        if ($pamResult['ok']) return $pamResult;
        if ($method === 'pam') return $pamResult;
    }

    return ['ok' => false, 'error' => 'Benutzername oder Passwort falsch'];
}

if (isset($_POST['_login'])) {
    $realm = $_POST['realm'] ?? $authMethod;
    $authResult = authenticateUser($_POST['user'] ?? '', $_POST['pass'] ?? '', $realm);
    if ($authResult['ok']) {
        $_SESSION['authed'] = true;
        $_SESSION['auth_user'] = $authResult['user'];
        $_SESSION['auth_method'] = $authResult['method'];
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $loginError = $authResult['error'] ?? 'Benutzername oder Passwort falsch';
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit; }

if (!isset($_SESSION['authed'])) {
    // API-Calls: 401 JSON statt Login-HTML
    if (isset($_GET['api'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Session expired']);
        exit;
    }
    showLoginPage($loginError);
    exit;
}

function showLoginPage(string $error = ''): void {
    $appName = APP_NAME;
    $errHtml = $error ? '<div class="login-error">' . htmlspecialchars($error) . '</div>' : '';
    $lblUser = __('login_user');
    $lblPass = __('login_pass');
    $lblBtn = __('login_btn');
    $lblHint = __('login_hint');
    $appVersion = APP_VERSION;
    $year = date('Y');
    echo <<<HTML
<!-- ╔══════════════════════════════════════════════════════════════╗ -->
<!-- ║                    HTML + CSS + LAYOUT                        ║ -->
<!-- ╚══════════════════════════════════════════════════════════════╝ -->
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$appName}  -  Login</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#050810;--accent:#ff5900;--surface:rgba(17,24,39,.55);--border:rgba(255,255,255,.05);--text:#e8eaed;--text2:#9aa0a6;--text3:#5f6368}
html,body{height:100%}
body{
    font-family:system-ui,-apple-system,"Segoe UI",Roboto,Ubuntu,"Helvetica Neue",Arial,sans-serif;
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
    font-size:.78rem;color:var(--text3);font-family:"SFMono-Regular","JetBrains Mono","Cascadia Code","Fira Code","Source Code Pro",Consolas,"Liberation Mono",Menlo,monospace;
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
    font-family:"SFMono-Regular","JetBrains Mono","Cascadia Code","Fira Code","Source Code Pro",Consolas,"Liberation Mono",Menlo,monospace;
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
    font-family:system-ui,-apple-system,"Segoe UI",Roboto,Ubuntu,"Helvetica Neue",Arial,sans-serif;
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

// ── CSRF-Token Verwaltung ────────────────────────────────
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf'];

function csrf_check(): void {
    if (($_POST['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'CSRF-Token ungueltig']));
    }
}

// ╔══════════════════════════════════════════════════════════════════╗
// ║                     API HANDLER (Module)                          ║
// ║  Jedes Modul stellt eine handleXxxAPI($action)-Funktion bereit.   ║
// ║  Dateien liegen in api/  -  ein Modul pro Feature.                  ║
// ╚══════════════════════════════════════════════════════════════════╝
require_once __DIR__ . '/api/dashboard.php';   // System-Stats (CPU, RAM, Disk)
require_once __DIR__ . '/api/fail2ban.php';    // Jails, Logs, Config
require_once __DIR__ . '/api/nginx.php';       // Reverse Proxy, Sites, SSL
require_once __DIR__ . '/api/vms.php';         // PVE VMs & Container
require_once __DIR__ . '/api/zfs.php';         // Pools, Datasets, Snapshots
require_once __DIR__ . '/api/wireguard.php';   // VPN Tunnel-Verwaltung
require_once __DIR__ . '/api/updates.php';     // App + System Updates, Repos
require_once __DIR__ . '/api/security.php';    // Port-Scan, Host-Firewall
require_once __DIR__ . '/api/firewall.php';    // VM/CT Firewall Templates

// ── API Router ──────────────────────────────────────────────────────
// Dispatch: Jede Handler-Funktion wird der Reihe nach aufgerufen.
// Die erste die true zurueckgibt hat den Request behandelt.
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $action = $_GET['api'];

    if (handleDashboardAPI($action)) exit;
    if (handleFail2banAPI($action)) exit;
    if (handleNginxAPI($action)) exit;
    if (handleVmsAPI($action)) exit;
    if (handleZfsAPI($action)) exit;
    if (handleWireguardAPI($action)) exit;
    if (handleUpdatesAPI($action)) exit;
    if (handleSecurityAPI($action)) exit;
    if (handleFirewallAPI($action)) exit;

    // Kein Handler hat den Request behandelt
    http_response_code(404);
    echo json_encode(['error' => 'Unknown API']);
    exit;
}
?>
<!-- ╔══════════════════════════════════════════════════════════════╗ -->
<!-- ║                    HTML + CSS + LAYOUT                        ║ -->
<!-- ╚══════════════════════════════════════════════════════════════╝ -->
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= APP_NAME ?></title>
<link rel="icon" type="image/png" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAAIGNIUk0AAHomAACAhAAA+gAAAIDoAAB1MAAA6mAAADqYAAAXcJy6UTwAAAAGYktHRAAAAAAAAPlDu38AAAAJcEhZcwAACxMAAAsTAQCanBgAAAAHdElNRQfqBAILOC7jnqLaAAAKyUlEQVRYw8WVd3CcxRnGn/32K/fdd10n3VknnSSfXDEGNwm5QBJMiW0gEAKZCSGkkZ5Mepn0MpkMaTYkkAQwwzCBFJyYQGaMY8cIN8BgY8myLVmS1axyd9L1r+5u/iDM2JS0f/L8tzs77/Obd3efF/g/i5y/WHLx8isURXlc8/l0KstEkiQJwDDn7MOHu7v3v3oulWpUVJ8eZVxECZF8gHAUWZ7ljM0NDZ5xAGDtFVdsASH3CCGSnDHuOA6smllxbPv6wYH+wxcAeHd1QbLH6eU7jFjO1bb5fOqtsiwTQiQQSYIQ/AiEeH8pnx8ClTsKhcJVnm11QHjzJAifAHEkWZ32+QPHw5HoU36/XhUEDwBkkRAcnHE4js0ty97+T3e+uO/ddknE5jP6oWdeAWDfTnaCiC9JlLi9c/Lge/ZH1nBJvopSCiJJAADO2FOyRKeKuXPXwpwVIUUMBv3yiCqjJCApFQfJooXFnhwIBWPJY0KiDULwFZxxyfNcocB9ascNlZ5MiM3njieB43v0W7ke+Z+dmAVHGwdWLIu65V+tn33g9r0hv0TpOgKAKspUQNeXsOL0psaw0l3ftuCuYF1TgCMHivlizW7PtDI3Nyj1nOi7vFwxH6zmRq/kSmgHUVQXQnSCsz2/v8E8mwnxz3Am/OBkP4SYu+ANsG8kVoOIh0GwRILIPTmsP/ipA8ZVzHMXtbc0jhYK5fZVHWvk9LIVZdsy87ds3sR8mubatm0/vXu3IwD7dP+ZhtP9/Qskp0LGzg5NGXWpg9VK0XhoY+3EhmbxQS4QBucvQZDb6fdnTgDAqx0A/d70Efb1hk8KxrczIdJbUvZtU8vx2N+kFbj60sb59+54TvYlm5Evl4PxYDDY0toKw+9HuVzCSy/3oDmdBqEUjstg24DJ5OS8+sTG92Uqj22oc27jJglDwgAIPkG/mz/xqq90/i+g35/ZKzx8VrjIcheNlzQaH7h5XdNiEZkXlvQwJJkiO50FEQLnpqcxPjWFM8NnUa5WYQQCyGbzcDwPuq7juhtvwsYr1gUnTdzyYs6IE9ubgMM/Rb+bP3y+p4zXSNi+P4FYMSHwk74xKzJ27jCOVgzMFspwXQarVETf8ePITk0jEAiAM4ZIOIzJyUlYrgMqSQiFQli1ahUSfpscHGyI3N+fy/9sWeXz8tNNu4C5C/yk1wKo28aF4Op24pEfqJWi1TteQd/gBEAILNPC4tY0DIUil82C+vxwuUBrUwqKRDA/k0EgEMDo6CheeLkHB/fsxdRYFcesSM/lf7R3Kv09rwui1wEAgHb3OSZxZevFQWx9Z2rOXeSbQyISRNCvw7UtdHfvAyUCly6/COnGBLqf2YuBU32IhYPwPA/nxscwNjKCY6fHMTwxDeqVJ1CtOW/kJeNNRO6btLNfiOzSStZHVYLwk0oU6VQKy9vTWL+2E1zxIRQ0ILe24MYbb4BCZdQYgeM4YFYNQy+/gBWZKFIRs2+oaP7mQBn8vwJg2+oXWzX+Q09TQklVBivoqI/XobE1AwiBXU/sBC8UYKSacM2m6yE4w8Hnj8C2baRCMj68OIvVC2tIG7zcVi+Pkc+9sc8bXoF3V7wFQvxC00lnNKoQ3nQpCg5FLjuDwaEhTM7MIKqqqJ/LoaW5CRXbRsmyMZPNAp6L5QszuGnzelyWMJGOk04Ofg/7UazxDTv92g33m/EE0fiviY9cT2QBq2kj7u5tw859R+E6Jggh8Os6AoEAdL8Ow2/Ab/gRCAYxMjKKidN9SIoSurZch48v6oc6ug/wiBAm/4OoSJ9QflrIvSmAuANRFgtuIwZ5j+STiJt5K+4bXoz7f78Luk+D7XHIsgJFVeF5DJ7ngXMOz3WgahpkRcMSw4FWm8a+rIpPv3cz7kz2Qh08AG5zLmp8Oy3XPkceQel1AF/+wB3175D7vuPX3Tstn05rRhwTyY3Y9cIAPMeEpshwhQTGBRjnqNVMCCFAKAUBAeMcQgBJXYA5JsZLDKqm4ab1F6Ft8u8ImLPwOTWv4qj3POpe9P14H30sDwCksSlNIdHr4/H6L65ctnRNMFYnuxwglGKuMIeh4SEkGhLYdPVVmJdM4NjxHtQsE2/dsB6cc/T2nYRt27h46VJYto2T/QMIBoLY190Nn09DqrEJhWIB+WwWsaABs1xwTw6NHcnn8z/lnv1n6jeMpYTKD6/t6lz21a99RWpvz8AwdGx8ywZMT03ixZeO4s733461nWswk53BdZvejrpYFNFwBCf6+vCOLVtQH4+jLhbF5NQkNl99NTRVxopLlqOrswNtrc0Ih4K4bvO1uPbaa7B42TKazWWbh8+e7fI8b7cUCocTgUAgum7tWpRKZfz16afx551PwDAM9PT2wqxW0NbSgocf+S22brsHpmlCURRomor6eBw100Q+n4NEKXyaDyDAvb/6NaKRCFavXInHd/wJP/v5Vpw9O4JKtYpDzz2PsfFx+HR/JByJJmhm4ZKLBOe33Hrrrarm02BbFqamp7B02cV4Zt8z0FQVXevWY3xiAg2JBOqT83BuahoeEzh06CB+++ij8ASQTDXjwP5n8bs/PI49e/agVK2huS2Dhx58EP39p3G6vx+yrCDVmMLqjg6cOnmSQaJ/kYuF4tpEMuEvmQ4+/4WP4NTJPtx487tQNB2874MfQe/LR7F79x50XNYF07Jw//0PoK4ujmqlgkce3g4hBKiioWba+OXdW+G6Lggh6OnpweDQWVBK4VgWdN1AXSKFQrmGBak0gqGQPj09cxlpbms/GIvFuuZn2vHUk0/AcRykGlMIRSKwLAulYhHVagXhSAyceahVq1A0HwgAxhgAAUJeyTPbtsA5B2MMlFJEIlGUyiVYtSqi0SguWbkasXg9HMvEof3dCARDh0iyKX3Mc71LEjyHjjjwXNmHUzmGrhYNC+o1zFoSBFXRHJUxPEdQYiqWNyrghKJiCzDGYSgcBZNDgYdC1UXC4KjZHjTigUAgW+XY219DOsSwIWrjSJbiHI1DVZRjcrVUuMUIRu5UIu131CfNuo9Fyvjxszbe1q5gUZzA4gSawtEQcLB/iIERB1sWS6gxgmrtlUyoC0go2wKUcZhMQHgMmgI4XMCgBINzEoZmCD7eoWOqmoCmBrLqbHl7aS7/m1eDSGpItbwllW790qoW5crSyBl5craMibJA1McR0QiifoKsSTFny6jTPOiygCYBhAh4noChCpRtAkXiCCgCDgckCShZQM0liEdDCLUscF8cdf82MTbyo+zEyLMA+AVR7Pf7I5d2rL+tIRb6tGVZGZdxqVKzYNsuXNeD7XoQABRFRiigg0oE5aoN22XgXECVKXSfAsOnQACoWi4sy4HHGK+LBAeZEFt7Xzz0iFmrFd90GAFAMBReQGW6VtV8nZrmuzIgiUwjr9CU4iDspyhAwUBVwaRFIJiHlMbQpnpQBUeFS8hJBvJqyCua1plKqbTHdd3nOGOHq5XywL+dhheAaBQNLQtbNF2/UuPuO5tppeuyaDXaOc+BUR9EX16BOTaOGPMwWZVxwglhVInnav7YAU+Sd8zmsn8fOHVy7F95/EuA81XftlD3+42VIVW6KW24m9dlWGZN2pOPdk/h+ZzfGRbBU2VJ/4sLsjM3NXm8nJu2/5O6/zHA+UotWtEeC/tvjmj89pmZ2XLBEQ85rvvk3Pjw2P9S7/+qfwDouDM3tiVkPwAAACV0RVh0ZGF0ZTpjcmVhdGUAMjAyNi0wMy0wN1QxMjozNzozNyswMDowMO2HUoYAAAAldEVYdGRhdGU6bW9kaWZ5ADIwMjYtMDItMTZUMTk6MjU6MTgrMDA6MDD0Kfd6AAAAKHRFWHRkYXRlOnRpbWVzdGFtcAAyMDI2LTA0LTAyVDExOjU2OjQ2KzAwOjAw7adSpgAAAABJRU5ErkJggg==">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
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
.form-input option, select.form-input option { background:var(--surface);color:var(--text) }
.help-section.open .help-arrow { transform:rotate(180deg) }
.help-body h4 { font-size:.78rem;font-weight:600;color:var(--text);margin:12px 0 6px }
.help-body ul, .help-body ol { margin:6px 0;padding-left:18px }
.help-body li { margin:4px 0 }
.help-body p { margin:6px 0 }
.help-body mark { background:rgba(255,89,0,.25);color:var(--text);padding:0 2px;border-radius:2px }
@keyframes spin { to { transform: rotate(360deg); } }

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
                <div class="stat-card" style="cursor:pointer" onclick="switchTab('updates')">
                    <div class="stat-label"><?= $lang === 'en' ? 'Updates' : 'Updates' ?></div>
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
                <div id="dashVmList" style="font-size:.75rem;color:var(--text3)">Laden...</div>
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
                        <button class="btn btn-sm" onclick="loadF2b()">Aktualisieren</button>
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
                    <div class="log-viewer" id="f2bLog">Laden...</div>
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
                            Browser -> <span style="color:var(--accent)">nginx (:443 SSL)</span> -> <span style="color:var(--green)">CT/VM (10.10.10.x:80)</span>
                        </div>
                        <?php else: ?>
                        Nginx on this server receives all HTTP/HTTPS requests and forwards them to internal containers or VMs.
                        Multiple websites/apps can run on one server, each with its own domain and SSL certificate.
                        <div style="margin:10px 0;padding:8px 12px;background:rgba(0,0,0,.2);border-radius:6px;font-family:var(--mono);font-size:.65rem;color:var(--text3)">
                            Browser -> <span style="color:var(--accent)">nginx (:443 SSL)</span> -> <span style="color:var(--green)">CT/VM (10.10.10.x:80)</span>
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
                    <button class="btn btn-sm" onclick="wgOpenLxcRouteModal()">LXC Reachability</button>
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
                    So erreichst du alle internen Dienste (CTs, VMs, PVE WebUI) sicher über das Internet - ohne Ports öffentlich freizugeben.

                    <div style="margin:10px 0;padding:8px 12px;background:rgba(0,0,0,.2);border-radius:6px;font-family:var(--mono);font-size:.65rem;color:var(--text3)">
                        Büro/Zuhause (10.10.20.2) -> <span style="color:var(--accent)">WireGuard Tunnel</span> -> Dedicated Server (10.10.20.1) -> <span style="color:var(--green)">CTs (10.10.10.x)</span>
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
                    Access all internal services (CTs, VMs, PVE WebUI) securely over the internet  -  without exposing ports publicly.

                    <div style="margin:10px 0;padding:8px 12px;background:rgba(0,0,0,.2);border-radius:6px;font-family:var(--mono);font-size:.65rem;color:var(--text3)">
                        Office/Home (10.10.20.2) -> <span style="color:var(--accent)">WireGuard Tunnel</span> -> Dedicated Server (10.10.20.1) -> <span style="color:var(--green)">CTs (10.10.10.x)</span>
                    </div>

                    <strong style="color:var(--text)">Typical use cases:</strong>
                    <div style="display:flex;flex-direction:column;gap:4px;margin-top:6px">
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> Secure PVE WebUI access without public port 8006</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> Access internal CTs/VMs from anywhere (home office, mobile)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> Site-to-site VPN between locations (office <-> datacenter)</div>
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

            <div id="wgRestartBanner" style="display:none;background:rgba(255,193,7,.06);border:1px solid rgba(255,193,7,.2);border-radius:var(--radius);padding:10px 14px;margin-bottom:12px;display:none">
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
                    <button class="btn btn-sm" onclick="loadZfs()">Aktualisieren</button>
                </div>
            </div>

            <!-- Info Box -->
            <div style="background:var(--surface);border:1px solid var(--border-subtle);border-radius:var(--radius);margin-bottom:14px;overflow:hidden">
                <div style="padding:10px 14px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--border-subtle);cursor:pointer" onclick="var g=document.getElementById('zfsGuide');g.style.display=g.style.display==='none'?'':'none'">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <span style="font-size:.75rem;font-weight:600;flex:1"><?= $lang === 'de' ? 'ZFS Snapshots & Auto-Backup' : 'ZFS Snapshots & Auto-Backup' ?></span>
                    <span style="font-size:.6rem;color:var(--text3)">&#9660;</span>
                </div>
                <div id="zfsGuide" style="display:none;padding:14px;font-size:.72rem;color:var(--text2);line-height:1.8">
                    <?php if ($lang === 'de'): ?>
                    <strong style="color:var(--text)">Datensicherung direkt auf dem Server</strong><br>
                    ZFS Snapshots sind sofortige, platzsparende Sicherungspunkte deiner Container und VMs. Sie ermöglichen sekundenschnelles Zurueckrollen bei Problemen.

                    <div style="display:flex;flex-direction:column;gap:4px;margin-top:8px">
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Auto-Snapshots</strong> - Automatisch alle 15 Min, stündlich, täglich, wöchentlich, monatlich</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Rollback</strong> - Container auf einen früheren Zustand zurücksetzen</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Clone</strong>  -  Neuen CT/VM aus einem Snapshot erstellen (Test, Migration)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Platzsparend</strong> - Nur geänderte Blöcke werden gespeichert (Copy-on-Write)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Keine Downtime</strong>  -  Snapshots sind sofort, ohne den CT/VM zu stoppen</div>
                    </div>

                    <div style="margin-top:10px;padding:8px 12px;background:rgba(255,89,0,.04);border:1px solid rgba(255,89,0,.1);border-radius:6px;font-size:.65rem">
                        <strong style="color:var(--accent)">Empfehlung:</strong> Installiere <code style="padding:1px 4px;background:rgba(255,255,255,.04);border-radius:3px">zfs-auto-snapshot</code> im Auto-Snapshots Tab für automatische Sicherungen. Standard-Retention: 4 frequent, 24 hourly, 31 daily, 8 weekly, 12 monthly = ca. 1 Jahr Historie.
                    </div>
                    <?php else: ?>
                    <strong style="color:var(--text)">Data protection directly on the server</strong><br>
                    ZFS snapshots are instant, space-efficient backup points of your containers and VMs. Roll back in seconds when problems occur.

                    <div style="display:flex;flex-direction:column;gap:4px;margin-top:8px">
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Auto-Snapshots</strong>  -  Automatically every 15 min, hourly, daily, weekly, monthly</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Rollback</strong>  -  Restore container to a previous state</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Clone</strong>  -  Create new CT/VM from a snapshot (testing, migration)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>Space-efficient</strong>  -  Only changed blocks are stored (copy-on-write)</div>
                        <div style="display:flex;align-items:center;gap:6px"><span style="color:var(--green)">&#10003;</span> <strong>No downtime</strong>  -  Snapshots are instant, no CT/VM stop required</div>
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
                    <thead><tr><th>Dataset</th><th>Belegt</th><th>Verfügbar</th><th>Mountpoint</th><th style="width:150px">Auslastung</th></tr></thead>
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
                            <option value="">Alle Datasets</option>
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
                    <span id="updCount" class="count" style="font-size:.58rem"> - </span>
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
                    <button class="btn btn-sm btn-green" onclick="repoAddNoSub()" id="btnRepoAddNoSub" style="display:none;margin-left:auto"><?= $lang === 'en' ? '+ Add No-Subscription' : '+ No-Subscription hinzufügen' ?></button>
                </div>
                <div id="repoWarning" style="display:none;padding:10px 16px;background:rgba(255,193,7,.05);border-bottom:1px solid var(--border-subtle);font-size:.72rem;display:flex;align-items:center;gap:8px">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--yellow)" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <span id="repoWarningText"></span>
                </div>
                <div id="repoList" style="padding:0;font-size:.72rem">
                    <div style="color:var(--text3);padding:12px 16px"><span class="spinner-small"></span> Laden...</div>
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
                        <div style="color:var(--text3);display:flex;align-items:center;gap:6px"><div class="spinner-small"></div> Prüfe...</div>
                    </div>
                    <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border-subtle)">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.72rem">
                            <input type="checkbox" id="appAutoUpdateToggle" onchange="appAutoUpdateChanged()" style="width:14px;height:14px;accent-color:var(--accent);cursor:pointer">
                            <span style="color:var(--text2)"><?= $lang === 'en' ? 'Auto-update app' : 'App automatisch aktualisieren' ?></span>
                        </label>
                        <div id="appAutoSchedule" style="margin-top:6px;display:flex;gap:6px;align-items:center;font-size:.68rem;flex-wrap:wrap;opacity:.4;pointer-events:none">
                            <select id="appAutoDay" onchange="appAutoUpdateChanged()" style="background:var(--surface-solid);border:1px solid var(--border-subtle);border-radius:4px;padding:2px 5px;font-size:.66rem;color:var(--text)">
                                <option value="0"><?= $lang === 'en' ? 'Daily' : 'Taeglich' ?></option>
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
                        <span style="font-size:.78rem;font-weight:600"><?= $lang === 'en' ? 'System Auto-Update' : 'System Auto-Update' ?></span>
                        <span id="autoUpdateStatus" style="font-size:.58rem;color:var(--text3);margin-left:auto"></span>
                    </div>
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:.76rem;padding:4px 0">
                        <input type="checkbox" id="autoUpdateToggle" onchange="autoUpdateChanged()" style="width:16px;height:16px;accent-color:var(--accent);cursor:pointer">
                        <span style="color:var(--text2)"><?= $lang === 'en' ? 'Automatic system updates (apt dist-upgrade)' : 'Automatische System-Updates (apt dist-upgrade)' ?></span>
                    </label>
                    <div id="autoUpdateSchedule" style="margin-top:8px;display:flex;gap:8px;align-items:center;font-size:.72rem;flex-wrap:wrap;opacity:.4;pointer-events:none">
                        <select id="autoUpdateDay" onchange="autoUpdateChanged()" style="background:var(--surface-solid);border:1px solid var(--border-subtle);border-radius:4px;padding:3px 6px;font-size:.68rem;color:var(--text)">
                            <option value="0"><?= $lang === 'en' ? 'Daily' : 'Taeglich' ?></option>
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
                        <li><strong>CPU-Auslastung</strong>  -  Aktuelle Prozessorlast in Prozent mit Live-Graph</li>
                        <li><strong>RAM-Verbrauch</strong>  -  Genutzter/Gesamter Arbeitsspeicher</li>
                        <li><strong>Disk-Auslastung</strong>  -  Speicherplatz pro Partition</li>
                        <li><strong>Netzwerk-Traffic</strong>  -  Ein-/Ausgehender Traffic pro Sekunde</li>
                        <li><strong>Uptime</strong>  -  Wie lange der Server laeuft</li>
                        <li><strong>Load Average</strong>  -  Systemlast (1/5/15 Minuten)</li>
                    </ul>
                    <p>Die Statistiken werden automatisch alle paar Sekunden aktualisiert. Die Stat-Cards oben zeigen Zusammenfassungen für VMs/CTs, Fail2ban Jails, Nginx Sites und offene Ports.</p>
                '],
                ['id' => 'h-vms', 'icon' => '🖥️', 'title' => 'VMs & Container', 'content' => '
                    <p>Verwaltung aller virtuellen Maschinen und LXC-Container auf diesem PVE-Host.</p>
                    <h4>Funktionen:</h4>
                    <ul>
                        <li><strong>Clone</strong>  -  Erstellt eine Kopie einer bestehenden VM/CT mit anpassbarer Hardware (CPU, RAM, Disk, Netzwerk)</li>
                        <li><strong>Hardware anpassen</strong>  -  CPU-Kerne, RAM und Swap direkt aendern (erfordert Neustart)</li>
                        <li><strong>Start/Stop/Reboot</strong>  -  VMs und Container steuern</li>
                        <li><strong>Netzwerk</strong>  -  Bridge, VLAN, IP-Konfiguration beim Klonen anpassen</li>
                    </ul>
                    <h4>Hinweise:</h4>
                    <ul>
                        <li>Hardware-Aenderungen an laufenden VMs erfordern einen Neustart</li>
                        <li>Beim Klonen wird die naechste freie VMID automatisch vergeben</li>
                        <li>Full Clone erstellt eine unabhaengige Kopie, Linked Clone teilt die Basis-Disk</li>
                    </ul>
                '],
                ['id' => 'h-firewall', 'icon' => '🛡️', 'title' => 'Firewall Templates', 'content' => '
                    <p>Vordefinierte Regelsaetze für typische Server-Rollen, die per Klick auf VMs/CTs angewendet werden. 18 eingebaute Templates (Mailcow, Webserver, Database, Proxmox, Docker, DNS, WireGuard, Virtualmin Web, Virtualmin Web+Mail, Nginx Proxy, PostgreSQL, Redis, Elasticsearch, Minecraft, TeamSpeak, Nextcloud, Gitea/GitLab, Monitoring) + eigene Custom Templates.</p>
                    <h4>Zwei Firewall-Ebenen:</h4>
                    <ul>
                        <li><strong>PVE Host-Firewall</strong> (Security Check)  -  Schuetzt den Proxmox-Host selbst. Regelt welche Ports von aussen erreichbar sind (SSH, WebUI, etc.).</li>
                        <li><strong>VM/CT Firewall</strong> (Templates)  -  Schuetzt einzelne VMs und Container. Jede Maschine bekommt eigene Regeln passend zu ihrer Rolle.</li>
                    </ul>
                    <h4>Wann welche Firewall?</h4>
                    <ul>
                        <li><strong>CTs mit öffentlicher IP</strong> (gelber Punkt in der IP-Spalte)  -  Erhalten direkten Traffic aus dem Internet. Die CT-Firewall filtert eingehende Verbindungen direkt am Container.</li>
                        <li><strong>CTs mit interner IP</strong> (grauer Punkt)  -  Sind typischerweise hinter Nginx. Die CT-Firewall schuetzt gegen laterale Angriffe (CT-zu-CT Bewegung im internen Netz).</li>
                        <li><strong>Nginx-proxied CTs</strong>  -  Brauchen keine zusaetzlichen PVE-Host-Regeln für Port 80/443, da diese bereits auf dem Host offen sind. Die CT-Firewall sollte trotzdem die erlaubten Ports auf das Minimum beschraenken.</li>
                    </ul>
                    <h4>Templates anwenden:</h4>
                    <ol>
                        <li>Template-Card anklicken (z.B. "Mailserver", "Webserver")</li>
                        <li>Im Modal: Regeln pruefen  -  <strong>Ports und Sources sind editierbar!</strong></li>
                        <li>Einzelne Regeln per Checkbox an-/abwaehlen</li>
                        <li>Ziel-VM/CT aus dem Dropdown waehlen</li>
                        <li>"Anwenden" klicken  -  Firewall wird aktiviert, Policy auf DROP gesetzt</li>
                    </ol>
                    <h4>Duplikat-Schutz:</h4>
                    <p>Bereits vorhandene Regeln werden automatisch erkannt und nicht doppelt angelegt. Wenn ein Template erneut auf eine VM/CT angewendet wird, werden nur fehlende Regeln ergaenzt.</p>
                    <h4>Custom Templates:</h4>
                    <p>Über "Eigenes Template" koennen eigene Regelsaetze erstellt, gespeichert und wiederverwendet werden.</p>
                    <h4>VM/CT Firewall-Tabelle:</h4>
                    <p>Zeigt den Firewall-Status aller VMs/CTs: Aktiv/Inaktiv, Policy, Anzahl Regeln, zugewiesenes Template. Per "ON/OFF" Button kann die Firewall pro Maschine ein-/ausgeschaltet werden.</p>
                    <h4>IP-Spalte:</h4>
                    <p>Die VM/CT-Tabelle zeigt die IP-Adresse jeder Maschine mit farbigem Punkt: <strong>Gelb</strong> = öffentliche IP (direkt aus dem Internet erreichbar), <strong>Grau</strong> = interne IP (nur im lokalen Netz). So siehst du sofort welche Maschinen besonders geschützt werden müssen.</p>
                    <h4>Wichtig:</h4>
                    <ul>
                        <li>Policy DROP bedeutet: Alles ist blockiert, nur explizit erlaubte Ports sind erreichbar</li>
                        <li>SSH (Port 22) ist in allen Templates enthalten  -  Zugriff bleibt gewaehrleistet</li>
                        <li>Templates gelten nur für VMs/CTs, nicht für den PVE-Host selbst (dafür: Security Check)</li>
                    </ul>
                '],
                ['id' => 'h-security', 'icon' => '⚠️', 'title' => 'Security Check', 'content' => '
                    <p>Scannt offene Ports auf dem Host und zeigt Sicherheitsrisiken.</p>
                    <h4>Port-Scan:</h4>
                    <ul>
                        <li>Zeigt alle offenen TCP-Ports mit Prozess, Adresse und Risikobewertung</li>
                        <li>Riskante Ports (Redis, MongoDB, MySQL von aussen) werden rot markiert</li>
                        <li>Per "Blockieren" Button kann ein Port sofort per Firewall-Regel gesperrt werden</li>
                    </ul>
                    <h4>PVE Host-Firewall:</h4>
                    <ul>
                        <li><strong>Datacenter-Level</strong>  -  Hauptschalter für die PVE Firewall (muss aktiv sein)</li>
                        <li><strong>Node-Level</strong>  -  Firewall auf diesem spezifischen Host</li>
                        <li>Beim Aktivieren werden automatisch SSH (22) und PVE WebUI (8006) erlaubt</li>
                    </ul>
                    <h4>Firewall-Regeln:</h4>
                    <p>Node- und Cluster-Regeln werden gemeinsam angezeigt. Neue Regeln koennen mit Action (ACCEPT/DROP/REJECT), Richtung, Port, Source und Kommentar erstellt werden.</p>
                    <h4>Standard-Regeln:</h4>
                    <p>Ein vordefinierter Satz empfohlener Regeln: SSH, PVE WebUI und SPICE erlauben, riskante Dienste blockieren.</p>
                '],
                ['id' => 'h-fail2ban', 'icon' => '🔒', 'title' => 'Fail2ban', 'content' => '
                    <p>Überwacht und verwaltet Fail2ban Jails  -  automatischer Schutz gegen Brute-Force-Angriffe.</p>
                    <h4>Jail-Übersicht:</h4>
                    <ul>
                        <li>Zeigt alle aktiven Jails mit Anzahl gebannter IPs und fehlgeschlagener Versuche</li>
                        <li>Gebannte IPs werden aufgelistet und koennen per Klick entbannt werden</li>
                    </ul>
                    <h4>Config-Editor:</h4>
                    <p>Die jail.local und Filter-Konfigurationen koennen direkt im Browser bearbeitet werden. Nach dem Speichern wird Fail2ban automatisch neu gestartet.</p>
                    <h4>Ban-Log:</h4>
                    <p>Zeigt die letzten Eintraege aus dem Fail2ban-Log mit Zeitstempel, Jail und IP-Adresse.</p>
                '],
                ['id' => 'h-nginx', 'icon' => '🌐', 'title' => 'Nginx Reverse Proxy', 'content' => '
                    <p>Verwaltet Nginx als Reverse Proxy für interne Container und VMs.</p>
                    <h4>Neue Site anlegen:</h4>
                    <ol>
                        <li>Domain eingeben (z.B. app.example.com)</li>
                        <li>Ziel-Adresse angeben (z.B. http://10.10.10.100:80)</li>
                        <li>"Erstellen"  -  Nginx-Config wird automatisch generiert</li>
                        <li>SSL per Let\'s Encrypt wird automatisch eingerichtet (Certbot)</li>
                    </ol>
                    <h4>SSL Health Check:</h4>
                    <p>Der SSL Health Check prueft alle konfigurierten Nginx-Sites automatisch auf haeufige Probleme:</p>
                    <ul>
                        <li><strong>DNS A-Record</strong>  -  Prüft ob ein IPv4-DNS-Eintrag auf die IP dieses Servers zeigt. Ohne korrekten A-Record kann kein SSL-Zertifikat ausgestellt werden.</li>
                        <li><strong>DNS AAAA-Record</strong>  -  Prüft ob ein IPv6-DNS-Eintrag existiert und auf diesen Server zeigt. Optional, aber wichtig für IPv6-Erreichbarkeit.</li>
                        <li><strong>SSL-Zertifikat</strong>  -  Ist ein gueltiges Zertifikat vorhanden? Wann laeuft es ab? Abgelaufene oder fehlende Zertifikate werden rot markiert.</li>
                        <li><strong>Cert-Match</strong>  -  Stimmt das ausgelieferte Zertifikat mit der Domain überein? Ein Mismatch tritt auf wenn z.B. ein anderes Zertifikat geladen wird.</li>
                        <li><strong>IPv4/IPv6 Konsistenz</strong>  -  Werden über IPv4 und IPv6 die gleichen Zertifikate ausgeliefert? Unterschiedliche Zertifikate deuten auf eine Fehlkonfiguration hin.</li>
                    </ul>
                    <h4>ipv6only=on Problem:</h4>
                    <p>Wenn mehrere Nginx-Sites auf Port 443 lauschen, kann es zu einem Konflikt kommen: Nur ein <code>server</code>-Block darf <code>ipv6only=on</code> in der <code>listen [::]:443</code> Direktive haben. Fehlt diese Einstellung oder ist sie bei mehreren Sites gesetzt, liefert Nginx über IPv6 moeglicherweise das falsche Zertifikat aus. Der SSL Health Check erkennt dieses Problem und bietet einen <strong>1-Klick Fix</strong> an, der die Nginx-Konfiguration automatisch korrigiert.</p>
                    <h4>System-Checks:</h4>
                    <p>Prüft Voraussetzungen: IP-Forwarding, NAT/Masquerading, interne Bridge, Nginx-Status, Certbot.</p>
                    <h4>Cloudflare Proxy Support:</h4>
                    <p>Wenn du Cloudflare als DNS-Proxy (orange Wolke) nutzt, sieht Nginx nur Cloudflare\'s IP statt der echten Client-IP. Das fuehrt zu Problemen mit IP-Whitelists und Logs.</p>
                    <p><strong>Loesung:</strong> Bei der Installation fragt das Setup-Script ob du Cloudflare nutzt. Wenn ja, wird automatisch eine Config erstellt (<code>/etc/nginx/conf.d/cloudflare-realip.conf</code>) die Nginx anweist, die echte IP aus dem <code>CF-Connecting-IP</code> Header zu lesen.</p>
                    <h4>Was das bedeutet:</h4>
                    <ul>
                        <li><strong>IP-Whitelists</strong> in Nginx funktionieren korrekt (deine echte IP wird erkannt)</li>
                        <li><strong>Logs</strong> zeigen die echte Client-IP statt Cloudflare\'s IP</li>
                        <li><strong>Fail2ban</strong> bannt die richtige IP, nicht Cloudflare</li>
                    </ul>
                    <h4>Welche Domains proxied (orange)?</h4>
                    <ul>
                        <li><strong>Ja:</strong> Webseiten (HTTP/HTTPS)  -  DDoS-Schutz, IP versteckt</li>
                        <li><strong>Nein:</strong> Mail (SMTP/IMAP), WireGuard (UDP), PVE WebUI (Port 8006)  -  diese Protokolle laufen nicht über CF Proxy</li>
                    </ul>
                    <p><strong>Tipp:</strong> Die Cloudflare IP-Ranges aendern sich selten, sollten aber ~1x jaehrlich aktualisiert werden. Aktuelle Listen: <code>cloudflare.com/ips-v4</code> und <code>cloudflare.com/ips-v6</code></p>
                '],
                ['id' => 'h-zfs', 'icon' => '💾', 'title' => 'ZFS Storage', 'content' => '
                    <p>Verwaltung von ZFS-Pools, Datasets und Snapshots.</p>
                    <h4>Pools & Datasets:</h4>
                    <ul>
                        <li>Zeigt alle ZFS-Pools mit Groesse, Belegung und Health-Status</li>
                        <li>Datasets mit Quota, Kompression und Mountpoint</li>
                    </ul>
                    <h4>Snapshots:</h4>
                    <ul>
                        <li><strong>Erstellen</strong>  -  Manueller Snapshot eines Datasets</li>
                        <li><strong>Rollback</strong>  -  Dataset auf einen früheren Snapshot zurücksetzen</li>
                        <li><strong>Clone</strong>  -  Neues Dataset aus einem Snapshot erstellen</li>
                        <li><strong>Loeschen</strong>  -  Alte Snapshots entfernen</li>
                    </ul>
                    <h4>Auto-Snapshots:</h4>
                    <p>Automatische Snapshots mit konfigurierbarer Retention (stündlich, täglich, wöchentlich). Wird über Cron-Jobs gesteuert.</p>
                    <h4>Hinweise:</h4>
                    <ul>
                        <li>Rollback loescht alle neueren Snapshots!</li>
                        <li>ZFS Kompression (lz4) spart Speicherplatz ohne Performance-Einbussen</li>
                    </ul>
                '],
                ['id' => 'h-wireguard', 'icon' => '🔐', 'title' => 'WireGuard VPN', 'content' => '
                    <p>Einrichtung und Verwaltung von WireGuard VPN-Tunneln.</p>
                    <h4>Tunnel-Wizard:</h4>
                    <ol>
                        <li>Interface-Name und Port waehlen</li>
                        <li>Subnetz konfigurieren (z.B. 10.10.20.0/24)</li>
                        <li>Peer hinzufügen (Remote-Seite)</li>
                        <li>Config wird automatisch generiert  -  Remote-Config zum Kopieren</li>
                    </ol>
                    <h4>Live-Traffic:</h4>
                    <p>Echtzeit-Graph zeigt ein-/ausgehenden Traffic pro Tunnel mit RX/TX Werten.</p>
                    <h4>Config-Editor:</h4>
                    <p>WireGuard-Konfigurationen koennen direkt bearbeitet werden. Firewall-Regeln für den WireGuard-Port werden automatisch vorgeschlagen.</p>
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
                        <li>"Alle installieren" fuehrt apt update + upgrade durch</li>
                        <li>Einzelne Pakete koennen ausgewaehlt werden</li>
                    </ul>
                    <h4>Repositories:</h4>
                    <p>Zeigt konfigurierte APT-Repositories (PVE Enterprise, No-Subscription, Ceph etc.).</p>
                    <h4>App-Update:</h4>
                    <p>FloppyOps Lite kann sich selbst aktualisieren. Zeigt aktuelle und verfügbare Version.</p>
                    <h4>Auto-Update:</h4>
                    <p>Optionales automatisches Update zu einem konfigurierbaren Zeitpunkt (Tag + Uhrzeit).</p>
                '],
                ['id' => 'h-navigation', 'icon' => '📑', 'title' => 'Navigation & Aufbau', 'content' => '
                    <p>Die Navigation ist in 6 Gruppen-Tabs organisiert, um zusammengehoerige Funktionen übersichtlich zu buendeln:</p>
                    <h4>Tab-Gruppen:</h4>
                    <ul>
                        <li><strong>Dashboard</strong>  -  Server-Übersicht mit Live-Charts und Stat-Cards</li>
                        <li><strong>VMs/CTs</strong>  -  Alle virtuellen Maschinen und Container mit IP-Anzeige und Template-Zuweisung</li>
                        <li><strong>Security</strong>  -  Enthaelt: Firewall Templates, Security Check (Port-Scanner + PVE Host-Firewall) und Fail2ban</li>
                        <li><strong>Network</strong>  -  Enthaelt: Nginx Reverse Proxy (mit SSL Health Check) und WireGuard VPN</li>
                        <li><strong>System</strong>  -  Enthaelt: ZFS Storage und System-Updates/Repositories</li>
                        <li><strong>Help</strong>  -  Diese Hilfe-Seite mit Suchfunktion</li>
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
                        <li><strong>CPU Usage</strong>  -  Current processor load in percent with live graph</li>
                        <li><strong>RAM Usage</strong>  -  Used/Total memory</li>
                        <li><strong>Disk Usage</strong>  -  Storage per partition</li>
                        <li><strong>Network Traffic</strong>  -  In/outbound traffic per second</li>
                        <li><strong>Uptime</strong>  -  How long the server has been running</li>
                        <li><strong>Load Average</strong>  -  System load (1/5/15 minutes)</li>
                    </ul>
                    <p>Statistics refresh automatically every few seconds. The stat cards at the top show summaries for VMs/CTs, Fail2ban jails, Nginx sites and open ports.</p>
                '],
                ['id' => 'h-vms', 'icon' => '🖥️', 'title' => 'VMs & Containers', 'content' => '
                    <p>Management of all virtual machines and LXC containers on this PVE host.</p>
                    <h4>Features:</h4>
                    <ul>
                        <li><strong>Clone</strong>  -  Create a copy of an existing VM/CT with customizable hardware (CPU, RAM, Disk, Network)</li>
                        <li><strong>Hardware Adjust</strong>  -  Change CPU cores, RAM and swap directly (requires restart)</li>
                        <li><strong>Start/Stop/Reboot</strong>  -  Control VMs and containers</li>
                        <li><strong>Network</strong>  -  Configure bridge, VLAN, IP when cloning</li>
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
                        <li><strong>PVE Host Firewall</strong> (Security Check)  -  Protects the Proxmox host itself. Controls which ports are reachable from the internet (SSH, WebUI, etc.).</li>
                        <li><strong>VM/CT Firewall</strong> (Templates)  -  Protects individual VMs and containers. Each machine gets its own rules matching its role.</li>
                    </ul>
                    <h4>When to Use Which Firewall?</h4>
                    <ul>
                        <li><strong>CTs with public IP</strong> (yellow dot in the IP column)  -  Receive direct traffic from the internet. The CT firewall filters incoming connections directly at the container.</li>
                        <li><strong>CTs with internal IP</strong> (gray dot)  -  Typically sit behind Nginx. The CT firewall protects against lateral movement (CT-to-CT traffic within the internal network).</li>
                        <li><strong>Nginx-proxied CTs</strong>  -  Do not need additional PVE host rules for ports 80/443, as those are already open on the host. However, the CT firewall should still restrict allowed ports to a minimum.</li>
                    </ul>
                    <h4>Applying Templates:</h4>
                    <ol>
                        <li>Click a template card (e.g. "Mailserver", "Webserver")</li>
                        <li>In the modal: Review rules  -  <strong>ports and sources are editable!</strong></li>
                        <li>Enable/disable individual rules via checkboxes</li>
                        <li>Select target VM/CT from dropdown</li>
                        <li>Click "Apply"  -  Firewall is enabled, policy set to DROP</li>
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
                        <li>SSH (port 22) is included in all templates  -  access is always maintained</li>
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
                        <li><strong>Datacenter Level</strong>  -  Main switch for the PVE Firewall (must be active)</li>
                        <li><strong>Node Level</strong>  -  Firewall on this specific host</li>
                        <li>When enabling, SSH (22) and PVE WebUI (8006) are automatically allowed</li>
                    </ul>
                    <h4>Firewall Rules:</h4>
                    <p>Node and cluster rules are shown together. New rules can be created with action (ACCEPT/DROP/REJECT), direction, port, source and comment.</p>
                    <h4>Default Rules:</h4>
                    <p>A predefined set of recommended rules: Allow SSH, PVE WebUI and SPICE, block risky services.</p>
                '],
                ['id' => 'h-fail2ban', 'icon' => '🔒', 'title' => 'Fail2ban', 'content' => '
                    <p>Monitors and manages Fail2ban jails  -  automatic protection against brute-force attacks.</p>
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
                        <li>Enter domain (e.g. app.example.com)</li>
                        <li>Set target address (e.g. http://10.10.10.100:80)</li>
                        <li>"Create"  -  Nginx config is automatically generated</li>
                        <li>SSL via Let\'s Encrypt is set up automatically (Certbot)</li>
                    </ol>
                    <h4>SSL Health Check:</h4>
                    <p>The SSL Health Check automatically inspects all configured Nginx sites for common issues:</p>
                    <ul>
                        <li><strong>DNS A Record</strong>  -  Checks if an IPv4 DNS entry points to this server\'s IP. Without a correct A record, no SSL certificate can be issued.</li>
                        <li><strong>DNS AAAA Record</strong>  -  Checks if an IPv6 DNS entry exists and points to this server. Optional but important for IPv6 reachability.</li>
                        <li><strong>SSL Certificate</strong>  -  Is a valid certificate present? When does it expire? Expired or missing certificates are highlighted in red.</li>
                        <li><strong>Cert Match</strong>  -  Does the served certificate match the domain? A mismatch occurs when e.g. a different certificate is loaded.</li>
                        <li><strong>IPv4/IPv6 Consistency</strong>  -  Are the same certificates served over IPv4 and IPv6? Different certificates indicate a misconfiguration.</li>
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
                        <li><strong>Yes:</strong> Websites (HTTP/HTTPS)  -  DDoS protection, IP hidden</li>
                        <li><strong>No:</strong> Mail (SMTP/IMAP), WireGuard (UDP), PVE WebUI (port 8006)  -  these protocols don\'t work through CF Proxy</li>
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
                        <li><strong>Create</strong>  -  Manual snapshot of a dataset</li>
                        <li><strong>Rollback</strong>  -  Revert dataset to a previous snapshot</li>
                        <li><strong>Clone</strong>  -  Create new dataset from a snapshot</li>
                        <li><strong>Delete</strong>  -  Remove old snapshots</li>
                    </ul>
                    <h4>Auto-Snapshots:</h4>
                    <p>Automatic snapshots with configurable retention (hourly, daily, weekly). Managed via cron jobs.</p>
                    <h4>Notes:</h4>
                    <ul>
                        <li>Rollback deletes all newer snapshots!</li>
                        <li>ZFS compression (lz4) saves storage without performance penalty</li>
                    </ul>
                '],
                ['id' => 'h-wireguard', 'icon' => '🔐', 'title' => 'WireGuard VPN', 'content' => '
                    <p>Setup and management of WireGuard VPN tunnels.</p>
                    <h4>Tunnel Wizard:</h4>
                    <ol>
                        <li>Choose interface name and port</li>
                        <li>Configure subnet (e.g. 10.10.20.0/24)</li>
                        <li>Add peer (remote side)</li>
                        <li>Config is automatically generated  -  remote config ready to copy</li>
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
                        <li><strong>Dashboard</strong>  -  Server overview with live charts and stat cards</li>
                        <li><strong>VMs/CTs</strong>  -  All virtual machines and containers with IP display and template assignment</li>
                        <li><strong>Security</strong>  -  Contains: Firewall Templates, Security Check (port scanner + PVE host firewall) and Fail2ban</li>
                        <li><strong>Network</strong>  -  Contains: Nginx Reverse Proxy (with SSL Health Check) and WireGuard VPN</li>
                        <li><strong>System</strong>  -  Contains: ZFS Storage and System Updates/Repositories</li>
                        <li><strong>Help</strong>  -  This help page with search</li>
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

<!-- ─ WireGuard Config Modal ──────────────────────────── -->
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
                <button class="btn btn-sm" id="wgLogsRefreshBtn" onclick="wgRefreshLogs()" style="font-size:.65rem">Aktualisieren</button>
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
                    ? 'Config-Datei (.conf) eines anderen WireGuard-Servers einfuegen oder hochladen. Die Datei wird unter /etc/wireguard/ gespeichert.'
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

<div class="modal-overlay" id="wgLxcRouteModal">
    <div class="modal" style="max-width:760px">
        <div class="modal-head">
            <div class="modal-title">LXC Reachability Fix</div>
            <button class="modal-close" onclick="closeModal('wgLxcRouteModal')">&times;</button>
        </div>
        <div class="modal-body" id="wgLxcRouteBody"></div>
        <div class="modal-foot">
            <button class="btn" onclick="closeModal('wgLxcRouteModal')"><?= $lang === 'de' ? 'Schließen' : 'Close' ?></button>
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
            <div class="form-group">
                <label class="form-label"><?= __('domains') ?></label>
                <input class="form-input" id="newDomain" placeholder="example.com, www.example.com">
                <div class="form-hint"><?= __('multi_domain_hint') ?></div>
            </div>
            <div class="form-row" style="display:grid;grid-template-columns:1fr 100px;gap:8px">
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
                <label class="form-check"><input type="checkbox" id="newSsl" checked> <?= __('enable_ssl') ?></label>
                <label class="form-check"><input type="checkbox" id="newForceSsl" checked> <?= __('force_ssl') ?></label>
                <label class="form-check"><input type="checkbox" id="newWs"> <?= __('enable_ws') ?></label>
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
</script>

<!-- JS-Module  -  aufgeteilt nach Feature -->
<script src="js/core.js?v=<?= APP_VERSION ?>"></script>
<script src="js/dashboard.js?v=<?= APP_VERSION ?>"></script>
<script src="js/fail2ban.js?v=<?= APP_VERSION ?>"></script>
<script src="js/nginx.js?v=<?= APP_VERSION ?>"></script>
<script src="js/vms.js?v=<?= APP_VERSION ?>"></script>
<script src="js/zfs.js?v=<?= APP_VERSION ?>"></script>
<script src="js/wireguard.js?v=<?= APP_VERSION ?>"></script>
<script src="js/security.js?v=<?= APP_VERSION ?>"></script>
<script src="js/firewall.js?v=<?= APP_VERSION ?>"></script>
<script src="js/updates.js?v=<?= APP_VERSION ?>"></script>

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
