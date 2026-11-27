<?php

namespace Pterodactyl\Http\Controllers\Api\Omcp;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Omcp\JobService;

class JobController extends Controller
{
    public function claim(Request $request, JobService $jobs): JsonResponse
    {
        $data = $request->validate(['node_id' => 'required|integer|min:1']);
        return response()->json(['job' => $jobs->claim((int) $data['node_id'], $this->credential($request))])
            ->header('Cache-Control', 'no-store');
    }

    public function complete(Request $request, JobService $jobs): JsonResponse
    {
        $data = $request->validate([
            'node_id' => 'required|integer|min:1',
            'id' => 'required|uuid',
            'lease' => ['required', 'regex:/\A[0-9a-f]{64}\z/'],
            'success' => 'required|boolean',
            'result' => 'required|string|max:4096',
        ]);
        $jobs->complete((int) $data['node_id'], $this->credential($request), $data);
        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }

    private function credential(Request $request): string
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && preg_match('/\A[0-9a-f]{64}\z/', $token), 401);
        return $token;
    }
}
