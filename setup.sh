#!/bin/bash
#
# FloppyOps Lite PVE — Setup Script
# https://github.com/floppy007/floppyops-lite-pve
#
# Installs the FloppyOps Lite PVE Panel on a Proxmox VE host.
# Manages: Fail2ban, Nginx Proxy, WireGuard VPN, ZFS
#
# Usage:
#   bash setup.sh
#   bash setup.sh --domain admin.example.com
#   bash setup.sh --no-ssl
#
set -euo pipefail

# ── Colors ────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
DIM='\033[2m'
NC='\033[0m'

# ── Defaults ──────────────────────────────────────────────
INSTALL_DIR="/var/www/server-admin"
DOMAIN=""
SKIP_SSL=false
STEP=0
TOTAL_STEPS=7

# ── Parse Arguments ───────────────────────────────────────
while [[ $# -gt 0 ]]; do
    case $1 in
        --domain)  DOMAIN="$2"; shift 2 ;;
        --no-ssl)  SKIP_SSL=true; shift ;;
        --help|-h)
            echo "FloppyOps Lite — Setup"
            echo ""
            echo "Usage: git clone ... /var/www/server-admin && cd /var/www/server-admin && bash setup.sh [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --domain FQDN    Domain for the panel (enables nginx vHost + SSL)"
            echo "  --no-ssl         Skip Let's Encrypt SSL certificate"
            echo "  --help           Show this help"
            exit 0
            ;;
        *) echo "Unknown option: $1"; exit 1 ;;
    esac
done

# ── Helpers ───────────────────────────────────────────────
step()   { STEP=$((STEP + 1)); echo ""; echo -e "${BLUE}${BOLD}[$STEP/$TOTAL_STEPS]${NC} ${BOLD}$1${NC}"; echo -e "${DIM}$(printf '%.0s─' {1..56})${NC}"; }
ok()     { echo -e "  ${GREEN}✓${NC}  $1"; }
info()   { echo -e "  ${CYAN}ℹ${NC}  $1"; }
warn()   { echo -e "  ${YELLOW}⚠${NC}  $1"; }
fail()   { echo -e "  ${RED}✗${NC}  $1"; }
die()    { echo ""; fail "$1"; exit 1; }
detail() { echo -e "     ${DIM}$1${NC}"; }

# ── Banner ────────────────────────────────────────────────
echo ""
echo -e "${BLUE}${BOLD}"
echo "  ┌────────────────────────────────────────────┐"
echo "  │                                            │"
echo "  │     FloppyOps Lite PVE                    │"
echo "  │     Setup Script v1.2.1                    │"
echo "  │                                            │"
echo "  └────────────────────────────────────────────┘"
echo -e "${NC}"

# ── Language Selection ────────────────────────────────────
echo -e "  ${BOLD}Language / Sprache:${NC}"
echo ""
echo -e "  ${GREEN}[1]${NC} English"
echo -e "  ${GREEN}[2]${NC} Deutsch"
echo ""
echo -e "  Select [1/2]: \c"
read -r langChoice </dev/tty 2>/dev/null || langChoice="1"
[[ "$langChoice" == "2" ]] && SETUPLANG="de" || SETUPLANG="en"

