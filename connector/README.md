# OMCP Connect — milestone 0.1

Experimental Linux outbound connector. No Wings, Docker, root, or systemd required for enrollment and heartbeat. No game execution yet.

On a configured test panel, run migrations and create a node using existing administration tools. Issue a token privately with `php artisan omcp:node:enroll NODE_ID`. Build locally with Go 1.22 or later: `go test ./... && go build -o omcp-connect .` from this directory.

Start `./omcp-connect --enroll --panel https://YOUR-PANEL --node NODE_ID` and supply the token privately on stdin. Terminal echo is possible; avoid shared terminals and shell history. Later run `./omcp-connect` to use the saved credential. Enrollment requires valid HTTPS. No token is passed as a command-line argument. If registration succeeds but saving fails, an administrator must rotate the enrollment before retrying.

The connector stores a credential in a private local file and reports basic capability hints every 30 seconds. CLI presence does not guarantee a working Docker daemon or systemd. The panel stores hashes, not plaintext credentials. Existing Wings paths are untouched and are not yet replaced.

Next: integration tests, admin node enrollment UI, resource telemetry, authenticated job protocol, safe native process supervision and optional Docker backend. Do not deploy for untrusted tenants yet.
