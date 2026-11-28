<?php

namespace Pterodactyl\Http\Controllers\Admin\Nodes;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Node;
use Pterodactyl\Services\Omcp\GameRoutes;

class OmcpNetworkController extends Controller
{
    public function index(Node $node, string $instance)
    {
        $row = DB::table('omcp_instances')->where('node_id', $node->id)->where('id', $instance)->whereNull('retired_at')->whereNull('retirement_job_id')->first();
        abort_unless($row, 404);
        return view('admin.nodes.view.omcp-network', ['node' => $node, 'instance' => $row,
            'gameRoute' => DB::table('omcp_game_routes')->where('instance_id', $instance)->first(), 'providers' => GameRoutes::PROVIDERS]);
    }

    public function action(Request $request, Node $node, string $instance, GameRoutes $routes)
    {
        $data = $request->validate(['action' => 'required|in:register,publish,unpublish,clear',
            'provider' => 'required_if:action,register|nullable|string|max:24',
            'host' => 'required_if:action,register|nullable|string|max:253',
            'port' => 'required_if:action,register|nullable|integer|min:1|max:65535',
            'confirmed' => 'required|accepted']);
        switch ($data['action']) {
            case 'register': $routes->register($node->id, $instance, $data['provider'], $data['host'], (int) $data['port']); break;
            case 'publish': $routes->publish($node->id, $instance); break;
            case 'unpublish': $routes->unpublish($node->id, $instance); break;
            case 'clear': $routes->clear($node->id, $instance); break;
        }
        return redirect()->route('admin.nodes.omcp.instances.network', [$node->id, $instance]);
    }
}
