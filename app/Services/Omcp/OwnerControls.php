<?php

namespace Pterodactyl\Services\Omcp;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pterodactyl\Models\Node;

class OwnerControls
{
    public function grant(int $node, string $id, bool $enabled): void
    {
        DB::transaction(function () use ($node, $id, $enabled) {
            Node::query()->lockForUpdate()->findOrFail($node);
            $instance = DB::table('omcp_instances')->where('node_id', $node)->where('id', $id)->lockForUpdate()->first();
            abort_unless($instance && !$instance->retired_at, 404);
            // Serialize revocation against job claim (same node connection lock).
            $connection = DB::table('omcp_connections')->where('node_id', $node)->lockForUpdate()->first();
            if ($enabled) {
                abort_unless($instance->owner_id && !$instance->retirement_job_id && in_array($instance->runtime, ['docker', 'podman'], true), 409, 'An active container reservation with an owner is required.');
                $caps = json_decode($connection->capabilities ?? '{}', true);
                abort_unless(!empty($caps['fleet_owner_controls']) && ($caps['runtime'] ?? null) === $instance->runtime && $connection->last_seen_at && Carbon::parse($connection->last_seen_at)->gt(now()->subSeconds(90)), 409, 'Enable trusted owner controls on the matching online connector first.');
                if ($instance->control_grant_id && (int) $instance->control_owner_id === (int) $instance->owner_id) {
                    return;
                }
            }
            DB::table('omcp_jobs')->where('node_id', $node)->where('instance_id', $id)
                ->whereNotNull('control_grant_id')->where('state', 'queued')
                ->update(['state' => 'expired', 'lease_hash' => null, 'updated_at' => now()]);
            DB::table('omcp_instances')->where('id', $id)->update([
                'control_owner_id' => $enabled ? $instance->owner_id : null,
                'control_grant_id' => $enabled ? (string) Str::uuid() : null,
                'updated_at' => now(),
            ]);
        });
    }

    public function result(int $owner, string $instance, string $job): array
    {
        $record = DB::table('omcp_jobs as j')->join('omcp_instances as i', function ($join) {
            $join->on('i.id', '=', 'j.instance_id')->on('i.node_id', '=', 'j.node_id')
                ->on('i.control_grant_id', '=', 'j.control_grant_id');
        })->where('j.id', $job)->where('i.id', $instance)->where('j.requester_id', $owner)
            ->where('i.owner_id', $owner)->where('i.control_owner_id', $owner)
            ->whereNull('i.retired_at')->whereNull('i.retirement_job_id')
            ->select(['j.action', 'j.state', 'j.result', 'j.expires_at'])->first();
        abort_unless($record, 404);
        $state = $record->state;
        if (in_array($state, ['queued', 'running'], true) && Carbon::parse($record->expires_at)->lte(now())) {
            $state = 'expired';
        }
        // Do not expose host-side errors, payloads, credentials or lease data.
        return ['state' => $state, 'result' => $state === 'succeeded' ? $record->result : null];
    }
}
