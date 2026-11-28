# OMCP private testing

The UI test installer is intentionally separate from the eventual production installer. It pins a panel revision whose PHP integration, React build and login HTTP check passed in GitHub Actions (run 46166138091). The connector installer pins the revision whose Go race tests, vet and Linux cross-builds passed (run 46056426460).

Use a disposable Linux user and a fresh local database. Run the installer with --check to list dependencies without changing anything. Default installation asks for an empty database with an omcp_test_ name and its dedicated user; --auto-db optionally creates a new database and restricted TCP user through sudo on an already running local MariaDB/MySQL server. It never deletes existing databases or installs packages. The user supplies PHP 8.2+, required PHP extensions, Composer 2, Node 22+, Yarn Classic and Git.

The installer builds frontend assets before running migrations, confirms the effective database target and starts a loopback-only development listener at 127.0.0.1:8080. For a remote VPS, forward that port via an authenticated SSH connection; do not expose the development server publicly. Authentication is enabled, but reCAPTCHA is disabled for the private test. The current installer does not install production TLS, Wings or an internet-facing proxy. Connector enrollment requires separate HTTPS setup.

The connector script now accepts --upgrade to compile the pinned version and preserve existing enrollment. Stop the connector and its managed games first. The installer keeps a private copy of the previous binary. Restart with the same runtime arguments.

Actual Minecraft downloads, Podman tenant isolation, multiple-user runtime access, routing, and production deployment remain separate milestones. A successful CI smoke build is not a claim that a real VPS installation has been tested.
