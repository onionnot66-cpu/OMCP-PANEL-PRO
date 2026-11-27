<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('omcp_instances', function (Blueprint $table) {
            $table->timestamp('runtime_requested_at')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('omcp_instances', function (Blueprint $table) {
            $table->dropColumn('runtime_requested_at');
        });
    }
};
