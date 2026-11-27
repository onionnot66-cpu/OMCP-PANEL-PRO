<?php

namespace Pterodactyl\Tests\Integration\Omcp;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Omcp\InstanceRegistry;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InstanceRegistryTest extends IntegrationTestCase
{
    use DatabaseTransactions;

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
}
