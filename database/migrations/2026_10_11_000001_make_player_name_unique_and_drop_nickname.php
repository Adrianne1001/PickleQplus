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
        $this->dedupeNames();

        // Each step is guarded and the new unique index goes first, so a run that failed halfway
        // (MySQL cannot roll back DDL) can simply be run again.
        if (! Schema::hasIndex('players', 'players_club_id_name_unique')) {
            Schema::table('players', function (Blueprint $table) {
                // MySQL's default collation makes this index case-insensitive; the SQLite tests rely on
                // validation (Player::nameTaken) for that. Every write path also catches the violation.
                $table->unique(['club_id', 'name']);
            });
        }

        if (Schema::hasIndex('players', 'players_club_id_nickname_unique')) {
            Schema::table('players', function (Blueprint $table) {
                $table->dropUnique(['club_id', 'nickname']);
            });
        }

        if (Schema::hasColumn('players', 'nickname')) {
            Schema::table('players', function (Blueprint $table) {
                $table->dropColumn('nickname');
            });
        }

        // The unique index above covers the plain (club_id, name) index from create_players_table.
        if (Schema::hasIndex('players', 'players_club_id_name_index')) {
            Schema::table('players', function (Blueprint $table) {
                $table->dropIndex(['club_id', 'name']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->index(['club_id', 'name']);
        });

        Schema::table('players', function (Blueprint $table) {
            $table->dropUnique(['club_id', 'name']);
        });

        Schema::table('players', function (Blueprint $table) {
            // Renamed duplicates are not restored; nicknames are gone for good.
            $table->string('nickname', 20)->nullable()->after('name');
            $table->unique(['club_id', 'nickname']);
        });
    }

    /**
     * Within each club, the first player (lowest id) keeps a name; later players with the same name
     * (case-insensitive, trimmed) get " 2", " 3", ... appended, skipping any name already in use.
     */
    private function dedupeNames(): void
    {
        $mysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
        $key = function (string $name) use ($mysql): string {
            $name = mb_strtolower(trim($name));
            if ($mysql) {
                // utf8mb4_unicode_ci also folds accents, so the unique index would reject these too.
                $ascii = mb_strtolower(Str::ascii($name));

                return $ascii !== '' ? $ascii : $name;
            }

            return $name;
        };

        /** @var array<int, array<string, true>> $taken club id => keys in use */
        $taken = [];
        /** @var list<object{id: int, club_id: int, name: string}> $duplicates */
        $duplicates = [];

        foreach (DB::table('players')->orderBy('id')->get(['id', 'club_id', 'name']) as $row) {
            $k = $key((string) $row->name);
            if (isset($taken[$row->club_id][$k])) {
                $duplicates[] = $row;
            } else {
                $taken[$row->club_id][$k] = true;
            }
        }

        foreach ($duplicates as $row) {
            $base = trim((string) $row->name);
            $n = 2;
            do {
                $suffix = ' '.$n++;
                $candidate = mb_substr($base, 0, 120 - mb_strlen($suffix)).$suffix;
                $k = $key($candidate);
            } while (isset($taken[$row->club_id][$k]) || ($mysql && $this->nameExists((int) $row->club_id, $candidate)));

            $taken[$row->club_id][$k] = true;
            DB::table('players')->where('id', $row->id)->update(['name' => $candidate]);
        }
    }

    /**
     * Asks the database, so the column collation (not our folding) decides equality.
     */
    private function nameExists(int $clubId, string $name): bool
    {
        return DB::table('players')->where('club_id', $clubId)->where('name', $name)->exists();
    }
};
