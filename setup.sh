#!/bin/bash
#
# FloppyOps Lite — Setup Script
# https://github.com/floppy007/floppyops-lite
#
# Installs the FloppyOps Lite Panel on a Proxmox VE host.
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
TOTAL_STEPS=8

# ── Parse Arguments ───────────────────────────────────────
while [[ $# -gt 0 ]]; do
    case $1 in
        --domain)  DOMAIN="$2"; shift 2 ;;
        --dir)     INSTALL_DIR="$2"; shift 2 ;;
        --no-ssl)  SKIP_SSL=true; shift ;;
        --help|-h)
            echo "FloppyOps Lite — Setup"
            echo ""
            echo "Usage: bash setup.sh [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --domain FQDN    Domain for the panel (enables nginx vHost + SSL)"
            echo "  --dir /path      Install directory (default: /var/www/server-admin)"
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
echo "  │     FloppyOps Lite                    │"
echo "  │     Setup Script v1.1                      │"
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
            mod_select)       echo "Nummern eingeben (kommagetrennt, z.B. 1,3) oder Enter für alle:";;
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
            cf_question)      echo "Nutzt du Cloudflare als DNS-Proxy?";;
            cf_desc)          echo "Wenn ja, wird Nginx so konfiguriert, dass die echte Client-IP hinter dem Cloudflare-Proxy erkannt wird (für IP-Whitelists, Logs etc.).";;
            cf_prompt)        echo "Cloudflare Proxy einrichten? [j/N]";;
            cf_done)          echo "Cloudflare Real IP konfiguriert";;
            cf_skip)          echo "Cloudflare Proxy übersprungen";;
            step_pve)         echo "PVE Dashboard Integration";;
            step_pve_btn)     echo "FloppyOps Button in PVE Toolbar";;
            step_pve_hook)    echo "apt-Hook für PVE-Updates";;
            step_pve_skip)    echo "Kein PVE erkannt — Dashboard-Integration übersprungen";;
            wl_question)      echo "Möchtest du den Zugriff auf bestimmte IPs beschränken?";;
            wl_desc)          echo "Ohne IP-Whitelist ist das Panel für jeden im Netzwerk erreichbar. Empfohlen: Nur deine eigene IP oder dein VPN-Netzwerk erlauben.";;
            wl_prompt)        echo "IPs eingeben (kommagetrennt, z.B. 192.168.1.0/24, 10.0.0.5) oder leer lassen:";;
            wl_done)          echo "IP-Whitelist konfiguriert";;
            wl_skip)          echo "Keine IP-Whitelist — Panel ist offen erreichbar";;
            wl_detected)      echo "Deine aktuelle IP";;
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
            mod_select)       echo "Enter numbers (comma-separated, e.g. 1,3) or Enter for all:";;
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
            cf_question)      echo "Do you use Cloudflare as DNS proxy?";;
            cf_desc)          echo "If yes, Nginx will be configured to detect the real client IP behind the Cloudflare proxy (for IP whitelists, logs etc.).";;
            cf_prompt)        echo "Set up Cloudflare Proxy? [y/N]";;
            cf_done)          echo "Cloudflare Real IP configured";;
            cf_skip)          echo "Cloudflare Proxy skipped";;
            step_pve)         echo "PVE Dashboard Integration";;
            step_pve_btn)     echo "FloppyOps Button in PVE Toolbar";;
            step_pve_hook)    echo "apt hook for PVE updates";;
            step_pve_skip)    echo "No PVE detected — skipping Dashboard integration";;
            wl_question)      echo "Do you want to restrict access to specific IPs?";;
            wl_desc)          echo "Without an IP whitelist, the panel is accessible to everyone on the network. Recommended: Only allow your own IP or your VPN subnet.";;
            wl_prompt)        echo "Enter IPs (comma-separated, e.g. 192.168.1.0/24, 10.0.0.5) or leave empty:";;
            wl_done)          echo "IP whitelist configured";;
            wl_skip)          echo "No IP whitelist — panel is publicly accessible";;
            wl_detected)      echo "Your current IP";;
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
echo -e "  ${BOLD}Module:${NC}"
echo -e "    ${GREEN}[1]${NC} Fail2ban     — Brute-Force Schutz + Ban-Verwaltung"
echo -e "    ${GREEN}[2]${NC} Nginx Proxy  — Reverse Proxy + SSL (Let's Encrypt)"
echo -e "    ${GREEN}[3]${NC} ZFS          — Pools, Datasets, Snapshots, Auto-Snapshots"
echo -e "    ${GREEN}[4]${NC} WireGuard    — VPN Tunnel Verwaltung + Wizard"
echo ""
echo -e "  $(L mod_select) \c"
read -r mod_choice </dev/tty 2>/dev/null || mod_choice=""

