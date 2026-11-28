<?php

namespace Pterodactyl\Http\Controllers\Api\Omcp;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Http\Controllers\Controller;

class ConnectionController extends Controller
{
    public function enroll(Request $request): JsonResponse
    {
        $data = $request->validate(['node_id' => 'required|integer|min:1']);
        $token = $this->token($request);
        $credential = bin2hex(random_bytes(32));

        // One atomic conditional write consumes the token; simultaneous replay cannot succeed.
        $updated = DB::table('omcp_connections')
            ->where('node_id', $data['node_id'])
            ->where('enrollment_hash', hash('sha256', $token))
            ->where('enrollment_expires_at', '>', now())
            ->whereNull('credential_hash')
            ->update([
                'enrollment_hash' => null,
                'enrollment_expires_at' => null,
                'credential_hash' => hash('sha256', $credential),
                'updated_at' => now(),
            ]);

        abort_unless($updated === 1, 401, 'Invalid or expired enrollment.');
        return response()->json(['credential' => $credential])->header('Cache-Control', 'no-store');
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $token = $this->token($request);
        $data = $request->validate([
            'node_id' => 'required|integer|min:1',
            'runtime' => 'sometimes|in:none,native,podman,docker',
            'capabilities' => 'required|array:os,arch,cpus,root,docker_cli,systemd_cli,java_cli,native_enabled,native_provision,native_console_input,fleet_enabled,fleet_provision,fleet_files,fleet_console_input,fleet_retire',
            'capabilities.os' => 'required|string|max:32',
            'capabilities.arch' => 'required|string|max:32',
            'capabilities.cpus' => 'required|integer|min:1|max:65536',
            'capabilities.root' => 'required|boolean',
            'capabilities.docker_cli' => 'required|boolean',
            'capabilities.systemd_cli' => 'required|boolean',
            'capabilities.java_cli' => 'required|boolean',
            'capabilities.native_enabled' => 'sometimes|boolean',
            'capabilities.native_provision' => 'sometimes|boolean',
            'capabilities.native_console_input' => 'sometimes|boolean',
            'capabilities.fleet_enabled' => 'sometimes|boolean',
            'capabilities.fleet_provision' => 'sometimes|boolean',
            'capabilities.fleet_files' => 'sometimes|boolean',
            'capabilities.fleet_console_input' => 'sometimes|boolean',
            'capabilities.fleet_retire' => 'sometimes|boolean',
            'telemetry' => 'sometimes|array:console,metrics',
            'telemetry.console' => 'required_with:telemetry|string|max:3500',
            'telemetry.metrics' => 'required_with:telemetry|array:process_running,java_heap_limit_mib,filesystem_available_bytes,filesystem_capacity_bytes,java_rss_bytes,cpu_host_percent',
            'telemetry.metrics.process_running' => 'required_with:telemetry|boolean',
            'telemetry.metrics.java_heap_limit_mib' => 'required_with:telemetry|integer|min:128|max:1048576',
            'telemetry.metrics.filesystem_available_bytes' => 'sometimes|integer|min:0',
            'telemetry.metrics.filesystem_capacity_bytes' => 'sometimes|integer|min:0',
            'telemetry.metrics.java_rss_bytes' => 'sometimes|integer|min:0',
            'telemetry.metrics.cpu_host_percent' => 'sometimes|numeric|min:0|max:100',
        ]);

        $hash = hash('sha256', $token);
        $authenticated = DB::transaction(function () use ($data, $hash) {
            $query = DB::table('omcp_connections')->where('node_id', $data['node_id']);
            $record = (clone $query)->lockForUpdate()->first();
            if (!$record || !$record->credential_hash || !hash_equals($record->credential_hash, $hash)) {
                return false;
            }
            $query->update([
                'last_seen_at' => now(),
                'telemetry' => !empty($data['capabilities']['native_enabled']) && isset($data['telemetry']) ? json_encode($data['telemetry'], JSON_THROW_ON_ERROR) : null,
                'capabilities' => json_encode($data['capabilities'] + array_intersect_key($data, ['runtime' => true]), JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            return true;
        });
        abort_unless($authenticated, 401, 'Invalid connector credential.');
        return response()->json(['ok' => true, 'heartbeat_interval' => 5])->header('Cache-Control', 'no-store');
    }

    private function token(Request $request): string
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && preg_match('/\A[0-9a-f]{64}\z/D', $token), 401, 'Invalid credential.');
        return $token;
    }
}
