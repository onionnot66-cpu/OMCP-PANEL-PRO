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

## Container retirement milestone

Admin-only data-preserving retirement is implemented in ef03a56. The runtime and Go CI passed at [run 46172035448](https://github.com/onionnot66-cpu/OMCP-PANEL-PRO/actions/runs/46172035448), including active-container refusal, durable retirement, idempotent retry, retained files and independent container lifecycle. Laravel integration and unit steps passed in [run 46172035451](https://github.com/onionnot66-cpu/OMCP-PANEL-PRO/actions/runs/46172035451); consult the final run conclusion for frontend/browser evidence. The connector installer at 950a86d uses the tested runtime revision.

See [retirement procedure](OMCP-RETIREMENT.md). Retirement releases RAM and port only after verified lease completion and admin finalization; disk allocation remains. Do not downgrade a retired fleet. Native retirement, physical purge, automatic manifest changes and hostile-tenant security remain out of scope.

### Final retirement verification

[Panel CI 46172256214](https://github.com/onionnot66-cpu/OMCP-PANEL-PRO/actions/runs/46172256214) passed at 1aecef3: 12 integration tests / 96 assertions, 93 PHP unit tests / 163 assertions, React production build, HTTP smoke, authenticated connector protocol and synthetic runtime smoke, Chrome login and dashboard checks. [Connector CI 46172035448](https://github.com/onionnot66-cpu/OMCP-PANEL-PRO/actions/runs/46172035448) passed the Go/race/vet and Docker/Podman retirement checks. The private UI installer now pins the tested panel source and the downloaded CI asset archive SHA-256; the archive digest and 19 manifest entries were checked (two permitted empty font integrity fields). Fresh external VPS installation and full real-world production validation are still outstanding.
