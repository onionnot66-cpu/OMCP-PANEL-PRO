#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
[[ "${DB_DATABASE:-}" == omcp_test_ci && "${APP_ENV:-}" == testing ]] || { echo 'CI database required.' >&2; exit 1; }
[[ -n "${OMCP_SMOKE_FIXTURE:-}" && -s "$OMCP_SMOKE_FIXTURE" ]] || exit 1
tmp="$(mktemp -d)"
proxy_pid=""
connector_pid=""
cleanup() {
    if [[ -n "$connector_pid" ]]; then kill "$connector_pid" 2>/dev/null || true; wait "$connector_pid" 2>/dev/null || true; fi
    if [[ -n "$proxy_pid" ]]; then kill "$proxy_pid" 2>/dev/null || true; wait "$proxy_pid" 2>/dev/null || true; fi
    rm -rf -- "$tmp"
}
trap cleanup EXIT
go build -trimpath -o "$tmp/proxy" tests/Smoke/https_proxy.go
(cd connector && CGO_ENABLED=0 go build -trimpath -o "$tmp/connector" .)
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=OMCP CI' \
    -addext 'subjectAltName=IP:127.0.0.1' -addext 'basicConstraints=critical,CA:TRUE' \
    -keyout "$tmp/key.pem" -out "$tmp/cert.pem" >/dev/null 2>&1
"$tmp/proxy" "$tmp/cert.pem" "$tmp/key.pem" >"$tmp/proxy.log" 2>&1 &
proxy_pid=$!
curl --cacert "$tmp/cert.pem" --retry 10 --retry-connrefused --retry-delay 1 --fail --silent --show-error https://127.0.0.1:8443/auth/login -o /dev/null
node="$(python3 -c 'import json,os; print(json.load(open(os.environ["OMCP_SMOKE_FIXTURE"]))["connector_node"])')"
token="$(php artisan omcp:node:enroll "$node" --no-ansi | tail -n1)"
[[ "$token" =~ ^[0-9a-f]{64}$ ]] || { echo 'Invalid enrollment CLI response.' >&2; exit 1; }
echo "::add-mask::$token"
mkdir -m 700 "$tmp/bin" "$tmp/game" "$tmp/home"
# A synthetic process tests the connector lifecycle without a Minecraft download or EULA acceptance.
cat > "$tmp/bin/java" <<'JAVA'
#!/bin/sh
if [ "${1:-}" = "-version" ]; then echo 'OMCP synthetic Java' >&2; exit 0; fi
echo 'OMCP smoke runtime ready'
while IFS= read -r command; do
    case "$command" in
        list) echo 'OMCP smoke players: 0' ;;
        stop) echo 'OMCP smoke runtime stopped'; exit 0 ;;
    esac
done
JAVA
chmod 700 "$tmp/bin/java"
: > "$tmp/game/server.jar"
printf '%s\n' "$token" > "$tmp/token"
unset token
PATH="$tmp/bin:$PATH" HOME="$tmp/home" SSL_CERT_FILE="$tmp/cert.pem" "$tmp/connector" \
    --config "$tmp/config.json" --enroll --panel https://127.0.0.1:8443 --node "$node" \
    --trusted-native-dir "$tmp/game" --trusted-native-memory 128 --allow-native-console \
    <"$tmp/token" >"$tmp/connector.log" 2>&1 &
connector_pid=$!
python3 tests/Smoke/http_smoke.py --connector
echo 'Real connector enrollment, authenticated heartbeat, diagnostic and synthetic game lifecycle passed.'
