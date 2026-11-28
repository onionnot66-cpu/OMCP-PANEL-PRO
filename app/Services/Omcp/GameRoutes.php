<?php

namespace Pterodactyl\Services\Omcp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Node;

class GameRoutes
{
    public const PROVIDERS = ['direct', 'playit', 'tunnl', 'ngrok', 'frp', 'rathole', 'ssh', 'custom'];

    public static function hostname(string $value): string
    {
        $value = strtolower(rtrim($value, '.'));
        abort_unless(strlen($value) <= 253 && str_contains($value, '.') && !preg_match('/^[0-9.]+$/', $value)
            && filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            && !preg_match('/\.(localhost|local|internal|test|invalid|example|onion)$/', $value), 422, 'Enter a public DNS hostname.');
        return $value;
    }

    public static function endpoint(string $value): string
    {
        $value = trim($value);
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            abort_unless(filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE), 422, 'Public IP address required.');
            return inet_ntop(inet_pton($value));
        }
        return self::hostname($value);
    }

    private function instance(int $node, string $id): object
    {
        Node::query()->lockForUpdate()->findOrFail($node);
        $row = DB::table('omcp_instances')->where('node_id', $node)->where('id', $id)->lockForUpdate()->first();
        abort_unless($row && !$row->retired_at && !$row->retirement_job_id, 404);
        return $row;
    }

    public function register(int $node, string $id, string $provider, string $host, int $port): void
    {
        abort_unless(in_array($provider, self::PROVIDERS, true) && $port >= 1 && $port <= 65535, 422);
        $host = self::endpoint($host);
        DB::transaction(function () use ($node, $id, $provider, $host, $port) {
            $this->instance($node, $id);
            $old = DB::table('omcp_game_routes')->where('instance_id', $id)->lockForUpdate()->first();
            abort_if($old && $old->dns_state !== 'none', 409, 'Remove published or pending DNS before changing the endpoint.');
            $data = ['provider' => $provider, 'endpoint_host' => $host, 'endpoint_port' => $port, 'updated_at' => now()];
            if ($old) {
                DB::table('omcp_game_routes')->where('instance_id', $id)->update($data);
            } else {
                DB::table('omcp_game_routes')->insert($data + ['instance_id' => $id, 'created_at' => now()]);
            }
        });
    }

    public function clear(int $node, string $id): void
    {
        DB::transaction(function () use ($node, $id) {
            $this->instance($node, $id);
            $row = DB::table('omcp_game_routes')->where('instance_id', $id)->lockForUpdate()->first();
            abort_if($row && $row->dns_state !== 'none', 409, 'Remove managed DNS before clearing the endpoint.');
            DB::table('omcp_game_routes')->where('instance_id', $id)->delete();
        });
    }

    private function configuration(): array
    {
        $zone = config('omcp.dns.zone');
        $token = config('omcp.dns.token');
        abort_unless(is_string($zone) && preg_match('/^[a-f0-9]{32}$/D', $zone) && is_string($token) && $token !== '', 409, 'Configure a zone-scoped DNS token first.');
        return [$zone, $token];
    }

    public static function plan(string $id, string $host, string $domain): array
    {
        abort_unless(preg_match('/^[a-f0-9-]{36}$/D', $id), 422);
        $host = self::endpoint($host);
        $name = self::hostname('mc-' . str_replace('-', '', $id) . '.' . self::hostname($domain));
        abort_if($host === $name, 422, 'DNS target cannot point to itself.');
        $type = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 'A'
            : (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'CNAME');
        return ['type' => $type, 'name' => $name, 'content' => $host, 'ttl' => 300, 'proxied' => false, 'comment' => 'OMCP instance ' . $id];
    }

    private function request(string $method, string $zone, string $token, string $suffix = '', array $body = []): array
    {
        try {
            $response = Http::withToken($token)->acceptJson()->asJson()->connectTimeout(3)->timeout(8)
                ->withOptions(['allow_redirects' => false])
                ->send($method, 'https://api.cloudflare.com/client/v4/zones/' . $zone . '/dns_records' . $suffix,
                    $method === 'GET' ? ['query' => $body] : ($method === 'POST' ? ['json' => $body] : []));
            $json = $response->json();
            abort_unless($response->successful() && is_array($json) && ($json['success'] ?? false) === true && is_array($json['result'] ?? null), 502, 'DNS provider request failed.');
            return $json;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            abort(502, 'DNS provider unavailable.');
        }
    }

    private function find(string $zone, string $token, array $plan): ?array
    {
        $reply = $this->request('GET', $zone, $token, '', ['name.exact' => $plan['name'], 'per_page' => 100]);
        $records = $reply['result'];
        abort_unless(array_is_list($records) && count($records) <= 1 && (int) ($reply['result_info']['total_count'] ?? count($records)) <= 1, 409, 'DNS name has conflicting records.');
        if (!$records) {
            return null;
        }
        $r = $records[0];
        $content = (string) ($r['content'] ?? '');
        if ($plan['type'] === 'AAAA' && filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $content = inet_ntop(inet_pton($content));
        }
        abort_unless(($r['type'] ?? null) === $plan['type'] && strtolower(rtrim((string) ($r['name'] ?? ''), '.')) === $plan['name']
            && strtolower(rtrim($content, '.')) === $plan['content'] && ($r['comment'] ?? null) === $plan['comment']
            && ($r['proxied'] ?? null) === false && preg_match('/^[a-f0-9]{32}$/D', (string) ($r['id'] ?? '')), 409, 'Existing DNS record is not owned by this exact route. No changes made.');
        return $r;
    }

    public function publish(int $node, string $id): void
    {
        [$zone, $token] = $this->configuration();
        // Persist the chosen DNS name BEFORE any remote write. A failed or lost
        // response can be safely reconciled by retrying the same route.
        DB::transaction(function () use ($node, $id, $zone) {
            $this->instance($node, $id);
            $r = DB::table('omcp_game_routes')->where('instance_id', $id)->lockForUpdate()->first();
            abort_unless($r, 404);
            if ($r->dns_state === 'none') {
                $plan = self::plan($id, $r->endpoint_host, (string) config('omcp.dns.domain'));
                DB::table('omcp_game_routes')->where('instance_id', $id)->update(['dns_state' => 'pending', 'dns_zone' => $zone, 'hostname' => $plan['name'], 'updated_at' => now()]);
            } else {
                abort_unless($r->dns_zone === $zone, 409, 'Restore the original zone configuration to reconcile DNS.');
            }
        });
        DB::transaction(function () use ($node, $id, $zone, $token) {
            $this->instance($node, $id);
            $r = DB::table('omcp_game_routes')->where('instance_id', $id)->lockForUpdate()->first();
            abort_unless($r && $r->dns_zone === $zone && $r->hostname && $r->dns_state !== 'none', 409);
            $plan = self::plan($id, $r->endpoint_host, substr($r->hostname, 36));
            abort_unless($plan['name'] === $r->hostname, 409);
            if (!$this->find($zone, $token, $plan)) {
                $this->request('POST', $zone, $token, '', $plan);
            }
            abort_unless($this->find($zone, $token, $plan), 502, 'DNS publication not confirmed; retry.');
            DB::table('omcp_game_routes')->where('instance_id', $id)->update(['dns_state' => 'published', 'updated_at' => now()]);
        });
    }

    public function unpublish(int $node, string $id): void
    {
        [$zone, $token] = $this->configuration();
        DB::transaction(function () use ($node, $id, $zone, $token) {
            $this->instance($node, $id);
            $r = DB::table('omcp_game_routes')->where('instance_id', $id)->lockForUpdate()->first();
            abort_unless($r, 404);
            if ($r->dns_state === 'none') {
                return;
            }
            abort_unless($r->dns_zone === $zone && $r->hostname, 409, 'Restore the original zone configuration to reconcile DNS.');
            $plan = self::plan($id, $r->endpoint_host, substr($r->hostname, 36));
            abort_unless($plan['name'] === $r->hostname, 409);
            if ($record = $this->find($zone, $token, $plan)) {
                $this->request('DELETE', $zone, $token, '/' . $record['id']);
            }
            abort_if($this->find($zone, $token, $plan), 502, 'DNS removal not confirmed; retry.');
            DB::table('omcp_game_routes')->where('instance_id', $id)->update(['dns_state' => 'none', 'dns_zone' => null, 'hostname' => null, 'updated_at' => now()]);
        });
    }
}
