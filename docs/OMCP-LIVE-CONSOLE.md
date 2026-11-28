# Trusted owner WebSocket console snapshots

Enable --allow-trusted-owner-controls and --allow-fleet-live-console on the dedicated rootless container connector. Administrators must grant trusted owner controls per instance. The connector sends a bounded recent console snapshot with each five-second heartbeat; the panel returns current grant generations, and the connector establishes a new output cursor per generation so pre-grant buffered logs are excluded. The panel retains only the latest authorized snapshot, tied to the grant and connector credential hash. Stale snapshots are not returned. Grant revocation deletes the stored snapshot and relay rechecks permission on each poll. Output is rendered as text, not HTML or terminal commands. This is a read-only snapshot feed over a real browser WebSocket, not a lossless line-by-line stream. Existing restricted command jobs remain separate.

The optional Node 22 relay uses the existing locked ws dependency. Install production dependencies with the project's frozen Yarn lock. Run the relay as an unprivileged panel service with OMCP_CONSOLE_ORIGIN set to the exact HTTPS public panel origin, OMCP_CONSOLE_UPSTREAM set to a trusted loopback HTTP panel origin (e.g. http://127.0.0.1:8080), and optional OMCP_CONSOLE_PORT (default 8766). Do not expose its listener publicly. The upstream must accept the public Host and forwarded HTTPS protocol through its trusted proxy configuration and authenticate browser session cookies. Do not put a token or cookie in relay configuration.

Reverse-proxy /omcp-console-ws/ on the HTTPS panel to http://127.0.0.1:8766, retaining the original URI, Origin and Cookie and passing Upgrade/Connection headers with HTTP/1.1. Example Nginx location inside the existing HTTPS server block:

```nginx
location /omcp-console-ws/ {
    proxy_pass http://127.0.0.1:8766;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 90s;
    proxy_buffering off;
}
```

The relay refuses cross-origin upgrades and accepts no input. It polls the authenticated client API every five seconds, closes on grant generation changes or authorization failure, bounds response size, and caps concurrent sockets. It never logs cookies or console content. This is an opt-in trusted single-host relay, not public untrusted hosting or a horizontally scaled production console. Active WebSocket revocation is bounded by the recheck interval and upstream timeout. The node may miss logs between snapshots. Configure session and proxy behavior in deployment and test with a real panel before public exposure.
