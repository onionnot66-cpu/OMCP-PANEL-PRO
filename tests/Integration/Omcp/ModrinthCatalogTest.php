<?php

namespace Pterodactyl\Tests\Integration\Omcp;

use Illuminate\Support\Facades\Http;
use Pterodactyl\Services\Omcp\ModrinthCatalog;

class ModrinthCatalogTest extends OmcpIntegrationTestCase
{
    public function testCatalogSelectionRejectsUnsafeOrIncompatibleReleases(): void
    {
        $file = ['url' => 'https://cdn.modrinth.com/data/ABCDEFGH/versions/IJKLMNOP/test.jar',
            'filename' => 'test.jar', 'size' => 123, 'primary' => true, 'hashes' => ['sha512' => str_repeat('a', 128)]];
        $version = ['id' => 'IJKLMNOP', 'project_id' => 'ABCDEFGH', 'version_type' => 'release', 'status' => 'listed',
            'version_number' => '1.0', 'game_versions' => ['1.21.4'], 'loaders' => ['paper'], 'files' => [$file], 'dependencies' => []];
        $catalog = app(ModrinthCatalog::class);
        $this->assertSame('IJKLMNOP', $catalog->select($version, 'ABCDEFGH', '1.21.4')['id']);
        $this->assertNull($catalog->select($version, 'ABCDEFGH', '1.20.1'));
        $this->assertNull($catalog->select(array_replace($version, ['loaders' => ['fabric']]), 'ABCDEFGH', '1.21.4'));
        $this->assertNull($catalog->select(array_replace($version, ['dependencies' => [['dependency_type' => 'required']]]), 'ABCDEFGH', '1.21.4'));
        $this->assertNull($catalog->select(array_replace($version, ['files' => [array_replace($file, ['url' => 'https://example.com/test.jar'])]]), 'ABCDEFGH', '1.21.4'));
        $this->assertNull($catalog->select(array_replace($version, ['files' => [$file, $file]]), 'ABCDEFGH', '1.21.4'));

        Http::preventStrayRequests();
        Http::fake(['https://api.modrinth.com/v2/search*' => Http::response(['hits' => [
            ['project_id' => 'ABCDEFGH', 'title' => 'Test', 'description' => 'Example', 'downloads' => 123],
        ]]), 'https://api.modrinth.com/v2/project/ABCDEFGH/version*' => Http::response([$version])]);
        $this->assertSame('ABCDEFGH', $catalog->search('Test', '1.21.4')[0]['id']);
        $this->assertSame(str_repeat('a', 128), $catalog->versions('ABCDEFGH', '1.21.4')[0]['sha512']);
        Http::assertSentCount(2);
    }
}
