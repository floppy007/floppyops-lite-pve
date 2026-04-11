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
cleanup() { [[ -n "${WORK_DIR:-}" && -d "${WORK_DIR:-}" ]] && rm -rf "$WORK_DIR"; }
trap cleanup EXIT

run_root_cmd() {
    if [[ ${EUID:-$(id -u)} -eq 0 ]]; then
        "$@"
    else
        sudo -n "$@"
    fi
}

download_release_to_temp() {
    WORK_DIR="$(mktemp -d /tmp/floppyops-lite-update-XXXXXX)"
    local archive="$WORK_DIR/release.tar.gz"

    info "Kein Git-Repository erkannt, lade Release-Archiv von GitHub..."
    curl -L --fail -A "FloppyOps-Lite" \
        -o "$archive" \
        "https://codeload.github.com/floppy007/floppyops-lite/tar.gz/refs/heads/main"

    tar -xzf "$archive" -C "$WORK_DIR"

    local extracted_dir
    extracted_dir="$(find "$WORK_DIR" -maxdepth 1 -mindepth 1 -type d -name 'floppyops-lite-*' | head -n 1)"
    [[ -n "$extracted_dir" ]] || { fail "Entpacktes Release-Verzeichnis nicht gefunden"; exit 1; }

    SOURCE_DIR="$extracted_dir"
}

