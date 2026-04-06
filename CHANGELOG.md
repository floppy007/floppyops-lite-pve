# Changelog

## v1.2.1 (2026-04-06)

### Added
- **Login Brute-Force Protection**: 2s delay on failed attempts, auth logging, Fail2ban jail (5 attempts = 15min ban)
- **Update Script** (`update.sh`): One-command update with post-update tasks (auth log, Fail2ban, permissions)
- **In-App Update improved**: Now syncs all files (JS, views, CSS, fonts, scripts), not just index.php

### Improved
- App update function downloads full release archive instead of single files
- Post-update tasks run automatically (creates auth log + Fail2ban jail if missing)
- Setup script v1.2 with Fail2ban panel jail
- README updated with login screenshot, auth documentation and update instructions

---

## v1.2.0 (2026-04-06)

### Added
- **DNS-01 Challenge**: SSL certificates via Cloudflare, Hetzner, or IPv64 DNS API
- **Progress Modal**: Live log with step-by-step progress for site creation and SSL renewal
- **CT/VM Target Picker**: Auto-detects container/VM IPs from PVE when creating a site
- **Email Field**: Let's Encrypt email for certificate notifications
- **Certificate Cleanup**: Deleting a site now also removes its SSL certificate
- **Login Language Toggle**: EN/DE switch on the login page
- **Screenshots**: 8 feature screenshots in README

### Improved
- Complete i18n (DE/EN) — zero hardcoded strings remaining
- All assets bundled locally — Chart.js and Google Fonts, no CDN dependencies
- Nginx config generation extracted into shared helper function
- Setup script fully translated (DE/EN), installs certbot-dns-cloudflare + certbot-dns-hetzner
- Help pages updated with DNS-01 providers, IPv6/NDP, cert cleanup documentation
- Auto-snapshot retention period recalculates live when values are changed
- Dead code removed (~200 unused i18n keys, dead JS functions, duplicate CSS)

### Fixed
- Ternary operator precedence bug in VM toast messages
- WireGuard peer endpoint not saved when adding peers
- Repo warning panel display conflict
- Dashboard update card linking to wrong tab
- Native confirm() dialogs replaced with themed appConfirm()
