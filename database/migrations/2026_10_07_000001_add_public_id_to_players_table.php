<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->string('public_id', 12)->nullable()->unique()->after('club_id');
            $table->foreignId('self_registered_session_id')->nullable()->after('self_registered_at')
                ->constrained('play_sessions')->nullOnDelete();
        });

        DB::table('players')->orderBy('id')->each(function (object $row): void {
            $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
            $id = '';
            for ($i = 0; $i < 12; $i++) {
                $id .= $alphabet[random_int(0, 35)];
            }
            DB::table('players')->where('id', $row->id)->update(['public_id' => $id]);
        });

        Schema::table('players', function (Blueprint $table) {
            $table->string('public_id', 12)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropConstrainedForeignId('self_registered_session_id');
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