# Translation function
L() {
    if [[ "$SETUPLANG" == "de" ]]; then
        case "$1" in
            must_root)        echo "Dieses Script muss als root ausgeführt werden.";;
            no_pve)           echo "Kein Proxmox VE erkannt. Einige Features funktionieren möglicherweise nicht.";;
            install_dir)      echo "Install-Verzeichnis";;
            server_ip)        echo "Server-IP";;
            select_modules)   echo "Module auswählen";;
            install_all)      echo "Alle Module installieren?";;
            installing)       echo "Wird installiert";;
            skipped)          echo "Übersprungen";;
            step_deps)        echo "Abhängigkeiten installieren";;
            step_files)       echo "App-Dateien einrichten";;
            step_php)         echo "PHP-FPM konfigurieren";;
            step_nginx)       echo "Nginx konfigurieren";;
            step_modules)     echo "Module konfigurieren";;
            step_sudoers)     echo "Sudoers-Regeln einrichten";;
            step_finish)      echo "Installation abschließen";;
            php_ver)          echo "PHP-Version";;
            install_pkgs)     echo "Installiere";;
            pkgs_done)        echo "Alle Abhängigkeiten installiert";;
            already)          echo "bereits installiert";;
            copied)           echo "kopiert";;
            not_found_in)     echo "nicht gefunden in";;
            exists_keep)      echo "existiert bereits, wird nicht überschrieben";;
            created)          echo "erstellt (aus config.example.php)";;
            created_new)      echo "erstellt";;
            change_pw)        echo "Passwort in config.php ändern!";;
            perms_set)        echo "Berechtigungen gesetzt";;
            fpm_running)      echo "PHP-FPM läuft";;
            fpm_restart)      echo "PHP-FPM Socket nicht gefunden, starte neu...";;
            fpm_fail)         echo "PHP-FPM konnte nicht gestartet werden";;
            vhost_created)    echo "vHost erstellt";;
            nginx_invalid)    echo "Nginx-Konfiguration fehlerhaft";;
            ssl_activated)    echo "SSL-Zertifikat aktiviert für";;
            ssl_failed)       echo "SSL fehlgeschlagen — Domain muss auf diesen Server zeigen";;
            ssl_skip_nossl)   echo "Admin-SSL übersprungen (--no-ssl)";;
            ssl_skip_domain)  echo "Admin-SSL übersprungen (keine --domain angegeben)";;
            ssl_hint)         echo "Für SSL: bash setup.sh --domain admin.example.com";;
            secheaders)       echo "Security Headers konfiguriert";;
            ratelimit)        echo "Rate Limiting konfiguriert";;
            proxy_mgmt)       echo "Nginx Proxy-Verwaltung eingerichtet";;
            certbot_renew)    echo "Certbot Auto-Renew aktiviert";;
            wg_readable)      echo "WireGuard-Config für Panel lesbar";;
            wg_no_tunnels)    echo "WireGuard installiert (noch keine Tunnel)";;
            f2b_activated)    echo "Fail2ban aktiviert";;
            zfs_found)        echo "ZFS erkannt";;
            zfs_not_found)    echo "ZFS nicht installiert — Tab wird trotzdem angezeigt";;
            nginx_reloaded)   echo "Nginx neu geladen";;
            sudoers_created)  echo "Sudoers-Regeln erstellt";;
            checks_ok)        echo "Alle Checks bestanden";;
            success)          echo "Installation erfolgreich!";;
            success_warn)     echo "Installation mit Warnungen abgeschlossen";;
            files)            echo "Dateien";;
            next_steps)       echo "Nächste Schritte";;
            step_pw)          echo "Passwort setzen";;
            step_whitelist)   echo "IP-Whitelist";;
            step_reload)      echo "nginx -t && systemctl reload nginx";;
            step_open)        echo "Im Browser öffnen und einloggen";;
            f2b_label)        echo "Fail2ban";;
            nginx_label)      echo "Nginx Proxy";;
            zfs_label)        echo "ZFS";;
            wg_label)         echo "WireGuard";;
            install_module)   echo "Installieren?";;
            install_failed)   echo "konnte nicht installiert werden";;
            url_label)        echo "URL";;
            login_label)      echo "Login";;
            app_label)        echo "App";;
            config_label)     echo "Config";;
            vhost_label)      echo "vHost";;
            sudoers_label)    echo "Sudoers";;
            log_label)        echo "Log";;
            change_pw_now)    echo "Passwort in config.php sofort aendern!";;
            whitelist_hint)   echo "IP-Whitelist im nginx vHost anpassen!";;
            *)                echo "$1";;
        esac
    else
        case "$1" in
            must_root)        echo "This script must be run as root.";;
            no_pve)           echo "No Proxmox VE detected. Some features may not work.";;
            install_dir)      echo "Install directory";;
            server_ip)        echo "Server IP";;
            select_modules)   echo "Select modules";;
            install_all)      echo "Install all modules?";;
            installing)       echo "Will be installed";;
            skipped)          echo "Skipped";;
            step_deps)        echo "Installing dependencies";;
            step_files)       echo "Setting up app files";;
            step_php)         echo "Configuring PHP-FPM";;
            step_nginx)       echo "Configuring Nginx";;
            step_modules)     echo "Configuring modules";;
            step_sudoers)     echo "Setting up sudoers rules";;
            step_finish)      echo "Finishing installation";;
            php_ver)          echo "PHP version";;
            install_pkgs)     echo "Installing";;
            pkgs_done)        echo "All dependencies installed";;
            already)          echo "already installed";;
            copied)           echo "copied";;
            not_found_in)     echo "not found in";;
            exists_keep)      echo "exists, not overwriting";;
            created)          echo "created (from config.example.php)";;
            created_new)      echo "created";;
            change_pw)        echo "Change password in config.php!";;
            perms_set)        echo "Permissions set";;
            fpm_running)      echo "PHP-FPM running";;
            fpm_restart)      echo "PHP-FPM socket not found, restarting...";;
            fpm_fail)         echo "PHP-FPM could not be started";;
            vhost_created)    echo "vHost created";;
            nginx_invalid)    echo "Nginx configuration invalid";;
            ssl_activated)    echo "SSL certificate activated for";;
            ssl_failed)       echo "SSL failed — domain must point to this server";;
            ssl_skip_nossl)   echo "Admin SSL skipped (--no-ssl)";;
            ssl_skip_domain)  echo "Admin SSL skipped (no --domain specified)";;
            ssl_hint)         echo "For SSL: bash setup.sh --domain admin.example.com";;
            secheaders)       echo "Security headers configured";;
            ratelimit)        echo "Rate limiting configured";;
            proxy_mgmt)       echo "Nginx proxy management enabled";;
            certbot_renew)    echo "Certbot auto-renew activated";;
            wg_readable)      echo "WireGuard config readable by panel";;
            wg_no_tunnels)    echo "WireGuard installed (no tunnels yet)";;
            f2b_activated)    echo "Fail2ban activated";;
            zfs_found)        echo "ZFS detected";;
            zfs_not_found)    echo "ZFS not installed — tab will still be shown";;
            nginx_reloaded)   echo "Nginx reloaded";;
            sudoers_created)  echo "Sudoers rules created";;
            checks_ok)        echo "All checks passed";;
            success)          echo "Installation successful!";;
            success_warn)     echo "Installation completed with warnings";;
            files)            echo "Files";;
            next_steps)       echo "Next steps";;
            step_pw)          echo "Set password";;
            step_whitelist)   echo "IP whitelist";;
            step_reload)      echo "nginx -t && systemctl reload nginx";;
            step_open)        echo "Open in browser and log in";;
            f2b_label)        echo "Fail2ban";;
            nginx_label)      echo "Nginx Proxy";;
            zfs_label)        echo "ZFS";;
            wg_label)         echo "WireGuard";;
            install_module)   echo "Install?";;
            install_failed)   echo "could not be installed";;
            url_label)        echo "URL";;
            login_label)      echo "Login";;
            app_label)        echo "App";;
            config_label)     echo "Config";;
            vhost_label)      echo "vHost";;
            sudoers_label)    echo "Sudoers";;
            log_label)        echo "Log";;
            change_pw_now)    echo "Change password in config.php immediately!";;
            whitelist_hint)   echo "Adjust IP whitelist in nginx vHost!";;
            *)                echo "$1";;
        esac
    fi
}

