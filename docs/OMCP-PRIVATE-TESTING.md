# OMCP private testing

The UI test installer is intentionally separate from the eventual production installer. It pins a panel revision whose PHP integration, React build and login HTTP check passed in GitHub Actions (run 46166138091). The connector installer pins the revision whose Go race tests, vet and Linux cross-builds passed (run 46056426460).

Use a disposable Linux user and a fresh local database. Run the installer with --check to list dependencies without changing anything. Default installation asks for an empty database with an omcp_test_ name and its dedicated user; --auto-db optionally creates a new database and restricted TCP user through sudo on an already running local MariaDB/MySQL server. It never deletes existing databases or installs packages. The user supplies PHP 8.2+, required PHP extensions, Composer 2, Node 22+, Yarn Classic and Git.

The installer builds frontend assets before running migrations, confirms the effective database target and starts a loopback-only development listener at 127.0.0.1:8080. For a remote VPS, forward that port via an authenticated SSH connection; do not expose the development server publicly. Authentication is enabled, but reCAPTCHA is disabled for the private test. The current installer does not install production TLS, Wings or an internet-facing proxy. Connector enrollment requires separate HTTPS setup.

The connector script now accepts --upgrade to compile the pinned version and preserve existing enrollment. Stop the connector and its managed games first. The installer keeps a private copy of the previous binary. Restart with the same runtime arguments.

Actual Minecraft downloads, Podman tenant isolation, multiple-user runtime access, routing, and production deployment remain separate milestones. A successful CI smoke build is not a claim that a real VPS installation has been tested.

## Low-memory UI installation (experimental)

Download the unchanged `omcp-panel-assets` ZIP artifact from the successful [panel build 46166138091](https://github.com/onionnot66-cpu/OMCP-PANEL-PRO/actions/runs/46166138091). It is pinned to SHA-256 `842a4815c4f187d3ab3d883a70dbef341d491a2ed96e2ff0fc081559c2100959`. Do not extract or repackage it. Upload it to your test VPS and use:

```bash
bash omcp-ui-test-install.sh --check --assets "$HOME/omcp-panel-assets.zip"
bash omcp-ui-test-install.sh --auto-db --assets "$HOME/omcp-panel-assets.zip"
```

Omit `--auto-db` if supplying your own empty test database. This mode needs Python 3, curl and sha256sum instead of Node/Yarn; PHP/Composer and database requirements are unchanged. The verifier checks the exact ZIP digest, rejects unsafe paths and verifies manifest integrity, filling only absent font integrity metadata. The ZIP is tied to the installer's pinned panel revision. The verifier has been tested against the original CI artifact; full VPS installation of this mode is not yet verified. GitHub artifact downloads may expire; the normal source-build path remains available.

## Latest verified smoke build

[Run 46166473905](https://github.com/onionnot66-cpu/OMCP-PANEL-PRO/actions/runs/46166473905) passed actual HTTP login, admin/user ownership checks, and the connector's HTTPS enrollment, heartbeat, diagnostics and synthetic game lifecycle. The seven PHP integration tests completed 42 assertions but were marked risky due to test error-handler cleanup; this is not a clean PHPUnit warning-free result. The game lifecycle used a simulated Java process, not a downloaded Minecraft server. The private installer remains pinned to the earlier successful build so it matches the prebuilt archive.
