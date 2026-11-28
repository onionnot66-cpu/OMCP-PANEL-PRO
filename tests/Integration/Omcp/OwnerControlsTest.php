<?php

namespace Pterodactyl\Tests\Integration\Omcp;

use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Omcp\InstanceRegistry;
use Pterodactyl\Services\Omcp\JobService;
use Pterodactyl\Services\Omcp\OwnerControls;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OwnerControlsTest extends OmcpIntegrationTestCase
{
    private function denied(int $status, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unauthorized operation succeeded.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public function testOwnerGrantJobsAndRevocation(): void
    {
        $node = Node::factory()->create(['location_id' => Location::factory()->create()->id, 'memory' => 4096, 'disk' => 8192]);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $credential = bin2hex(random_bytes(32));
        $registry = app(InstanceRegistry::class);
        $jobs = app(JobService::class);
        $controls = app(OwnerControls::class);
        $caps = ['fleet_enabled' => true, 'runtime' => 'docker'];
        DB::table('omcp_connections')->insert(['node_id' => $node->id, 'credential_hash' => hash('sha256', $credential),
            'last_seen_at' => now(), 'capabilities' => json_encode($caps), 'created_at' => now(), 'updated_at' => now()]);
        $id = $registry->reserve($node->id, ['owner_id' => $owner->id, 'name' => 'Owner game', 'runtime' => 'docker',
            'software' => 'vanilla', 'version' => '1.21.4', 'port' => 25565, 'memory_mib' => 1024, 'disk_mib' => 4096]);
        $dispatch = fn ($user, $action = 'status') => $registry->dispatch($node->id, $id, $action, false, $jobs, [], $user->id);
        $this->denied(403, fn () => $dispatch($owner));
        $this->denied(404, fn () => $dispatch($other));
        $this->denied(409, fn () => $controls->grant($node->id, $id, true));
        DB::table('omcp_connections')->where('node_id', $node->id)->update(['capabilities' => json_encode($caps + ['fleet_owner_controls' => true])]);
        $controls->grant($node->id, $id, true);
        $this->denied(422, fn () => $dispatch($owner, 'retire'));
        $job = $dispatch($owner);
        $claim = $jobs->claim($node->id, $credential, true);
        $this->assertSame($job, $claim['id']);
        $this->assertTrue($claim['payload']['owner_control']);
        $jobs->complete($node->id, $credential, ['id' => $job, 'lease' => $claim['lease'], 'success' => true, 'result' => 'stopped']);
        $this->assertSame(['state' => 'succeeded', 'result' => 'stopped'], $controls->result($owner->id, $id, $job));
        $this->denied(404, fn () => $controls->result($other->id, $id, $job));
        $pending = $dispatch($owner, 'start');
        $controls->grant($node->id, $id, false);
        $this->assertDatabaseHas('omcp_jobs', ['id' => $pending, 'state' => 'expired']);
        $this->assertNull($jobs->claim($node->id, $credential, true));
        $this->denied(404, fn () => $controls->result($owner->id, $id, $job));
        $controls->grant($node->id, $id, true);
        $this->denied(404, fn () => $controls->result($owner->id, $id, $job));
        $adminJob = $registry->dispatch($node->id, $id, 'console', false, $jobs);
        $this->denied(404, fn () => $controls->result($owner->id, $id, $adminJob));
    }
}
