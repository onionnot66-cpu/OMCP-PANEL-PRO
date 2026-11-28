#!/usr/bin/env bash
set -euo pipefail
umask 077

# Development installer; compiles a pinned revision without sudo.
revision=ef03a56fb83fd8c53af04cdb3802866062f11191
repo=https://github.com/onionnot66-cpu/OMCP-PANEL-PRO.git
if [[ "$(uname -s)" != Linux ]]; then echo "Linux required." >&2; exit 1; fi
for cmd in go git mktemp install; do
  command -v "$cmd" >/dev/null || { echo "Missing dependency: $cmd" >&2; exit 1; }
done
if [[ "$EUID" -eq 0 ]]; then echo "Run as a dedicated unprivileged connector user, not root." >&2; exit 1; fi
dest="$HOME/.local/bin/omcp-connect"
config="$HOME/.omcp-connect/config.json"
mode=enroll
if [[ "$#" -eq 1 && "$1" == --upgrade ]]; then
  mode=upgrade
  [[ -f "$config" && ! -L "$config" && -x "$dest" && ! -L "$dest" ]] || { echo "Existing private config and connector binary required." >&2; exit 1; }
  command -v pgrep >/dev/null || { echo "pgrep required to verify connector is stopped." >&2; exit 1; }
  if pgrep -u "$UID" -x omcp-connect >/dev/null; then echo "Stop OMCP Connect and its Minecraft processes before upgrading." >&2; exit 1; fi
else
  if [[ "$#" -ne 4 || "$1" != --panel || "$3" != --node || ! "$4" =~ ^[1-9][0-9]*$ ]]; then
    echo "Usage: bash omcp-connect-install.sh --panel https://panel.example.com --node NODE_ID | --upgrade" >&2
    exit 1
  fi
  panel="$2"
  node="$4"
  if [[ -e "$config" || -L "$config" ]]; then echo "Connector config exists. Use --upgrade or explicitly revoke and re-enroll." >&2; exit 1; fi
fi
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
git -C "$tmp" init -q
git -C "$tmp" fetch --no-tags --depth=1 "$repo" "$revision"
[[ "$(git -C "$tmp" rev-parse FETCH_HEAD)" == "$revision" ]]
git -C "$tmp" checkout --quiet FETCH_HEAD -- connector
(
  cd "$tmp/connector"
  GOTOOLCHAIN=local CGO_ENABLED=0 go test ./...
  GOTOOLCHAIN=local CGO_ENABLED=0 go build -trimpath -o omcp-connect .
)
install -d -m 0700 "$HOME/.local/bin"
if [[ "$mode" == upgrade ]]; then
  if pgrep -u "$UID" -x omcp-connect >/dev/null; then echo "Connector restarted during build; aborting upgrade." >&2; exit 1; fi
  backup="$(mktemp "$HOME/.local/bin/omcp-connect.previous.XXXXXX")"
  install -m 0700 "$dest" "$backup"
  staged="$(mktemp "$HOME/.local/bin/.omcp-connect-new.XXXXXX")"
  install -m 0700 "$tmp/connector/omcp-connect" "$staged"
  mv -f -- "$staged" "$dest"
  echo "Upgrade complete. Existing enrollment was preserved. Previous binary: $backup"
  echo "Restart using your previous runtime arguments."
  exit 0
fi
install -m 0700 "$tmp/connector/omcp-connect" "$dest"
echo "Enter your private enrollment token from OMCP admin. Input is hidden."
IFS= read -r -s token </dev/tty
echo
printf '%s\n' "$token" | "$dest" --enroll --panel "$panel" --node "$node"
