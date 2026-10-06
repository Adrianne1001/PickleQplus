<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->string('late_arrival_policy', 16)->default('minimum');
            $table->boolean('allow_concurrent_sessions')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn(['late_arrival_policy', 'allow_concurrent_sessions']);
        });
    }
};
