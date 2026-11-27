<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('omcp_connections', function (Blueprint $table) {
            $table->unsignedInteger('node_id')->primary();
            $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();
            $table->string('enrollment_hash', 64)->nullable();
            $table->timestamp('enrollment_expires_at')->nullable();
            $table->string('credential_hash', 64)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('capabilities')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('omcp_connections');
    }
};
