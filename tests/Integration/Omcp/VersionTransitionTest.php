<?php

namespace Pterodactyl\Tests\Integration\Omcp;

use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Omcp\InstanceRegistry;
use Pterodactyl\Services\Omcp\JobService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VersionTransitionTest extends OmcpIntegrationTestCase
{
    private function denied(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Unsafe transition accepted.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public function testVersionChangesRequireConsistentReceiptAndBlockStartUntilFinalized(): void
    {
        $node = Node::factory()->create(['location_id' => Location::factory()->create()->id, 'memory' => 2048, 'disk' => 8192]);
        $owner = User::factory()->create();
        $credential = bin2hex(random_bytes(32));
        DB::table('omcp_connections')->insert(['node_id' => $node->id, 'credential_hash' => hash('sha256', $credential),
            'last_seen_at' => now(), 'capabilities' => json_encode(['fleet_enabled' => true, 'runtime' => 'docker', 'fleet_versions' => true]),
            'created_at' => now(), 'updated_at' => now()]);
        $registry = app(InstanceRegistry::class);
        $jobs = app(JobService::class);
        $id = $registry->reserve($node->id, ['owner_id' => $owner->id, 'name' => 'Version fixture', 'runtime' => 'docker',
            'software' => 'paper', 'version' => '1.21.4', 'memory_mib' => 1024, 'disk_mib' => 4096, 'port' => 25565]);
        $this->denied(422, fn () => $registry->dispatch($node->id, $id, 'version_change', true, $jobs, ['target_version' => '1.20.1']));
        $this->denied(422, fn () => $registry->dispatch($node->id, $id, 'version_change', false, $jobs, ['target_version' => '1.21.5']));
        $job = $registry->dispatch($node->id, $id, 'version_change', true, $jobs, ['target_version' => '1.21.5']);
        $this->assertDatabaseHas('omcp_instances', ['id' => $id, 'version' => '1.21.4', 'version_job_id' => $job]);
        $this->denied(409, fn () => $registry->dispatch($node->id, $id, 'start', false, $jobs));
        $this->denied(409, fn () => $registry->finalizeVersion($node->id, $id));
        $claim = $jobs->claim($node->id, $credential, true);
        $jobs->complete($node->id, $credential, ['id' => $job, 'lease' => $claim['lease'], 'success' => true, 'result' => 'version:paper:1.20.1:' . str_repeat('a', 64)]);
        $this->denied(409, fn () => $registry->finalizeVersion($node->id, $id));
        $inspect = $registry->dispatch($node->id, $id, 'version_inspect', false, $jobs);
        $claim = $jobs->claim($node->id, $credential, true);
        $this->assertSame($inspect, $claim['id']);
        $this->assertSame('1.21.5', $claim['payload']['target_version']);
        $jobs->complete($node->id, $credential, ['id' => $inspect, 'lease' => $claim['lease'], 'success' => true, 'result' => 'version:paper:1.21.5:' . str_repeat('a', 64)]);
        $this->assertSame('1.21.5', $registry->finalizeVersion($node->id, $id));
        $this->assertDatabaseHas('omcp_instances', ['id' => $id, 'version' => '1.21.5', 'version_job_id' => null, 'version_target' => null]);
        $start = $registry->dispatch($node->id, $id, 'start', false, $jobs);
        $this->assertSame($start, $jobs->claim($node->id, $credential, true)['id']);
    }
}
