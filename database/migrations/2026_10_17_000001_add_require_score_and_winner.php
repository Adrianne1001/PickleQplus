<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->boolean('require_score')->default(true);
        });

        Schema::table('play_sessions', function (Blueprint $table) {
            $table->boolean('require_score')->default(true);
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->string('winner', 1)->nullable()->after('team_b_score');
        });

        // Every finished match that has both scores gets its winner from the higher score.
        DB::table('matches')
            ->where('status', 'done')
            ->whereNotNull('team_a_score')
            ->whereNotNull('team_b_score')
            ->whereColumn('team_a_score', '>', 'team_b_score')
            ->update(['winner' => 'A']);
        DB::table('matches')
            ->where('status', 'done')
            ->whereNotNull('team_a_score')
            ->whereNotNull('team_b_score')
            ->whereColumn('team_b_score', '>', 'team_a_score')
            ->update(['winner' => 'B']);
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('winner');
        });

        Schema::table('play_sessions', function (Blueprint $table) {
            $table->dropColumn('require_score');
        });

        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn('require_score');
        });
    }
};