# ── Pre-flight ────────────────────────────────────────────
if [[ $EUID -ne 0 ]]; then
    die "$(L must_root)"
fi

if [[ ! -f /etc/pve/.version ]] && ! command -v pveversion &>/dev/null; then
    warn "$(L no_pve)"
fi

SERVER_IP=$(ip -4 route get 1.1.1.1 2>/dev/null | grep -oP 'src \K[\d.]+' || hostname -I | awk '{print $1}' || echo "DEINE-IP")

info "$(L install_dir): ${BOLD}$INSTALL_DIR${NC}"
[[ -n "$DOMAIN" ]] && info "$(L domain): ${BOLD}$DOMAIN${NC}"
info "$(L server_ip): ${BOLD}$SERVER_IP${NC}"

# ── Module Selection ─────────────────────────────────────
MOD_FAIL2BAN=true
MOD_NGINX=true
MOD_ZFS=true
MOD_WIREGUARD=true

echo ""
echo -e "  ${BOLD}$(L select_modules):${NC}"
echo ""
echo -e "  $(L install_all) [$([ "$SETUPLANG" = "de" ] && echo "J/n" || echo "Y/n")] \c"
read -r allmod </dev/tty 2>/dev/null || allmod="j"
if [[ "$allmod" =~ ^[nN]$ ]]; then
    echo ""
    for mod_name in "Fail2ban:MOD_FAIL2BAN:Brute-Force Schutz + Ban-Verwaltung" \
                    "Nginx Proxy:MOD_NGINX:Reverse Proxy + SSL (Let's Encrypt)" \
                    "ZFS:MOD_ZFS:ZFS Pools, Datasets, Snapshots, Auto-Snapshots" \
                    "WireGuard:MOD_WIREGUARD:VPN Tunnel Verwaltung + Wizard"; do
        IFS=':' read -r label var desc <<< "$mod_name"
        echo -e "  ${CYAN}[?]${NC} ${BOLD}${label}${NC} — ${DIM}${desc}${NC}"
        echo -e "      $(L install_module) [$([ "$SETUPLANG" = "de" ] && echo "J/n" || echo "Y/n")] \c"
        read -r yn </dev/tty 2>/dev/null || yn="j"
        if [[ "$yn" =~ ^[nN]$ ]]; then
            eval "$var=false"
            echo -e "      ${DIM}→ $(L skipped)${NC}"
        else
            echo -e "      ${GREEN}→ $(L installing)${NC}"
        fi
    done
