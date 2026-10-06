<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('play_sessions', function (Blueprint $table) {
            $table->string('public_id', 12)->nullable()->unique()->after('club_id');
        });

        $random = function (int $length): string {
            $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
            $out = '';
            for ($i = 0; $i < $length; $i++) {
                $out .= $alphabet[random_int(0, 35)];
            }

            return $out;
        };

        DB::table('play_sessions')->orderBy('id')->each(function (object $row) use ($random): void {
            $values = ['public_id' => $random(12)];
            if ($row->checkin_token === null && in_array($row->status, ['draft', 'live'], true)) {
                $values['checkin_token'] = $random(40);
            }
            DB::table('play_sessions')->where('id', $row->id)->update($values);
        });

        Schema::table('play_sessions', function (Blueprint $table) {
            $table->string('public_id', 12)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('play_sessions', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
