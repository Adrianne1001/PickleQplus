<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\PlaySession;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Re-download a past DUPR export. Owners and staff of the club only; files are never public.
 */
class DuprExportDownloadController extends Controller
{
    public function __invoke(Club $club, PlaySession $session, string $export): StreamedResponse
    {
        abort_unless($session->club_id === $club->id, 404);
        Gate::authorize('view', $session);

        $record = ctype_digit($export) ? $session->duprExports()->whereKey((int) $export)->first() : null;
        abort_if($record === null, 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($record->file_path), 404);

        $name = 'dupr-'.$club->slug.'-'.$session->date->format('Y-m-d').'-'.$record->id.'.csv';

        return $disk->download($record->file_path, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