fi

echo ""
info "Module: \
${MOD_FAIL2BAN:+${GREEN}Fail2ban${NC} }\
${MOD_NGINX:+${GREEN}Nginx${NC} }\
${MOD_ZFS:+${GREEN}ZFS${NC} }\
${MOD_WIREGUARD:+${GREEN}WireGuard${NC} }"

# ══════════════════════════════════════════════════════════
# STEP 1: Dependencies
# ══════════════════════════════════════════════════════════

step "$(L step_deps)"

export DEBIAN_FRONTEND=noninteractive

# Determine PHP version
PHP_VERSION=$(apt-cache show php-fpm 2>/dev/null | grep -oP 'Depends:.*php(\d+\.\d+)-fpm' | head -1 | grep -oP '\d+\.\d+' || echo "")

if [[ -z "$PHP_VERSION" ]]; then
    info "apt update ..."
    apt-get update -qq
    PHP_VERSION=$(apt-cache show php-fpm 2>/dev/null | grep -oP 'Depends:.*php(\d+\.\d+)-fpm' | head -1 | grep -oP '\d+\.\d+' || echo "8.2")
fi

info "$(L php_ver): ${BOLD}$PHP_VERSION${NC}"

PACKAGES=(
    nginx
    "php${PHP_VERSION}-fpm"
    "php${PHP_VERSION}-json"
    openssl
)
[[ "$MOD_FAIL2BAN" == "true" ]] && PACKAGES+=(fail2ban)
[[ "$MOD_NGINX" == "true" ]] && PACKAGES+=(certbot python3-certbot-nginx python3-certbot-dns-cloudflare python3-pip)
[[ "$MOD_WIREGUARD" == "true" ]] && PACKAGES+=(wireguard wireguard-tools)

for pkg in "${PACKAGES[@]}"; do
    if dpkg -l "$pkg" 2>/dev/null | grep -q "^ii"; then
        detail "$pkg ($(L already))"
    else
        if apt-get install -y -qq "$pkg" >> /tmp/floppyops-lite-setup.log 2>&1; then
            ok "$pkg"
        else
            warn "$pkg — $(L install_failed)"
        fi
    fi
done

ok "$(L pkgs_done)"

# Hetzner DNS plugin (not in apt, install via pip)
if [[ "$MOD_NGINX" == "true" ]]; then
    if pip3 install --break-system-packages certbot-dns-hetzner >> /tmp/floppyops-lite-setup.log 2>&1; then
        ok "certbot-dns-hetzner (pip)"
    else
        warn "certbot-dns-hetzner — $(L install_failed)"
    fi
fi

# ══════════════════════════════════════════════════════════
# STEP 2: App Files
# ══════════════════════════════════════════════════════════

step "$(L step_files)"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Verify we're running from the cloned repo
if [[ ! -f "$SCRIPT_DIR/index.php" ]]; then
    die "index.php $(L not_found_in) $SCRIPT_DIR"
fi

# Set install dir to script location (git clone target)
INSTALL_DIR="$SCRIPT_DIR"

# Config
if [[ -f "$INSTALL_DIR/config.php" ]]; then
    info "config.php $(L exists_keep)"
else
    cp "$INSTALL_DIR/config.example.php" "$INSTALL_DIR/config.php"
    ok "config.php $(L created)"
    warn "$(L change_pw)"
fi

# Permissions
chown -R www-data:www-data "$INSTALL_DIR"
chmod 640 "$INSTALL_DIR/config.php"
chmod +x "$INSTALL_DIR/update.sh" 2>/dev/null || true
ok "$(L perms_set)"

