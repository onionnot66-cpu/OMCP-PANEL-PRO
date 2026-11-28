<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('omcp_transfers', function (Blueprint $table) {
            $table->uuid('job_id')->primary();
            $table->foreign('job_id')->references('id')->on('omcp_jobs')->cascadeOnDelete();
            $table->unsignedInteger('node_id');
            $table->uuid('instance_id');
            $table->string('direction', 8);
            $table->string('state', 8);
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('omcp_transfers');
    }
};