if [[ -n "$mod_choice" ]]; then
    MOD_FAIL2BAN=false
    MOD_NGINX=false
    MOD_ZFS=false
    MOD_WIREGUARD=false
    IFS=',' read -ra SELECTED <<< "$mod_choice"
    for num in "${SELECTED[@]}"; do
        num=$(echo "$num" | xargs)
        case "$num" in
            1) MOD_FAIL2BAN=true ;;
            2) MOD_NGINX=true ;;
            3) MOD_ZFS=true ;;
            4) MOD_WIREGUARD=true ;;
        esac
    done
fi

echo ""
MOD_SUMMARY=""
[[ "$MOD_FAIL2BAN" == "true" ]] && MOD_SUMMARY+="${GREEN}Fail2ban${NC} "
[[ "$MOD_NGINX" == "true" ]] && MOD_SUMMARY+="${GREEN}Nginx${NC} "
[[ "$MOD_ZFS" == "true" ]] && MOD_SUMMARY+="${GREEN}ZFS${NC} "
[[ "$MOD_WIREGUARD" == "true" ]] && MOD_SUMMARY+="${GREEN}WireGuard${NC} "
info "Module: $MOD_SUMMARY"

# ══════════════════════════════════════════════════════════
# STEP 1: Abhaengigkeiten
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
    openssl
    python3-pam
)
[[ "$MOD_FAIL2BAN" == "true" ]] && PACKAGES+=(fail2ban)
[[ "$MOD_NGINX" == "true" ]] && PACKAGES+=(certbot python3-certbot-nginx)
[[ "$MOD_WIREGUARD" == "true" ]] && PACKAGES+=(wireguard wireguard-tools)

for pkg in "${PACKAGES[@]}"; do
    if dpkg -l "$pkg" 2>/dev/null | grep -q "^ii"; then
        detail "$pkg ($(L already))"
    else
        if apt-get install -y -qq "$pkg" >> /tmp/floppyops-lite-setup.log 2>&1; then
            ok "$pkg"
        else
            warn "$pkg — install failed"
        fi
    fi
done

ok "$(L pkgs_done)"

# ══════════════════════════════════════════════════════════
# STEP 2: App-Dateien
# ══════════════════════════════════════════════════════════

step "$(L step_files)"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

mkdir -p "$INSTALL_DIR"

# Copy app files
if [[ -f "$SCRIPT_DIR/index.php" ]]; then
    cp "$SCRIPT_DIR/index.php" "$INSTALL_DIR/index.php"
    ok "index.php $(L copied)"
else
    die "index.php $(L not_found_in) $SCRIPT_DIR"
fi

if [[ -f "$SCRIPT_DIR/lang.php" ]]; then
    cp "$SCRIPT_DIR/lang.php" "$INSTALL_DIR/lang.php"
    ok "lang.php $(L copied)"
else
    die "lang.php $(L not_found_in) $SCRIPT_DIR"
fi

# API-Module (api/*.php)
if [[ -d "$SCRIPT_DIR/api" ]]; then
    mkdir -p "$INSTALL_DIR/api"
    cp "$SCRIPT_DIR/api/"*.php "$INSTALL_DIR/api/"
    ok "api/ Module kopiert ($(ls "$SCRIPT_DIR/api/"*.php | wc -l) Dateien)"
else
    die "api/ Verzeichnis nicht gefunden in $SCRIPT_DIR"
fi

# JavaScript-Module (js/*.js)
if [[ -d "$SCRIPT_DIR/js" ]]; then
    mkdir -p "$INSTALL_DIR/js"
    cp "$SCRIPT_DIR/js/"*.js "$INSTALL_DIR/js/"
    ok "js/ Module kopiert ($(ls "$SCRIPT_DIR/js/"*.js | wc -l) Dateien)"