# ══════════════════════════════════════════════════════════
# STEP 3: PHP-FPM
# ══════════════════════════════════════════════════════════

step "$(L step_php)"

PHP_SOCK=$(find /run/php/ -name "php*-fpm.sock" 2>/dev/null | head -1 || echo "/run/php/php${PHP_VERSION}-fpm.sock")

systemctl enable "php${PHP_VERSION}-fpm" >> /tmp/floppyops-lite-setup.log 2>&1 || true
systemctl start "php${PHP_VERSION}-fpm" >> /tmp/floppyops-lite-setup.log 2>&1 || true

if [[ -S "$PHP_SOCK" ]]; then
    ok "$(L fpm_running) ($PHP_SOCK)"
else
    warn "$(L fpm_restart)"
    systemctl restart "php${PHP_VERSION}-fpm"
    PHP_SOCK=$(find /run/php/ -name "php*-fpm.sock" 2>/dev/null | head -1)
    [[ -S "$PHP_SOCK" ]] && ok "$(L fpm_running)" || die "$(L fpm_fail)"
fi

# ══════════════════════════════════════════════════════════
# STEP 4: Nginx vHost
# ══════════════════════════════════════════════════════════

step "$(L step_nginx)"

if [[ -n "$DOMAIN" ]]; then
    VHOST_NAME="$DOMAIN"
    SERVER_NAME="$DOMAIN"
else
    VHOST_NAME="server-admin"
    SERVER_NAME="_"
fi

VHOST_FILE="/etc/nginx/sites-available/$VHOST_NAME"

cat > "$VHOST_FILE" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${SERVER_NAME};
    root ${INSTALL_DIR};
    index index.php;

    # IP Whitelist — adjust!
    # allow YOUR.IP.HERE;
    # allow 10.10.20.0/24;
    # deny all;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include fastcgi_params;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 30;
    }

    location ~ /\.ht { deny all; }
    location ~ config\.php { deny all; }
}
NGINX

ln -sf "$VHOST_FILE" "/etc/nginx/sites-enabled/$VHOST_NAME"

nginx -t >> /tmp/floppyops-lite-setup.log 2>&1 || die "$(L nginx_invalid)"
systemctl reload nginx
ok "$(L vhost_created): $VHOST_NAME"

# ══════════════════════════════════════════════════════════
# STEP 5: SSL + Nginx Proxy Management
# ══════════════════════════════════════════════════════════

step "$(L step_modules)"

# --- SSL ---
if [[ "$MOD_NGINX" == "true" ]]; then
    if [[ "$SKIP_SSL" == "true" ]]; then
        info "$(L ssl_skip_nossl)"
    elif [[ -z "$DOMAIN" ]]; then
        info "$(L ssl_skip_domain)"
        detail "$(L ssl_hint)"
    else
        if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email >> /tmp/floppyops-lite-setup.log 2>&1; then
            ok "$(L ssl_activated) $DOMAIN"
        else
            warn "$(L ssl_failed)"
        fi
    fi

    # Security headers
    if [[ ! -f /etc/nginx/conf.d/security-headers.conf ]]; then
        cat > /etc/nginx/conf.d/security-headers.conf <<'SECEOF'
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
SECEOF
        ok "$(L secheaders)"
    fi

    # Rate limiting
    if [[ ! -f /etc/nginx/conf.d/rate-limit.conf ]]; then
        cat > /etc/nginx/conf.d/rate-limit.conf <<'RLEOF'
limit_req_zone $binary_remote_addr zone=general:10m rate=10r/s;
limit_req_zone $binary_remote_addr zone=api:10m rate=30r/s;
limit_req_status 429;
RLEOF
        ok "$(L ratelimit)"
    fi

    chown root:www-data /etc/nginx/sites-available /etc/nginx/sites-enabled
    chmod 775 /etc/nginx/sites-available /etc/nginx/sites-enabled
    ok "$(L proxy_mgmt)"

    # Certbot timer
    systemctl enable certbot.timer >> /tmp/floppyops-lite-setup.log 2>&1 || true
    systemctl start certbot.timer >> /tmp/floppyops-lite-setup.log 2>&1 || true
    ok "$(L certbot_renew)"
else
    info "$(L nginx_label) — $(L skipped)"
fi

