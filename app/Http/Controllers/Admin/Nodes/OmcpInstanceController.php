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
        $registry->reserve($node->id, $request->only(['owner_id', 'name', 'software', 'version', 'port', 'memory_mib', 'disk_mib']));
        return redirect()->route('admin.nodes.omcp.instances', $node->id);
    }

    public function destroy(Node $node, string $instance, InstanceRegistry $registry)
    {
        $registry->release($node->id, $instance);
        return redirect()->route('admin.nodes.omcp.instances', $node->id);
    }
}