else
    die "js/ Verzeichnis nicht gefunden in $SCRIPT_DIR"
fi

# Public Assets
if [[ -d "$SCRIPT_DIR/public" ]] && [[ -f "$SCRIPT_DIR/public/style.css" ]]; then
    mkdir -p "$INSTALL_DIR/public"
    cp "$SCRIPT_DIR/public/style.css" "$INSTALL_DIR/public/style.css"
    ok "public/style.css $(L copied)"
else
    die "public/style.css $(L not_found_in) $SCRIPT_DIR/public"
fi

# Setup-/Update-Skripte
if [[ -f "$SCRIPT_DIR/setup.sh" ]]; then
    cp "$SCRIPT_DIR/setup.sh" "$INSTALL_DIR/setup.sh"
    ok "setup.sh $(L copied)"
else
    die "setup.sh $(L not_found_in) $SCRIPT_DIR"
fi

if [[ -f "$SCRIPT_DIR/update.sh" ]]; then
    cp "$SCRIPT_DIR/update.sh" "$INSTALL_DIR/update.sh"
    ok "update.sh $(L copied)"
else
    die "update.sh $(L not_found_in) $SCRIPT_DIR"
fi

if [[ -d "$SCRIPT_DIR/helpers" ]] && [[ -f "$SCRIPT_DIR/helpers/pam_auth.py" ]]; then
    mkdir -p "$INSTALL_DIR/helpers"
    cp "$SCRIPT_DIR/helpers/pam_auth.py" "$INSTALL_DIR/helpers/pam_auth.py"
    ok "helpers/pam_auth.py $(L copied)"
else
    die "helpers/pam_auth.py $(L not_found_in) $SCRIPT_DIR/helpers"
fi

# Config
if [[ -f "$INSTALL_DIR/config.php" ]]; then
    info "config.php existiert bereits, wird nicht ueberschrieben"
else
    if [[ -f "$SCRIPT_DIR/config.example.php" ]]; then
        cp "$SCRIPT_DIR/config.example.php" "$INSTALL_DIR/config.php"
        ok "config.php erstellt (aus config.example.php)"
    else
        cat > "$INSTALL_DIR/config.php" <<'PHPEOF'
<?php
define('AUTH_METHOD', 'auto');
define('NGINX_SITES_DIR', '/etc/nginx/sites-enabled');
define('NGINX_SITES_AVAILABLE', '/etc/nginx/sites-available');
define('F2B_LOG', '/var/log/fail2ban.log');
define('APP_NAME', 'FloppyOps Lite');
PHPEOF
        ok "config.php erstellt"
    fi
fi

# Data directory for firewall templates etc.
mkdir -p "$INSTALL_DIR/data"

# Permissions
chown -R www-data:www-data "$INSTALL_DIR"
chmod 644 "$INSTALL_DIR/index.php" "$INSTALL_DIR/lang.php"
chmod 644 "$INSTALL_DIR/api/"*.php
chmod 644 "$INSTALL_DIR/js/"*.js
chmod 644 "$INSTALL_DIR/public/style.css"
chmod 644 "$INSTALL_DIR/helpers/pam_auth.py"
chmod 755 "$INSTALL_DIR/setup.sh" "$INSTALL_DIR/update.sh"
chmod 640 "$INSTALL_DIR/config.php"
chmod 750 "$INSTALL_DIR/data"
ok "$(L perms_set)"

mkdir -p /usr/local/libexec/floppyops-lite
install -o root -g www-data -m 0750 "$INSTALL_DIR/helpers/pam_auth.py" /usr/local/libexec/floppyops-lite/pam_auth.py
ok "PAM-Helper installiert"

cat > /etc/pam.d/floppyops-lite <<'PAMEOF'
# PAM stack for FloppyOps Lite local Linux auth
@include common-auth
@include common-account
PAMEOF
chmod 644 /etc/pam.d/floppyops-lite
ok "PAM-Service installiert"

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

# IP Whitelist
echo ""
echo -e "  ${BOLD}$(L wl_question)${NC}"
echo -e "  ${DIM}$(L wl_desc)${NC}"
# Try to detect caller IP (SSH_CLIENT or who)
CALLER_IP=$(echo "$SSH_CLIENT" | awk '{print $1}' 2>/dev/null || who -m 2>/dev/null | grep -oP '\(\K[^)]+' || echo "")
[[ -n "$CALLER_IP" ]] && echo -e "  ${CYAN}$(L wl_detected): ${BOLD}${CALLER_IP}${NC}"
echo ""
echo -e "  $(L wl_prompt) \c"
read -r WHITELIST_IPS </dev/tty 2>/dev/null || WHITELIST_IPS=""

