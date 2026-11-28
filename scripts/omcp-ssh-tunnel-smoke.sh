#!/usr/bin/env bash
set -euo pipefail
[[ $GITHUB_ACTIONS == true && -n $RUNNER_TEMP && $EUID != 0 ]] || { echo "Disposable unprivileged GitHub runner only"; exit 1; }
umask 077
tmp=$(mktemp -d "$RUNNER_TEMP/omcp-tunnel-XXXXXX")
gateway=22243
remote=25577
localport=25578
account=$(id -un)
gateway_pid='' echo_pid='' tunnel_pid=''
cleanup(){
  if [[ -n $tunnel_pid ]]; then kill "$tunnel_pid" 2>/dev/null || true; wait "$tunnel_pid" 2>/dev/null || true; fi
  if [[ -n $echo_pid ]]; then kill "$echo_pid" 2>/dev/null || true; wait "$echo_pid" 2>/dev/null || true; fi
  if [[ -n $gateway_pid ]]; then sudo kill "$gateway_pid" 2>/dev/null || true; fi
  rm -rf -- "$tmp"
}
trap cleanup EXIT
ssh-keygen -q -t ed25519 -N '' -f "$tmp/client"
ssh-keygen -q -t ed25519 -N '' -f "$tmp/host"
printf 'restrict,port-forwarding,permitlisten="127.0.0.1:%s" %s\n' "$remote" "$(cat "$tmp/client.pub")" > "$tmp/authorized_keys"
awk -v host="[127.0.0.1]:$gateway" '{print host, $1, $2}' "$tmp/host.pub" > "$tmp/known_hosts"
cat > "$tmp/sshd_config" <<EOF
Port $gateway
ListenAddress 127.0.0.1
HostKey $tmp/host
PidFile $tmp/sshd.pid
AllowUsers $account
AuthorizedKeysFile $tmp/authorized_keys
StrictModes yes
PermitRootLogin no
PubkeyAuthentication yes
PasswordAuthentication no
KbdInteractiveAuthentication no
AuthenticationMethods publickey
UsePAM yes
AllowTcpForwarding remote
GatewayPorts no
PermitListen 127.0.0.1:$remote
PermitOpen none
AllowAgentForwarding no
X11Forwarding no
PermitTTY no
MaxSessions 0
EOF
sudo install -d -m 0755 /run/sshd
sudo /usr/sbin/sshd -t -f "$tmp/sshd_config"
sudo /usr/sbin/sshd -f "$tmp/sshd_config" -E "$tmp/sshd.log"
gateway_pid=$(cat "$tmp/sshd.pid")
(cd connector && go build -o "$tmp/omcp-connect" .)
python3 - "$localport" <<'PY' &
import socketserver, sys
class Echo(socketserver.BaseRequestHandler):
    def handle(self):
        self.request.sendall(self.request.recv(4096))
with socketserver.ThreadingTCPServer(("127.0.0.1", int(sys.argv[1])), Echo) as server:
    server.serve_forever()
PY
echo_pid=$!
cat > "$tmp/tunnel.json" <<EOF
{"gateway":"127.0.0.1","gateway_port":$gateway,"user":"$account","identity_file":"$tmp/client","known_hosts_file":"$tmp/known_hosts","local_port":$localport,"remote_port":$remote,"public_bind":false}
EOF
"$tmp/omcp-connect" tunnel --config "$tmp/tunnel.json" --check
"$tmp/omcp-connect" tunnel --config "$tmp/tunnel.json" > "$tmp/tunnel.log" 2>&1 &
tunnel_pid=$!
probe(){
  python3 - "$remote" <<'PY'
import socket, sys, time
for _ in range(60):
    try:
        with socket.create_connection(("127.0.0.1", int(sys.argv[1])), timeout=1) as s:
            s.sendall(b"OMCP fixture")
            if s.recv(4096) == b"OMCP fixture":
                sys.exit(0)
    except OSError:
        pass
    time.sleep(.25)
sys.exit("reverse TCP probe failed")
PY
}
probe
child=$(pgrep -P "$tunnel_pid" -x ssh)
kill "$child"
# The supervisor must launch a new SSH process, not merely leave the old
# gateway listener running.
for i in $(seq 1 60); do
  new=$(pgrep -P "$tunnel_pid" -x ssh || true)
  if [[ -n $new && $new != "$child" ]]; then break; fi
  sleep .25
done
[[ -n $new && $new != "$child" ]]
probe
kill "$tunnel_pid"
wait "$tunnel_pid"
tunnel_pid=''
python3 - "$remote" <<'PY'
import socket, sys, time
for _ in range(40):
    try:
        with socket.create_connection(("127.0.0.1", int(sys.argv[1])), timeout=.3):
            pass
    except OSError:
        sys.exit(0)
    time.sleep(.25)
sys.exit("gateway listener remained open")
PY
echo "Real OpenSSH reverse TCP forwarding, reconnect and shutdown passed."
