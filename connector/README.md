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

## Local Minecraft setup and console snapshot

As the dedicated non-root test account, import a trusted server jar using the expected SHA-256 from a trusted source. Read the Minecraft EULA before accepting it:

```bash
~/.local/bin/omcp-connect setup --dir "$HOME/minecraft-test" --jar /path/to/trusted-server.jar --sha256 EXPECTED_SHA256 --port 25565 --accept-eula
~/.local/bin/omcp-connect --trusted-native-dir "$HOME/minecraft-test" --trusted-native-memory 1024
```

Setup refuses existing directories, verifies the jar checksum, writes server.properties with online-mode enabled, and requires Java to be present. It does not download Minecraft or install Java automatically. Admin diagnostics report Java version if available. The admin Console button retrieves at most 3500 bytes of recent process output through a node-scoped job. It is not a live stream or command input. Console output is stored in the panel database as a job result, so use only disposable test workloads and do not log secrets.

## Download a fresh Minecraft release

The experimental CLI supports Vanilla, Paper and Purpur. It resolves an explicit numeric version from upstream metadata, refuses unexpected HTTPS hosts, bounds download size, checks the published checksum (Paper SHA-256, Vanilla SHA-1, Purpur MD5), and creates a NEW server directory without starting the game. SHA-1 and MD5 are legacy integrity checks, not strong authenticity signatures. The source and calculated SHA-256 are recorded locally. Install compatible Java first and read the Minecraft EULA before accepting.

```bash
~/.local/bin/omcp-connect download --software paper --version 1.21.4 --dir "$HOME/minecraft-paper" --port 25565 --accept-eula
```

Paper uses the Fill v3 stable build API; Vanilla uses Mojang's version manifest; Purpur uses its v2 latest-successful build API. No fallback to a different Minecraft version is performed. Existing worlds and jars are never overwritten by this command. This is a local CLI, not yet the panel version changer.

With the game and connector stopped, edit supported non-security settings:

```bash
~/.local/bin/omcp-connect properties --dir "$HOME/minecraft-paper" --key max-players --value 40
```

Supported keys: difficulty, gamemode, pvp, white-list, allow-flight, spawn-monsters, hardcore, max-players, view-distance, simulation-distance. The editor refuses unusual Java properties syntax rather than risking corruption. Do not run it while a manually started server is active.
