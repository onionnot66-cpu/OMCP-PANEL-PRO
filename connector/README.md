# OMCP Connect — development milestone

OMCP is the product name. OMCP-PANEL-PRO is the repository name.

## Enrollment and heartbeat

On an existing configured OMCP development panel, run migrations, configure a valid HTTPS APP_URL, and create a node in Admin. Open Nodes > Configuration > OMCP Connect to generate a single-use token. The generated command downloads the pinned connector installer. Run it as a dedicated non-root Linux user with Go 1.22 or later and curl installed. Supply the token at the hidden prompt. It expires after 15 minutes. The installer builds and tests the connector before running it in the foreground. The credential is stored in ~/.omcp-connect/config.json with private permissions. After stopping, restart with ~/.local/bin/omcp-connect. Root/systemd/Docker are not required for registration. A persistent service is not yet provided.

CLI alternative: php artisan omcp:node:enroll NODE_ID. Use --rotate only to revoke an existing credential; remove the old local configuration privately before re-enrolling.

## Native runtime experiment

Run as a separate non-root game account, not the connector account:
```bash
omcp-connect native --dir /path/to/minecraft --memory 1024
```
Requires a trusted existing server.jar, Java and manual acceptance of the Minecraft EULA. The process runs in the foreground, streams stdout/stderr, and attempts a graceful stop on SIGINT/SIGTERM. It does not implement interactive command input, a hard RAM cap, sandboxing, or remote process management. Do not use for untrusted multi-tenant hosting.

## Limitations

Registration and heartbeat are the only panel-integrated operations. Existing Wings paths remain active and must be replaced before game management without Wings works. Docker runtime, secure job delivery, resource enforcement, TLS deployment, full panel installer, routing, and marketplace remain outstanding. Do not deploy as a production game host yet.
