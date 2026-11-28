<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Omcp\InstanceRegistry;

class OmcpInstanceController extends Controller
{
    public function index(Request $request, InstanceRegistry $registry)
    {
        $owner = $request->user()->id;
        $page = $registry->forOwner($owner);
        $page->through(static function ($row) use ($owner) {
            $row->controls_enabled = (int) $row->control_owner_id === (int) $owner && $row->control_grant_id && !$row->retirement_job_id;
            unset($row->control_owner_id, $row->control_grant_id, $row->retirement_job_id);
            return $row;
        });
        return response()->json($page)->header('Cache-Control', 'no-store');
    }
    public function action(Request $request, string $instance, InstanceRegistry $registry, \Pterodactyl\Services\Omcp\JobService $jobs)
    {
        $owner = (int) $request->user()->id;
        $row = \Illuminate\Support\Facades\DB::table('omcp_instances')->where('id', $instance)->where('owner_id', $owner)->whereNull('retired_at')->first();
        abort_unless($row, 404);
        $data = $request->validate(['action' => 'required|in:start,stop,restart,status,metrics,console,command', 'command' => 'required_if:action,command|nullable|string|max:256']);
        $extra = [];
        if ($data['action'] === 'command') {
            abort_unless(\Pterodactyl\Services\Omcp\ConsoleCommand::allowed($data['command']), 422, 'Unsupported command.');
            $extra['command'] = $data['command'];
        }
        $id = $registry->dispatch($row->node_id, $instance, $data['action'], false, $jobs, $extra, $owner);
        return response()->json(['id' => $id], 202)->header('Cache-Control', 'no-store');
    }

    public function result(Request $request, string $instance, string $job, \Pterodactyl\Services\Omcp\OwnerControls $controls)
    {
        return response()->json($controls->result((int) $request->user()->id, $instance, $job))->header('Cache-Control', 'no-store');
    }
}
