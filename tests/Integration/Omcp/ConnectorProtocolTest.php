<?php

namespace Pterodactyl\Tests\Integration\Omcp;

use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Omcp\EnrollmentService;
use Pterodactyl\Services\Omcp\JobService;

class ConnectorProtocolTest extends OmcpIntegrationTestCase
{
    private function node(): Node
    {
        return Node::factory()->create(['location_id' => Location::factory()->create()->id]);
    }

    public function testEnrollmentIsSingleUseAndCredentialIsNodeScoped(): void
    {
        $node = $this->node();
        $other = $this->node();
        $service = app(EnrollmentService::class);
        $token = $service->issue($node->id);
        $this->assertNotNull($token);
        $response = $this->withToken($token)->postJson('/api/omcp/v1/enroll', ['node_id' => $node->id]);
        $response->assertOk()->assertJsonStructure(['credential']);
        $credential = $response->json('credential');
        $this->assertDatabaseHas('omcp_connections', [
            'node_id' => $node->id,
            'enrollment_hash' => null,
            'credential_hash' => hash('sha256', $credential),
        ]);
        $this->withToken($token)->postJson('/api/omcp/v1/enroll', ['node_id' => $node->id])->assertUnauthorized();
        $this->withToken($credential)->postJson('/api/omcp/v1/jobs/claim', ['node_id' => $other->id])->assertUnauthorized();
        $this->withToken($credential)->postJson('/api/omcp/v1/jobs/claim', ['node_id' => $node->id])->assertOk()->assertJson(['job' => null]);
    }

    public function testHeartbeatIsNodeScopedAndRotationClearsTelemetry(): void
    {
        $node = $this->node();
        $other = $this->node();
        $service = app(EnrollmentService::class);
        $token = $service->issue($node->id);
        $credential = $this->withToken($token)->postJson('/api/omcp/v1/enroll', ['node_id' => $node->id])->assertOk()->json('credential');
        $payload = [
            'node_id' => $node->id,
            'capabilities' => [
                'os' => 'linux', 'arch' => 'amd64', 'cpus' => 4, 'root' => false,
                'docker_cli' => false, 'systemd_cli' => false, 'java_cli' => true,
                'native_enabled' => true, 'native_provision' => true,
            ],
            'telemetry' => [
                'console' => 'Smoke test output',
                'metrics' => ['process_running' => true, 'java_heap_limit_mib' => 1024, 'cpu_host_percent' => 12.5],
            ],
        ];
        $this->withToken($credential)->postJson('/api/omcp/v1/heartbeat', array_replace($payload, ['node_id' => $other->id]))->assertUnauthorized();
        $this->withToken($credential)->postJson('/api/omcp/v1/heartbeat', $payload)->assertOk()->assertJson(['ok' => true]);
        $record = DB::table('omcp_connections')->where('node_id', $node->id)->first();
        $this->assertNotNull($record->last_seen_at);
        $this->assertSame('Smoke test output', json_decode($record->telemetry, true)['console']);

        $invalid = $payload;
        $invalid['capabilities']['fleet_files'] = 'invalid';
        $this->withToken($credential)->postJson('/api/omcp/v1/heartbeat', $invalid)->assertStatus(422);

        $service->issue($node->id, true);
        $this->assertDatabaseHas('omcp_connections', [
            'node_id' => $node->id, 'credential_hash' => null, 'telemetry' => null, 'last_seen_at' => null,
        ]);
        $this->withToken($credential)->postJson('/api/omcp/v1/heartbeat', $payload)->assertUnauthorized();
    }

    public function testExpiredEnrollmentFails(): void
    {
        $node = $this->node();
        $token = app(EnrollmentService::class)->issue($node->id);
        DB::table('omcp_connections')->where('node_id', $node->id)->update(['enrollment_expires_at' => now()->subSecond()]);
        $this->withToken($token)->postJson('/api/omcp/v1/enroll', ['node_id' => $node->id])->assertUnauthorized();
    }

