<?php

namespace Pterodactyl\Services\Omcp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Pterodactyl\Models\Node;

class InstanceRegistry
{
    // Reservations are metadata only, not process creation or enforced resource limits.
    public function reserve(int $nodeId, array $input): string
    {
        $data = Validator::make($input, [
            'owner_id' => 'required|integer|exists:users,id',
            'name' => 'required|string|max:100',
            'software' => 'required|in:vanilla,paper,purpur',
            'version' => ['required', 'string', 'max:32', 'regex:/\A[0-9]+(\.[0-9]+){1,3}\z/'],
            'port' => 'required|integer|min:1024|max:65535',
            'memory_mib' => 'required|integer|min:128|max:1048576',
            'disk_mib' => 'required|integer|min:512|max:1073741824',
        ])->validate();

        return DB::transaction(function () use ($nodeId, $data) {
            $node = Node::query()->lockForUpdate()->findOrFail($nodeId);
            abort_if($node->isUnderMaintenance(), 409, 'Node is in maintenance.');
            $connection = DB::table('omcp_connections')->where('node_id', $nodeId)->first();
            abort_unless($connection && $connection->credential_hash, 409, 'Enroll OMCP Connect first.');

            // Do not mix experimental reservations with the legacy allocator.
            abort_if(DB::table('servers')->where('node_id', $nodeId)->exists(), 409, 'Use a dedicated OMCP node without legacy servers.');
            abort_if(DB::table('allocations')->where('node_id', $nodeId)->where('port', $data['port'])->exists(), 409, 'Port is reserved by the legacy allocator.');

            $existing = DB::table('omcp_instances')->where('node_id', $nodeId);
            abort_if((clone $existing)->where('port', $data['port'])->exists(), 409, 'Port already reserved.');
            abort_if((clone $existing)->count() >= 32, 409, 'Experimental registry supports at most 32 reservations per node.');
            abort_if((clone $existing)->sum('memory_mib') + (int) $data['memory_mib'] > $node->memory, 409, 'Configured node RAM budget exceeded.');
            abort_if((clone $existing)->sum('disk_mib') + (int) $data['disk_mib'] > $node->disk, 409, 'Configured node disk budget exceeded.');

            $id = (string) Str::uuid();
            DB::table('omcp_instances')->insert($data + [
                'id' => $id,
                'node_id' => $nodeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return $id;
        });
    }

    public function dispatch(int $nodeId, string $id, string $action, bool $acceptEula, JobService $jobs): string
    {
        abort_unless(in_array($action, ['install', 'start', 'stop', 'restart', 'kill', 'status', 'console', 'metrics'], true), 422);
        abort_if($action === 'install' && !$acceptEula, 422, 'Minecraft EULA acceptance required.');

        return DB::transaction(function () use ($nodeId, $id, $action, $acceptEula, $jobs) {
            $node = Node::query()->lockForUpdate()->findOrFail($nodeId);
            abort_if($node->isUnderMaintenance() && in_array($action, ['install', 'start', 'restart'], true), 409, 'Node is in maintenance.');
            abort_if(DB::table('servers')->where('node_id', $nodeId)->exists(), 409, 'Use a dedicated OMCP node.');
            $instance = DB::table('omcp_instances')->where('node_id', $nodeId)->where('id', $id)->lockForUpdate()->first();
            abort_unless($instance, 404);
            abort_if(DB::table('allocations')->where('node_id', $nodeId)->where('port', $instance->port)->exists(), 409, 'Legacy allocation conflicts with instance port.');
            $job = $jobs->enqueue($nodeId, 'instance.' . $action, [
                'instance_id' => $instance->id,
                'software' => $instance->software,
                'version' => $instance->version,
                'port' => (int) $instance->port,
                'memory_mib' => (int) $instance->memory_mib,
                'accept_eula' => $acceptEula,
            ]);
            if (in_array($action, ['install', 'start', 'restart'], true)) {
                DB::table('omcp_instances')->where('id', $id)->update(['runtime_requested_at' => now()]);
            }
            return $job;
        });
    }

    public function release(int $nodeId, string $id): void
    {
        DB::transaction(function () use ($nodeId, $id) {
            Node::query()->lockForUpdate()->findOrFail($nodeId);
            $row = DB::table('omcp_instances')->where('node_id', $nodeId)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if($row->runtime_requested_at, 409, 'Runtime was requested. Manual decommission is required before release.');
            DB::table('omcp_instances')->where('id', $id)->delete();
        });
    }

    public function forOwner(int $ownerId)
    {
        return DB::table('omcp_instances')->where('owner_id', $ownerId)
            ->select(['id', 'name', 'software', 'version', 'port', 'memory_mib', 'disk_mib'])
            ->orderBy('name')->orderBy('id')->paginate(25);
    }
}
