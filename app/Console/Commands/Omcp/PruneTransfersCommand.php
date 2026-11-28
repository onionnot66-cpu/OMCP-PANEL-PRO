<?php

namespace Pterodactyl\Console\Commands\Omcp;

use Illuminate\Console\Command;
use Pterodactyl\Services\Omcp\TransferService;

class PruneTransfersCommand extends Command
{
    protected $signature = 'omcp:transfers:prune';
    protected $description = 'Remove expired OMCP binary transfers from the private local spool';

    public function handle(TransferService $transfers): int
    {
        $this->info('Expired transfers removed: ' . $transfers->prune());
        return 0;
    }
}
