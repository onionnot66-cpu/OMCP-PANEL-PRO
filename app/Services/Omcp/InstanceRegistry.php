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
            'runtime' => 'sometimes|in:native,podman,docker',
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
            abort_if((clone $existing)->whereNull('retired_at')->count() >= 32, 409, 'Experimental registry supports at most 32 reservations per node.');
            abort_if((clone $existing)->whereNull('retired_at')->sum('memory_mib') + (int) $data['memory_mib'] > $node->memory, 409, 'Configured node RAM budget exceeded.');
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

    public function dispatch(int $nodeId, string $id, string $action, bool $acceptEula, JobService $jobs, array $extra = [], ?int $ownerId = null): string
    {
        abort_unless(in_array($action, ['install', 'start', 'stop', 'restart', 'kill', 'status', 'console', 'metrics', 'files', 'file_read', 'file_write', 'file_delete', 'property', 'command', 'retire', 'plugin_list', 'plugin_install', 'plugin_enable', 'plugin_disable', 'plugin_delete'], true), 422);
        if ($ownerId !== null) {
            abort_unless(in_array($action, ['start', 'stop', 'restart', 'status', 'metrics', 'console', 'command'], true), 422);
        }
        abort_if($action === 'install' && !$acceptEula, 422, 'Minecraft EULA acceptance required.');

        return DB::transaction(function () use ($nodeId, $id, $action, $acceptEula, $jobs, $extra, $ownerId) {
            $node = Node::query()->lockForUpdate()->findOrFail($nodeId);
            abort_if($node->isUnderMaintenance() && in_array($action, ['install', 'start', 'restart', 'command'], true), 409, 'Node is in maintenance.');
            abort_if(DB::table('servers')->where('node_id', $nodeId)->exists(), 409, 'Use a dedicated OMCP node.');
            $instance = DB::table('omcp_instances')->where('node_id', $nodeId)->where('id', $id)->lockForUpdate()->first();
            abort_unless($instance && ($ownerId === null || (int) $instance->owner_id === $ownerId), 404);
            if ($ownerId !== null) {
                abort_unless((int) $instance->control_owner_id === $ownerId && $instance->control_grant_id && !$instance->retirement_job_id && in_array($instance->runtime, ['docker', 'podman'], true), 403, 'Trusted owner controls are not enabled.');
            }
            abort_if($instance->retired_at, 409, 'Instance is retired.');
            abort_if($instance->retirement_job_id && !in_array($action, ['retire', 'stop', 'kill', 'status', 'console', 'metrics'], true), 409, 'Retirement requested; runtime changes are blocked.');
            if ($action === 'retire' && $instance->retirement_job_id) {
                $previous = DB::table('omcp_jobs')->where('node_id', $nodeId)->where('id', $instance->retirement_job_id)->first();
                if ($previous && (($previous->state === 'succeeded' && $previous->result === 'retired:' . $id) || (in_array($previous->state, ['queued', 'running'], true) && \Illuminate\Support\Carbon::parse($previous->expires_at)->gt(now())))) {
                    return $previous->id;
                }
            }
            abort_if(DB::table('allocations')->where('node_id', $nodeId)->where('port', $instance->port)->exists(), 409, 'Legacy allocation conflicts with instance port.');
            $capabilities = json_decode(DB::table('omcp_connections')->where('node_id', $nodeId)->lockForUpdate()->value('capabilities') ?? '{}', true);
            $backend = $capabilities['runtime'] ?? null;
            if ($ownerId !== null) {
                abort_unless(!empty($capabilities['fleet_owner_controls']), 409, 'Local trusted owner controls are not enabled.');
            }
            if ($instance->runtime !== null) {
                abort_unless($backend === $instance->runtime, 409, 'The connector runtime differs from this reservation.');
            } elseif (in_array($backend, ['native', 'podman', 'docker'], true)) {
                // Adopt legacy reservations only after an operator-selected connector identifies its backend.
                DB::table('omcp_instances')->where('id', $id)->update(['runtime' => $backend]);
            }
            if (str_starts_with($action, 'plugin_')) {
                abort_unless(in_array($backend, ['docker', 'podman'], true) && in_array($instance->software, ['paper', 'purpur'], true), 409, 'Plugin management requires a Paper-family container.');
                abort_if($node->isUnderMaintenance() && $action !== 'plugin_list', 409, 'Node is in maintenance.');
            }
            if ($action === 'retire') {
                abort_unless(in_array($backend, ['podman', 'docker'], true), 409, 'Retirement requires an isolated container backend.');
            }
            $job = $jobs->enqueue($nodeId, 'instance.' . $action, [
                'instance_id' => $instance->id,
                'owner_control' => $ownerId !== null,
                'runtime' => $backend,
                'software' => $instance->software,
                'version' => $instance->version,
                'port' => (int) $instance->port,
                'memory_mib' => (int) $instance->memory_mib,
                'accept_eula' => $acceptEula,
            ] + array_intersect_key($extra, array_flip(['file', 'content_base64', 'expected', 'key', 'value', 'command', 'plugin_project', 'plugin_version', 'plugin_sha512', 'plugin_disabled'])));
            if ($ownerId !== null) {
                DB::table('omcp_jobs')->where('id', $job)->update([
                    'requester_id' => $ownerId, 'instance_id' => $id, 'control_grant_id' => $instance->control_grant_id,
                ]);
            }
            if (in_array($action, ['install', 'start', 'restart', 'file_write', 'file_delete', 'property', 'plugin_install', 'plugin_enable', 'plugin_disable', 'plugin_delete'], true)) {
                DB::table('omcp_instances')->where('id', $id)->update(['runtime_requested_at' => now()]);
            }
            if ($action === 'retire') {
                DB::table('omcp_instances')->where('id', $id)->update(['retirement_job_id' => $job, 'runtime_requested_at' => now()]);
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
            if ($row->retired_at) {
                return; // Idempotent release confirmation; retain the archive record.
            }
            if ($row->retirement_job_id) {
                $job = DB::table('omcp_jobs')->where('node_id', $nodeId)->where('id', $row->retirement_job_id)->first();
                $payload = json_decode($job->payload ?? '{}', true);
                abort_unless($job && $job->action === 'instance.retire' && $job->state === 'succeeded'
                    && ($payload['instance_id'] ?? null) === $id
                    && ($payload['runtime'] ?? null) === $row->runtime
                    && ($payload['port'] ?? null) === (int) $row->port
                    && ($payload['memory_mib'] ?? null) === (int) $row->memory_mib
                    && $job->result === 'retired:' . $id, 409, 'Verified retirement receipt required before release.');
                DB::table('omcp_instances')->where('id', $id)->update([
                    'retired_port' => $row->port, 'port' => null, 'retired_at' => now(), 'updated_at' => now(),
                ]);
                return;
            }
            abort_if($row->runtime_requested_at, 409, 'Runtime was requested. Manual decommission is required before release.');
            DB::table('omcp_instances')->where('id', $id)->delete();
        });
    }

    public function forOwner(int $ownerId)
    {
        return DB::table('omcp_instances')->where('owner_id', $ownerId)->whereNull('retired_at')
            ->select(['id', 'name', 'software', 'version', 'port', 'memory_mib', 'disk_mib', 'runtime', 'control_owner_id', 'control_grant_id', 'retirement_job_id'])
            ->orderBy('name')->orderBy('id')->paginate(25);
    }
}