# --- WireGuard ---
if [[ "$MOD_WIREGUARD" == "true" ]]; then
    if [[ -d /etc/wireguard ]]; then
        chmod 750 /etc/wireguard
        chown root:www-data /etc/wireguard
        for wgconf in /etc/wireguard/wg*.conf; do
            [[ -f "$wgconf" ]] && chmod 640 "$wgconf" && chown root:www-data "$wgconf"
        done
        ok "$(L wg_readable)"
    else
        ok "$(L wg_no_tunnels)"
    fi
else
    info "$(L wg_label) — $(L skipped)"
fi

# --- Fail2ban ---
if [[ "$MOD_FAIL2BAN" == "true" ]]; then
    systemctl enable fail2ban >> /tmp/floppyops-lite-setup.log 2>&1 || true
    systemctl start fail2ban >> /tmp/floppyops-lite-setup.log 2>&1 || true

    # Panel login protection
    touch /var/log/floppyops-lite-auth.log
    chown www-data:www-data /var/log/floppyops-lite-auth.log

    cat > /etc/fail2ban/filter.d/floppyops-lite.conf <<'F2BFILTER'
[Definition]
failregex = LOGIN FAILED user=.* ip=<HOST>
ignoreregex =
F2BFILTER

    cat > /etc/fail2ban/jail.d/floppyops-lite.conf <<'F2BJAIL'
[floppyops-lite]
enabled = true
filter = floppyops-lite
logpath = /var/log/floppyops-lite-auth.log
maxretry = 5
findtime = 300
bantime = 900
F2BJAIL

    systemctl restart fail2ban >> /tmp/floppyops-lite-setup.log 2>&1 || true
    ok "$(L f2b_activated)"
    ok "Panel login brute-force protection (5 attempts / 15min ban)"
else
    info "$(L f2b_label) — $(L skipped)"
fi

# --- ZFS ---
if [[ "$MOD_ZFS" == "true" ]]; then
    if command -v zfs &>/dev/null; then
        ok "$(L zfs_found)"
    else
        info "$(L zfs_not_found)"
    fi
else
    info "$(L zfs_label) — $(L skipped)"
fi

nginx -t >> /tmp/floppyops-lite-setup.log 2>&1 && systemctl reload nginx
ok "$(L nginx_reloaded)"

# ══════════════════════════════════════════════════════════
# STEP 6: Sudoers
# ══════════════════════════════════════════════════════════

step "$(L step_sudoers)"

{
echo "# FloppyOps Lite PVE Panel"
if [[ "$MOD_FAIL2BAN" == "true" ]]; then
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/fail2ban-client status *"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/fail2ban-client status"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/fail2ban-client set * unbanip *"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/tail -* /var/log/fail2ban.log"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart fail2ban"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl is-active fail2ban"
fi
if [[ "$MOD_NGINX" == "true" ]]; then
    echo "www-data ALL=(root) NOPASSWD: /usr/sbin/nginx -t"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl reload nginx"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/certbot *"
fi
if [[ "$MOD_WIREGUARD" == "true" ]]; then
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/wg show *"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/wg genkey"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/wg pubkey"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/wg genpsk"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl start wg-quick@*"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl stop wg-quick@*"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart wg-quick@*"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl enable wg-quick@*"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl disable wg-quick@*"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl is-active wg-quick@*"
fi
if [[ "$MOD_ZFS" == "true" ]]; then
    echo "www-data ALL=(root) NOPASSWD: /usr/sbin/zfs list *"
    echo "www-data ALL=(root) NOPASSWD: /usr/sbin/zfs snapshot *"
    echo "www-data ALL=(root) NOPASSWD: /usr/sbin/zfs destroy *"
    echo "www-data ALL=(root) NOPASSWD: /usr/sbin/zfs rollback *"
    echo "www-data ALL=(root) NOPASSWD: /usr/sbin/zfs clone *"
    echo "www-data ALL=(root) NOPASSWD: /usr/sbin/zfs set *"
    echo "www-data ALL=(root) NOPASSWD: /usr/sbin/zfs get *"
    echo "www-data ALL=(root) NOPASSWD: /usr/sbin/zpool list *"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/apt-get install -y zfs-auto-snapshot"
fi
# Security Check (PVE Firewall via pvesh)
echo "www-data ALL=(root) NOPASSWD: /usr/bin/pvesh get *"
echo "www-data ALL=(root) NOPASSWD: /usr/bin/pvesh set *"
echo "www-data ALL=(root) NOPASSWD: /usr/bin/pvesh create *"
echo "www-data ALL=(root) NOPASSWD: /usr/bin/pvesh delete *"
echo "www-data ALL=(root) NOPASSWD: /usr/bin/ss -tlnpH"
# Self-Update + System Updates
echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl reload php*-fpm"
echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart php*-fpm"
echo "www-data ALL=(root) NOPASSWD: /usr/bin/apt-get update"
echo "www-data ALL=(root) NOPASSWD: /usr/bin/apt-get dist-upgrade *"
echo "www-data ALL=(root) NOPASSWD: /usr/bin/apt-get autoremove *"
} > /etc/sudoers.d/floppyops-lite
chmod 440 /etc/sudoers.d/floppyops-lite
ok "$(L sudoers_created)"

