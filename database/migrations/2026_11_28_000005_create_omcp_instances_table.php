<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('omcp_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedInteger('node_id');
            $table->unsignedInteger('owner_id')->nullable();
            $table->string('name', 100);
            $table->string('software', 16);
            $table->string('version', 32);
            $table->unsignedSmallInteger('port');
            $table->unsignedInteger('memory_mib');
            $table->unsignedInteger('disk_mib');
            $table->timestamps();
            $table->foreign('node_id')->references('id')->on('nodes')->restrictOnDelete();
            $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['node_id', 'port']);
            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('omcp_instances');
    }
};
