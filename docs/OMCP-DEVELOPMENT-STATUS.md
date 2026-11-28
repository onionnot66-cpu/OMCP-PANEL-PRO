# OMCP development checkpoint — 2026-11-28

This report distinguishes shipped code, CI evidence, and unverified deployment. Product name: OMCP Panel. Runtime: OMCP Connect.

## Verified checkpoints

- Work session source commits: 8ca6d59 (pinned connector installer), 04e5315 (heartbeat/lease renewal and real Vanilla test harness), 4786efd (runtime abstraction and reservation binding), 9130484 (Podman capability detection), c5fdeef (browser and TypeScript checks), 7699e6a (rootless Docker CI provisioning).
- [Panel CI at c5fdeef](https://github.com/onionnot66-cpu/OMCP-PANEL-PRO/actions/runs/46169908669): passed, including Chrome UI smoke and TypeScript.
- [Connector CI at 37e3ab7](https://github.com/onionnot66-cpu/OMCP-PANEL-PRO/actions/runs/46171056682): Go race tests, vet, Linux cross-builds, rootless Docker and Podman passed. Both runtime jobs verified two simultaneous synthetic processes with actual cgroup memory, CPU and PID settings and independent termination. The fix normalizes the fixture image archive ownership, executable permissions and PATH.
- [Connector CI at d524dd6](https://github.com/onionnot66-cpu/OMCP-PANEL-PRO/actions/runs/46171134273): passed all three jobs, including per-instance console command dispatch and rejection of output crossing to the other fixture.
- The Work session reported real Vanilla 1.21.4 on Java 21, protocol readiness, console list, graceful save, shutdown and world persistence across restart. This is a report of its separate local test, not a claim that the container fixture jobs ran real Minecraft.
- [Panel CI for d524dd6](https://github.com/onionnot66-cpu/OMCP-PANEL-PRO/actions/runs/46171134234): PHP integration and unit steps passed at the time of writing; final frontend/browser conclusion still needs verification.

## Important limitations

Native and portable environments without sufficient isolation remain trusted/admin-only. Rootless engine tests demonstrate specific controls, not a complete hostile-tenant security boundary. Users currently see their own reservation metadata; unrestricted runtime actions are not available to customers. Dedicated transfer service, disk quotas, decommissioning, plugin/modpack/world/backup tools, networking integrations, marketplace, advanced theme editor and production installer remain incomplete. Do not claim full Wings replacement or production readiness.

## Continuation

1. Finish verifying panel CI and publish compatible private installer pins.
2. Add verified decommissioning and allocation lifecycle; avoid deleting a running instance or releasing a port while a connector can still use it.
3. Build secure owner-facing runtime authorization and audit controls only after the isolation boundary is reviewed.
4. Introduce bounded, authenticated binary transfers separate from the small JSON job queue.
5. Implement Minecraft tools on that foundation.

All changes are direct, non-forced fast-forward commits to main. No production data or third-party EULA acceptance is implied by tests.
