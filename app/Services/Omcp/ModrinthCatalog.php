<?php

namespace Pterodactyl\Services\Omcp;

use Illuminate\Support\Facades\Http;

class ModrinthCatalog
{
    private const BASE = 'https://api.modrinth.com/v2/';
    private const ID = '/\A[A-Za-z0-9]{3,32}\z/';
    private const MAX_FILE = 67108864;

    private function get(string $path, array $query): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'OMCP-Panel/0.1 (https://github.com/onionnot66-cpu/OMCP-PANEL-PRO)'])
                ->acceptJson()->connectTimeout(5)->timeout(15)
                ->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => 10])
                ->get(self::BASE . $path, $query);
            $body = $response->toPsrResponse()->getBody();
            try {
                abort_unless($response->successful(), 502, 'Modrinth catalog unavailable.');
                $raw = '';
                while (!$body->eof()) {
                    $chunk = $body->read(16384);
                    if ($chunk === '') {
                        throw new \RuntimeException('Empty provider stream read.');
                    }
                    $raw .= $chunk;
                    if (strlen($raw) > 2097152) {
                        throw new \RuntimeException('Catalog response exceeds limit.');
                    }
                }
                $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($data)) {
                    throw new \RuntimeException('Unexpected catalog response.');
                }
                return $data;
            } finally {
                $body->close();
            }
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            abort(502, 'Modrinth catalog request failed.');
        }
    }

    public function search(string $query, string $version): array
    {
        $data = $this->get('search', [
            'query' => $query, 'limit' => 20, 'index' => 'relevance',
            'facets' => json_encode([['categories:paper', 'categories:spigot', 'categories:bukkit'], ['versions:' . $version]], JSON_THROW_ON_ERROR),
        ]);
        $results = [];
        foreach (array_slice(is_array($data['hits'] ?? null) ? $data['hits'] : [], 0, 20) as $hit) {
            if (!is_array($hit) || !is_string($hit['project_id'] ?? null) || !preg_match(self::ID, $hit['project_id'])) {
                continue;
            }
            $results[] = [
                'id' => $hit['project_id'],
                'title' => $this->short($hit['title'] ?? '', 100),
                'description' => $this->short($hit['description'] ?? '', 300),
                'author' => $this->short($hit['author'] ?? '', 80),
                'license' => $this->short($hit['license'] ?? '', 80),
                'downloads' => max(0, is_int($hit['downloads'] ?? null) ? $hit['downloads'] : 0),
            ];
        }
        return $results;
    }

    public function versions(string $project, string $version): array
    {
        abort_unless(preg_match(self::ID, $project), 422);
        $data = $this->get('project/' . $project . '/version', [
            'loaders' => json_encode(['paper', 'spigot', 'bukkit']),
            'game_versions' => json_encode([$version]),
        ]);
        $results = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $selected = $this->select($row, $project, $version);
            if ($selected) {
                $results[] = $selected;
            }
            if (count($results) >= 30) {
                break;
            }
        }
        return $results;
    }

    public function select(array $v, string $project, string $version): ?array
    {
        if (($v['project_id'] ?? null) !== $project || !is_string($v['id'] ?? null) || !preg_match(self::ID, $v['id'])
            || ($v['version_type'] ?? null) !== 'release' || !in_array($v['status'] ?? '', ['', 'listed'], true)
            || !is_array($v['game_versions'] ?? null) || !in_array($version, $v['game_versions'], true)
            || !is_array($v['loaders'] ?? null) || !array_intersect(['paper', 'spigot', 'bukkit'], $v['loaders'])) {
            return null;
        }
        foreach (is_array($v['dependencies'] ?? null) ? $v['dependencies'] : [] as $dep) {
            if (!is_array($dep) || in_array($dep['dependency_type'] ?? null, ['required', 'incompatible'], true)) {
                return null;
            }
        }
        $files = $v['files'] ?? [];
        if (!is_array($files) || !$files || array_filter($files, static fn ($f) => !is_array($f))) {
            return null;
        }
        $primary = array_values(array_filter($files, static fn ($f) => ($f['primary'] ?? false) === true));
        $file = count($primary) === 1 ? $primary[0] : (!$primary && count($files) === 1 ? reset($files) : null);
        if (!$file || !is_string($file['filename'] ?? null) || !str_ends_with(strtolower($file['filename']), '.jar')
            || !is_int($file['size'] ?? null) || $file['size'] < 1 || $file['size'] > self::MAX_FILE
            || !is_string($file['hashes']['sha512'] ?? null) || !preg_match('/\A[a-f0-9]{128}\z/', $file['hashes']['sha512'])
            || !is_string($file['url'] ?? null)) {
            return null;
        }
        $url = parse_url($file['url']);
        if (!$url || ($url['scheme'] ?? null) !== 'https' || ($url['host'] ?? null) !== 'cdn.modrinth.com'
            || ($url['port'] ?? 443) !== 443 || isset($url['user'], $url['pass']) || isset($url['user']) || isset($url['query']) || isset($url['fragment'])) {
            return null;
        }
        return [
            'id' => $v['id'], 'version' => $this->short($v['version_number'] ?? '', 100),
            'filename' => $this->short($file['filename'], 120), 'sha512' => $file['hashes']['sha512'], 'size' => $file['size'],
        ];
    }

    private function short(mixed $value, int $max): string
    {
        return is_string($value) ? mb_substr($value, 0, $max) : '';
    }
}
