<?php

namespace Pterodactyl\Services\Omcp;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LiveConsole
{
    // Called under the authenticated connection row lock. Grant changes use
    // the same lock, so snapshots belong to one explicit owner generation.
    public function capture(int $node, string $credentialHash, array $data): void
    {
        $caps = $data['capabilities'];
        $runtime = $data['runtime'] ?? null;
        $rows = DB::table('omcp_instances')->where('node_id', $node)->get([
            'id', 'runtime', 'owner_id', 'control_owner_id', 'control_grant_id', 'retired_at', 'retirement_job_id',
        ]);
        if (empty($caps['fleet_live_console']) || empty($caps['fleet_owner_controls']) || !in_array($runtime, ['docker', 'podman'], true)) {
            DB::table('omcp_live_console')->whereIn('instance_id', $rows->pluck('id'))->delete();
            return;
        }
        $snapshots = array_column($data['fleet_console'] ?? [], null, 'instance_id');
        $allowed = [];
        foreach ($rows as $row) {
            if (!$row->owner_id || (int) $row->owner_id !== (int) $row->control_owner_id || !$row->control_grant_id
                || $row->retired_at || $row->retirement_job_id || $row->runtime !== $runtime || ($snapshots[$row->id]['generation'] ?? null) !== $row->control_grant_id) {
                continue;
            }
            $allowed[] = $row->id;
            DB::table('omcp_live_console')->updateOrInsert(['instance_id' => $row->id], [
                'grant_id' => $row->control_grant_id, 'credential_hash' => $credentialHash,
                'output' => $snapshots[$row->id]['output'], 'captured_at' => now(),
            ]);
        }
        DB::table('omcp_live_console')->whereIn('instance_id', $rows->pluck('id'))->whereNotIn('instance_id', $allowed)->delete();
    }

    public function grants(int $node, array $data): array
    {
        if (empty($data['capabilities']['fleet_live_console']) || empty($data['capabilities']['fleet_owner_controls'])
            || !in_array($data['runtime'] ?? null, ['docker', 'podman'], true)) {
            return [];
        }
        return DB::table('omcp_instances')->where('node_id', $node)->where('runtime', $data['runtime'])
            ->whereNotNull('owner_id')->whereColumn('owner_id', 'control_owner_id')->whereNotNull('control_grant_id')
            ->whereNull('retired_at')->whereNull('retirement_job_id')->pluck('control_grant_id', 'id')->all();
    }

    public function read(int $owner, string $id): array
    {
        $row = DB::table('omcp_instances as i')->join('omcp_connections as c', 'c.node_id', '=', 'i.node_id')
            ->leftJoin('omcp_live_console as s', function ($join) {
                $join->on('s.instance_id', '=', 'i.id')->on('s.grant_id', '=', 'i.control_grant_id')
                    ->on('s.credential_hash', '=', 'c.credential_hash');
            })
            ->where('i.id', $id)->where('i.owner_id', $owner)->where('i.control_owner_id', $owner)
            ->whereNotNull('i.control_grant_id')->whereNotNull('c.credential_hash')
            ->whereNull('i.retired_at')->whereNull('i.retirement_job_id')
            ->select(['i.control_grant_id', 'i.runtime', 'c.capabilities', 'c.last_seen_at', 's.output', 's.captured_at'])->first();
        abort_unless($row, 404);
        $caps = json_decode($row->capabilities ?? '{}', true);
        abort_unless(!empty($caps['fleet_live_console']) && !empty($caps['fleet_owner_controls'])
            && in_array($row->runtime, ['docker', 'podman'], true) && ($caps['runtime'] ?? null) === $row->runtime, 403, 'Live console is not enabled.');
        $fresh = $row->captured_at && Carbon::parse($row->captured_at)->gt(now()->subSeconds(20))
            && $row->last_seen_at && Carbon::parse($row->last_seen_at)->gt(now()->subSeconds(20));
        return [
            'generation' => $row->control_grant_id,
            'state' => $fresh ? 'live' : 'waiting',
            'output' => $fresh ? $row->output : '',
            'captured_at' => $fresh ? Carbon::parse($row->captured_at)->toIso8601String() : null,
        ];
    }
}
