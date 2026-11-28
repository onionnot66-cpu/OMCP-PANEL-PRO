<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('omcp_live_console', function (Blueprint $table) {
            $table->uuid('instance_id')->primary();
            $table->foreign('instance_id')->references('id')->on('omcp_instances')->cascadeOnDelete();
            $table->uuid('grant_id');
            $table->string('credential_hash', 64);
            $table->text('output');
            $table->timestamp('captured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('omcp_live_console');
    }
};
