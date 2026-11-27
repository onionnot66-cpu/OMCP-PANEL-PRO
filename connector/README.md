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

## Admin-created trusted test server

After enrollment, restart the connector under a disposable non-root account with a new, unused server directory whose parent exists:

```bash
~/.local/bin/omcp-connect --trusted-native-dir "$HOME/omcp-admin-test" --trusted-native-memory 1024 --allow-native-provision
```

Open Admin > Nodes > Configuration > OMCP Connect. Choose software, type the exact version, select a TCP port, read and accept the Minecraft EULA, and submit Install Minecraft. The job is delivered over the authenticated outbound connector. It cannot choose a different local path or overwrite an existing server. Installation does not start the game automatically. Use Start after a successful job result.

Large downloads can temporarily delay heartbeat updates; do not issue duplicate jobs when a download is in progress. Metrics reports Java RSS when available and filesystem capacity/available space, not per-server disk usage, CPU percentage or enforced limits. Console remains a bounded recent snapshot. These admin-only controls are not wired into the normal user server list, and the single trusted account is not a sandbox.

## Automatic console refresh and resource metrics

The native supervisor now sends a bounded recent console snapshot and resource metrics with its authenticated heartbeat, approximately every five seconds when idle. The admin node Configuration page refreshes that snapshot without creating new jobs. The panel retains the latest snapshot only; manual console jobs still store a bounded result. Treat logs as sensitive. A download or other long-running job can delay heartbeats and console updates. This is polling, not a lossless WebSocket stream.

For restricted command input, explicitly opt in locally:

```bash
~/.local/bin/omcp-connect --trusted-native-dir "$HOME/omcp-admin-test" --trusted-native-memory 1024 --allow-native-console
```

Supported commands: list, save-all, save-all flush, whitelist list, time query daytime, time query gametime, and say followed by a message. Commands are sent to Minecraft stdin, not a shell. No arbitrary plugin commands or line breaks are accepted. A successful job means the command was written, not that Minecraft executed it successfully.

CPU reports the Java process share of the total CPU time exposed by Linux /proc/stat between samples, not a percentage of its cgroup quota. RSS is process resident memory, heap is a JVM setting, filesystem free space is not per-server disk usage. First or unavailable CPU samples are omitted. This remains a trusted single-server admin-only experiment.

## Multiple instance reservation registry (panel foundation)

Admin > Nodes > Configuration > Instance reservations records a UUID, owner, software/version, TCP port, RAM and disk budgets. Reservations are serialized per node, check the configured node budgets, and reject duplicate ports and existing legacy allocations. They are **not** running servers, do not install software and do not enforce kernel quotas. Releasing a reservation only deletes metadata. Nodes with reservations cannot be deleted until reservations are released; deleting an owner unassigns the reservation without deleting it.

Use a separate dedicated node for these records. The legacy allocator and earlier single-server experiment are not coordinated with this registry; do not use them on the same node. The normal dashboard displays only the signed-in owner's reservation metadata, from GET /api/client/omcp/instances. It exposes no native power, files, logs or credentials. Do not grant users runtime access before independent process identities or container isolation and server-scoped job authorization are implemented.

## Trusted multi-instance fleet experiment

The registry now supports per-instance install and power jobs in a separate locally authorized fleet mode. Reserve the instances in the panel first. On the dedicated test account, create a private directory and a mode-0600 JSON manifest listing only the exact UUID, port and memory values from those reservations.

Example manifest (replace the UUIDs with actual reserved instance IDs):

```json
[
  {"id":"aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa","memory_mib":1024,"port":25565},
  {"id":"bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb","memory_mib":1024,"port":25566}
]
```

```bash
mkdir -m 700 "$HOME/omcp-fleet"
chmod 600 "$HOME/fleet.json"
~/.local/bin/omcp-connect --trusted-fleet-root "$HOME/omcp-fleet" --trusted-fleet-manifest "$HOME/fleet.json" --trusted-fleet-memory 2048 --allow-native-provision
```

