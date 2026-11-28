<?php

namespace Pterodactyl\Tests\Integration\Omcp;

use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Omcp\InstanceRegistry;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InstanceRegistryTest extends OmcpIntegrationTestCase
{
    private function node(): Node
    {
        $node = Node::factory()->create(['location_id' => Location::factory()->create()->id, 'memory' => 2048, 'disk' => 8192]);
        DB::table('omcp_connections')->insert(['node_id' => $node->id, 'credential_hash' => hash('sha256', random_bytes(32)), 'created_at' => now(), 'updated_at' => now()]);
        return $node;
    }

    private function input(User $owner, int $port = 25565): array
    {
        return ['owner_id' => $owner->id, 'name' => 'Minecraft test', 'software' => 'paper', 'version' => '1.21.4', 'port' => $port, 'memory_mib' => 1024, 'disk_mib' => 4096];
    }

    public function testReservationsAreScopedToOwnerAndReleaseDoesNotTouchOtherNodes(): void
    {
        $node = $this->node();
        $other = $this->node();
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $registry = app(InstanceRegistry::class);
        $id = $registry->reserve($node->id, $this->input($owner));
        $this->assertSame([$id], $registry->forOwner($owner->id)->pluck('id')->all());
        $this->assertCount(0, $registry->forOwner($stranger->id));
        try {
            $registry->release($other->id, $id);
            $this->fail('Cross-node release accepted.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertDatabaseHas('omcp_instances', ['id' => $id]);
        $registry->release($node->id, $id);
        $this->assertDatabaseMissing('omcp_instances', ['id' => $id]);
    }

    public function testFleetJobsUseStoredInstanceAllocationAndPreventPrematureRelease(): void
    {
        $node = $this->node();
        $owner = User::factory()->create();
        $registry = app(InstanceRegistry::class);
        $jobs = app(\Pterodactyl\Services\Omcp\JobService::class);
        $id = $registry->reserve($node->id, $this->input($owner));
        DB::table('omcp_connections')->where('node_id', $node->id)->update([
            'last_seen_at' => now(),
            'capabilities' => json_encode(['fleet_enabled' => true, 'fleet_provision' => true]),
        ]);

        $job = $registry->dispatch($node->id, $id, 'install', true, $jobs);
        $record = DB::table('omcp_jobs')->where('id', $job)->first();
        $this->assertSame('instance.install', $record->action);
        $this->assertSame($node->id, (int) $record->node_id);
        $payload = json_decode($record->payload, true);
        $this->assertSame($id, $payload['instance_id']);
        $this->assertSame(25565, $payload['port']);
        $this->assertSame(1024, $payload['memory_mib']);
        $this->assertTrue($payload['accept_eula']);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Runtime was requested. Manual decommission is required before release.');
        $registry->release($node->id, $id);
    }

    public function testDuplicatePortsAndCapacityOvercommitAreRejected(): void
    {
        $node = $this->node();
        $owner = User::factory()->create();
        $registry = app(InstanceRegistry::class);
        $registry->reserve($node->id, $this->input($owner));
        try {
            $registry->reserve($node->id, $this->input($owner));
            $this->fail('Duplicate port accepted.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $registry->reserve($node->id, $this->input($owner, 25566));
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Configured node RAM budget exceeded.');
        $registry->reserve($node->id, $this->input($owner, 25567));
    }

    public function testFleetConsoleRequiresLocalOptInAndPreservesInstanceScope(): void
    {
        $node = $this->node();
        $registry = app(InstanceRegistry::class);
        $id = $registry->reserve($node->id, $this->input(User::factory()->create()) + ['runtime' => 'podman']);
        $connection = DB::table('omcp_connections')->where('node_id', $node->id);
        $jobs = app(\Pterodactyl\Services\Omcp\JobService::class);
        $connection->update(['last_seen_at' => now(), 'capabilities' => json_encode(['fleet_enabled' => true, 'runtime' => 'podman'])]);
        try {
            $registry->dispatch($node->id, $id, 'command', false, $jobs, ['command' => 'list']);
            $this->fail('Console action accepted without local opt-in.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $connection->update(['capabilities' => json_encode(['fleet_enabled' => true, 'runtime' => 'podman', 'fleet_console_input' => true])]);
        $job = $registry->dispatch($node->id, $id, 'command', false, $jobs, ['command' => 'list', 'instance_id' => 'forged', 'port' => 1234]);
        $row = DB::table('omcp_jobs')->where('id', $job)->first();
        $payload = json_decode($row->payload, true);
        $this->assertSame('instance.command', $row->action);
        $this->assertSame($id, $payload['instance_id']);
        $this->assertSame(25565, $payload['port']);
        $this->assertSame('list', $payload['command']);
        $this->assertFalse(\Pterodactyl\Services\Omcp\ConsoleCommand::allowed("say hi\nstop"));
        $this->assertFalse(\Pterodactyl\Services\Omcp\ConsoleCommand::allowed('op user'));
        $this->assertTrue(\Pterodactyl\Services\Omcp\ConsoleCommand::allowed('list'));
    }

    public function testContainerRetirementReleasesOnlyVerifiedRamAndPortButRetainsDisk(): void
    {
        $node = $this->node();
        $registry = app(InstanceRegistry::class);
        $jobs = app(\Pterodactyl\Services\Omcp\JobService::class);
        $owner = User::factory()->create();
        $id = $registry->reserve($node->id, $this->input($owner) + ['runtime' => 'docker']);
        $credential = bin2hex(random_bytes(32));
        DB::table('omcp_connections')->where('node_id', $node->id)->update([
            'credential_hash' => hash('sha256', $credential),
            'last_seen_at' => now(),
            'capabilities' => json_encode(['fleet_enabled' => true, 'runtime' => 'docker', 'fleet_retire' => true]),
        ]);
        try {
            $job = $registry->dispatch($node->id, $id, 'retire', false, $jobs);
            $this->assertSame($job, $registry->dispatch($node->id, $id, 'retire', false, $jobs));
            foreach (['release', 'start'] as $operation) {
                try {
                    if ($operation === 'release') {
                        $registry->release($node->id, $id);
                    } else {
                        $registry->dispatch($node->id, $id, 'start', false, $jobs);
                    }
                    $this->fail('Unverified retirement allowed ' . $operation);
                } catch (HttpException $e) {
                    $this->assertSame(409, $e->getStatusCode());
                }
            }
            $claim = $jobs->claim($node->id, $credential, true);
            $this->assertSame($job, $claim['id']);
            $jobs->complete($node->id, $credential, ['id' => $job, 'lease' => $claim['lease'], 'success' => true, 'result' => 'retired:wrong']);
            try {
                $registry->release($node->id, $id);
                $this->fail('Mismatched receipt allowed release.');
            } catch (HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
            $retry = $registry->dispatch($node->id, $id, 'retire', false, $jobs);
            $this->assertNotSame($job, $retry);
            $claim = $jobs->claim($node->id, $credential, true);
            $jobs->complete($node->id, $credential, ['id' => $retry, 'lease' => $claim['lease'], 'success' => true, 'result' => 'retired:' . $id]);
            $registry->release($node->id, $id);
            $registry->release($node->id, $id);
            $this->assertDatabaseHas('omcp_instances', ['id' => $id, 'port' => null, 'retired_port' => 25565, 'disk_mib' => 4096]);
            $this->assertNotNull(DB::table('omcp_instances')->where('id', $id)->value('retired_at'));
            $this->assertCount(0, $registry->forOwner($owner->id));
            $new = $registry->reserve($node->id, array_replace($this->input($owner), ['memory_mib' => 2048, 'runtime' => 'docker']));
            $this->assertNotSame($id, $new);
            try {
                $registry->reserve($node->id, array_replace($this->input($owner, 25566), ['memory_mib' => 128, 'disk_mib' => 512]));
                $this->fail('Retained disk reservation or RAM budget ignored.');
            } catch (HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
            // Isolate disk accounting from the RAM budget.
            $registry->release($node->id, $new);
            $registry->reserve($node->id, $this->input($owner));
            try {
                $registry->reserve($node->id, $this->input($owner, 25566));
                $this->fail('Retired disk budget ignored.');
            } catch (HttpException $e) {
                $this->assertStringContainsString('disk budget', $e->getMessage());
            }
        } finally {
            // This suite owns a guarded disposable database; remove test rows
            // before migration rollback, which intentionally refuses live archives.
            DB::table('omcp_instances')->where('node_id', $node->id)->delete();
        }
    }

    public function testRuntimeBoundReservationRejectsDowngradeAndOldConnectors(): void
    {
        $node = $this->node();
        $registry = app(InstanceRegistry::class);
        $id = $registry->reserve($node->id, $this->input(User::factory()->create()) + ['runtime' => 'podman']);
        $connection = DB::table('omcp_connections')->where('node_id', $node->id);
        foreach ([null, 'native', 'docker', 'podman'] as $reported) {
            $connection->update(['last_seen_at' => now(), 'capabilities' => json_encode(['fleet_enabled' => true, 'runtime' => $reported])]);
            try {
                $job = $registry->dispatch($node->id, $id, 'start', false, app(\Pterodactyl\Services\Omcp\JobService::class));
                $this->assertSame('podman', $reported);
                $payload = json_decode(DB::table('omcp_jobs')->where('id', $job)->value('payload'), true);
                $this->assertSame('podman', $payload['runtime']);
            } catch (HttpException $e) {
                $this->assertNotSame('podman', $reported);
                $this->assertSame(409, $e->getStatusCode());
                $this->assertDatabaseCount('omcp_jobs', 0);
            }
        }
    }
}
