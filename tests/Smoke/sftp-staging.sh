#!/usr/bin/env bash
set -euo pipefail
[[ $GITHUB_ACTIONS == true && -n $RUNNER_TEMP ]] || { echo "Disposable GitHub Actions runner only"; exit 1; }
account=omcpsftp_ci
port=22242
tmp=$(mktemp -d "$RUNNER_TEMP/omcp-sftp-XXXXXX")
jail=/var/lib/omcp-sftp/$account
conf=/etc/omcp-sftp/$account
pid=''
cleanup(){
  if [[ -n $pid ]]; then sudo kill "$pid" 2>/dev/null || true; fi
  sudo userdel "$account" 2>/dev/null || true
  sudo rm -rf -- "$jail" "$conf"
  rm -rf -- "$tmp"
}
if getent passwd "$account" >/dev/null || getent group "$account" >/dev/null || sudo test -e "$jail" || sudo test -e "$conf"; then exit 1; fi
trap cleanup EXIT
ssh-keygen -q -t ed25519 -N '' -f "$tmp/client"
sudo bash scripts/omcp-sftp-init.sh --user "$account" --port "$port" --public-key "$tmp/client.pub"
sudo awk -v host="[127.0.0.1]:$port" '{print host, $1, $2}' "$conf/host_ed25519.pub" > "$tmp/known_hosts"
printf 'fixture upload\n' > "$tmp/upload.txt"
printf 'fixture download\n' | sudo tee "$jail/outgoing/export.txt" >/dev/null
sudo chmod 0644 "$jail/outgoing/export.txt"
sudo /usr/sbin/sshd -f "$conf/sshd_config" -E "$tmp/sshd.log"
pid=$(sudo cat "/run/omcp-sftp-$account.pid")
opts=(-o BatchMode=yes -o StrictHostKeyChecking=yes -o "UserKnownHostsFile=$tmp/known_hosts" -o GlobalKnownHostsFile=/dev/null -o IdentitiesOnly=yes -i "$tmp/client")
printf 'put %s /incoming/upload.txt\nget /outgoing/export.txt %s\n' "$tmp/upload.txt" "$tmp/download.txt" > "$tmp/batch"
sftp -b "$tmp/batch" -P "$port" "${opts[@]}" "$account@127.0.0.1"
sudo cmp "$tmp/upload.txt" "$jail/incoming/upload.txt"
printf 'fixture download\n' | cmp - "$tmp/download.txt"
printf 'get /etc/passwd %s\n' "$tmp/escape.txt" > "$tmp/blocked"
if sftp -b "$tmp/blocked" -P "$port" "${opts[@]}" "$account@127.0.0.1"; then echo "Chroot escape unexpectedly succeeded"; exit 1; fi
printf 'put %s /outgoing/forbidden.txt\n' "$tmp/upload.txt" > "$tmp/blocked"
if sftp -b "$tmp/blocked" -P "$port" "${opts[@]}" "$account@127.0.0.1"; then echo "Read-only export directory unexpectedly writable"; exit 1; fi
if timeout 8 ssh -p "$port" "${opts[@]}" "$account@127.0.0.1" id; then echo "Interactive command unexpectedly succeeded"; exit 1; fi
echo "SFTP upload, download, chroot, read-only export and shell denial passed."