    public function testJobRequiresLeaseAndRotationRevokesPendingJobs(): void
    {
        $node = $this->node();
        $service = app(EnrollmentService::class);
        $token = $service->issue($node->id);
        $credential = $this->withToken($token)->postJson('/api/omcp/v1/enroll', ['node_id' => $node->id])->assertOk()->json('credential');
        $jobs = app(JobService::class);
        $id = $jobs->enqueueDiagnostic($node->id);
        $job = $this->withToken($credential)->postJson('/api/omcp/v1/jobs/claim', ['node_id' => $node->id])->assertOk()->json('job');
        $this->assertSame($id, $job['id']);
        $body = ['node_id' => $node->id, 'id' => $id, 'lease' => str_repeat('a', 64), 'success' => true, 'result' => 'diagnostic'];
        $this->withToken($credential)->postJson('/api/omcp/v1/jobs/complete', $body)->assertStatus(409);
        $body['lease'] = $job['lease'];
        $this->withToken($credential)->postJson('/api/omcp/v1/jobs/complete', $body)->assertOk();
        $this->withToken($credential)->postJson('/api/omcp/v1/jobs/complete', $body)->assertStatus(409);
        $queued = $jobs->enqueueDiagnostic($node->id);
        $service->issue($node->id, true);
        $this->assertDatabaseHas('omcp_jobs', ['id' => $queued, 'state' => 'expired']);
        $this->withToken($credential)->postJson('/api/omcp/v1/jobs/claim', ['node_id' => $node->id])->assertUnauthorized();
    }

    public function testRenewableLeaseIsNodeScopedAndRetryAcknowledgesOnlyTheSameResult(): void
    {
        $node = $this->node();
        $other = $this->node();
        $token = app(EnrollmentService::class)->issue($node->id);
        $credential = $this->withToken($token)->postJson('/api/omcp/v1/enroll', ['node_id' => $node->id])->assertOk()->json('credential');
        $jobs = app(JobService::class);
        $jobs->enqueueDiagnostic($node->id);
        $claim = ['node_id' => $node->id, 'renewable' => true];
        $job = $this->withToken($credential)->postJson('/api/omcp/v1/jobs/claim', $claim)->assertOk()->json('job');
        $this->assertSame(60, $job['lease_seconds']);
        $jobs->enqueueDiagnostic($node->id);
        $this->postJson('/api/omcp/v1/jobs/claim', $claim)->assertOk()->assertJson(['job' => null]);
        $body = ['node_id' => $node->id, 'id' => $job['id'], 'lease' => $job['lease']];
        $this->postJson('/api/omcp/v1/jobs/renew', array_replace($body, ['node_id' => $other->id]))->assertUnauthorized();
        $this->postJson('/api/omcp/v1/jobs/renew', array_replace($body, ['lease' => str_repeat('0', 64)]))->assertConflict();
        $this->travel(40)->seconds();
        $this->postJson('/api/omcp/v1/jobs/renew', $body)->assertOk()->assertJson(['lease_seconds' => 60]);
        $this->travel(45)->seconds();
        $this->postJson('/api/omcp/v1/jobs/renew', $body)->assertOk()->assertJson(['lease_seconds' => 35]);
        $complete = $body + ['success' => true, 'result' => 'done'];
        $this->postJson('/api/omcp/v1/jobs/complete', $complete)->assertOk();
        $this->postJson('/api/omcp/v1/jobs/complete', $complete)->assertOk();
        $this->postJson('/api/omcp/v1/jobs/complete', array_replace($complete, ['result' => 'changed']))->assertConflict();
        $this->postJson('/api/omcp/v1/jobs/complete', array_replace($complete, ['success' => false]))->assertConflict();
        $this->postJson('/api/omcp/v1/jobs/renew', $body)->assertConflict();
    }

    public function testLostWorkerExpiresWithoutRedispatchingItsAction(): void
    {
        $node = $this->node();
        $token = app(EnrollmentService::class)->issue($node->id);
        $credential = $this->withToken($token)->postJson('/api/omcp/v1/enroll', ['node_id' => $node->id])->assertOk()->json('credential');
        $id = app(JobService::class)->enqueueDiagnostic($node->id);
        $claim = ['node_id' => $node->id, 'renewable' => true];
        $job = $this->withToken($credential)->postJson('/api/omcp/v1/jobs/claim', $claim)->assertOk()->json('job');
        $lease = ['node_id' => $node->id, 'id' => $id, 'lease' => $job['lease']];
        $this->travel(61)->seconds();
        $this->postJson('/api/omcp/v1/jobs/renew', $lease)->assertConflict();
        $this->postJson('/api/omcp/v1/jobs/complete', $lease + ['success' => true, 'result' => 'late'])->assertConflict();
        $this->postJson('/api/omcp/v1/jobs/claim', $claim)->assertOk()->assertJson(['job' => null]);
        $this->assertDatabaseHas('omcp_jobs', ['id' => $id, 'state' => 'expired', 'lease_hash' => null]);
    }
}
