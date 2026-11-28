<?php

namespace Pterodactyl\Tests\Integration\Omcp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Omcp\GameRoutes;
use Pterodactyl\Services\Omcp\InstanceRegistry;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GameRoutesTest extends OmcpIntegrationTestCase
{
    private function denied(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Unsafe network operation accepted.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public function testManagedDnsAndEndpointLifecycle(): void
    {
        $node = Node::factory()->create(['location_id' => Location::factory()->create()->id, 'memory' => 2048, 'disk' => 8192]);
        $owner = User::factory()->create();
        DB::table('omcp_connections')->insert(['node_id' => $node->id, 'credential_hash' => hash('sha256', random_bytes(32)),
            'last_seen_at' => now(), 'capabilities' => json_encode(['fleet_enabled' => true, 'runtime' => 'docker']), 'created_at' => now(), 'updated_at' => now()]);
        $registry = app(InstanceRegistry::class);
        $id = $registry->reserve($node->id, ['owner_id' => $owner->id, 'name' => 'Networking fixture', 'runtime' => 'docker',
            'software' => 'vanilla', 'version' => '1.21.4', 'memory_mib' => 1024, 'disk_mib' => 4096, 'port' => 25565]);
        $service = app(GameRoutes::class);
        $this->denied(422, fn () => $service->register($node->id, $id, 'direct', '127.0.0.1', 25565));
        $this->denied(422, fn () => GameRoutes::endpoint('https://example.com'));
        $this->assertSame('AAAA', GameRoutes::plan($id, '2606:4700:4700::1111', 'example.com')['type']);
        $service->register($node->id, $id, 'playit', 'endpoint.example.com', 25570);
        $this->denied(409, fn () => $registry->release($node->id, $id));

        $zone = str_repeat('a', 32);
        config(['omcp.dns.zone' => $zone, 'omcp.dns.domain' => 'example.com', 'omcp.dns.token' => 'fixture-token']);
        $plan = GameRoutes::plan($id, 'endpoint.example.com', 'example.com');
        $this->assertSame('CNAME', $plan['type']);
        $this->assertFalse($plan['proxied']);
        $this->assertSame('mc-' . str_replace('-', '', $id) . '.example.com', $plan['name']);
        $records = [$plan + ['id' => str_repeat('b', 32)]];
        $records[0]['comment'] = 'unrelated';
        $writes = 0;
        Http::preventStrayRequests();
        Http::fake(['https://api.cloudflare.com/client/v4/zones/*' => function ($request) use (&$records, &$writes, $zone, $plan) {
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer fixture-token'));
            $this->assertStringContainsString('/zones/' . $zone . '/dns_records', $request->url());
            if ($request->method() === 'GET') {
                return Http::response(['success' => true, 'result' => $records, 'result_info' => ['total_count' => count($records)]]);
            }
            ++$writes;
            if ($request->method() === 'POST') {
                $this->assertSame($plan, $request->data());
                $records = [$plan + ['id' => str_repeat('b', 32)]];
                return Http::response(['success' => true, 'result' => $records[0]]);
            }
            $this->assertSame('DELETE', $request->method());
            $this->assertStringEndsWith('/' . str_repeat('b', 32), $request->url());
            $records = [];
            return Http::response(['success' => true, 'result' => ['id' => str_repeat('b', 32)]]);
        }]);

        $this->denied(409, fn () => $service->publish($node->id, $id));
        $this->assertSame(0, $writes);
        $this->assertDatabaseHas('omcp_game_routes', ['instance_id' => $id, 'dns_state' => 'pending']);
        $this->denied(409, fn () => $service->register($node->id, $id, 'direct', '8.8.8.8', 25565));
        $this->denied(409, fn () => $service->clear($node->id, $id));
        $records = []; // Operator resolves an unrelated record before retry.
        $service->publish($node->id, $id);
        $service->publish($node->id, $id);
        $this->assertSame(1, $writes);
        $this->assertDatabaseHas('omcp_game_routes', ['instance_id' => $id, 'dns_state' => 'published', 'hostname' => $plan['name']]);
        $service->unpublish($node->id, $id);
        $this->assertSame(2, $writes);
        $this->assertDatabaseHas('omcp_game_routes', ['instance_id' => $id, 'dns_state' => 'none']);
        $service->clear($node->id, $id);
        $registry->release($node->id, $id);
        $this->assertDatabaseMissing('omcp_instances', ['id' => $id]);
    }
}
