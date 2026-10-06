<?php

namespace App\Services\Dupr;

use App\Domain\Dupr\DuprCsvBuilder;
use App\Domain\Dupr\DuprCsvRow;
use App\Enums\Team;
use App\Models\DuprExport;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\PlaySession;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Writes the DUPR doubles-import CSV to the private `local` disk at
 * dupr-exports/{club_id}/{export_id}.csv and records the dupr_exports row.
 * Call inside the caller's transaction; if the file write fails nothing is left behind.
 */
class CsvPublisher implements DuprPublisher
{
    public function __construct(private readonly DuprCsvBuilder $builder) {}

    public function publish(PlaySession $session, Collection $matches, User $user): DuprExport
    {
        $location = $session->club()->firstOrFail()->name;
        $rows = $matches->map(fn (GameMatch $m): DuprCsvRow => $this->row($session, $location, $m))->values()->all();
        $csv = $this->builder->build($rows);

        $export = $session->duprExports()->create([
            'user_id' => $user->id,
            'match_count' => count($rows),
            'file_path' => '',
        ]);
        $export->file_path = 'dupr-exports/'.$session->club_id.'/'.$export->id.'.csv';
        $export->save();

        if (! Storage::disk('local')->put($export->file_path, $csv)) {
            throw new RuntimeException('Could not write the DUPR export file.');
        }

        return $export;
    }

    private function row(PlaySession $session, string $location, GameMatch $match): DuprCsvRow
    {
        /** @var array<string, array{0: string, 1: string}> $slots */
        $slots = [];
        foreach ($match->matchPlayers as $mp) {
            /** @var MatchPlayer $mp */
            $player = $mp->player;
            if ($player === null) {
                throw new RuntimeException('A match player is missing.');
            }
            $slots[$mp->team->value.$mp->slot] = [$player->name, (string) $player->dupr_id];
        }

        return new DuprCsvRow(
            event: $session->name,
            date: $session->date->format('Y-m-d'),
            a1: $slots[Team::A->value.'1'],
            a2: $slots[Team::A->value.'2'],
            b1: $slots[Team::B->value.'1'],
            b2: $slots[Team::B->value.'2'],
            location: $location,
            games: [[$match->team_a_score, $match->team_b_score]],
        );
    }
}
