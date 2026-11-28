<?php

namespace Pterodactyl\Services\Omcp;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class JobService
{
    public function enqueueDiagnostic(int $node): string
    {
        return $this->enqueue($node, 'diagnostic');
    }

    public function enqueue(int $node, string $action, array $payload = []): string
    {
        abort_unless(in_array($action, ['diagnostic', 'native.start', 'native.stop', 'native.restart', 'native.kill', 'native.status', 'native.console', 'native.install', 'native.metrics', 'native.command', 'instance.install', 'instance.start', 'instance.stop', 'instance.restart', 'instance.kill', 'instance.status', 'instance.console', 'instance.metrics', 'instance.files', 'instance.file_read', 'instance.file_write', 'instance.file_delete', 'instance.property', 'instance.command', 'instance.retire'], true), 422, 'Unsupported action.');
        return DB::transaction(function () use ($node, $action, $payload) {
            $connection = DB::table('omcp_connections')->where('node_id', $node)->lockForUpdate()->first();
            abort_unless($connection && $connection->credential_hash, 409, 'Connector not enrolled.');
            if ($action !== 'diagnostic') {
                abort_unless($connection->last_seen_at && \Illuminate\Support\Carbon::parse($connection->last_seen_at)->gt(now()->subSeconds(90)), 409, 'Connector offline.');
                $capabilities = json_decode($connection->capabilities ?? '{}', true) ?: [];
                if (str_starts_with($action, 'instance.')) {
                    abort_unless(!empty($capabilities['fleet_enabled']), 409, 'Connector has not enabled trusted fleet mode.');
                    if (in_array($action, ['instance.files', 'instance.file_read', 'instance.file_write', 'instance.file_delete', 'instance.property'], true)) {
                        abort_unless(!empty($capabilities['fleet_files']), 409, 'Local file manager opt-in required.');
                    }
                    if ($action === 'instance.retire') {
                        abort_unless(!empty($capabilities['fleet_retire']) && in_array($capabilities['runtime'] ?? null, ['podman', 'docker'], true), 409, 'An updated container fleet connector is required for retirement.');
                    }
                    if ($action === 'instance.command') {
                        abort_unless(!empty($capabilities['fleet_console_input']), 409, 'Local fleet console input opt-in required.');
                    }
                    if ($action === 'instance.install') {
                        abort_unless(!empty($capabilities['fleet_provision']), 409, 'Local fleet provisioning opt-in required.');
                    }
                } else {
                    abort_unless(!empty($capabilities['native_enabled']), 409, 'Connector has not enabled trusted native mode.');
                }
                if ($action === 'native.install') {
                    abort_unless(!empty($capabilities['native_provision']), 409, 'Local provisioning opt-in required.');
                }
                if ($action === 'native.command') {
                    abort_unless(!empty($capabilities['native_console_input']), 409, 'Local console input opt-in required.');
                }
                $busy = DB::table('omcp_jobs')->where('node_id', $node)->where(function ($query) { $query->where('action', 'like', 'native.%')->orWhere('action', 'like', 'instance.%'); })
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
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'state' => 'queued',
                'expires_at' => $action === 'diagnostic' ? now()->addMinutes(5) : now()->addSeconds(90),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return $id;
        });
    }

    public function claim(int $node, string $credential, bool $renewable = false): ?array
    {
        return DB::transaction(function () use ($node, $credential, $renewable) {
            $this->authenticate($node, $credential);
            DB::table('omcp_jobs')->where('node_id', $node)
                ->whereIn('state', ['queued', 'running'])->where('expires_at', '<=', now())
                ->update(['state' => 'expired', 'lease_hash' => null, 'updated_at' => now()]);
            if (DB::table('omcp_jobs')->where('node_id', $node)->where('state', 'running')->exists()) {
                return null;
            }
            $job = DB::table('omcp_jobs')->where('node_id', $node)
                ->where('state', 'queued')->where('expires_at', '>', now())
                ->orderBy('created_at')->orderBy('id')->lockForUpdate()->first();
            if (!$job) {
                return null;
            }
            if ($job->control_grant_id) {
                $instance = DB::table('omcp_instances')->where('id', $job->instance_id)->where('node_id', $node)
                    ->where('control_grant_id', $job->control_grant_id)->whereNotNull('owner_id')
                    ->where('owner_id', $job->requester_id)->where('control_owner_id', $job->requester_id)
                    ->whereNull('retired_at')->whereNull('retirement_job_id')->first();
                $caps = json_decode(DB::table('omcp_connections')->where('node_id', $node)->value('capabilities') ?? '{}', true);
                if (!$instance || !$job->requester_id || empty($caps['fleet_owner_controls']) || ($caps['runtime'] ?? null) !== $instance->runtime) {
                    DB::table('omcp_jobs')->where('id', $job->id)->update(['state' => 'expired', 'lease_hash' => null, 'updated_at' => now()]);
                    return null;
                }
            }
            $lease = bin2hex(random_bytes(32));
            $installation = in_array($job->action, ['native.install', 'instance.install'], true);
            $now = now();
            $deadline = $now->copy()->addMinutes($installation ? 20 : 2);
            $expires = $renewable ? $now->copy()->addSeconds(60) : $deadline;
            DB::table('omcp_jobs')->where('id', $job->id)->update([
                'state' => 'running',
                'lease_hash' => hash('sha256', $lease),
                'expires_at' => $expires,
                'deadline_at' => $renewable ? $deadline : null,
                'updated_at' => now(),
            ]);
            $response = ['id' => $job->id, 'action' => $job->action, 'lease' => $lease, 'payload' => json_decode($job->payload ?? '{}', true) ?: (object) []];
            return $renewable ? $response + ['lease_seconds' => 60] : $response;
        });
    }

    public function renew(int $node, string $credential, string $id, string $lease): int
    {
        return DB::transaction(function () use ($node, $credential, $id, $lease) {
            $this->authenticate($node, $credential);
            $job = DB::table('omcp_jobs')->where('node_id', $node)->where('id', $id)->lockForUpdate()->first();
            $now = now();
            abort_unless($job && $job->deadline_at && $this->ownsLease($job, $lease, $now), 409, 'Job lease is invalid or expired.');
            $ttl = min(60, Carbon::parse($job->deadline_at)->timestamp - $now->timestamp);
            abort_if($ttl <= 0, 409, 'Job deadline reached.');
            DB::table('omcp_jobs')->where('id', $id)->update([
                'expires_at' => $now->copy()->addSeconds($ttl),
                'updated_at' => $now,
            ]);
            return $ttl;
        });
    }

    public function complete(int $node, string $credential, array $data): void
    {
        DB::transaction(function () use ($node, $credential, $data) {
            $this->authenticate($node, $credential);
            $job = DB::table('omcp_jobs')->where('node_id', $node)->where('id', $data['id'])->lockForUpdate()->first();
            $state = $data['success'] ? 'succeeded' : 'failed';
            // A terminal receipt authenticates only an identical acknowledgement retry.
            $receipt = hash_hmac('sha256', 'completed:' . $data['lease'], $credential);
            if ($job && $job->deadline_at && $job->state === $state && $job->result === $data['result']
                && hash_equals($job->lease_hash ?? '', $receipt)) {
                return;
            }

            abort_unless($job && $this->ownsLease($job, $data['lease'], now()), 409, 'Job lease is invalid or expired.');
            DB::table('omcp_jobs')->where('id', $job->id)->update([
                'state' => $state,
                'result' => $data['result'],
                'lease_hash' => $job->deadline_at ? $receipt : null,
                'updated_at' => now(),
            ]);
        });
    }

    private function ownsLease(object $job, string $lease, Carbon $now): bool
    {
        return $job->state === 'running' && Carbon::parse($job->expires_at)->gt($now)
            && hash_equals($job->lease_hash ?? '', hash('sha256', $lease));
    }

    private function authenticate(int $node, string $credential): void
    {
        $record = DB::table('omcp_connections')->where('node_id', $node)->lockForUpdate()->first();
        abort_unless($record && $record->credential_hash && hash_equals($record->credential_hash, hash('sha256', $credential)), 401, 'Invalid connector credential.');
    }
}
