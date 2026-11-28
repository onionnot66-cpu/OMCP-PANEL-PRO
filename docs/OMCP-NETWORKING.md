# Game endpoint registration and optional DNS publishing

Admin > Nodes > OMCP instances > Networking. First provision and test an external TCP tunnel or a direct game address under your control. Register the public address and port. Provider labels are metadata; they do NOT create tunnels or manage provider credentials. No unsupported free-hosting bypass or guaranteed uptime is implied.

Optional DNS: set OMCP_DNS_ZONE_ID, OMCP_DNS_BASE_DOMAIN and OMCP_DNS_TOKEN in the panel's private environment and refresh its configuration cache. Use a zone-scoped Cloudflare DNS Read/Edit token. Publish creates a stable mc-<instance UUID without hyphens> hostname with a DNS-only A/AAAA/CNAME record. Players must use the displayed port. This is not Cloudflare Spectrum or a TCP proxy. No SRV record is generated.

The panel persists pending names before external writes, refuses to overwrite conflicting DNS records, and reconciles only exact records bearing the instance ownership comment. Retry pending publication after provider errors. Remove managed DNS before changing an endpoint; disable external forwarding before clearing it. Retirement and port release are blocked while an endpoint remains registered. Keep the original zone/token available for reconciliation. Administrators must review manual provider changes and DNS propagation before reuse.

No live Cloudflare account is configured in tests. DNS API fixture tests do not establish real-world DNS propagation or game connectivity.
