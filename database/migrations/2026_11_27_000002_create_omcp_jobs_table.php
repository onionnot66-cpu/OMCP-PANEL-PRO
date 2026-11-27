<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('omcp_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedInteger('node_id');
            $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();
            $table->string('action', 32);
            $table->string('state', 16)->default('queued');
            $table->string('lease_hash', 64)->nullable();
            $table->timestamp('expires_at');
            $table->text('result')->nullable();
            $table->timestamps();
            $table->index(['node_id', 'state', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('omcp_jobs');
    }
};
