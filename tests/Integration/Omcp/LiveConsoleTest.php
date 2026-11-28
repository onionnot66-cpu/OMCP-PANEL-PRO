<?php

namespace Pterodactyl\Tests\Integration\Omcp;

use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Omcp\InstanceRegistry;
use Pterodactyl\Services\Omcp\LiveConsole;
use Pterodactyl\Services\Omcp\OwnerControls;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LiveConsoleTest extends OmcpIntegrationTestCase
{
    private function denied(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Unauthorized live console read.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public function testSnapshotIsBoundToOwnerGenerationAndCredential(): void
    {
        $node = Node::factory()->create(['location_id' => Location::factory()->create()->id, 'memory' => 4096, 'disk' => 8192]);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $hash = hash('sha256', random_bytes(32));
        $caps = ['fleet_enabled' => true, 'runtime' => 'docker', 'fleet_owner_controls' => true, 'fleet_live_console' => true];
        DB::table('omcp_connections')->insert(['node_id' => $node->id, 'credential_hash' => $hash,
            'last_seen_at' => now(), 'capabilities' => json_encode($caps), 'created_at' => now(), 'updated_at' => now()]);
        $registry = app(InstanceRegistry::class);
        $id = $registry->reserve($node->id, ['owner_id' => $owner->id, 'name' => 'Live fixture', 'runtime' => 'docker',
            'software' => 'vanilla', 'version' => '1.21.4', 'port' => 25565, 'memory_mib' => 1024, 'disk_mib' => 4096]);
        $otherId = $registry->reserve($node->id, ['owner_id' => $other->id, 'name' => 'Other fixture', 'runtime' => 'docker',
            'software' => 'vanilla', 'version' => '1.21.4', 'port' => 25566, 'memory_mib' => 1024, 'disk_mib' => 4096]);
        $live = app(LiveConsole::class);
        $controls = app(OwnerControls::class);
        $payload = ['runtime' => 'docker', 'capabilities' => $caps, 'fleet_console' => [
            ['instance_id' => $id, 'output' => 'trusted owner console'], ['instance_id' => $otherId, 'output' => 'not granted'],
        ]];
        $live->capture($node->id, $hash, $payload);
        $this->assertDatabaseCount('omcp_live_console', 0);
        $this->denied(404, fn () => $live->read($owner->id, $id));
        $controls->grant($node->id, $id, true);
        $this->assertSame('waiting', $live->read($owner->id, $id)['state']);
        $payload['fleet_console'][0]['generation'] = $live->grants($node->id, $payload)[$id];
        $live->capture($node->id, $hash, $payload);
        $this->assertDatabaseCount('omcp_live_console', 1);
        $first = $live->read($owner->id, $id);
        $this->assertSame('live', $first['state']);
        $this->assertSame('trusted owner console', $first['output']);
        $this->denied(404, fn () => $live->read($other->id, $id));
        DB::table('omcp_live_console')->where('instance_id', $id)->update(['captured_at' => now()->subSeconds(21)]);
        $this->assertSame('', $live->read($owner->id, $id)['output']);
        $live->capture($node->id, $hash, $payload);
        DB::table('omcp_connections')->where('node_id', $node->id)->update(['credential_hash' => hash('sha256', random_bytes(32))]);
        $this->assertSame('waiting', $live->read($owner->id, $id)['state']);
        $controls->grant($node->id, $id, false);
        $this->assertDatabaseCount('omcp_live_console', 0);
        $this->denied(404, fn () => $live->read($owner->id, $id));
        $controls->grant($node->id, $id, true);
        $second = $live->read($owner->id, $id);
        $this->assertNotSame($first['generation'], $second['generation']);
        $this->assertSame('', $second['output']);
        $live->capture($node->id, $hash, $payload);
        $this->assertDatabaseCount('omcp_live_console', 0);
        DB::table('omcp_connections')->where('node_id', $node->id)->update(['capabilities' => json_encode(array_replace($caps, ['fleet_live_console' => false]))]);
        $this->denied(403, fn () => $live->read($owner->id, $id));
    }
}
