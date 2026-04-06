#!/bin/bash
#
# FloppyOps Lite — Update Script
# Pulls latest code and runs post-update tasks.
#
# Usage:
#   bash update.sh
#
set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
DIM='\033[2m'
NC='\033[0m'

INSTALL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

ok()   { echo -e "  ${GREEN}✓${NC}  $1"; }
info() { echo -e "  ${CYAN}ℹ${NC}  $1"; }
warn() { echo -e "  ${YELLOW}⚠${NC}  $1"; }
fail() { echo -e "  ${RED}✗${NC}  $1"; }

echo ""
echo -e "${CYAN}${BOLD}  FloppyOps Lite — Update${NC}"
echo -e "${DIM}  $(printf '%.0s─' {1..40})${NC}"
echo ""

# Must be root
if [[ $EUID -ne 0 ]]; then
    fail "Must be run as root"
    exit 1
fi

# Pull latest code
if [[ -d "$INSTALL_DIR/.git" ]]; then
    echo -e "  ${BOLD}Pulling latest changes...${NC}"
    cd "$INSTALL_DIR"
    git pull origin main 2>&1 | sed 's/^/     /'
    ok "Code updated"
else
    # Download mode
    echo -e "  ${BOLD}Downloading latest release...${NC}"
    TMP=$(mktemp -d)
    curl -sL "https://github.com/floppy007/floppyops-lite/archive/refs/heads/main.tar.gz" | tar xz -C "$TMP" --strip-components=1
    rsync -a --exclude='config.php' --exclude='data/' --exclude='.git' "$TMP/" "$INSTALL_DIR/"
    rm -rf "$TMP"
    ok "Code updated (download)"
fi

NEW_VERSION=$(grep -oP "APP_VERSION.*?'\K[^']+" "$INSTALL_DIR/index.php" 2>/dev/null || echo "unknown")
echo ""
echo -e "  ${BOLD}Version: ${GREEN}v${NEW_VERSION}${NC}"
echo ""

# ── Post-Update Tasks ─────────────────────────────────
echo -e "  ${BOLD}Post-update tasks...${NC}"

# Auth log file
AUTH_LOG="/var/log/floppyops-lite-auth.log"
if [[ ! -f "$AUTH_LOG" ]]; then
    touch "$AUTH_LOG"
    chown www-data:www-data "$AUTH_LOG"
    ok "Auth log created ($AUTH_LOG)"
else
    info "Auth log exists"
fi

# Fail2ban jail for panel login
if command -v fail2ban-client &>/dev/null; then
    if [[ ! -f /etc/fail2ban/filter.d/floppyops-lite.conf ]]; then
        cat > /etc/fail2ban/filter.d/floppyops-lite.conf <<'F2BFILTER'
[Definition]
failregex = LOGIN FAILED user=.* ip=<HOST>
ignoreregex =
F2BFILTER
        ok "Fail2ban filter created"
    else
        info "Fail2ban filter exists"
    fi

    if [[ ! -f /etc/fail2ban/jail.d/floppyops-lite.conf ]]; then
        cat > /etc/fail2ban/jail.d/floppyops-lite.conf <<'F2BJAIL'
[floppyops-lite]
enabled = true
filter = floppyops-lite
logpath = /var/log/floppyops-lite-auth.log
maxretry = 5
findtime = 300
bantime = 900
F2BJAIL
        systemctl restart fail2ban 2>/dev/null || true
        ok "Fail2ban jail created (5 attempts = 15min ban)"
    else
        info "Fail2ban jail exists"
    fi
else
    info "Fail2ban not installed, skipping jail"
fi

# Permissions
chown -R www-data:www-data "$INSTALL_DIR"
chmod 640 "$INSTALL_DIR/config.php" 2>/dev/null || true
ok "Permissions set"

# Reload PHP-FPM
PHP_FPM=$(systemctl list-units --type=service --state=running 2>/dev/null | grep -oP 'php[\d.]+-fpm\.service' | head -1)
if [[ -n "$PHP_FPM" ]]; then
    systemctl reload "$PHP_FPM" 2>/dev/null || true
    ok "PHP-FPM reloaded"
fi

echo ""
echo -e "  ${GREEN}${BOLD}✓  Update complete — v${NEW_VERSION}${NC}"
echo ""
