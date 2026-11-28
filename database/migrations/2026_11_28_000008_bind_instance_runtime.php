<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('omcp_instances', function (Blueprint $table) {
            $table->string('runtime', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('omcp_instances', function (Blueprint $table) {
            $table->dropColumn('runtime');
        });
    }
};