Admin > Nodes > Configuration > Instance reservations now offers per-instance installation (explicit EULA acceptance), Start, Stop, Restart, Kill, Status, Console and Metrics. Unknown IDs and mismatched local port/heap values are rejected. Each UUID has its own directory and Java process. The existing node job queue serializes operations, including downloads; running Java processes can coexist. Console and metrics are on-demand snapshots, not continuous fleet streaming.

Fleet and single native modes are mutually exclusive. This is NOT a tenant sandbox: instances and connector share the same OS identity. The heap budget does not enforce physical memory, CPU or disk quotas. Do not install untrusted JARs/plugins or grant customers runtime access. The owner dashboard remains a read-only plan list. Once install/start/restart has been requested, the panel prevents releasing the allocation; a verified decommission workflow is still needed. There is no automatic delete, migration, or port reuse. Do not use the legacy Wings allocator on the fleet node.

This section supersedes the metadata-only limitation for the admin fleet experiment described above. Full integration and build verification are still required before deployment.

## Optional rootless Podman fleet backend (experimental)

On a dedicated Linux test account with functional rootless Podman, cgroup v2, delegated resource controllers, and an approved local Java runtime image, add the following flags to the trusted fleet command:

```bash
--fleet-podman-image sha256:YOUR_LOCAL_IMAGE_ID --fleet-podman-cpus 2
```

Use the exact local image ID obtained from Podman after independently verifying the image source and Java version. No image is pulled automatically. Host Java is still required by the existing downloader. The aggregate fleet memory flag must include 512 MiB extra per instance (for example, two 1024 MiB heaps require at least 3072 MiB budget). Each container requests a RAM limit of heap + 512 MiB, RAM-plus-swap limit of heap + 640 MiB, CPU and PID limits; verify that your host actually enforces them. Only the corresponding instance directory is explicitly bind-mounted; the container root filesystem is read-only, Linux capabilities are dropped, privilege escalation and automatic proxy-environment injection are disabled. The selected game TCP port is published on the host. Configure a firewall as needed.

The feature refuses missing rootless/cgroup-v2 prerequisites and never falls back to native execution. If an attachment exits and a named container remains, reconcile it locally before retrying; no automatic removal of existing containers. Connector termination is not a guarantee that all containers exit. Container telemetry currently reports configured limits and attachment status, not Java RSS or CPU measurements.

This is additional isolation, not a verified hostile-tenant sandbox. Rootless keep-id uses the connector user's mapped identity, outbound networking is not restricted, there is no disk quota, and the host-side file APIs are not audited for malicious symlink races. Continue using only trusted workloads and admin-only actions. Full runtime tests on a real Podman host and security review are required before customer access.

## Offline admin File Manager

Add --allow-fleet-files to an explicitly configured trusted fleet connector. Stop the chosen instance first (including any remaining Podman container). In Admin > Nodes > Configuration > Instance reservations, select the instance in Offline File Manager.

The initial file API lists a bounded set of root-level configuration files and notes-*.txt. It supports UTF-8 text up to 2048 bytes, reading, loading local text into the editor, saving with an expected SHA-256, creating and deleting notes files, and downloading editor text. It refuses path traversal, symlinks, hardlinks and non-regular files. Raw server.properties access is intentionally blocked; the separate properties editor changes only approved keys. The underlying properties file must be small and use canonical Java Properties syntax. Existing configuration edits use atomic replacement.

No recursive browsing, arbitrary uploads, JAR files, archives, world transfers or file access for customers. File payloads/results are stored in the panel job database and base64 is not encryption. Never put credentials or other secrets in managed files. Only use trusted offline workloads, without concurrent local modifications; these checks are not a sandbox against a malicious same-UID process. No automatic decommission or disk-quota enforcement. Runtime and panel integration require further testing.
