<?php

// Disposable CI database only.
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (!$app->environment('testing') || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'omcp_test_ci') {
    throw new RuntimeException('Smoke fixtures require the isolated CI test database.');
}
$password = getenv('OMCP_SMOKE_PASSWORD');
if (!is_string($password) || strlen($password) < 24) {
    throw new RuntimeException('A generated smoke-test password is required.');
}
$admin = Pterodactyl\Models\User::factory()->admin()->create([
    'username' => 'omcp_ci_admin', 'email' => 'omcp-ci-admin@example.com', 'password' => bcrypt($password),
]);
// Separate test account prevents unrelated smoke phases from sharing
// the production per-user action rate limit.
Pterodactyl\Models\User::factory()->admin()->create([
    'username' => 'omcp_ci_connector_admin', 'email' => 'omcp-ci-connector@example.com', 'password' => bcrypt($password),
]);
$owner = Pterodactyl\Models\User::factory()->create([
    'username' => 'omcp_ci_owner', 'email' => 'omcp-ci-owner@example.com', 'password' => bcrypt($password),
]);
$node = Pterodactyl\Models\Node::factory()->create([
    'location_id' => Pterodactyl\Models\Location::factory()->create()->id,
    'name' => 'OMCP CI Node', 'memory' => 4096, 'disk' => 16384,
]);
app(Pterodactyl\Services\Omcp\EnrollmentService::class)->issue($node->id);
Illuminate\Support\Facades\DB::table('omcp_connections')->where('node_id', $node->id)->update([
    'enrollment_hash' => null, 'enrollment_expires_at' => null,
    'credential_hash' => hash('sha256', random_bytes(32)),
    'last_seen_at' => now(),
    'capabilities' => json_encode(['fleet_enabled' => true, 'runtime' => 'docker', 'fleet_owner_controls' => true]),
]);
$registry = app(Pterodactyl\Services\Omcp\InstanceRegistry::class);
$common = ['software' => 'paper', 'version' => '1.21.4', 'memory_mib' => 1024, 'disk_mib' => 4096];
$ownerId = $registry->reserve($node->id, $common + ['owner_id' => $owner->id, 'name' => 'Owner test instance', 'port' => 25565, 'runtime' => 'docker']);
$adminId = $registry->reserve($node->id, $common + ['owner_id' => $admin->id, 'name' => 'Admin test instance', 'port' => 25566]);
$connectorNode = Pterodactyl\Models\Node::factory()->create([
    'location_id' => $node->location_id, 'name' => 'OMCP CI Connector',
    'memory' => 1024, 'disk' => 4096,
]);
echo json_encode(['connector_node' => $connectorNode->id, 'node' => $node->id, 'owner_instance' => $ownerId, 'admin_instance' => $adminId], JSON_THROW_ON_ERROR);
