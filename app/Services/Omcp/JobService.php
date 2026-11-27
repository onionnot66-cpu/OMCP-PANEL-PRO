<?php

namespace Pterodactyl\Services\Omcp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class JobService
{
    public function enqueueDiagnostic(int $node): string
    {
        return $this->enqueue($node, 'diagnostic');
    }

    public function enqueue(int $node, string $action): string
    {
        abort_unless(in_array($action, ['diagnostic', 'native.start', 'native.stop', 'native.restart', 'native.kill', 'native.status', 'native.console'], true), 422, 'Unsupported action.');
        return DB::transaction(function () use ($node, $action) {
            $connection = DB::table('omcp_connections')->where('node_id', $node)->lockForUpdate()->first();
            abort_unless($connection && $connection->credential_hash, 409, 'Connector not enrolled.');
            if ($action !== 'diagnostic') {
                abort_unless($connection->last_seen_at && \Illuminate\Support\Carbon::parse($connection->last_seen_at)->gt(now()->subSeconds(90)), 409, 'Connector offline.');
                $busy = DB::table('omcp_jobs')->where('node_id', $node)->where('action', 'like', 'native.%')
                    ->whereIn('state', ['queued', 'running'])->where('expires_at', '>', now())->exists();
                abort_if($busy, 409, 'A native action is already pending.');
            }
            $pending = DB::table('omcp_jobs')->where('node_id', $node)
                ->whereIn('state', ['queued', 'running'])->where('expires_at', '>', now())->count();
            abort_if($pending >= 10, 429, 'Too many pending jobs.');
            $id = (string) Str::uuid();
            DB::table('omcp_jobs')->insert([
                'id' => $id,
                'node_id' => $node,
                'action' => $action,
                'state' => 'queued',
                'expires_at' => $action === 'diagnostic' ? now()->addMinutes(5) : now()->addSeconds(90),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return $id;
        });
    }

    public function claim(int $node, string $credential): ?array
    {
        return DB::transaction(function () use ($node, $credential) {
            $this->authenticate($node, $credential);
            DB::table('omcp_jobs')->where('node_id', $node)
                ->whereIn('state', ['queued', 'running'])->where('expires_at', '<=', now())
                ->update(['state' => 'expired', 'lease_hash' => null, 'updated_at' => now()]);
            $job = DB::table('omcp_jobs')->where('node_id', $node)
                ->where('state', 'queued')->where('expires_at', '>', now())
                ->orderBy('created_at')->orderBy('id')->lockForUpdate()->first();
            if (!$job) {
                return null;
            }
            $lease = bin2hex(random_bytes(32));
            DB::table('omcp_jobs')->where('id', $job->id)->update([
                'state' => 'running',
                'lease_hash' => hash('sha256', $lease),
                'expires_at' => now()->addMinutes(2),
                'updated_at' => now(),
            ]);
            return ['id' => $job->id, 'action' => $job->action, 'lease' => $lease];
        });
    }

    public function complete(int $node, string $credential, array $data): void
    {
        DB::transaction(function () use ($node, $credential, $data) {
            $this->authenticate($node, $credential);
            $updated = DB::table('omcp_jobs')->where('id', $data['id'])
                ->where('node_id', $node)->where('state', 'running')
                ->where('expires_at', '>', now())
                ->where('lease_hash', hash('sha256', $data['lease']))
                ->update([
                    'state' => $data['success'] ? 'succeeded' : 'failed',
                    'result' => $data['result'],
                    'lease_hash' => null,
                    'updated_at' => now(),
                ]);
            abort_unless($updated === 1, 409, 'Job lease is invalid or expired.');
        });
    }

    private function authenticate(int $node, string $credential): void
    {
        $record = DB::table('omcp_connections')->where('node_id', $node)->lockForUpdate()->first();
        abort_unless($record && $record->credential_hash && hash_equals($record->credential_hash, hash('sha256', $credential)), 401, 'Invalid connector credential.');
    }
}
