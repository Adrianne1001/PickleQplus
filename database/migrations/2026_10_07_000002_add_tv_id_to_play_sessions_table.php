<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('play_sessions', function (Blueprint $table) {
            $table->string('tv_id', 32)->nullable()->unique()->after('public_id');
        });

        DB::table('play_sessions')->orderBy('id')->each(function (object $row): void {
            DB::table('play_sessions')->where('id', $row->id)->update(['tv_id' => Str::random(32)]);
        });

        Schema::table('play_sessions', function (Blueprint $table) {
            $table->string('tv_id', 32)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('play_sessions', function (Blueprint $table) {
            $table->dropUnique(['tv_id']);
            $table->dropColumn('tv_id');
        });
    }
};
