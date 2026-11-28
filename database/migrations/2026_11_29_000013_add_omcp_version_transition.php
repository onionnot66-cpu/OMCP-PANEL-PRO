<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('omcp_instances', function (Blueprint $table) {
            $table->uuid('version_job_id')->nullable();
            $table->string('version_target', 32)->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('omcp_instances', function (Blueprint $table) {
            $table->dropColumn(['version_job_id', 'version_target']);
        });
    }
};
