<?php

namespace Pterodactyl\Console\Commands\Omcp;

use Illuminate\Console\Command;
use Pterodactyl\Services\Omcp\EnrollmentService;

class IssueEnrollmentCommand extends Command
{
    protected $signature = 'omcp:node:enroll {node : Existing node ID} {--rotate : Revoke existing enrollment and connector credentials}';
    protected $description = 'Issue a single-use OMCP Connect enrollment token (expires in 15 minutes)';

    public function handle(EnrollmentService $service): int
    {
        $id = filter_var($this->argument('node'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) {
            $this->error('Invalid node ID.');
            return self::FAILURE;
        }
        $token = $service->issue($id, (bool) $this->option('rotate'));
        if ($token === null) {
            $this->error('Node not found or already has a connection record. Use --rotate only to revoke that record.');
            return self::FAILURE;
        }
        $this->warn('Secret: enter privately at the connector prompt. Do not share or log this token.');
        $this->line($token);
        return self::SUCCESS;
    }
}
