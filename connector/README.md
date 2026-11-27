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

Registration, heartbeat, diagnostics and opt-in single-server admin power actions are panel-integrated. Existing Wings paths remain active and must be replaced before game management without Wings works. Docker runtime, secure job delivery, resource enforcement, TLS deployment, full panel installer, routing, and marketplace remain outstanding. Do not deploy as a production game host yet.

## Remote native power experiment

Use a dedicated, disposable non-root account and a trusted Minecraft server directory containing server.jar. Accept the EULA manually and configure the server port locally. After enrolling normally, stop the connector and restart it explicitly:

```bash
~/.local/bin/omcp-connect --trusted-native-dir /absolute/path/to/server --trusted-native-memory 1024
```

The node Configuration page can queue Start, Stop, Restart, Kill and Status operations for this one local server. There are no remote path or command arguments. A process running result does not guarantee Minecraft is accepting players. Graceful stop has a timeout and may force kill. Stopping the connector stops its supervised Java process. This feature shares an OS account with the connector: trusted jars/plugins only, never expose to ordinary hosting customers or treat it as isolated. Existing client server creation, console, multi-server management and tenant resource enforcement are not implemented by this experiment.
