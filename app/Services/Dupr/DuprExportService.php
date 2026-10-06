<?php

namespace App\Services\Dupr;

use App\Models\DuprExport;
use App\Models\GameMatch;
use App\Models\PlaySession;
use App\Models\User;
use App\Services\LocksPlaySession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Exports a session's eligible matches to DUPR in one transaction (PLAN Phase 4 rules).
 */
class DuprExportService
{
    use LocksPlaySession;

    public function __construct(
        private readonly DuprEligibility $eligibility,
        private readonly DuprPublisher $publisher,
    ) {}

    /**
     * @throws ValidationException when the session has not ended or nothing is eligible
     */
    public function export(PlaySession $session, User $user): DuprExport
    {
        $written = null;

        try {
            return DB::transaction(function () use ($session, $user, &$written): DuprExport {
                $this->lockSession($session);

                if (! $session->isEnded()) {
                    throw ValidationException::withMessages(['session' => 'End the session before exporting to DUPR.']);
                }

                $summary = $this->eligibility->summarize($session);
                if (! $summary->hasEligible()) {
                    throw ValidationException::withMessages(['session' => 'There are no matches to export.']);
                }

                $export = $this->publisher->publish($session, $summary->eligible, $user);
                $written = $export->file_path;

                GameMatch::query()
                    ->whereIn('id', $summary->eligible->pluck('id')->all())
                    ->update(['dupr_exported_at' => now(), 'dupr_export_id' => $export->id]);

                return $export;
            });
        } catch (Throwable $e) {
            // The transaction rolled back the row and stamps; remove a file that was already written.
            if ($written !== null && $written !== '') {
                Storage::disk('local')->delete($written);
            }

            throw $e;
        }
    }
}
