# Offline world manager and local backups

Use a dedicated trusted rootless Docker/Podman fleet with --allow-fleet-worlds. Stop the instance first. List recognized world directories containing level.dat. Backups include one world directory, up to 256 MiB and 10,000 ZIP entries, with SHA-256 receipts in a private sibling directory outside the game mount. Five backup receipts per instance are allowed. Local backups are not off-site disaster recovery; keep independent copies.

Restore verifies the selected receipt and archive digest, rejects unsafe archive paths, links and excessive expanded size, and extracts into a fresh staging directory before publishing a new world folder. Original worlds are never overwritten. Vanilla can activate the new folder on next restart. Paper/Purpur worlds may have separate dimensions and need manual coordination. Backups, restore copies and retained worlds consume real disk space; no disk quota enforcement or automatic pruning is implied. A crash between archive and receipt publication may require local reconciliation. All operations assume trusted offline workloads and no concurrent local edits.

No public customer access, world deletion, schedule, external storage or complete server backup in this milestone.
