<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('omcp_instances', function (Blueprint $table) {
            $table->unsignedSmallInteger('port')->nullable()->change();
            $table->unsignedSmallInteger('retired_port')->nullable();
            $table->uuid('retirement_job_id')->nullable();
            $table->timestamp('retired_at')->nullable();
        });
    }

    public function down(): void
    {
        // Restoring a retired port could conflict with a newer reservation.
        if (DB::table('omcp_instances')->whereNotNull('retirement_job_id')->orWhereNotNull('retired_at')->exists()) {
            throw new RuntimeException('Retirement records exist; refusing unsafe schema rollback.');
        }
        Schema::table('omcp_instances', function (Blueprint $table) {
            $table->unsignedSmallInteger('port')->nullable(false)->change();
            $table->dropColumn(['retired_port', 'retirement_job_id', 'retired_at']);
        });
    }
};
