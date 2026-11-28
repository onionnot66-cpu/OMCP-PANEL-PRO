<?php

namespace Pterodactyl\Services\Omcp;

use Illuminate\Support\Facades\Http;

class HangarCatalog
{
    private const ID = '/\A[1-9][0-9]{0,18}\z/';

    private function get(string $path, array $query): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'OMCP-Panel/0.1 (https://github.com/onionnot66-cpu/OMCP-PANEL-PRO)'])
                ->acceptJson()->connectTimeout(5)->timeout(15)
                ->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => 10])
                ->get('https://hangar.papermc.io/api/v1/' . $path, $query);
            $stream = $response->toPsrResponse()->getBody();
            try {
                abort_unless($response->successful(), 502, 'Hangar catalog unavailable.');
                $raw = '';
                while (!$stream->eof()) {
                    $chunk = $stream->read(16384);
                    if ($chunk === '') {
                        throw new \RuntimeException('Empty provider read.');
                    }
                    $raw .= $chunk;
                    if (strlen($raw) > 2097152) {
                        throw new \RuntimeException('Catalog exceeds limit.');
                    }
                }
                $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($data)) {
                    throw new \RuntimeException('Unexpected catalog response.');
                }
                return $data;
            } finally {
                $stream->close();
            }
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            abort(502, 'Hangar catalog request failed.');
        }
    }

    public function search(string $query, string $version): array
    {
        $params = ['limit' => 20, 'offset' => 0, 'platform' => 'PAPER', 'version' => $version];
        if ($query !== '') {
            $params['query'] = $query;
        }
        $data = $this->get('projects', $params);
        $results = [];
        foreach (array_slice(is_array($data['result'] ?? null) ? $data['result'] : [], 0, 20) as $row) {
            if (!is_array($row) || ($row['visibility'] ?? null) !== 'public' || !is_int($row['id'] ?? null) || $row['id'] < 1) {
                continue;
            }
            $owner = $row['namespace']['owner'] ?? '';
            $slug = $row['namespace']['slug'] ?? '';
            if (!is_string($owner) || !is_string($slug) || !preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $owner) || !preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $slug)) {
                continue;
            }
            $results[] = [
                'id' => (string) $row['id'], 'title' => $this->short($row['name'] ?? '', 100),
                'description' => $this->short($row['description'] ?? '', 300), 'author' => $owner,
                'license' => 'Review project license', 'downloads' => max(0, (int) ($row['stats']['downloads'] ?? 0)),
                'source_url' => 'https://hangar.papermc.io/' . rawurlencode($owner) . '/' . rawurlencode($slug),
            ];
        }
        return $results;
    }

    public function versions(string $project, string $version): array
    {
        abort_unless(preg_match(self::ID, $project), 422);
        $data = $this->get('projects/' . $project . '/versions', ['limit' => 25, 'offset' => 0, 'includeHiddenChannels' => 'false']);
        $out = [];
        foreach (array_slice(is_array($data['result'] ?? null) ? $data['result'] : [], 0, 25) as $row) {
            if (is_array($row) && ($item = $this->select($row, $project, $version))) {
                $out[] = $item;
            }
        }
        return $out;
    }

    public function select(array $v, string $project, string $version): ?array
    {
        $flags = $v['channel']['flags'] ?? [];
        $platforms = $v['platformDependencies']['PAPER'] ?? [];
        if (!is_int($v['id'] ?? null) || $v['id'] < 1 || (string) ($v['projectId'] ?? '') !== $project
            || ($v['visibility'] ?? null) !== 'public' || ($v['reviewState'] ?? null) !== 'reviewed'
            || strtolower((string) ($v['channel']['name'] ?? '')) !== 'release'
            || !is_array($flags) || array_intersect($flags, ['UNSTABLE', 'HIDE_BY_DEFAULT'])
            || !is_array($platforms) || !in_array($version, $platforms, true)) {
            return null;
        }
        foreach (($v['pluginDependencies']['PAPER'] ?? []) as $dependency) {
            if (!is_array($dependency) || !empty($dependency['required'])) {
                return null;
            }
        }
        $download = $v['downloads']['PAPER'] ?? null;
        $f = $download['fileInfo'] ?? null;
        if (!is_array($download) || !empty($download['externalUrl']) || !is_array($f)
            || !is_string($f['name'] ?? null) || !str_ends_with(strtolower($f['name']), '.jar')
            || !is_int($f['sizeBytes'] ?? null) || $f['sizeBytes'] < 1 || $f['sizeBytes'] > 67108864
            || !is_string($f['sha256Hash'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/', $f['sha256Hash'])
            || !is_string($download['downloadUrl'] ?? null)) {
            return null;
        }
        $url = parse_url($download['downloadUrl']);
        if (!$url || ($url['scheme'] ?? null) !== 'https' || ($url['host'] ?? null) !== 'hangarcdn.papermc.io'
            || ($url['port'] ?? 443) !== 443 || isset($url['user']) || isset($url['query']) || isset($url['fragment'])) {
            return null;
        }
        return [
            'id' => (string) $v['id'], 'version' => $this->short($v['name'] ?? '', 100),
            'filename' => $this->short($f['name'], 120), 'size' => $f['sizeBytes'], 'sha256' => $f['sha256Hash'],
        ];
    }

    private function short(mixed $value, int $max): string
    {
        return is_string($value) ? mb_substr($value, 0, $max) : '';
    }
}
