<?php

namespace Pterodactyl\Tests\Integration\Omcp;

use Illuminate\Support\Facades\Http;
use Pterodactyl\Services\Omcp\HangarCatalog;

class HangarCatalogTest extends OmcpIntegrationTestCase
{
    public function testReviewedHostedPaperReleaseSelection(): void
    {
        $file = ['name' => 'Fixture.jar', 'sizeBytes' => 100, 'sha256Hash' => str_repeat('a', 64)];
        $download = ['fileInfo' => $file, 'externalUrl' => null,
            'downloadUrl' => 'https://hangarcdn.papermc.io/plugins/test/Test/versions/1/PAPER/Fixture.jar'];
        $version = ['id' => 2, 'projectId' => 1, 'name' => '1.0', 'visibility' => 'public', 'reviewState' => 'reviewed',
            'channel' => ['name' => 'Release', 'flags' => []], 'platformDependencies' => ['PAPER' => ['1.21.4']],
            'pluginDependencies' => ['PAPER' => []], 'downloads' => ['PAPER' => $download]];
        $catalog = app(HangarCatalog::class);
        $this->assertSame('2', $catalog->select($version, '1', '1.21.4')['id']);
        $this->assertNull($catalog->select($version, '3', '1.21.4'));
        $this->assertNull($catalog->select($version, '1', '1.20.1'));
        $this->assertNull($catalog->select(array_replace($version, ['reviewState' => 'unreviewed']), '1', '1.21.4'));
        $this->assertNull($catalog->select(array_replace($version, ['pluginDependencies' => ['PAPER' => [['required' => true]]]]), '1', '1.21.4'));
        $this->assertNull($catalog->select(array_replace($version, ['downloads' => ['PAPER' => array_replace($download, ['externalUrl' => 'https://example.com/test.jar'])]]), '1', '1.21.4'));
        Http::preventStrayRequests();
        Http::fake([
            'https://hangar.papermc.io/api/v1/projects?*' => Http::response(['result' => [
                ['id' => 1, 'visibility' => 'public', 'name' => 'Fixture', 'namespace' => ['owner' => 'test', 'slug' => 'Test'], 'stats' => ['downloads' => 100]],
            ]]),
            'https://hangar.papermc.io/api/v1/projects/1/versions*' => Http::response(['result' => [$version]]),
        ]);
        $this->assertSame('1', $catalog->search('Fixture', '1.21.4')[0]['id']);
        $this->assertSame(str_repeat('a', 64), $catalog->versions('1', '1.21.4')[0]['sha256']);
        Http::assertSentCount(2);
    }
}
