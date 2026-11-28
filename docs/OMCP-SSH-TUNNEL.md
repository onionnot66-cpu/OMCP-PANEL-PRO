# Outbound SSH reverse TCP tunnel

OMCP Connect includes a local operator-run tunnel supervisor. It uses OpenSSH rather than requiring Docker, root or an inbound public address on the game node. A separately provisioned gateway with reachable SSH is required; it is not a free relay service.

Create a dedicated, non-root forwarding-only gateway account with a restricted key. Configure the gateway's OpenSSH server to permit only the selected remote listen address/port (PermitListen), remote forwarding only, no agent/X11/TTY, and no interactive session. A public bind additionally needs GatewayPorts clientspecified and a firewall rule for the game TCP port. Default loopback bind requires a separately configured gateway proxy to be public. Confirm the gateway host key through a trusted independent channel and put it in a private known_hosts file; do not blindly trust ssh-keyscan. Store the dedicated private key and config outside game mounts, mode 0600. The game must listen on the chosen node-local port.

Example private config (replace all placeholders):

```json
{"gateway":"gateway.example.com","gateway_port":22,"user":"omcp_forward","identity_file":"/home/omcp/.ssh/forward_key","known_hosts_file":"/home/omcp/.ssh/forward_known_hosts","local_port":25565,"remote_port":25570,"public_bind":false}
```

Run `omcp-connect tunnel --config /home/omcp/.omcp-connect/tunnel.json --check` to validate local files; omit --check to run the foreground reconnecting supervisor. It binds only the approved loopback game port to the selected gateway port. Public exposure requires explicit public_bind true and gateway authorization. It verifies the pinned host key, refuses password/interactive auth, and reconnects with bounded backoff. Stop with SIGINT/SIGTERM. Register the actual verified gateway address and port in OMCP Networking manually. Never expose a database, SSH management port or panel admin interface by using this game-forwarding config.

No gateway/account creation, automatic provider API enrollment, automatic panel endpoint registration, public reachability verification, or free-VPS uptime guarantee. Obey provider limits and terms. Tests verify argument constraints and reconnect behavior, not a real gateway.