# ══════════════════════════════════════════════════════════
# STEP 7: Finish
# ══════════════════════════════════════════════════════════

step "$(L step_finish)"

# Verify
VERIFY_OK=true
[[ ! -f "$INSTALL_DIR/index.php" ]]   && fail "index.php $(L not_found_in) $INSTALL_DIR" && VERIFY_OK=false
[[ ! -f "$INSTALL_DIR/config.php" ]]  && fail "config.php $(L not_found_in) $INSTALL_DIR" && VERIFY_OK=false
[[ ! -S "$PHP_SOCK" ]]                && fail "PHP-FPM $(L fpm_fail)" && VERIFY_OK=false
systemctl is-active --quiet nginx     || { fail "Nginx $(L nginx_invalid)"; VERIFY_OK=false; }

if [[ "$VERIFY_OK" == "true" ]]; then
    ok "$(L checks_ok)"
fi

# Summary
echo ""
echo ""
if [[ "$VERIFY_OK" == "true" ]]; then
    echo -e "${GREEN}${BOLD}"
    echo "  ┌──────────────────────────────────────────────────┐"
    echo "  │                                                  │"
    printf "  │   ✓  %-44s │\n" "$(L success)"
    echo "  │                                                  │"
    echo "  └──────────────────────────────────────────────────┘"
    echo -e "${NC}"
else
    echo -e "${YELLOW}${BOLD}"
    echo "  ┌──────────────────────────────────────────────────┐"
    echo "  │                                                  │"
    printf "  │   ⚠  %-44s │\n" "$(L success_warn)"
    echo "  │                                                  │"
    echo "  └──────────────────────────────────────────────────┘"
    echo -e "${NC}"
fi

if [[ -n "$DOMAIN" ]]; then
    echo -e "  ${BOLD}FloppyOps Lite PVE${NC}"
    echo -e "  ${CYAN}$(L url_label):${NC}      https://$DOMAIN"
else
    echo -e "  ${BOLD}FloppyOps Lite PVE${NC}"
    echo -e "  ${CYAN}$(L url_label):${NC}      http://$SERVER_IP"
fi
echo -e "  ${CYAN}$(L login_label):${NC}    admin / ${YELLOW}CHANGE_ME${NC}"
echo ""
echo -e "  ${BOLD}$(L files)${NC}"
echo -e "  ${CYAN}$(L app_label):${NC}      $INSTALL_DIR"
echo -e "  ${CYAN}$(L config_label):${NC}   $INSTALL_DIR/config.php"
echo -e "  ${CYAN}$(L vhost_label):${NC}    $VHOST_FILE"
echo -e "  ${CYAN}$(L sudoers_label):${NC}  /etc/sudoers.d/floppyops-lite"
echo -e "  ${CYAN}$(L log_label):${NC}      /tmp/floppyops-lite-setup.log"
echo ""
echo -e "  ${YELLOW}${BOLD}⚠  $(L change_pw_now)${NC}"
echo -e "  ${YELLOW}${BOLD}⚠  $(L whitelist_hint)${NC}"
echo ""
echo -e "  ${DIM}──────────────────────────────────────────────────${NC}"
echo -e "  ${DIM}$(L next_steps):${NC}"
echo -e "  ${DIM}  1. nano $INSTALL_DIR/config.php → $(L step_pw)${NC}"
echo -e "  ${DIM}  2. nano $VHOST_FILE → $(L step_whitelist)${NC}"
echo -e "  ${DIM}  3. $(L step_reload)${NC}"
echo -e "  ${DIM}  4. $(L step_open)${NC}"
echo ""

