<?php

namespace Pterodactyl\Tests\Integration\Omcp;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Omcp\EnrollmentService;
use Pterodactyl\Services\Omcp\JobService;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

class ConnectorProtocolTest extends IntegrationTestCase
{
    use DatabaseTransactions;

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
}
