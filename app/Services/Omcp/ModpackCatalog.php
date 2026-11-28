<?php

namespace Pterodactyl\Services\Omcp;

class ModpackCatalog extends ModrinthCatalog
{
    public const LOADERS = ['fabric', 'forge', 'neoforge', 'quilt'];

    public function searchPacks(string $query, string $version, string $loader): array
    {
        abort_unless(in_array($loader, self::LOADERS, true), 422);
        $data = $this->get('search', [
            'query' => $query, 'limit' => 20, 'index' => 'relevance',
            'facets' => json_encode([['project_type:modpack'], ['categories:' . $loader], ['versions:' . $version]], JSON_THROW_ON_ERROR),
        ]);
        $out = [];
        foreach (array_slice(is_array($data['hits'] ?? null) ? $data['hits'] : [], 0, 20) as $hit) {
            if (!is_array($hit) || !is_string($hit['project_id'] ?? null) || !preg_match('/\A[A-Za-z0-9]{3,32}\z/', $hit['project_id'])) {
                continue;
            }
            $out[] = ['id' => $hit['project_id'], 'title' => $this->short($hit['title'] ?? '', 100),
                'description' => $this->short($hit['description'] ?? '', 300), 'author' => $this->short($hit['author'] ?? '', 80)];
        }
        return $out;
    }

    public function packVersions(string $project, string $version, string $loader): array
    {
        abort_unless(preg_match('/\A[A-Za-z0-9]{3,32}\z/', $project) && in_array($loader, self::LOADERS, true), 422);
        $data = $this->get('project/' . $project . '/version', ['loaders' => json_encode([$loader]), 'game_versions' => json_encode([$version])]);
        $out = [];
        foreach ($data as $row) {
            if (is_array($row) && ($item = $this->selectPack($row, $project, $version, $loader))) {
                $out[] = $item;
            }
            if (count($out) >= 30) {
                break;
            }
        }
        return $out;
    }

    public function selectPack(array $v, string $project, string $version, string $loader): ?array
    {
        if (($v['project_id'] ?? null) !== $project || !is_string($v['id'] ?? null) || !preg_match('/\A[A-Za-z0-9]{3,32}\z/', $v['id'])
            || ($v['version_type'] ?? null) !== 'release' || !in_array($v['status'] ?? '', ['', 'listed'], true)
            || !is_array($v['game_versions'] ?? null) || !in_array($version, $v['game_versions'], true)
            || !is_array($v['loaders'] ?? null) || !in_array($loader, $v['loaders'], true) || !in_array($loader, self::LOADERS, true)) {
            return null;
        }
        $files = $v['files'] ?? [];
        if (!is_array($files) || !$files || array_filter($files, static fn ($f) => !is_array($f))) {
            return null;
        }
        $primary = array_values(array_filter($files, static fn ($f) => ($f['primary'] ?? false) === true));
        $f = count($primary) === 1 ? $primary[0] : (!$primary && count($files) === 1 ? reset($files) : null);
        if (!$f || !is_string($f['filename'] ?? null) || !str_ends_with(strtolower($f['filename']), '.mrpack')
            || !is_int($f['size'] ?? null) || $f['size'] < 1 || $f['size'] > 67108864
            || !is_string($f['hashes']['sha512'] ?? null) || !preg_match('/\A[a-f0-9]{128}\z/', $f['hashes']['sha512'])
            || !is_string($f['url'] ?? null)) {
            return null;
        }
        $url = parse_url($f['url']);
        if (!$url || ($url['scheme'] ?? null) !== 'https' || ($url['host'] ?? null) !== 'cdn.modrinth.com'
            || ($url['port'] ?? 443) !== 443 || isset($url['user']) || isset($url['query']) || isset($url['fragment'])) {
            return null;
        }
        return ['id' => $v['id'], 'version' => $this->short($v['version_number'] ?? '', 100), 'filename' => $this->short($f['filename'], 120),
            'sha512' => $f['hashes']['sha512'], 'size' => $f['size']];
    }
}