WHITELIST_BLOCK=""
if [[ -n "$WHITELIST_IPS" ]]; then
    WHITELIST_BLOCK=$'\n    # IP-Whitelist'
    IFS=',' read -ra WL_ADDRS <<< "$WHITELIST_IPS"
    for addr in "${WL_ADDRS[@]}"; do
        addr=$(echo "$addr" | xargs)  # trim whitespace
        [[ -n "$addr" ]] && WHITELIST_BLOCK+=$'\n'"    allow ${addr};"
    done
    WHITELIST_BLOCK+=$'\n    deny all;\n'
    ok "$(L wl_done)"
else
    warn "$(L wl_skip)"
fi

cat > "$VHOST_FILE" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${SERVER_NAME};
    root ${INSTALL_DIR};
    index index.php;
${WHITELIST_BLOCK}
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

# Remove default site to avoid conflicts
rm -f /etc/nginx/sites-enabled/default 2>/dev/null

# Cloudflare Proxy Support (real_ip)
echo ""
echo -e "  ${BOLD}$(L cf_question)${NC}"
echo -e "  $(L cf_desc)"
echo -e "  $(L cf_prompt) \c"
read -r cfproxy </dev/tty 2>/dev/null || cfproxy="n"
if [[ "$cfproxy" == "j" || "$cfproxy" == "J" || "$cfproxy" == "y" || "$cfproxy" == "Y" ]]; then
    cat > /etc/nginx/conf.d/cloudflare-realip.conf <<'CFEOF'
# Cloudflare Real IP — erkennt echte Client-IP hinter CF Proxy
# Aktualisieren: https://www.cloudflare.com/ips-v4 + ips-v6
set_real_ip_from 173.245.48.0/20;
set_real_ip_from 103.21.244.0/22;
set_real_ip_from 103.22.200.0/22;
set_real_ip_from 103.31.4.0/22;
set_real_ip_from 141.101.64.0/18;
set_real_ip_from 108.162.192.0/18;
set_real_ip_from 190.93.240.0/20;
set_real_ip_from 188.114.96.0/20;
set_real_ip_from 197.234.240.0/22;
set_real_ip_from 198.41.128.0/17;
set_real_ip_from 162.158.0.0/15;
set_real_ip_from 104.16.0.0/13;
set_real_ip_from 104.24.0.0/14;
set_real_ip_from 172.64.0.0/13;
set_real_ip_from 131.0.72.0/22;
set_real_ip_from 2400:cb00::/32;
set_real_ip_from 2606:4700::/32;
set_real_ip_from 2803:f800::/32;
set_real_ip_from 2405:b500::/32;
set_real_ip_from 2405:8100::/32;
set_real_ip_from 2a06:98c0::/29;
set_real_ip_from 2c0f:f248::/32;
real_ip_header CF-Connecting-IP;
CFEOF
    ok "$(L cf_done)"
else
    info "$(L cf_skip)"
fi

nginx -t >> /tmp/floppyops-lite-setup.log 2>&1 || die "$(L nginx_invalid)"
systemctl reload nginx
ok "$(L vhost_created): $VHOST_NAME"

# PVE SSL vHost on port 8443 (external access via PVE certificate)
if [[ -f /etc/pve/local/pve-ssl.pem ]]; then
    cat > /etc/nginx/sites-available/server-admin-ssl <<SSLNGINX
