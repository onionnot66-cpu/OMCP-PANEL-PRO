<?php

namespace Pterodactyl\Tests\Integration\Omcp;

use Illuminate\Support\Facades\Http;
use Pterodactyl\Services\Omcp\ModpackCatalog;

class ModpackCatalogTest extends OmcpIntegrationTestCase
{
    public function testExactModpackSelection(): void
    {
        $file = ['filename' => 'Fixture.mrpack', 'url' => 'https://cdn.modrinth.com/data/ABCDEFGH/versions/IJKLMNOP/fixture.mrpack',
            'size' => 123, 'primary' => true, 'hashes' => ['sha512' => str_repeat('a', 128)]];
        $v = ['id' => 'IJKLMNOP', 'project_id' => 'ABCDEFGH', 'version_type' => 'release', 'status' => 'listed',
            'game_versions' => ['1.21.4'], 'loaders' => ['fabric'], 'version_number' => '1.0', 'files' => [$file]];
        $catalog = app(ModpackCatalog::class);
        $this->assertSame('IJKLMNOP', $catalog->selectPack($v, 'ABCDEFGH', '1.21.4', 'fabric')['id']);
        $this->assertNull($catalog->selectPack($v, 'ABCDEFGH', '1.21.4', 'forge'));
        $this->assertNull($catalog->selectPack($v, 'ABCDEFGH', '1.20.1', 'fabric'));
        $this->assertNull($catalog->selectPack(array_replace($v, ['files' => [array_replace($file, ['filename' => 'bad.jar'])]]), 'ABCDEFGH', '1.21.4', 'fabric'));
        Http::preventStrayRequests();
        Http::fake(['https://api.modrinth.com/v2/search*' => Http::response(['hits' => [['project_id' => 'ABCDEFGH', 'title' => 'Fixture']]]),
            'https://api.modrinth.com/v2/project/ABCDEFGH/version*' => Http::response([$v])]);
        $this->assertSame('ABCDEFGH', $catalog->searchPacks('Fixture', '1.21.4', 'fabric')[0]['id']);
        $this->assertSame(str_repeat('a', 128), $catalog->packVersions('ABCDEFGH', '1.21.4', 'fabric')[0]['sha512']);
    }
}
