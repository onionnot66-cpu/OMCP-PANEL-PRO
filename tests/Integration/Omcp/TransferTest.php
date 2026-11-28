<?php

namespace Pterodactyl\Tests\Integration\Omcp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Omcp\InstanceRegistry;
use Pterodactyl\Services\Omcp\JobService;
use Pterodactyl\Services\Omcp\TransferService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TransferTest extends OmcpIntegrationTestCase
{
    private function denied(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Unsafe transfer accepted.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    private function stream(string $bytes)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);
        return $stream;
    }

    public function testBinaryTransfersAreLeaseScopedAndExpire(): void
    {
        $originalStorage = storage_path();
        $temp = sys_get_temp_dir() . '/omcp-transfer-test-' . bin2hex(random_bytes(12));
        mkdir($temp, 0700);
        app()->useStoragePath($temp);
        try {
            $node = Node::factory()->create(['location_id' => Location::factory()->create()->id, 'memory' => 2048, 'disk' => 8192]);
            $owner = User::factory()->create();
            $credential = bin2hex(random_bytes(32));
            DB::table('omcp_connections')->insert([
                'node_id' => $node->id, 'credential_hash' => hash('sha256', $credential), 'last_seen_at' => now(),
                'capabilities' => json_encode(['fleet_enabled' => true, 'runtime' => 'docker', 'fleet_worlds' => true]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $instance = app(InstanceRegistry::class)->reserve($node->id, [
                'owner_id' => $owner->id, 'name' => 'World fixture', 'runtime' => 'docker', 'software' => 'vanilla',
                'version' => '1.21.4', 'memory_mib' => 1024, 'disk_mib' => 4096, 'port' => 25565,
            ]);
            $transfers = app(TransferService::class);
            $jobs = app(JobService::class);
            // Binary content is deliberately not JSON or base64. The node, not
            // the panel spool, validates world ZIP structure before extraction.
            $bytes = "PK\x03\x04\x00\xfffixture";
            $sha = hash('sha256', $bytes);
            $stream = $this->stream($bytes);
            try {
                $id = $transfers->queue($node->id, $instance, 'import', ['world' => 'new-world'], $stream);
            } finally {
                fclose($stream);
            }
            $this->denied(403, fn () => $transfers->downloadImport($node->id, $credential, $id, str_repeat('a', 64)));
            $claim = $jobs->claim($node->id, $credential, true);
            $this->assertSame($id, $claim['id']);
            $this->assertSame(strlen($bytes), $claim['payload']['transfer_bytes']);
            $this->assertSame($sha, $claim['payload']['transfer_sha256']);
            $this->assertArrayNotHasKey('content_base64', $claim['payload']);
            $this->denied(401, fn () => $transfers->downloadImport($node->id, str_repeat('b', 64), $id, $claim['lease']));
            $response = $transfers->downloadImport($node->id, $credential, $id, $claim['lease']);
            $this->assertSame($sha, $response->headers->get('X-OMCP-SHA256'));
            $this->assertSame($bytes, file_get_contents($response->getFile()->getPathname()));
            $jobs->complete($node->id, $credential, ['id' => $id, 'lease' => $claim['lease'], 'success' => true, 'result' => 'imported']);
            $this->denied(403, fn () => $transfers->downloadImport($node->id, $credential, $id, $claim['lease']));
            $export = $transfers->queue($node->id, $instance, 'export', ['backup_id' => $id, 'backup_sha256' => $sha]);
            $claim = $jobs->claim($node->id, $credential, true);
            $this->assertSame($export, $claim['id']);
            $this->denied(404, fn () => $transfers->downloadExport($node->id, $instance, $export));
            $stream = $this->stream($bytes);
            try {
                $this->denied(422, fn () => $transfers->uploadExport($node->id, $credential, $export, $claim['lease'], strlen($bytes), str_repeat('0', 64), $stream));
                rewind($stream);
                $transfers->uploadExport($node->id, $credential, $export, $claim['lease'], strlen($bytes), $sha, $stream);
                rewind($stream);
                $transfers->uploadExport($node->id, $credential, $export, $claim['lease'], strlen($bytes), $sha, $stream);
            } finally {
                fclose($stream);
            }
            $jobs->complete($node->id, $credential, ['id' => $export, 'lease' => $claim['lease'], 'success' => true, 'result' => 'exported']);
            $this->denied(404, fn () => $transfers->downloadExport($node->id, $id, $export));
            $this->assertSame($bytes, file_get_contents($transfers->downloadExport($node->id, $instance, $export)->getFile()->getPathname()));
            DB::table('omcp_transfers')->update(['expires_at' => now()->subSecond()]);
            $this->denied(404, fn () => $transfers->downloadExport($node->id, $instance, $export));
            $this->assertSame(2, $transfers->prune());
            $this->assertDatabaseCount('omcp_transfers', 0);
            $this->assertSame([], glob($temp . '/app/omcp-transfers/*.zip') ?: []);
        } finally {
            app()->useStoragePath($originalStorage);
            File::deleteDirectory($temp);
        }
    }
}
