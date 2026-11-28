<?php

namespace Pterodactyl\Http\Controllers\Admin\Nodes;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Node;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Omcp\InstanceRegistry;

class OmcpInstanceController extends Controller
{
    public function index(Node $node)
    {
        $instances = DB::table('omcp_instances')->where('node_id', $node->id)->orderBy('name')->get();
        return view('admin.nodes.view.omcp-instances', compact('node', 'instances'));
    }

    public function store(Request $request, Node $node, InstanceRegistry $registry)
    {
        $registry->reserve($node->id, $request->only(['owner_id', 'name', 'runtime', 'software', 'version', 'port', 'memory_mib', 'disk_mib']));
        return redirect()->route('admin.nodes.omcp.instances', $node->id);
    }

    public function action(Request $request, Node $node, string $instance, InstanceRegistry $registry, \Pterodactyl\Services\Omcp\JobService $jobs)
    {
        $data = $request->validate(['action' => 'required|in:install,start,stop,restart,kill,status,console,metrics', 'accept_eula' => 'sometimes|boolean']);
        $id = $registry->dispatch($node->id, $instance, $data['action'], (bool) ($data['accept_eula'] ?? false), $jobs);
        return response()->json(['id' => $id], 202)->header('Cache-Control', 'no-store');
    }

    public function files(Request $request, Node $node, string $instance, InstanceRegistry $registry, \Pterodactyl\Services\Omcp\JobService $jobs)
    {
        $data = $request->validate([
            'action' => 'required|in:files,file_read,file_write,file_delete,property',
            'file' => ['required_if:action,file_read,file_write,file_delete', 'nullable', 'string', 'max:64', 'regex:/\A(?:bukkit\.yml|spigot\.yml|commands\.yml|paper\.yml|notes-[A-Za-z0-9_-]{1,48}\.txt)\z/'],
            'content_base64' => 'nullable|string|max:2732',
            'expected' => ['required_if:action,file_write,file_delete', 'nullable', 'regex:/\A(?:new|[a-f0-9]{64})\z/'],
            'key' => 'required_if:action,property|nullable|in:difficulty,gamemode,pvp,white-list,allow-flight,spawn-monsters,hardcore,max-players,view-distance,simulation-distance',
            'value' => 'required_if:action,property|nullable|string|max:32',
        ]);
        if ($data['action'] === 'file_write') {
            $data['content_base64'] = $data['content_base64'] ?? '';
            $decoded = base64_decode($data['content_base64'], true);
            abort_if($decoded === false || strlen($decoded) > 2048 || !mb_check_encoding($decoded, 'UTF-8'), 422, 'Only UTF-8 text up to 2048 bytes is supported.');
        }
        // Avoid null values for the connector's typed string fields.
        $extra = array_filter($data, static fn ($v) => $v !== null);
        $id = $registry->dispatch($node->id, $instance, $data['action'], false, $jobs, $extra);
        return response()->json(['id' => $id], 202)->header('Cache-Control', 'no-store');
    }

    public function destroy(Node $node, string $instance, InstanceRegistry $registry)
    {
        $registry->release($node->id, $instance);
        return redirect()->route('admin.nodes.omcp.instances', $node->id);
    }
}
