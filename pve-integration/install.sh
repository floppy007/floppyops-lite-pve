#!/bin/bash
# FloppyOps Lite — PVE Dashboard Integration Installer
# Adds FloppyOps to the PVE sidebar navigation
#
# Usage: bash install.sh [--uninstall]

set -e

PVE_JS_DIR="/usr/share/pve-manager/js"
PVE_TPL="/usr/share/pve-manager/index.html.tpl"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
JS_FILE="floppyops.js"
MARKER="<!-- FloppyOps Lite Integration -->"
HOOK_DIR="/etc/apt/apt.conf.d"
HOOK_FILE="99-floppyops-pve"

# Colors
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

log()  { echo -e "${GREEN}[FloppyOps]${NC} $1"; }
warn() { echo -e "${YELLOW}[FloppyOps]${NC} $1"; }
err()  { echo -e "${RED}[FloppyOps]${NC} $1" >&2; }

uninstall() {
    log "Removing FloppyOps PVE integration..."

    # Remove JS file
    if [ -f "$PVE_JS_DIR/$JS_FILE" ]; then
        rm -f "$PVE_JS_DIR/$JS_FILE"
        log "Removed $PVE_JS_DIR/$JS_FILE"
    fi

    # Remove script tag from template
    if grep -q "$MARKER" "$PVE_TPL" 2>/dev/null; then
        sed -i "/$MARKER/d" "$PVE_TPL"
        sed -i "/floppyops\.js/d" "$PVE_TPL"
        log "Removed script tag from PVE template"
    fi

    # Remove apt hook
    if [ -f "$HOOK_DIR/$HOOK_FILE" ]; then
        rm -f "$HOOK_DIR/$HOOK_FILE"
        log "Removed apt hook"
    fi

    # Restart pveproxy
    systemctl restart pveproxy 2>/dev/null && log "Restarted pveproxy" || warn "Could not restart pveproxy"

    log "Uninstall complete."
    exit 0
}

# Handle --uninstall flag
[ "${1:-}" = "--uninstall" ] && uninstall

# ── Pre-checks ──
if [ "$(id -u)" -ne 0 ]; then
    err "Must be run as root"
    exit 1
fi

if [ ! -f "$PVE_TPL" ]; then
    err "PVE template not found: $PVE_TPL"
    err "Is this a Proxmox VE host?"
    exit 1
fi

if [ ! -f "$SCRIPT_DIR/$JS_FILE" ]; then
    err "JS file not found: $SCRIPT_DIR/$JS_FILE"
    exit 1
fi

# ── Install ──
log "Installing FloppyOps PVE integration..."

# 1. Copy JS file
cp "$SCRIPT_DIR/$JS_FILE" "$PVE_JS_DIR/$JS_FILE"
chmod 644 "$PVE_JS_DIR/$JS_FILE"
log "Copied $JS_FILE to $PVE_JS_DIR/"

# 2. Add script tag to PVE template (before </head>)
if grep -q "$MARKER" "$PVE_TPL"; then
    warn "Script tag already present in PVE template — skipping"
else
    sed -i "/<\/head>/i\\    $MARKER\n    <script type=\"text/javascript\" src=\"/pve2/js/$JS_FILE\"></script>" "$PVE_TPL"
    log "Added script tag to PVE template"
fi

# 3. Create apt hook to re-apply after PVE updates
cat > "$HOOK_DIR/$HOOK_FILE" << 'HOOK'
DPkg::Post-Invoke {
    "if [ -f /usr/share/pve-manager/js/floppyops.js ] && ! grep -q 'FloppyOps Lite Integration' /usr/share/pve-manager/index.html.tpl 2>/dev/null; then sed -i '/<\\/head>/i\\    <!-- FloppyOps Lite Integration -->\\n    <script type=\"text/javascript\" src=\"/pve2/js/floppyops.js\"></script>' /usr/share/pve-manager/index.html.tpl && systemctl restart pveproxy 2>/dev/null; fi";
};
HOOK
chmod 644 "$HOOK_DIR/$HOOK_FILE"
log "Created apt hook for PVE update persistence"

# 4. Restart pveproxy to apply changes
systemctl restart pveproxy 2>/dev/null && log "Restarted pveproxy" || warn "Could not restart pveproxy"

echo ""
log "Installation complete!"
log "Open PVE WebUI and look for 'FloppyOps' in the sidebar."
log ""
log "To uninstall: bash $0 --uninstall"
