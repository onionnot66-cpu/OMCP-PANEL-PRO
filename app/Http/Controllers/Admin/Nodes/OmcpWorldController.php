<?php

namespace Pterodactyl\Http\Controllers\Admin\Nodes;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Omcp\InstanceRegistry;
use Pterodactyl\Services\Omcp\JobService;

class OmcpWorldController extends Controller
{
    private function instance(Node $node, string $id): object
    {
        $row = DB::table('omcp_instances')->where('node_id', $node->id)->where('id', $id)->whereNull('retired_at')->whereNull('retirement_job_id')->first();
        abort_unless($row, 404);
        abort_unless(in_array($row->runtime, ['docker', 'podman'], true), 409, 'World management requires a container reservation.');
        return $row;
    }

    public function index(Node $node, string $instance)
    {
        return view('admin.nodes.view.omcp-worlds', ['node' => $node, 'instance' => $this->instance($node, $instance)]);
    }

    public function action(Request $request, Node $node, string $instance, InstanceRegistry $registry, JobService $jobs)
    {
        $this->instance($node, $instance);
        $data = $request->validate([
            'action' => 'required|in:world_list,world_backup_list,world_backup,world_restore,world_activate',
            'world' => ['required_if:action,world_backup,world_restore,world_activate', 'nullable', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_-]{0,47}\z/'],
            'backup_id' => 'required_if:action,world_restore|nullable|uuid',
            'backup_sha256' => ['required_if:action,world_restore', 'nullable', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
        if (!in_array($data['action'], ['world_list', 'world_backup_list'], true)) {
            abort_unless($request->boolean('confirmed'), 422, 'Explicit world operation confirmation required.');
        }
        $job = $registry->dispatch($node->id, $instance, $data['action'], false, $jobs, array_filter($data, static fn ($v) => $v !== null));
        return response()->json(['id' => $job], 202)->header('Cache-Control', 'no-store');
    }
}
