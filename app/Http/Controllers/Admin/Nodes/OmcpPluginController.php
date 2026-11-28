<?php

namespace Pterodactyl\Http\Controllers\Admin\Nodes;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Omcp\InstanceRegistry;
use Pterodactyl\Services\Omcp\JobService;
use Pterodactyl\Services\Omcp\ModrinthCatalog;

class OmcpPluginController extends Controller
{
    private function instance(Node $node, string $id): object
    {
        $row = DB::table('omcp_instances')->where('node_id', $node->id)->where('id', $id)->whereNull('retired_at')->whereNull('retirement_job_id')->first();
        abort_unless($row, 404);
        abort_unless(in_array($row->runtime, ['docker', 'podman'], true) && in_array($row->software, ['paper', 'purpur'], true), 409, 'Select a Paper or Purpur container reservation.');
        return $row;
    }

    public function index(Node $node, string $instance)
    {
        return view('admin.nodes.view.omcp-plugins', ['node' => $node, 'instance' => $this->instance($node, $instance)]);
    }

    public function catalog(Request $request, Node $node, string $instance, ModrinthCatalog $catalog, \Pterodactyl\Services\Omcp\HangarCatalog $hangar)
    {
        $row = $this->instance($node, $instance);
        $data = $request->validate(['provider' => 'sometimes|in:modrinth,hangar', 'query' => 'nullable|string|max:100', 'project' => ['nullable', 'regex:/\A[A-Za-z0-9]{1,32}\z/']]);
        $provider = $data['provider'] ?? 'modrinth';
        $catalog = $provider === 'hangar' ? $hangar : $catalog;
        if (!empty($data['project']) && $provider === 'hangar') {
            abort_unless(preg_match('/\A[1-9][0-9]{0,18}\z/', $data['project']), 422);
        }
        $results = !empty($data['project']) ? $catalog->versions($data['project'], $row->version) : $catalog->search($data['query'] ?? '', $row->version);
        return response()->json($results)->header('Cache-Control', 'no-store');
    }

    public function action(Request $request, Node $node, string $instance, InstanceRegistry $registry, JobService $jobs)
    {
        $this->instance($node, $instance);
        $data = $request->validate([
            'action' => 'required|in:plugin_list,plugin_install,plugin_enable,plugin_disable,plugin_delete',
            'plugin_project' => ['required_unless:action,plugin_list', 'nullable', 'regex:/\A[A-Za-z0-9]{3,32}\z/'],
            'plugin_version' => ['required_unless:action,plugin_list', 'nullable', 'regex:/\A[A-Za-z0-9]{3,32}\z/'],
            'plugin_provider' => 'sometimes|in:modrinth,hangar',
            'plugin_sha256' => ['nullable', 'regex:/\A[a-f0-9]{64}\z/'],
            'plugin_sha512' => ['nullable', 'regex:/\A[a-f0-9]{128}\z/'],
            'plugin_disabled' => 'sometimes|boolean',
        ]);
        if ($data['action'] !== 'plugin_list') {
            abort_unless($request->boolean('confirmed'), 422, 'Explicit trusted plugin change confirmation required.');
        }
        $provider = $data['plugin_provider'] ?? 'modrinth';
        if ($data['action'] !== 'plugin_list') {
            $pattern = $provider === 'hangar' ? '/\A[1-9][0-9]{0,18}\z/' : '/\A[A-Za-z0-9]{3,32}\z/';
            abort_unless(preg_match($pattern, $data['plugin_project']) && preg_match($pattern, $data['plugin_version']), 422, 'Invalid provider IDs.');
        }
        if ($data['action'] === 'plugin_install') {
            abort_unless(!empty($data[$provider === 'hangar' ? 'plugin_sha256' : 'plugin_sha512']), 422, 'Provider checksum required.');
        }
        $extra = array_filter($data, static fn ($v) => $v !== null);
        if (isset($extra['plugin_disabled'])) {
            $extra['plugin_disabled'] = (bool) $extra['plugin_disabled'];
        }
        $job = $registry->dispatch($node->id, $instance, $data['action'], false, $jobs, $extra);
        return response()->json(['id' => $job], 202)->header('Cache-Control', 'no-store');
    }
}
