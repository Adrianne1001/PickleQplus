<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_list_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('play_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('source', 16)->default('manual');
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['play_session_id', 'player_id']);
            $table->index('player_id');
        });

        // Everyone who is already checked in is on the list.
        DB::table('session_list_players')->insertUsing(
            ['play_session_id', 'player_id', 'source', 'created_at', 'updated_at'],
            DB::table('session_players')->select(['play_session_id', 'player_id', DB::raw("'manual'"), 'created_at', 'updated_at']),
        );

        Schema::table('players', function (Blueprint $table) {
            $table->boolean('stars_pending')->default(false)->after('stars');
        });

        Schema::table('play_sessions', function (Blueprint $table) {
            $table->text('checkin_password')->nullable()->after('checkin_token');
        });
    }

    public function down(): void
    {
        Schema::table('play_sessions', function (Blueprint $table) {
            $table->dropColumn('checkin_password');
        });

        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('stars_pending');
        });

        Schema::dropIfExists('session_list_players');
    }
};
