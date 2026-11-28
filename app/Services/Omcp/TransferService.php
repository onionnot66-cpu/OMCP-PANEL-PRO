<?php

namespace Pterodactyl\Services\Omcp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransferService
{
    public const LIMIT = 33554432; // 32 MiB binary transport; not the local backup limit.
    private const SLOTS = 16; // At most 512 MiB of committed panel spool files.

    public function __construct(private JobService $jobs, private InstanceRegistry $registry)
    {
    }

    private function root(): string
    {
        $root = storage_path('app/omcp-transfers');
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            abort(503, 'Private transfer spool unavailable.');
        }
        abort_if(is_link($root) || !is_dir($root) || (fileperms($root) & 0077), 503, 'Transfer spool must be private.');
        return $root;
    }

    private function locked(callable $callback): mixed
    {
        $root = $this->root();
        $path = $root . '/.lock';
        abort_if(is_link($path), 503, 'Unsafe transfer lock.');
        $lock = fopen($path, 'c+b');
        abort_unless($lock !== false, 503, 'Transfer lock unavailable.');
        try {
            chmod($path, 0600);
            abort_unless(flock($lock, LOCK_EX | LOCK_NB), 409, 'Transfer spool is busy; retry later.');
            return $callback($root);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function path(string $root, string $id): string
    {
        abort_unless(Str::isUuid($id), 404);
        return $root . '/' . $id . '.zip';
    }

    private function stage(string $root, $source, ?int $expectedBytes = null, ?string $expectedHash = null): array
    {
        $temp = tempnam($root, '.stage-');
        abort_unless($temp !== false, 503, 'Transfer staging unavailable.');
        try {
            chmod($temp, 0600);
            $out = fopen($temp, 'wb');
            abort_unless($out !== false, 503);
            try {
                $size = stream_copy_to_stream($source, $out, self::LIMIT + 1);
                abort_unless(is_int($size) && $size > 0 && $size <= self::LIMIT, 422, 'Transfer exceeds 32 MiB or is empty.');
                abort_unless(fflush($out) && fsync($out), 503, 'Transfer write failed.');
            } finally {
                fclose($out);
            }
            $sha = hash_file('sha256', $temp);
            abort_unless(is_string($sha) && ($expectedBytes === null || $size === $expectedBytes)
                && ($expectedHash === null || hash_equals($expectedHash, $sha)), 422, 'Transfer size or SHA-256 mismatch.');
            return [$temp, $size, $sha];
        } catch (\Throwable $e) {
            @unlink($temp);
            throw $e;
        }
    }

    private function capacity(string $root): void
    {
        // Count physical files too, so a crash or deleted DB row cannot bypass the limit.
        $entries = glob($root . '/*.zip') ?: [];
        $staged = glob($root . '/.stage-*') ?: [];
        abort_if(count($entries) + count($staged) >= self::SLOTS || DB::table('omcp_transfers')->count() >= self::SLOTS, 409, 'Transfer spool full. Run omcp:transfers:prune after expiry.');
    }

    public function queue(int $node, string $instance, string $direction, array $extra, $source = null): string
    {
        abort_unless(in_array($direction, ['import', 'export'], true), 422);
        return $this->locked(function ($root) use ($node, $instance, $direction, $extra, $source) {
            $this->capacity($root);
            $temp = $published = null;
            try {
                $bytes = $sha = null;
                if ($direction === 'import') {
                    abort_unless(is_resource($source), 422, 'ZIP file required.');
                    [$temp, $bytes, $sha] = $this->stage($root, $source);
                    $extra['transfer_bytes'] = $bytes;
                    $extra['transfer_sha256'] = $sha;
                }
                return DB::transaction(function () use ($root, $node, $instance, $direction, $extra, $temp, $bytes, $sha, &$published) {
                    $id = $this->registry->dispatch($node, $instance, 'world_' . $direction, false, $this->jobs, $extra);
                    if ($temp !== null) {
                        $dest = $this->path($root, $id);
                        abort_unless(!file_exists($dest) && !is_link($dest) && rename($temp, $dest), 503, 'Transfer publication failed.');
                        $published = $dest;
                    }
                    DB::table('omcp_transfers')->insert([
                        'job_id' => $id, 'node_id' => $node, 'instance_id' => $instance,
                        'direction' => $direction, 'state' => $temp === null ? 'pending' : 'ready',
                        'bytes' => $bytes, 'sha256' => $sha, 'expires_at' => now()->addHour(),
                    ]);
                    return $id;
                });
            } catch (\Throwable $e) {
                if ($published !== null) {
                    @unlink($published);
                }
                throw $e;
            } finally {
                if ($temp !== null && file_exists($temp)) {
                    @unlink($temp);
                }
            }
        });
    }

    private function nodeRecord(int $node, string $credential, string $id, string $lease, string $direction): object
    {
        $payload = $this->jobs->authorizeTransfer($node, $credential, $id, $lease, 'instance.world_' . $direction);
        $row = DB::table('omcp_transfers')->where('job_id', $id)->where('node_id', $node)
            ->where('direction', $direction)->where('expires_at', '>', now())->first();
        abort_unless($row && ($payload['instance_id'] ?? null) === $row->instance_id, 404);
        $row->expected_sha256 = $direction === 'export' ? ($payload['backup_sha256'] ?? null) : ($payload['transfer_sha256'] ?? null);
        return $row;
    }

    public function uploadExport(int $node, string $credential, string $id, string $lease, int $size, string $sha, $source): void
    {
        $this->nodeRecord($node, $credential, $id, $lease, 'export');
        $this->locked(function ($root) use ($node, $credential, $id, $lease, $size, $sha, $source) {
            $row = $this->nodeRecord($node, $credential, $id, $lease, 'export');
            abort_unless(is_string($row->expected_sha256) && hash_equals($row->expected_sha256, $sha), 422, 'Export differs from selected backup digest.');
            $dest = $this->path($root, $id);
            if ($row->state === 'ready') {
                abort_unless((int) $row->bytes === $size && hash_equals($row->sha256, $sha) && is_file($dest) && !is_link($dest)
                    && hash_equals($sha, hash_file('sha256', $dest) ?: ''), 409, 'Transfer already exists.');
                return; // Idempotent acknowledgement retry.
            }
            abort_unless($row->state === 'pending' && !file_exists($dest) && !is_link($dest), 409, 'Transfer requires reconciliation.');
            [$temp, $bytes, $digest] = $this->stage($root, $source, $size, $sha);
            try {
                $this->nodeRecord($node, $credential, $id, $lease, 'export');
                abort_unless(rename($temp, $dest), 503, 'Transfer publication failed.');
                try {
                    DB::table('omcp_transfers')->where('job_id', $id)->update(['state' => 'ready', 'bytes' => $bytes, 'sha256' => $digest]);
                } catch (\Throwable $e) {
                    @unlink($dest);
                    throw $e;
                }
            } finally {
                if (file_exists($temp)) {
                    @unlink($temp);
                }
            }
        });
    }

    private function download(object $row)
    {
        $path = $this->path($this->root(), $row->job_id);
        abort_unless($row->state === 'ready' && is_file($path) && !is_link($path) && filesize($path) === (int) $row->bytes
            && hash_equals($row->sha256, hash_file('sha256', $path) ?: ''), 409, 'Transfer not ready or corrupt.');
        return response()->download($path, 'omcp-world-' . $row->job_id . '.zip', [
            'Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'X-OMCP-SHA256' => $row->sha256,
        ]);
    }

    public function downloadImport(int $node, string $credential, string $id, string $lease)
    {
        return $this->download($this->nodeRecord($node, $credential, $id, $lease, 'import'));
    }

    public function downloadExport(int $node, string $instance, string $id)
    {
        $row = DB::table('omcp_transfers as t')->join('omcp_jobs as j', 'j.id', '=', 't.job_id')
            ->where('t.job_id', $id)->where('t.node_id', $node)->where('t.instance_id', $instance)
            ->where('t.direction', 'export')->where('t.expires_at', '>', now())
            ->where('j.state', 'succeeded')->select('t.*')->first();
        abort_unless($row, 404);
        return $this->download($row);
    }

    public function prune(): int
    {
        return $this->locked(function ($root) {
            $count = 0;
            foreach (DB::table('omcp_transfers')->where('expires_at', '<=', now())->get() as $row) {
                $path = $this->path($root, $row->job_id);
                if ((is_file($path) || is_link($path)) && !@unlink($path)) {
                    continue;
                }
                DB::table('omcp_transfers')->where('job_id', $row->job_id)->delete();
                ++$count;
            }
            foreach (array_merge(glob($root . '/*.zip') ?: [], glob($root . '/.stage-*') ?: []) as $file) {
                if (is_link($file) || !is_file($file) || filemtime($file) > time() - 3600) {
                    continue;
                }
                $id = basename($file, '.zip');
                if (str_starts_with(basename($file), '.stage-') || (Str::isUuid($id) && !DB::table('omcp_transfers')->where('job_id', $id)->exists())) {
                    @unlink($file);
                }
            }
            return $count;
        });
    }
}
