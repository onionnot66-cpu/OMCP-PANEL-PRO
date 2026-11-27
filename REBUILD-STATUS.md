# Native panel rebuild

This branch replaces the old user interface with a new frontend. The native Python
process and SFTP backend is being adapted; this is not a Pterodactyl fork and does
not require Docker or Wings. Pterodactyl feature parity is NOT complete.

Implemented frontend: account setup/login/logout, server overview, local creation
with owner/template/version/RAM/CPU/port assignment, power controls, polling
console and command input, file browsing/editing, world/player views, compatible
plugin search/install, SFTP credential generation, scoped backup listing/creation,
runtime settings, interval schedules, MariaDB provisioning, subuser permissions,
admin user listing/creation, node inventory and activity log.

Access control: administrators can manage the installation. Owners have full
server access. Owners can assign panel users a validated permission set for
console, power, files, backups, settings, players, plugins, SFTP and subuser
management. Existing unowned servers remain administrator-only. Global backup
listing and server creation are administrator-only. Security-relevant server
actions are appended to an audit log. Denials currently return 401.

Not implemented in this rebuild: remote connector, two-factor authentication,
email password recovery, allocation pools, arbitrary Pterodactyl egg imports,
server transfers and branding editor. The supported native server templates
are Paper, Purpur, Folia, Spigot, Fabric, Vanilla, Velocity and Waterfall.

Validation: `python3 tests/test_access.py` starts an isolated real HTTP agent and
checks anonymous denial, owner isolation, cross-user file denial, permission-
scoped subuser access, audit events, admin-only resources, and automatic local
node. JavaScript and Python syntax checked.
Live Minecraft startup, SFTP handshake, browser visual QA and VPS installation
have not yet been verified. This branch is not a production release.
