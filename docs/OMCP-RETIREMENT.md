# Retiring a managed container instance

Scope: dedicated trusted/admin-only rootless Docker and Podman fleets. Native/portable mode cannot automatically prove the absence of orphan processes and is intentionally excluded.

## Procedure

1. Stop the server normally and verify it is stopped. Do not force kill a world that has unsaved changes.
2. Admin > Nodes > Configuration > Instance reservations > **Retire (keep files)**. Confirm explicitly.
3. The connector refuses active attachments and requires the named container to be absent. It writes a durable mode-0600 tombstone in the private fleet root, outside the game mount. No game files are moved or deleted.
4. The panel shows the job status. If failed or expired, inspect the result, resolve the local blocker and retry. A lost completion acknowledgement is safe to retry: the same tombstone is checked again. Pending retirement blocks installation, start, restart, command input and file changes.
5. Once the authenticated lease completion confirms the exact instance retirement receipt, click **Finalize release**. The original UUID and owner record remain, the old TCP port and RAM reservation are released, and the disk reservation is retained because the world is still on disk.
6. Update the private local fleet manifest to remove the retired entry before adding a new UUID with the released port; stop the connector gracefully before changing the manifest, then restart it. Do not remove the tombstone, downgrade the connector, or manually launch a retired UUID. The new server gets a new UUID and directory. The port may still be occupied by unrelated software, so local allocation checks remain necessary.

The retained data is still at the original UUID directory. There is no automatic physical deletion, archive restore, disk-quota enforcement or recovery from local disk loss. An administrator must retain independent backups. Retirement is intentionally irreversible through the panel. Direct local operator modification and downgraded binaries are outside this guarantee. The system assumes a trusted connector identity and dedicated node, not hostile-tenant isolation.

The release transaction refuses absent or mismatched receipts. It is idempotent and never reassigns a retired UUID. Schema rollback is refused if retirement records exist, to prevent restoring ports that may have been reused.

Tests: Go retirement marker, cancellation, invalid markers, no native retirement; real rootless-engine synthetic fixtures for active-container refusal, retry, persistence, retained file and independent process; Laravel job lease, allocation accounting and exact receipt validation; HTTP admin access and explicit confirmation; Chrome retirement UI presence. Consult the relevant GitHub Actions run for pass/fail rather than interpreting the presence of tests as proof.
