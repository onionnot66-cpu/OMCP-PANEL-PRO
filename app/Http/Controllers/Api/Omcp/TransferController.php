<?php

namespace Pterodactyl\Http\Controllers\Api\Omcp;

use Illuminate\Http\Request;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Omcp\TransferService;

class TransferController extends Controller
{
    private function auth(Request $request): array
    {
        $credential = $request->bearerToken();
        $lease = $request->header('X-OMCP-Lease');
        $node = $request->header('X-OMCP-Node');
        abort_unless(is_string($credential) && preg_match('/\A[a-f0-9]{64}\z/', $credential)
            && is_string($lease) && preg_match('/\A[a-f0-9]{64}\z/', $lease)
            && is_string($node) && preg_match('/\A[1-9][0-9]{0,9}\z/', $node), 401);
        return [(int) $node, $credential, $lease];
    }

    public function download(Request $request, string $job, TransferService $transfers)
    {
        [$node, $credential, $lease] = $this->auth($request);
        return $transfers->downloadImport($node, $credential, $job, $lease);
    }

    public function upload(Request $request, string $job, TransferService $transfers)
    {
        [$node, $credential, $lease] = $this->auth($request);
        $size = $request->header('Content-Length');
        $sha = $request->header('X-OMCP-SHA256');
        abort_unless($request->header('Content-Type') === 'application/zip'
            && is_string($size) && preg_match('/\A[1-9][0-9]{0,8}\z/', $size) && (int) $size <= TransferService::LIMIT
            && is_string($sha) && preg_match('/\A[a-f0-9]{64}\z/', $sha), 422, 'Bounded ZIP body and SHA-256 required.');
        $stream = $request->getContent(true);
        try {
            $transfers->uploadExport($node, $credential, $job, $lease, (int) $size, $sha, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }
}
