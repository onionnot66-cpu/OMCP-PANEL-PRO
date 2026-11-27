<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Omcp\InstanceRegistry;

class OmcpInstanceController extends Controller
{
    public function index(Request $request, InstanceRegistry $registry)
    {
        // Owner-scoped metadata only. No runtime operations, logs or credentials.
        return response()->json($registry->forOwner($request->user()->id))->header('Cache-Control', 'no-store');
    }
}
