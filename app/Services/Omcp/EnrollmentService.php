<?php

namespace Pterodactyl\Services\Omcp;

use Illuminate\Support\Facades\DB;

class EnrollmentService
{
    public function issue(int $id, bool $rotate = false): ?string
    {
        $token = bin2hex(random_bytes(32));
        return DB::transaction(function () use ($id, $token, $rotate) {
            if (!DB::table('nodes')->where('id', $id)->lockForUpdate()->first()) {
                return null;
            }
            $existing = DB::table('omcp_connections')->where('node_id', $id)->first();
            if ($existing && !$rotate) {
                return null;
            }
            $values = [
                'enrollment_hash' => hash('sha256', $token),
                'enrollment_expires_at' => now()->addMinutes(15),
                'credential_hash' => null,
                'last_seen_at' => null,
                'capabilities' => null,
                'telemetry' => null,
                'updated_at' => now(),
            ];
            if ($existing) {
                DB::table('omcp_connections')->where('node_id', $id)->update($values);
                DB::table('omcp_jobs')->where('node_id', $id)->whereIn('state', ['queued', 'running'])->update([
                    'state' => 'expired', 'lease_hash' => null, 'updated_at' => now(),
                ]);
            } else {
                DB::table('omcp_connections')->insert($values + ['node_id' => $id, 'created_at' => now()]);
            }
            return $token;
        });
    }
}
