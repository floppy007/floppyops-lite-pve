# Changelog

## v1.1.4 (2026-04-06)

### Added
- **Add Peer Wizard**: 2-step wizard to add new peers to existing tunnels (auto-generated keys, suggested IPs)
- **Peer Edit Modal**: Form-based peer settings (Name, Endpoint, AllowedIPs, PSK, Keepalive)
- **Config Import**: Import .conf files from other WireGuard servers (upload or paste)
- **Setup Script Generator**: Download .sh scripts for remote peers (installs WG, checks existing configs, starts tunnel)
- **Download Buttons**: .conf and .sh downloads per peer and in both wizards
- **Peer Info Display**: VPN IP, Peer Name, PSK (click to copy), Public Key per peer row
- **Tunnel Info Bar**: VPN subnet, gateway, port, public key, peer count per tunnel
- **Log Viewer**: Logs button per tunnel — journalctl + dmesg in modal with line count selector
- **Interface Settings Modal**: Form-based interface editing (Address, Port, PostUp/Down) — consistent with peer edit
- **Restart Banner**: Persistent notification when config was changed since last service start (survives page reload)
- **Auto-Refresh**: Peer status updates every 10 seconds (handshake, transfer, endpoint)
- **Firewall Integration**: Auto-add UDP port to PVE firewall when creating/importing tunnels

### Changed
- **Navigation restructured**: Dashboard | Security | Network | ZFS | Updates | Help
- **ZFS own top tab**: Moved out of System sub-tab for better visibility
- **System tab renamed to Updates**: Cleaner, dedicated updates management
- **VMs/CTs moved to Dashboard**: Compact table with Status, VMID, Name, Type, vCPU, RAM + Start/Stop/Restart buttons
- **Subscription status**: New dashboard tile showing Active/None + subscription level

### Improved
- Peers always visible (merged from config + live data, no restart needed to see new peers)
- Peer rows use CSS Grid for consistent alignment regardless of content
- Remove peer button per peer row with confirmation
- Config writes use sudo fallback when www-data lacks write permissions
- **Firewall template cards**: Compact single-line layout (icon + name + badge inline)

### Performance
- **pve-vms**: 15s response cache (pvesh calls ~2s each, was the main bottleneck)
- **fw-vm-list**: 30s response cache (3x pvesh per VM/CT)
- **zfs-status**: 5s response cache
- **Updates tab**: All 3 checks (repo, app, apt) load in parallel via Promise.all
- **Security tab**: All loads run in parallel
- **ZFS tab**: No longer loads PVE VMs, loads in ~160ms instead of ~2s

### Fixed
- JS syntax error from regex in template literal
- WG data not loading on page reload with #network hash
- ZFS "Pools & Datasets" button not highlighted on tab switch

---

## v1.1.3 (2026-04-05)

### Added
- **IPv6 Network Config**: IPv6 address + gateway fields in ZFS Snap Clone dialog
- **IPv6 NDP Proxy Check**: Security tab now checks if NDP proxy is enabled when needed, with one-click fix (permanent via sysctl.conf)
- **IPv6 in VM list**: Clone modal shows IPv6 addresses in network info

### Improved
- Security checks now cover IPv4 forwarding, IPv6 forwarding, and NDP proxy status
- Network section in clone dialog split into clear IPv4/IPv6 sections

---

## v1.1.2 (2026-04-04)

### Fixed
- System tab showing empty page due to broken HTML nesting (Network sub-panels were incorrectly placed inside System panel)

---

## v1.1.1 (2026-04-03)

- Initial public release
- Dashboard with live charts (CPU, RAM, Network, Disk I/O)
- Fail2ban management (jails, banned IPs, log viewer)
- Nginx reverse proxy (sites, SSL, diagnostics)
- WireGuard VPN tunnel management
- ZFS snapshots, pools, auto-snapshots
- VM/CT management (clone, start/stop, resize)
- Firewall templates (iptables presets)
- System updates (APT, repositories, auto-update)
- PVE & PAM authentication
- Dark theme, responsive, i18n (EN/DE)
