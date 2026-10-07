<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('play_sessions', function (Blueprint $table) {
            $table->string('rotation_mode', 16)->default('balanced');
            $table->json('mode_settings')->nullable();
        });

        Schema::table('players', function (Blueprint $table) {
            $table->string('gender', 8)->nullable()->after('nickname');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('gender');
        });

        Schema::table('play_sessions', function (Blueprint $table) {
            $table->dropColumn(['rotation_mode', 'mode_settings']);
        });
    }
};
