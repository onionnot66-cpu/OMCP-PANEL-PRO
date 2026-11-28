#!/usr/bin/env bash
# Optional trusted operator-only SFTP staging service. No game mounts.
set -euo pipefail
umask 077
fail() { printf '%s\n' "$*" >&2; exit 1; }
[[ $EUID == 0 ]] || fail "Root setup required on a supported Linux host."
account='' port='' key=''
while (($#)); do
  case "$1" in
    --user) (($# >= 2)) || fail "Missing user"; account=$2; shift 2 ;;
    --port) (($# >= 2)) || fail "Missing port"; port=$2; shift 2 ;;
    --public-key) (($# >= 2)) || fail "Missing public key"; key=$2; shift 2 ;;
    *) fail "Usage: omcp-sftp-init.sh --user omcpsftp_NAME --port PORT --public-key /path/to/key.pub" ;;
  esac
done
[[ $account =~ ^omcpsftp_[a-z0-9_]{1,20}$ ]] || fail "Use a dedicated omcpsftp_ account name."
[[ $port =~ ^[1-9][0-9]{3,4}$ ]] && ((port >= 1024 && port <= 65535)) || fail "Use an unprivileged listener port."
[[ -f $key && ! -L $key ]] || fail "Provide one regular Ed25519 public key file."
[[ $(stat -c %s -- "$key") -le 4096 ]] || fail "Public key is oversized."
for cmd in ssh-keygen useradd getent install stat awk; do command -v "$cmd" >/dev/null || fail "Missing $cmd"; done
[[ -x /usr/sbin/sshd && -x /usr/sbin/nologin && -f /etc/pam.d/sshd ]] || fail "OpenSSH server and PAM are required."
pub=$(awk 'NF { if (++n != 1 || $1 != "ssh-ed25519" || $2 !~ /^[A-Za-z0-9+\/=]+$/) exit 1; print $1 " " $2 } END { if (n != 1) exit 1 }' "$key") || fail "One plain Ed25519 public key required."
check=$(mktemp)
trap 'rm -f -- "$check"' EXIT
printf '%s\n' "$pub" > "$check"
ssh-keygen -l -f "$check" >/dev/null || fail "Invalid public key."
base=/var/lib/omcp-sftp
confbase=/etc/omcp-sftp
jail="$base/$account"
conf="$confbase/$account"
for p in /var /var/lib /etc "$base" "$confbase"; do
  if [[ -e $p || -L $p ]]; then
    [[ -d $p && ! -L $p && $(stat -c %u -- "$p") == 0 ]] || fail "Unsafe root path: $p"
    mode=$(stat -c %a -- "$p")
    (( (8#$mode & 0022) == 0 )) || fail "Root path is writable by others: $p"
  fi
done
if getent passwd "$account" >/dev/null || getent group "$account" >/dev/null || [[ -e $jail || -L $jail || -e $conf || -L $conf ]]; then
  fail "Account or staging directory already exists; no overwrite."
fi
install -d -o root -g root -m 0755 "$base" "$confbase" "$jail" "$conf"
useradd --system --user-group --no-create-home --home-dir /incoming --shell /usr/sbin/nologin "$account"
install -d -o "$account" -g "$account" -m 0700 "$jail/incoming"
install -d -o root -g root -m 0755 "$jail/outgoing"
printf 'restrict %s\n' "$pub" > "$conf/authorized_keys"
chmod 0644 "$conf/authorized_keys"
ssh-keygen -q -t ed25519 -N '' -f "$conf/host_ed25519"
cat > "$conf/sshd_config" <<EOF
Port $port
ListenAddress 127.0.0.1
HostKey $conf/host_ed25519
PidFile /run/omcp-sftp-$account.pid
AllowUsers $account
AuthorizedKeysFile $conf/authorized_keys
StrictModes yes
PermitRootLogin no
PubkeyAuthentication yes
PasswordAuthentication no
KbdInteractiveAuthentication no
AuthenticationMethods publickey
PermitEmptyPasswords no
UsePAM yes
PermitUserEnvironment no
PermitUserRC no
PermitTTY no
DisableForwarding yes
AllowTcpForwarding no
X11Forwarding no
PermitTunnel no
MaxAuthTries 3
MaxSessions 1
Subsystem sftp internal-sftp
ChrootDirectory $jail
ForceCommand internal-sftp -d /incoming -u 0077
EOF
chmod 0600 "$conf/sshd_config"
install -d -o root -g root -m 0755 /run/sshd
/usr/sbin/sshd -t -f "$conf/sshd_config"
printf 'Created loopback-only SFTP staging account: %s\n' "$account"
printf 'Start dedicated service manually: /usr/sbin/sshd -D -e -f %s/sshd_config\n' "$conf"
printf 'Host key fingerprint (verify independently):\n'
ssh-keygen -l -f "$conf/host_ed25519.pub"
printf 'Incoming: %s/incoming | Outgoing: %s/outgoing\n' "$jail" "$jail"
printf 'No game files are mounted. Use a quota-limited filesystem before exposing to other users.\n'