server {
    listen 8443 ssl;
    server_name _;
    root ${INSTALL_DIR};
    index index.php;

    ssl_certificate /etc/pve/local/pve-ssl.pem;
    ssl_certificate_key /etc/pve/local/pve-ssl.key;

    # Redirect HTTP → HTTPS on same port
    error_page 497 301 =301 https://\$host:\$server_port\$request_uri;
${WHITELIST_BLOCK}
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
SSLNGINX
    ln -sf /etc/nginx/sites-available/server-admin-ssl /etc/nginx/sites-enabled/server-admin-ssl
    nginx -t >> /tmp/floppyops-lite-setup.log 2>&1 && systemctl reload nginx
    ok "SSL vHost on port 8443 (PVE certificate)"
else
    info "No PVE certificate found — skipping port 8443 vHost"
fi

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
            ok "SSL-Zertifikat fuer $DOMAIN aktiviert"
        else
            warn "SSL fehlgeschlagen — Domain muss auf diesen Server zeigen"
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
        ok "WireGuard-Config fuer Panel lesbar"
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
    ok "$(L f2b_activated)"
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
echo "# FloppyOps Lite Panel"
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
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/cp /tmp/nginx_* /etc/nginx/sites-available/*"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/ln -sf /etc/nginx/sites-available/* /etc/nginx/sites-enabled/*"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/rm -f /etc/nginx/sites-available/*"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/rm -f /etc/nginx/sites-enabled/*"
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
echo "www-data ALL=(root) NOPASSWD: /usr/sbin/pct list"
echo "www-data ALL=(root) NOPASSWD: /usr/sbin/pct config *"
echo "www-data ALL=(root) NOPASSWD: /usr/sbin/pct exec *"
echo "www-data ALL=(root) NOPASSWD: /usr/bin/ss -tlnpH"
# Self-Update + System Updates
echo "www-data ALL=(root) NOPASSWD: /usr/local/libexec/floppyops-lite/pam_auth.py --user *"
echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl list-units --type=service --all php*-fpm.service --no-legend"
echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl reload php*-fpm.service"
echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart php*-fpm.service"
echo "www-data ALL=(root) NOPASSWD: /usr/bin/apt-get update"
echo "www-data ALL=(root) NOPASSWD: /usr/bin/apt-get dist-upgrade *"
echo "www-data ALL=(root) NOPASSWD: /usr/bin/apt-get autoremove *"
} > /etc/sudoers.d/server-admin
chmod 440 /etc/sudoers.d/server-admin
visudo -cf /etc/sudoers.d/server-admin >/dev/null || die "Sudoers-Regeln fehlerhaft"
ok "$(L sudoers_created)"

# ══════════════════════════════════════════════════════════
# STEP 7: PVE Dashboard Integration
# ══════════════════════════════════════════════════════════

step "$(L step_pve)"

PVE_TPL="/usr/share/pve-manager/index.html.tpl"
PVE_JS_DIR="/usr/share/pve-manager/js"
PVE_MARKER="<!-- FloppyOps Lite Integration -->"

if [[ -f "$PVE_TPL" ]] && [[ -f "$SCRIPT_DIR/pve-integration/floppyops.js" ]]; then
    cp "$SCRIPT_DIR/pve-integration/floppyops.js" "$PVE_JS_DIR/floppyops.js"
    chmod 644 "$PVE_JS_DIR/floppyops.js"

    if ! grep -q "$PVE_MARKER" "$PVE_TPL"; then
        sed -i "/<\/head>/i\\    $PVE_MARKER\n    <script type=\"text/javascript\" src=\"/pve2/js/floppyops.js\"></script>" "$PVE_TPL"
    fi

    # apt hook to restore after PVE updates
    cat > /etc/apt/apt.conf.d/99-floppyops-pve << 'HOOK'
DPkg::Post-Invoke {
    "if [ -f /usr/share/pve-manager/js/floppyops.js ] && ! grep -q 'FloppyOps Lite Integration' /usr/share/pve-manager/index.html.tpl 2>/dev/null; then sed -i '/<\\/head>/i\\    <!-- FloppyOps Lite Integration -->\\n    <script type=\"text/javascript\" src=\"/pve2/js/floppyops.js\"></script>' /usr/share/pve-manager/index.html.tpl && systemctl restart pveproxy 2>/dev/null; fi";
};
HOOK
    chmod 644 /etc/apt/apt.conf.d/99-floppyops-pve

    systemctl restart pveproxy >> /tmp/floppyops-lite-setup.log 2>&1 || true
    ok "$(L step_pve_btn)"
    ok "$(L step_pve_hook)"
else
    info "$(L step_pve_skip)"
fi

# ══════════════════════════════════════════════════════════
# STEP 8: Abschluss
# ══════════════════════════════════════════════════════════

step "$(L step_finish)"

# Verify
VERIFY_OK=true
[[ ! -f "$INSTALL_DIR/index.php" ]]   && fail "index.php missing" && VERIFY_OK=false
[[ ! -f "$INSTALL_DIR/config.php" ]]  && fail "config.php missing" && VERIFY_OK=false
[[ ! -d "$INSTALL_DIR/api" ]]         && fail "api/ directory missing" && VERIFY_OK=false
[[ ! -d "$INSTALL_DIR/js" ]]          && fail "js/ directory missing" && VERIFY_OK=false
[[ ! -d "$INSTALL_DIR/public" ]]      && fail "public/ directory missing" && VERIFY_OK=false
[[ ! -f "$INSTALL_DIR/public/style.css" ]] && fail "public/style.css missing" && VERIFY_OK=false
[[ ! -f "$INSTALL_DIR/setup.sh" ]]    && fail "setup.sh missing" && VERIFY_OK=false
[[ ! -f "$INSTALL_DIR/update.sh" ]]   && fail "update.sh missing" && VERIFY_OK=false
[[ ! -S "$PHP_SOCK" ]]                && fail "PHP-FPM not running" && VERIFY_OK=false
systemctl is-active --quiet nginx     || { fail "Nginx not running"; VERIFY_OK=false; }

if [[ "$VERIFY_OK" == "true" ]]; then
    ok "$(L checks_ok)"
fi

# Summary
echo ""
echo ""
if [[ "$VERIFY_OK" == "true" ]]; then
    SUCCESS_MSG="✓  $(L success)"
    echo -e "${GREEN}${BOLD}"
    echo "  ┌──────────────────────────────────────────────────┐"
    echo "  │                                                  │"
    printf "  │   %-48s │\n" "$SUCCESS_MSG"
    echo "  │                                                  │"
    echo "  └──────────────────────────────────────────────────┘"
    echo -e "${NC}"
else
    WARN_MSG="⚠  $(L success_warn)"
    echo -e "${YELLOW}${BOLD}"
    echo "  ┌──────────────────────────────────────────────────┐"
    echo "  │                                                  │"
    printf "  │   %-48s │\n" "$WARN_MSG"
    echo "  │                                                  │"
    echo "  └──────────────────────────────────────────────────┘"
    echo -e "${NC}"
fi

echo -e "  ${BOLD}FloppyOps Lite${NC}"
if [[ -n "$DOMAIN" ]]; then
    echo -e "  ${CYAN}URL:${NC}      https://$DOMAIN"
else
    echo -e "  ${CYAN}URL:${NC}      http://$SERVER_IP"
fi
if [[ -f /etc/pve/local/pve-ssl.pem ]]; then
    echo -e "  ${CYAN}SSL:${NC}      https://$SERVER_IP:8443"
    echo -e "  ${CYAN}PVE:${NC}      $(L step_pve_btn)"
fi
echo -e "  ${CYAN}Login:${NC}    PVE root (root / PVE-Passwort)"
echo ""
echo -e "  ${BOLD}$(L files)${NC}"
echo -e "  ${CYAN}App:${NC}      $INSTALL_DIR"
echo -e "  ${CYAN}Config:${NC}   $INSTALL_DIR/config.php"
echo -e "  ${CYAN}vHost:${NC}    $VHOST_FILE"
echo -e "  ${CYAN}Sudoers:${NC}  /etc/sudoers.d/server-admin"
echo -e "  ${CYAN}Log:${NC}      /tmp/floppyops-lite-setup.log"
if [[ -z "$WHITELIST_IPS" ]]; then
    echo ""
    echo -e "  ${YELLOW}${BOLD}⚠  $(L step_whitelist) — nginx vHost!${NC}"
fi
echo ""
echo -e "  ${DIM}──────────────────────────────────────────────────${NC}"
echo -e "  ${DIM}$(L next_steps):${NC}"
STEP_N=1
if [[ -z "$WHITELIST_IPS" ]]; then
    echo -e "  ${DIM}  $STEP_N. nano $VHOST_FILE → $(L step_whitelist)${NC}"
    STEP_N=$((STEP_N + 1))
    echo -e "  ${DIM}  $STEP_N. $(L step_reload)${NC}"
    STEP_N=$((STEP_N + 1))
fi
echo -e "  ${DIM}  $STEP_N. $(L step_open)${NC}"
echo ""
