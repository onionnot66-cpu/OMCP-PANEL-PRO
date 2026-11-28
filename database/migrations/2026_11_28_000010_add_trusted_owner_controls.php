<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('omcp_instances', function (Blueprint $table) {
            $table->unsignedInteger('control_owner_id')->nullable();
            $table->uuid('control_grant_id')->nullable();
            $table->foreign('control_owner_id')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('omcp_jobs', function (Blueprint $table) {
            $table->unsignedInteger('requester_id')->nullable();
            $table->uuid('instance_id')->nullable();
            $table->uuid('control_grant_id')->nullable();
            $table->foreign('requester_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['instance_id', 'requester_id']);
        });
    }

    public function down(): void
    {
        // Reverting to a connector without owner-job restrictions requires
        // local reconciliation. No queued owner jobs may survive rollback.
        if (\Illuminate\Support\Facades\DB::table('omcp_jobs')->whereNotNull('control_grant_id')->whereIn('state', ['queued', 'running'])->exists()) {
            throw new RuntimeException('Owner jobs exist; drain them before rollback.');
        }
        Schema::table('omcp_jobs', function (Blueprint $table) {
            $table->dropForeign(['requester_id']);
            $table->dropIndex(['instance_id', 'requester_id']);
            $table->dropColumn(['requester_id', 'instance_id', 'control_grant_id']);
        });
        Schema::table('omcp_instances', function (Blueprint $table) {
            $table->dropForeign(['control_owner_id']);
            $table->dropColumn(['control_owner_id', 'control_grant_id']);
        });
    }
};
