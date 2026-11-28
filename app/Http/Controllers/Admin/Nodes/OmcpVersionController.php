<?php

namespace Pterodactyl\Http\Controllers\Admin\Nodes;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Omcp\InstanceRegistry;
use Pterodactyl\Services\Omcp\JobService;

class OmcpVersionController extends Controller
{
    private function instance(Node $node, string $id): object
    {
        $row = DB::table('omcp_instances')->where('node_id', $node->id)->where('id', $id)->whereNull('retired_at')->whereNull('retirement_job_id')->first();
        abort_unless($row, 404);
        abort_unless(in_array($row->runtime, ['docker', 'podman'], true), 409, 'Version management requires a container reservation.');
        return $row;
    }

    public function index(Node $node, string $instance)
    {
        $row = $this->instance($node, $instance);
        $job = $row->version_job_id ? DB::table('omcp_jobs')->where('node_id', $node->id)->where('id', $row->version_job_id)->first() : null;
        return view('admin.nodes.view.omcp-versions', ['node' => $node, 'instance' => $row, 'job' => $job]);
    }

    public function action(Request $request, Node $node, string $instance, InstanceRegistry $registry, JobService $jobs)
    {
        $this->instance($node, $instance);
        $data = $request->validate([
            'action' => 'required|in:version_change,version_inspect,finalize',
            'target_version' => ['required_if:action,version_change', 'nullable', 'string', 'max:32', 'regex:/\A[0-9]+(\.[0-9]+){1,3}\z/'],
            'confirmed' => 'required|accepted',
            'accept_eula' => 'sometimes|accepted',
            'backup_confirmed' => 'sometimes|accepted',
        ]);
        if ($data['action'] === 'finalize') {
            $registry->finalizeVersion($node->id, $instance);
        } else {
            if ($data['action'] === 'version_change') {
                abort_unless($request->boolean('accept_eula') && $request->boolean('backup_confirmed'), 422, 'Accept the EULA and confirm a verified independent full backup first.');
            }
            $registry->dispatch($node->id, $instance, $data['action'], $request->boolean('accept_eula'), $jobs,
                array_intersect_key($data, ['target_version' => true]));
        }
        return redirect()->route('admin.nodes.omcp.instances.versions', [$node->id, $instance]);
    }
}
