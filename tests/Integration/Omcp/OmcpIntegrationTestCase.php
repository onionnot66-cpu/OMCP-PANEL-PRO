<?php

namespace Pterodactyl\Tests\Integration\Omcp;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

/**
 * HTTP errors in the upstream application roll back outer transactions.
 * Every OMCP test gets its own migrated database instead of an outer transaction.
 */
abstract class OmcpIntegrationTestCase extends IntegrationTestCase
{
    use DatabaseMigrations;

    protected $seed = true;

    protected function beforeRefreshingDatabase(): void
    {
        $database = (string) config('database.connections.' . config('database.default') . '.database');
        if (!preg_match('/\Aomcp_test_[A-Za-z0-9_]+\z/', $database)) {
            throw new \RuntimeException('OMCP tests require a disposable omcp_test_* database.');
        }
    }
}
