<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            // Unique per club. MySQL's default collation makes this index case-insensitive;
            // validation (Player::nicknameTaken) enforces it on every driver.
            $table->string('nickname', 20)->nullable()->after('name');
            $table->timestamp('self_registered_at')->nullable()->after('active');

            $table->unique(['club_id', 'nickname']);
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropUnique(['club_id', 'nickname']);
            $table->dropColumn(['nickname', 'self_registered_at']);
        });
    }
};
