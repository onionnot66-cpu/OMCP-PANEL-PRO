#!/usr/bin/env bash
set -euo pipefail
umask 077

# Development installer; compiles a pinned revision without sudo.
revision=f95c839010165c8a8ed46b823b390e267bdfb6bc
base="https://raw.githubusercontent.com/onionnot66-cpu/OMCP-PANEL-PRO/$revision/connector"
if [[ "$(uname -s)" != Linux ]]; then echo "Linux required." >&2; exit 1; fi
for cmd in go curl mktemp install; do
  command -v "$cmd" >/dev/null || { echo "Missing dependency: $cmd" >&2; exit 1; }
done
if [[ "$EUID" -eq 0 ]]; then echo "Run as a dedicated unprivileged connector user, not root." >&2; exit 1; fi
if [[ "$#" -ne 4 || "$1" != --panel || "$3" != --node || ! "$4" =~ ^[1-9][0-9]*$ ]]; then
  echo "Usage: bash omcp-connect-install.sh --panel https://panel.example.com --node NODE_ID" >&2
  exit 1
fi
panel="$2"
node="$4"
dest="$HOME/.local/bin/omcp-connect"
if [[ -e "$HOME/.omcp-connect/config.json" ]]; then echo "Connector configuration exists. Back up and revoke old credentials before re-enrollment." >&2; exit 1; fi
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
for file in go.mod main.go native.go main_test.go jobs.go jobs_test.go supervisor.go setup.go console.go console_test.go download.go download_test.go properties.go properties_test.go provision.go provision_test.go telemetry.go telemetry_test.go; do
  curl --proto '=https' --proto-redir '=https' -fLsS --retry 3 "$base/$file" -o "$tmp/$file"
done
(
  cd "$tmp"
  GOTOOLCHAIN=local CGO_ENABLED=0 go test ./...
  GOTOOLCHAIN=local CGO_ENABLED=0 go build -trimpath -o omcp-connect .
)
install -d -m 0700 "$HOME/.local/bin"
install -m 0700 "$tmp/omcp-connect" "$dest"
echo "Enter your private enrollment token from OMCP admin. Input is hidden."
IFS= read -r -s token </dev/tty
echo
printf '%s\n' "$token" | "$dest" --enroll --panel "$panel" --node "$node"
