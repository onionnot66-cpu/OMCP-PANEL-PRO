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
        abort_unless(in_array($action, ['install', 'start', 'stop', 'restart', 'kill', 'status', 'console', 'metrics', 'files', 'file_read', 'file_write', 'file_delete', 'property', 'command', 'retire', 'plugin_list', 'plugin_install', 'plugin_enable', 'plugin_disable', 'plugin_delete', 'world_list', 'world_backup_list', 'world_backup', 'world_restore', 'world_activate', 'world_import', 'world_export', 'version_change', 'version_inspect', 'modpack_stage'], true), 422);
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
            abort_if($instance->version_job_id && !in_array($action, ['version_inspect', 'stop', 'kill', 'status'], true), 409, 'Finalize or reconcile the pending version transition first.');
            if ($action === 'version_inspect') {
                abort_unless($instance->version_job_id && $instance->version_target, 409, 'No pending version change.');
                $extra['target_version'] = $instance->version_target;
            }
            if ($action === 'version_change') {
                abort_unless($acceptEula && self::newerVersion($instance->version, $extra['target_version'] ?? ''), 422, 'Explicit EULA acceptance and a newer exact release required.');
            }
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
            if ($action === 'modpack_stage') {
                abort_unless(in_array($backend, ['docker', 'podman'], true), 409, 'Modpack staging requires a container fleet.');
                abort_if($node->isUnderMaintenance(), 409, 'Node is in maintenance.');
            }
            if (str_starts_with($action, 'version_')) {
                abort_unless(in_array($backend, ['docker', 'podman'], true), 409, 'Version management requires a container fleet.');
                abort_if($node->isUnderMaintenance(), 409, 'Node is in maintenance.');
            }
            if (str_starts_with($action, 'world_')) {
                abort_unless(in_array($backend, ['docker', 'podman'], true), 409, 'World management requires a container fleet.');
                abort_if($node->isUnderMaintenance() && !in_array($action, ['world_list', 'world_backup_list'], true), 409, 'Node is in maintenance.');
            }
            if (str_starts_with($action, 'plugin_')) {
                abort_unless(in_array($backend, ['docker', 'podman'], true) && in_array($instance->software, ['paper', 'purpur'], true), 409, 'Plugin management requires a Paper-family container.');
                abort_if($node->isUnderMaintenance() && $action !== 'plugin_list', 409, 'Node is in maintenance.');
            }
            if ($action === 'retire') {
                abort_if(DB::table('omcp_game_routes')->where('instance_id', $id)->exists(), 409, 'Clear the game endpoint and managed DNS before retirement.');
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
            ] + array_intersect_key($extra, array_flip(['file', 'content_base64', 'expected', 'key', 'value', 'command', 'plugin_provider', 'plugin_sha256', 'plugin_project', 'plugin_version', 'plugin_sha512', 'plugin_disabled', 'world', 'backup_id', 'backup_sha256', 'transfer_bytes', 'transfer_sha256', 'target_version', 'modpack_loader'])));
            if (str_starts_with($action, 'version_')) {
                DB::table('omcp_instances')->where('id', $id)->update([
                    'version_job_id' => $job, 'version_target' => $extra['target_version'], 'runtime_requested_at' => now(),
                ]);
            }
            if ($ownerId !== null) {
                DB::table('omcp_jobs')->where('id', $job)->update([
                    'requester_id' => $ownerId, 'instance_id' => $id, 'control_grant_id' => $instance->control_grant_id,
                ]);
            }
            if (in_array($action, ['install', 'start', 'restart', 'file_write', 'file_delete', 'property', 'plugin_install', 'plugin_enable', 'plugin_disable', 'plugin_delete', 'world_backup', 'world_restore', 'world_activate', 'world_import', 'modpack_stage'], true)) {
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
            abort_if($row->version_job_id, 409, 'Reconcile pending version change before release.');
            abort_if(DB::table('omcp_game_routes')->where('instance_id', $id)->exists(), 409, 'Clear the game endpoint before release.');
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

    public static function newerVersion(string $old, string $target): bool
    {
        $parse = static function (string $value): ?array {
            if (!preg_match('/\A(0|[1-9][0-9]{0,9})(\.(0|[1-9][0-9]{0,9})){1,3}\z/', $value) || strlen($value) > 32) {
                return null;
            }
            $parts = array_map('intval', explode('.', $value));
            if (max($parts) > 4294967295) {
                return null;
            }
            return array_pad($parts, 4, 0);
        };
        $a = $parse($old);
        $b = $parse($target);
        return $a !== null && $b !== null && $b > $a;
    }

    public function finalizeVersion(int $nodeId, string $id): string
    {
        return DB::transaction(function () use ($nodeId, $id) {
            Node::query()->lockForUpdate()->findOrFail($nodeId);
            $row = DB::table('omcp_instances')->where('node_id', $nodeId)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row && $row->version_job_id && $row->version_target && !$row->retired_at, 409, 'No pending version transition.');
            $job = DB::table('omcp_jobs')->where('node_id', $nodeId)->where('id', $row->version_job_id)->first();
            $payload = json_decode($job->payload ?? '{}', true);
            abort_unless($job && $job->state === 'succeeded' && in_array($job->action, ['instance.version_change', 'instance.version_inspect'], true)
                && ($payload['instance_id'] ?? null) === $id && ($payload['software'] ?? null) === $row->software
                && ($payload['version'] ?? null) === $row->version && ($payload['target_version'] ?? null) === $row->version_target
                && ($payload['runtime'] ?? null) === $row->runtime && ($payload['memory_mib'] ?? null) === (int) $row->memory_mib
                && ($payload['port'] ?? null) === (int) $row->port
                && preg_match('/\Aversion:(vanilla|paper|purpur):([0-9.]{1,32}):[a-f0-9]{64}\z/', $job->result ?? '', $match), 409, 'Verified version receipt required.');
            abort_unless($match[1] === $row->software && ($match[2] === $row->version_target
                || ($job->action === 'instance.version_inspect' && $match[2] === $row->version)), 409, 'Version receipt differs from pending transition.');
            DB::table('omcp_instances')->where('id', $id)->update([
                'version' => $match[2], 'version_job_id' => null, 'version_target' => null, 'updated_at' => now(),
            ]);
            return $match[2];
        });
    }

    public function forOwner(int $ownerId)
    {
        return DB::table('omcp_instances')->where('owner_id', $ownerId)->whereNull('retired_at')
            ->select(['id', 'name', 'software', 'version', 'port', 'memory_mib', 'disk_mib', 'runtime', 'control_owner_id', 'control_grant_id', 'retirement_job_id'])
            ->orderBy('name')->orderBy('id')->paginate(25);
    }
}
