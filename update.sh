#!/bin/bash
#
# FloppyOps Lite — Update Script
# Aktualisiert eine bestehende Installation auf die neueste Version.
#
# Usage:
#   bash update.sh                  # Update aus Git (wenn .git vorhanden)
#   bash update.sh --from /pfad     # Update aus lokalem Verzeichnis
#
set -euo pipefail

# ── Colors ────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

ok()   { echo -e "  ${GREEN}✓${NC}  $1"; }
warn() { echo -e "  ${YELLOW}⚠${NC}  $1"; }
fail() { echo -e "  ${RED}✗${NC}  $1"; }
info() { echo -e "  ${CYAN}ℹ${NC}  $1"; }

# ── Defaults ──────────────────────────────────────────────
INSTALL_DIR="/var/www/server-admin"
SOURCE_DIR=""

# ── Parse Arguments ───────────────────────────────────────
while [[ $# -gt 0 ]]; do
    case $1 in
        --from)    SOURCE_DIR="$2"; shift 2 ;;
        --dir)     INSTALL_DIR="$2"; shift 2 ;;
        --help|-h)
            echo "FloppyOps Lite — Update"
            echo ""
            echo "Usage: bash update.sh [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --from /pfad   Update aus lokalem Verzeichnis (statt Git)"
            echo "  --dir /pfad    Installationsverzeichnis (default: /var/www/server-admin)"
            echo "  --help         Hilfe anzeigen"
            exit 0
            ;;
        *) echo "Unbekannte Option: $1"; exit 1 ;;
    esac
done

# ── Pre-Checks ───────────────────────────────────────────
if [[ ! -f "$INSTALL_DIR/index.php" ]]; then
    fail "Keine Installation gefunden in $INSTALL_DIR"
    echo "  Nutze setup.sh fuer die Erstinstallation."
    exit 1
fi

OLD_VERSION=$(grep -oP "define\('APP_VERSION',\s*'\\K[^']+" "$INSTALL_DIR/index.php" 2>/dev/null || echo "unbekannt")
echo ""
echo -e "${BOLD}FloppyOps Lite — Update${NC}"
echo -e "Installiert in: ${CYAN}$INSTALL_DIR${NC}"
echo -e "Aktuelle Version: ${YELLOW}v$OLD_VERSION${NC}"
echo ""

# ── Update-Methode bestimmen ─────────────────────────────
if [[ -n "$SOURCE_DIR" ]]; then
    # Update aus lokalem Verzeichnis
    if [[ ! -f "$SOURCE_DIR/index.php" ]]; then
        fail "Keine gueltige Quelle: $SOURCE_DIR/index.php nicht gefunden"
        exit 1
    fi
    info "Update-Quelle: $SOURCE_DIR"
elif [[ -d "$INSTALL_DIR/.git" ]]; then
    # Git Pull
    info "Git-Repository erkannt, fuehre git pull aus..."
    cd "$INSTALL_DIR"
    git pull --ff-only 2>&1 | while read -r line; do echo "  $line"; done
    SOURCE_DIR="$INSTALL_DIR"
    NEW_VERSION=$(grep -oP "define\('APP_VERSION',\s*'\\K[^']+" "$INSTALL_DIR/index.php" 2>/dev/null || echo "unbekannt")
    echo ""
    ok "Update abgeschlossen: v$OLD_VERSION → v$NEW_VERSION"
    # Bei Git ist alles schon am richtigen Platz, nur Rechte setzen
    chown -R www-data:www-data "$INSTALL_DIR" 2>/dev/null || true
    chmod 644 "$INSTALL_DIR/index.php" "$INSTALL_DIR/lang.php" 2>/dev/null || true
    chmod 644 "$INSTALL_DIR/api/"*.php 2>/dev/null || true
    chmod 644 "$INSTALL_DIR/js/"*.js 2>/dev/null || true
    chmod 640 "$INSTALL_DIR/config.php" 2>/dev/null || true
    chmod 750 "$INSTALL_DIR/data" 2>/dev/null || true
    exit 0
else
    fail "Kein Git-Repo und kein --from angegeben"
    echo "  Nutze: bash update.sh --from /pfad/zu/floppyops-lite"
    exit 1
fi

# ── Dateien kopieren (nur bei --from) ────────────────────
info "Kopiere Dateien..."

# PHP Hauptdateien
cp "$SOURCE_DIR/index.php" "$INSTALL_DIR/index.php"
cp "$SOURCE_DIR/lang.php" "$INSTALL_DIR/lang.php"
ok "index.php + lang.php"

# API-Module
if [[ -d "$SOURCE_DIR/api" ]]; then
    mkdir -p "$INSTALL_DIR/api"
    cp "$SOURCE_DIR/api/"*.php "$INSTALL_DIR/api/"
    ok "api/ Module ($(ls "$SOURCE_DIR/api/"*.php | wc -l) Dateien)"
fi

# JavaScript-Module
if [[ -d "$SOURCE_DIR/js" ]]; then
    mkdir -p "$INSTALL_DIR/js"
    cp "$SOURCE_DIR/js/"*.js "$INSTALL_DIR/js/"
    ok "js/ Module ($(ls "$SOURCE_DIR/js/"*.js | wc -l) Dateien)"
fi

# Setup-Script aktualisieren
if [[ -f "$SOURCE_DIR/setup.sh" ]]; then
    cp "$SOURCE_DIR/setup.sh" "$INSTALL_DIR/setup.sh"
    ok "setup.sh"
fi

# Alte Dateien aufraeumen (aus Single-File-Version)
for OLD_FILE in "$INSTALL_DIR/js/app.js" "$INSTALL_DIR/js/init.js" "$INSTALL_DIR/js/ui.js"; do
    [[ -f "$OLD_FILE" ]] && rm -f "$OLD_FILE" && info "Alte Datei entfernt: $(basename $OLD_FILE)"
done
[[ -d "$INSTALL_DIR/views" ]] && rm -rf "$INSTALL_DIR/views" && info "Altes views/ Verzeichnis entfernt"

# config.php wird NIE ueberschrieben
info "config.php bleibt unveraendert"

# Rechte setzen
chown -R www-data:www-data "$INSTALL_DIR"
chmod 644 "$INSTALL_DIR/index.php" "$INSTALL_DIR/lang.php" 2>/dev/null || true
chmod 644 "$INSTALL_DIR/api/"*.php 2>/dev/null || true
chmod 644 "$INSTALL_DIR/js/"*.js 2>/dev/null || true
chmod 640 "$INSTALL_DIR/config.php" 2>/dev/null || true
chmod 750 "$INSTALL_DIR/data" 2>/dev/null || true
ok "Berechtigungen gesetzt"

# ── Ergebnis ─────────────────────────────────────────────
NEW_VERSION=$(grep -oP "define\('APP_VERSION',\s*'\\K[^']+" "$INSTALL_DIR/index.php" 2>/dev/null || echo "unbekannt")
echo ""
echo -e "${GREEN}${BOLD}  ✓ Update abgeschlossen: v$OLD_VERSION → v$NEW_VERSION${NC}"
echo ""
