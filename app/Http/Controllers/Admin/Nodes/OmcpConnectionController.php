<?php

namespace Pterodactyl\Http\Controllers\Admin\Nodes;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Node;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Omcp\EnrollmentService;

class OmcpConnectionController extends Controller
{
    public function status(Node $node): JsonResponse
    {
        $record = DB::table('omcp_connections')->where('node_id', $node->id)->first();
        return response()->json([
            'registered' => (bool) ($record->credential_hash ?? false),
            'online' => isset($record->last_seen_at) && \Illuminate\Support\Carbon::parse($record->last_seen_at)->gt(now()->subSeconds(90)),
            'telemetry' => isset($record->telemetry) && isset($record->last_seen_at) && \Illuminate\Support\Carbon::parse($record->last_seen_at)->gt(now()->subSeconds(90)) ? json_decode($record->telemetry, true) : null,
            'last_seen_at' => $record->last_seen_at ?? null,
            'capabilities' => isset($record->capabilities) ? json_decode($record->capabilities, true) : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function diagnostic(Node $node, \Pterodactyl\Services\Omcp\JobService $jobs): JsonResponse
    {
        return response()->json(['id' => $jobs->enqueueDiagnostic($node->id)], 202)->header('Cache-Control', 'no-store');
    }

    public function power(Request $request, Node $node, \Pterodactyl\Services\Omcp\JobService $jobs): JsonResponse
    {
        $data = $request->validate(['action' => 'required|in:start,stop,restart,kill,status,console,metrics']);
        abort_if($node->isUnderMaintenance() && in_array($data['action'], ['start', 'restart'], true), 409, 'Node is in maintenance.');
        return response()->json(['id' => $jobs->enqueue($node->id, 'native.' . $data['action'])], 202)
            ->header('Cache-Control', 'no-store');
    }

    public function command(Request $request, Node $node, \Pterodactyl\Services\Omcp\JobService $jobs): JsonResponse
    {
        abort_if($node->isUnderMaintenance(), 409, 'Node is in maintenance.');
        $data = $request->validate(['command' => ['required', 'string', 'max:256', 'regex:/\A[^\x00-\x1f\x7f]+\z/']]);
        $command = $data['command'];
        abort_unless(in_array($command, ['list', 'save-all', 'save-all flush', 'whitelist list', 'time query daytime', 'time query gametime'], true) || (str_starts_with($command, 'say ') && trim(substr($command, 4)) !== ''), 422, 'Unsupported Minecraft command.');
        return response()->json(['id' => $jobs->enqueue($node->id, 'native.command', ['command' => $command])], 202)->header('Cache-Control', 'no-store');
    }

    public function install(Request $request, Node $node, \Pterodactyl\Services\Omcp\JobService $jobs): JsonResponse
    {
        abort_if($node->isUnderMaintenance(), 409, 'Node is in maintenance.');
        $data = $request->validate([
            'software' => 'required|in:vanilla,paper,purpur',
            'version' => ['required', 'string', 'max:32', 'regex:/\A[0-9]+(\.[0-9]+){1,3}\z/'],
            'port' => 'required|integer|min:1024|max:65535',
            'accept_eula' => 'required|accepted',
        ]);
        $payload = ['software' => $data['software'], 'version' => $data['version'], 'port' => (int) $data['port'], 'accept_eula' => true];
        return response()->json(['id' => $jobs->enqueue($node->id, 'native.install', $payload)], 202)->header('Cache-Control', 'no-store');
    }

    public function diagnosticResult(Node $node, string $job): JsonResponse
    {
        $record = DB::table('omcp_jobs')->where('node_id', $node->id)->where('id', $job)->first();
        abort_unless($record, 404);
        $state = $record->state;
        if (in_array($state, ['queued', 'running'], true) && \Illuminate\Support\Carbon::parse($record->expires_at)->lte(now())) {
            $state = 'expired';
        }
        return response()->json(['state' => $state, 'result' => $record->result])->header('Cache-Control', 'no-store');
    }

    public function enroll(Request $request, Node $node, EnrollmentService $service): JsonResponse
    {
        $data = $request->validate(['rotate' => 'sometimes|boolean']);
        $origin = rtrim((string) config('app.url'), '/');
        $url = parse_url($origin);
        abort_unless(is_array($url) && ($url['scheme'] ?? '') === 'https' && !empty($url['host']) && !isset($url['user']) && !isset($url['pass']) && empty($url['path']) && empty($url['query']) && empty($url['fragment']), 422, 'Configure an HTTPS panel origin first.');
        $token = $service->issue($node->id, (bool) ($data['rotate'] ?? false));
        abort_unless($token !== null, 409, 'Enrollment exists. Explicitly rotate to revoke it.');

        return response()->json([
            'token' => $token,
            'expires_in' => 900,
            'command' => 'curl --proto =https -fL --retry 3 https://raw.githubusercontent.com/onionnot66-cpu/OMCP-PANEL-PRO/266bf94c3bbfa76a51cc69bc80a26932692b06d7/omcp-connect-install.sh -o omcp-connect-install.sh && bash omcp-connect-install.sh --panel ' . escapeshellarg($origin) . ' --node ' . $node->id,
        ])->header('Cache-Control', 'no-store');
    }
}
