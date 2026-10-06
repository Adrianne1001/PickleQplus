<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('play_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('date');
            $table->unsignedTinyInteger('courts');
            $table->json('scoring');
            $table->string('status', 16)->default('draft');
            $table->string('checkin_token', 64)->nullable()->unique();
            $table->unsignedTinyInteger('up_next_count')->default(1);
            $table->boolean('auto_fill')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'status']);
            $table->index(['club_id', 'date']);
        });

        Schema::create('session_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('play_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('waiting');
            $table->timestamp('checked_in_at')->nullable();
            $table->unsignedInteger('games_played')->default(0);
            $table->unsignedInteger('games_credit')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->timestamps();

            $table->unique(['play_session_id', 'player_id']);
            $table->index(['player_id', 'status']);
        });

        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('play_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('court_no')->nullable();
            $table->string('status', 16)->default('staged');
            $table->unsignedSmallInteger('team_a_score')->nullable();
            $table->unsignedSmallInteger('team_b_score')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->boolean('dupr_eligible')->default(true);
            $table->timestamp('dupr_exported_at')->nullable();
            $table->timestamp('dupr_synced_at')->nullable();
            $table->string('dupr_match_ref')->nullable();
            $table->timestamps();

            $table->index(['play_session_id', 'status']);
        });

        Schema::create('match_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('team', 1);
            $table->unsignedTinyInteger('slot');
            $table->timestamps();

            $table->unique(['match_id', 'team', 'slot']);
            $table->unique(['match_id', 'player_id']);
            $table->index('player_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_players');
        Schema::dropIfExists('matches');
        Schema::dropIfExists('session_players');
        Schema::dropIfExists('play_sessions');
    }
};
