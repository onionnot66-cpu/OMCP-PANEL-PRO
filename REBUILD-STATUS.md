# Native panel rebuild

This branch replaces the old user interface with a new frontend. The native Python
process and SFTP backend is being adapted; this is not a Pterodactyl fork and does
not require Docker or Wings. Pterodactyl feature parity is NOT complete.

Implemented frontend: account setup/login/logout, server overview, local creation,
power controls, polling console and command input, file browsing/editing,
SFTP credential generation, admin backup listing/creation, runtime settings,
admin user listing/creation, node inventory.

Access control: administrators can manage the installation. Owners have full
server access. Owners can assign panel users a validated permission set for
console, power, files, backups, settings, players, plugins, SFTP and subuser
management. Existing unowned servers remain administrator-only. Global backup
listing and server creation are administrator-only. Security-relevant server
actions are appended to an audit log. Denials currently return 401.

Not implemented in this rebuild: remote connector, subuser management UI,
two-factor authentication, email password recovery, database provisioning,
scheduled power/command tasks, allocation management, egg templates, transfers,
full audit log, complete file upload/download/archive UI, backup restore UI,
branding editor, user edit/delete UI, and complete Pterodactyl feature parity.

Validation: `python3 tests/test_access.py` starts an isolated real HTTP agent and
checks anonymous denial, owner isolation, cross-user file denial, permission-
scoped subuser access, audit events, admin-only resources, and automatic local
node. JavaScript and Python syntax checked.
Live Minecraft startup, SFTP handshake, browser visual QA and VPS installation
have not yet been verified. This branch is not a production release.
