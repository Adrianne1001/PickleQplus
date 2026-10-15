<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->boolean('is_guest')->default(false)->after('leaderboard_min_games');
            $table->char('guest_token_hash', 64)->nullable()->unique()->after('is_guest');
            $table->timestamp('guest_expires_at')->nullable()->index()->after('guest_token_hash');
            $table->timestamp('guest_claimed_at')->nullable()->after('guest_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropUnique(['guest_token_hash']);
            $table->dropIndex(['guest_expires_at']);
            $table->dropColumn(['is_guest', 'guest_token_hash', 'guest_expires_at', 'guest_claimed_at']);
        });
    }
};
