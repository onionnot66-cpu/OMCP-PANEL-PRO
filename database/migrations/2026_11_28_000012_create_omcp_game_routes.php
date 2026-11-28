<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('omcp_game_routes', function (Blueprint $table) {
            $table->uuid('instance_id')->primary();
            $table->string('provider', 24);
            $table->string('endpoint_host', 253);
            $table->unsignedSmallInteger('endpoint_port');
            $table->string('dns_state', 16)->default('none');
            $table->string('dns_zone', 32)->nullable();
            $table->string('hostname', 253)->nullable();
            $table->timestamps();
            $table->foreign('instance_id')->references('id')->on('omcp_instances')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('omcp_game_routes');
    }
};
