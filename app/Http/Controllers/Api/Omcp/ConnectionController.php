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
            'capabilities' => 'required|array:os,arch,cpus,root,docker_cli,systemd_cli,java_cli',
            'capabilities.os' => 'required|string|max:32',
            'capabilities.arch' => 'required|string|max:32',
            'capabilities.cpus' => 'required|integer|min:1|max:65536',
            'capabilities.root' => 'required|boolean',
            'capabilities.docker_cli' => 'required|boolean',
            'capabilities.systemd_cli' => 'required|boolean',
            'capabilities.java_cli' => 'required|boolean',
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
                'capabilities' => json_encode($data['capabilities'], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            return true;
        });
        abort_unless($authenticated, 401, 'Invalid connector credential.');
        return response()->json(['ok' => true, 'heartbeat_interval' => 30])->header('Cache-Control', 'no-store');
    }

    private function token(Request $request): string
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && preg_match('/\A[0-9a-f]{64}\z/D', $token), 401, 'Invalid credential.');
        return $token;
    }
}