reload_php_fpm() {
    local units=()
    while IFS= read -r unit; do
        [[ -n "$unit" ]] && units+=("$unit")
    done < <(run_root_cmd systemctl list-units --type=service --all 'php*-fpm.service' --no-legend 2>/dev/null | awk '{print $1}')

    if [[ ${#units[@]} -eq 0 ]]; then
        warn "Keine php-fpm systemd Units gefunden, ueberspringe Reload"
        return 0
    fi

    local unit
    for unit in "${units[@]}"; do
        if run_root_cmd systemctl reload "$unit" 2>/dev/null; then
            ok "PHP-FPM neu geladen: $unit"
            continue
        fi

        warn "Reload fehlgeschlagen, versuche Restart: $unit"
        run_root_cmd systemctl restart "$unit"
        ok "PHP-FPM neu gestartet: $unit"
    done
}

require_file() {
    local file="$1"
    [[ -f "$file" ]] || { fail "Pflichtdatei fehlt: $file"; exit 1; }
}

require_dir() {
    local dir="$1"
    [[ -d "$dir" ]] || { fail "Pflichtverzeichnis fehlt: $dir"; exit 1; }
}

ensure_sudoers_line() {
    local line="$1"
    local sudoers_file="/etc/sudoers.d/server-admin"

    if ! run_root_cmd true 2>/dev/null; then
        warn "Sudoers-Update uebersprungen (kein root/sudo ohne Passwort)"
        return 0
    fi

    run_root_cmd mkdir -p /etc/sudoers.d
    run_root_cmd touch "$sudoers_file"
    if ! run_root_cmd grep -qF "$line" "$sudoers_file" 2>/dev/null; then
        printf '%s\n' "$line" | run_root_cmd tee -a "$sudoers_file" >/dev/null
    fi
}

install_pam_helper() {
    local source="$INSTALL_DIR/helpers/pam_auth.py"
    [[ -f "$source" ]] || return 0

    if ! run_root_cmd true 2>/dev/null; then
        warn "PAM-Helper-Update uebersprungen (kein root/sudo ohne Passwort)"
        return 0
    fi

    if ! python3 - <<'PY' >/dev/null 2>&1
import importlib.util
import sys
sys.exit(0 if importlib.util.find_spec("pam") or importlib.util.find_spec("PAM") else 1)
PY
    then
        info "Installiere python3-pam..."
        run_root_cmd apt-get update
        run_root_cmd apt-get install -y python3-pam
        ok "python3-pam installiert"
    fi

    run_root_cmd mkdir -p /usr/local/libexec/floppyops-lite
    run_root_cmd install -o root -g www-data -m 0750 "$source" /usr/local/libexec/floppyops-lite/pam_auth.py
    ok "PAM-Helper aktualisiert"

    run_root_cmd tee /etc/pam.d/floppyops-lite >/dev/null <<'PAMEOF'
# PAM stack for FloppyOps Lite local Linux auth
@include common-auth
@include common-account
PAMEOF
    run_root_cmd chmod 644 /etc/pam.d/floppyops-lite
    ok "PAM-Service aktualisiert"

    run_root_cmd mkdir -p /etc/sudoers.d
    if ! run_root_cmd grep -qF 'www-data ALL=(root) NOPASSWD: /usr/local/libexec/floppyops-lite/pam_auth.py --user *' /etc/sudoers.d/server-admin 2>/dev/null; then
        run_root_cmd sh -c "printf '%s\n' 'www-data ALL=(root) NOPASSWD: /usr/local/libexec/floppyops-lite/pam_auth.py --user *' >> /etc/sudoers.d/server-admin"
    fi
    run_root_cmd chmod 440 /etc/sudoers.d/server-admin
    run_root_cmd visudo -cf /etc/sudoers.d/server-admin >/dev/null
    ok "Sudoers fuer PAM-Helper aktualisiert"
}

install_lxc_route_fix_sudoers() {
    ensure_sudoers_line "www-data ALL=(root) NOPASSWD: /usr/sbin/pct list"
    ensure_sudoers_line "www-data ALL=(root) NOPASSWD: /usr/sbin/pct config *"
    ensure_sudoers_line "www-data ALL=(root) NOPASSWD: /usr/sbin/pct exec *"
    if run_root_cmd true 2>/dev/null; then
        run_root_cmd chmod 440 /etc/sudoers.d/server-admin
        run_root_cmd visudo -cf /etc/sudoers.d/server-admin >/dev/null
        ok "Sudoers fuer LXC Reachability aktualisiert"
    fi
}

set_permissions() {
    find "$INSTALL_DIR" -type d -exec chmod 755 {} + 2>/dev/null || true
    find "$INSTALL_DIR" -type f -exec chmod 644 {} + 2>/dev/null || true
    chown -R www-data:www-data "$INSTALL_DIR" 2>/dev/null || true
    chmod 755 "$INSTALL_DIR/setup.sh" "$INSTALL_DIR/update.sh" 2>/dev/null || true
    chmod 640 "$INSTALL_DIR/config.php" 2>/dev/null || true
    chown root:www-data "$INSTALL_DIR/config.php" 2>/dev/null || true
    chmod 750 "$INSTALL_DIR/data" 2>/dev/null || true
    chown -R www-data:www-data "$INSTALL_DIR/data" 2>/dev/null || true
}

validate_tree() {
    local tree="$1"
    require_file "$tree/index.php"
    require_file "$tree/lang.php"
    require_file "$tree/setup.sh"
    require_file "$tree/update.sh"
    require_dir "$tree/api"
    require_dir "$tree/helpers"
    require_dir "$tree/js"
    require_dir "$tree/public"
    require_file "$tree/helpers/pam_auth.py"
    require_file "$tree/public/style.css"

    php -l "$tree/index.php" >/dev/null
    while IFS= read -r phpfile; do
        php -l "$phpfile" >/dev/null
    done < <(find "$tree/api" -maxdepth 1 -type f -name '*.php' | sort)
}

sync_release() {
    local source="$1"
    local backup_dir="$BACKUP_ROOT/$(date +%Y%m%d-%H%M%S)"

    mkdir -p "$BACKUP_ROOT" "$backup_dir"
    rsync -a --exclude='.git' "$INSTALL_DIR/" "$backup_dir/" >/dev/null
    info "Backup erstellt: $backup_dir"

    rsync -a --delete \
        --exclude='.git' \
        --exclude='config.php' \
        --exclude='data/' \
        "$source/" "$INSTALL_DIR/"

    mkdir -p "$INSTALL_DIR/data"
    set_permissions
    validate_tree "$INSTALL_DIR"
}

# ── Defaults ──────────────────────────────────────────────
INSTALL_DIR="/var/www/server-admin"
SOURCE_DIR=""
BACKUP_ROOT="/root/floppyops-lite-update-backups"
WORK_DIR=""

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
    [[ -f "$SOURCE_DIR/index.php" ]] || { fail "Keine gueltige Quelle: $SOURCE_DIR/index.php nicht gefunden"; exit 1; }
    info "Update-Quelle: $SOURCE_DIR"
elif [[ -d "$INSTALL_DIR/.git" ]]; then
    info "Git-Repository erkannt, hole Release-Stand aus Git..."
    cd "$INSTALL_DIR"
    if [[ -n "$(git status --porcelain)" ]]; then
        fail "Git-Worktree ist nicht sauber. Bitte zuerst committen oder stashen."
        exit 1
    fi
    git fetch origin main 2>&1 | while read -r line; do echo "  $line"; done
    git pull --ff-only origin main 2>&1 | while read -r line; do echo "  $line"; done
    WORK_DIR="$(mktemp -d /tmp/floppyops-lite-update-XXXXXX)"
    git archive --format=tar HEAD | tar -xf - -C "$WORK_DIR"
    SOURCE_DIR="$WORK_DIR"
else
    download_release_to_temp
fi

info "Validiere Release-Dateisatz..."
validate_tree "$SOURCE_DIR"
ok "Release-Dateisatz validiert"

info "Synchronisiere vollstaendigen Release-Stand..."
sync_release "$SOURCE_DIR"
ok "Dateien vollstaendig synchronisiert"

info "config.php bleibt unveraendert"
install_pam_helper
install_lxc_route_fix_sudoers
reload_php_fpm

# ── Ergebnis ─────────────────────────────────────────────
NEW_VERSION=$(grep -oP "define\('APP_VERSION',\s*'\\K[^']+" "$INSTALL_DIR/index.php" 2>/dev/null || echo "unbekannt")
echo ""
echo -e "${GREEN}${BOLD}  ✓ Update abgeschlossen: v$OLD_VERSION → v$NEW_VERSION${NC}"
echo ""
