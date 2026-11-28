<?php

namespace Pterodactyl\Http\Controllers\Admin\Nodes;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Omcp\InstanceRegistry;
use Pterodactyl\Services\Omcp\JobService;
use Pterodactyl\Services\Omcp\ModpackCatalog;

class OmcpModpackController extends Controller
{
    private function instance(Node $node, string $id): object
    {
        $row = DB::table('omcp_instances')->where('node_id', $node->id)->where('id', $id)
            ->whereNull('retired_at')->whereNull('retirement_job_id')->first();
        abort_unless($row, 404);
        abort_unless(in_array($row->runtime, ['docker', 'podman'], true), 409, 'Modpack staging requires a container reservation.');
        return $row;
    }

    public function index(Node $node, string $instance)
    {
        return view('admin.nodes.view.omcp-modpacks', ['node' => $node, 'instance' => $this->instance($node, $instance)]);
    }

    public function catalog(Request $request, Node $node, string $instance, ModpackCatalog $catalog)
    {
        $row = $this->instance($node, $instance);
        $data = $request->validate(['loader' => 'required|in:fabric,forge,neoforge,quilt', 'query' => 'nullable|string|max:100',
            'project' => ['nullable', 'regex:/\A[A-Za-z0-9]{3,32}\z/']]);
        $result = !empty($data['project']) ? $catalog->packVersions($data['project'], $row->version, $data['loader'])
            : $catalog->searchPacks($data['query'] ?? '', $row->version, $data['loader']);
        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    public function stage(Request $request, Node $node, string $instance, InstanceRegistry $registry, JobService $jobs)
    {
        $this->instance($node, $instance);
        $data = $request->validate([
            'modpack_loader' => 'required|in:fabric,forge,neoforge,quilt',
            'plugin_project' => ['required', 'regex:/\A[A-Za-z0-9]{3,32}\z/'],
            'plugin_version' => ['required', 'regex:/\A[A-Za-z0-9]{3,32}\z/'],
            'plugin_sha512' => ['required', 'regex:/\A[a-f0-9]{128}\z/'],
            'confirmed' => 'required|accepted',
        ]);
        $job = $registry->dispatch($node->id, $instance, 'modpack_stage', false, $jobs, $data);
        return response()->json(['id' => $job], 202)->header('Cache-Control', 'no-store');
    }
}
