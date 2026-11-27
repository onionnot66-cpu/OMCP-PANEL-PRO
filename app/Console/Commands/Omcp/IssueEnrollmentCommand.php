<?php

namespace Pterodactyl\Console\Commands\Omcp;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IssueEnrollmentCommand extends Command
{
    protected $signature = 'omcp:node:enroll {node : Existing node ID} {--rotate : Revoke existing enrollment and connector credentials}';
    protected $description = 'Issue a single-use OMCP Connect enrollment token (expires in 15 minutes)';

    public function handle(): int
    {
        $id = filter_var($this->argument('node'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) {
            $this->error('Invalid node ID.');
            return self::FAILURE;
        }

        $token = bin2hex(random_bytes(32));
        $issued = DB::transaction(function () use ($id, $token) {
            if (!DB::table('nodes')->where('id', $id)->lockForUpdate()->first()) {
                return false;
            }
            $existing = DB::table('omcp_connections')->where('node_id', $id)->first();
            if ($existing && !$this->option('rotate')) {
                return false;
            }

            $values = [
                'enrollment_hash' => hash('sha256', $token),
                'enrollment_expires_at' => now()->addMinutes(15),
                'credential_hash' => null,
                'last_seen_at' => null,
                'capabilities' => null,
                'updated_at' => now(),
            ];
            if ($existing) {
                DB::table('omcp_connections')->where('node_id', $id)->update($values);
            } else {
                DB::table('omcp_connections')->insert($values + ['node_id' => $id, 'created_at' => now()]);
            }
            return true;
        });

        if (!$issued) {
            $this->error('Node not found or already has a connection record. Use --rotate only to revoke that record.');
            return self::FAILURE;
        }

        $this->warn('Secret: enter privately at the connector prompt. Do not share or log this token.');
        $this->line($token);
        return self::SUCCESS;
    }
}
