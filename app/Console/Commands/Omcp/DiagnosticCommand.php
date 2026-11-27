<?php

namespace Pterodactyl\Console\Commands\Omcp;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Services\Omcp\JobService;

class DiagnosticCommand extends Command
{
    protected $signature = 'omcp:node:diagnostic {node} {--job= : Read an existing job result}';
    protected $description = 'Queue an OMCP connector diagnostic or view its result';

    public function handle(JobService $jobs): int
    {
        $node = filter_var($this->argument('node'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$node) {
            $this->error('Invalid node ID.');
            return self::FAILURE;
        }
        if ($id = $this->option('job')) {
            $job = DB::table('omcp_jobs')->where('node_id', $node)->where('id', $id)->first();
            if (!$job) {
                $this->error('Job not found.');
                return self::FAILURE;
            }
            $this->line(json_encode(['id' => $job->id, 'state' => $job->state, 'result' => $job->result], JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
            return self::SUCCESS;
        }
        $this->line($jobs->enqueueDiagnostic($node));
        return self::SUCCESS;
    }
}
