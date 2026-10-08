<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\PlaySession;
use App\Services\SessionResultsService;
use App\Services\Share\PodiumData;
use App\Services\Share\PodiumEntry;
use App\Services\Share\PodiumImageRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public podium share images (Phase 11): the top 3 of an ended session as an
 * animated GIF or a still PNG. Names only; never ids or DUPR data.
 */
class PodiumImageController extends Controller
{
    private const RENDERS_PER_MINUTE = 30;

    public function __construct(
        private readonly SessionResultsService $results,
        private readonly PodiumImageRenderer $renderer,
    ) {}

    public function gif(Request $request, Club $club, string $publicId): Response
    {
        return $this->respond($request, $club, $publicId, 'gif');
    }

    public function png(Request $request, Club $club, string $publicId): Response
    {
        return $this->respond($request, $club, $publicId, 'png');
    }

    private function respond(Request $request, Club $club, string $publicId, string $type): Response
    {
        $session = PlaySession::findByPublicId($club, $publicId);
        abort_unless($session !== null && $session->isEnded(), 404);

        $data = $this->podiumData($club, $session);
        abort_if($data === null, 404);

        $etag = '"'.md5($data->hash().':'.PodiumImageRenderer::RENDERER_VERSION.':'.$type).'"';
        $headers = [
            'Cache-Control' => 'public, max-age=300',
            'ETag' => $etag,
        ];
        if ($this->matches($request, $etag)) {
            return response('', 304, $headers);
        }

        $filename = $club->slug.'-'.$session->date->format('Y-m-d').'-podium.'.$type;
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($this->bytes($request, $session, $data, $type), 200, $headers + [
            'Content-Type' => $type === 'gif' ? 'image/gif' : 'image/png',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
        ]);
    }

    /** The podium of an ended session, or null when no counted match exists. */
    private function podiumData(Club $club, PlaySession $session): ?PodiumData
    {
        $results = $this->results->publicResults($club, $session); // cached 60 s; null unless ended
        $rows = $results['podium'] ?? [];
        if ($rows === []) {
            return null;
        }

        return new PodiumData(
            clubName: $club->name,
            sessionName: $session->name,
            dateLabel: $session->date->format('F j, Y'),
            entries: array_map(fn (array $r, int $i) => new PodiumEntry(
                rank: $r['rank'] ?? $i + 1,
                name: $r['name'],
                wins: $r['wins'],
                losses: $r['losses'],
                winPct: $r['win_pct'],
            ), $rows, array_keys($rows)),
        );
    }

    /** Rendered bytes from the local disk cache, drawn on a miss (limited per IP, one drawer at a time). */
    private function bytes(Request $request, PlaySession $session, PodiumData $data, string $type): string
    {
        $disk = Storage::disk('local');
        $dir = 'podium/'.$session->id;
        $path = $dir.'/'.$data->hash().'-v'.PodiumImageRenderer::RENDERER_VERSION.'.'.$type;

        $cached = $disk->exists($path) ? $disk->get($path) : null;
        if ($cached !== null) {
            return $cached;
        }

        // Only drawing counts against the limit; cache hits and 304s never do.
        $key = 'podium-render:'.$request->ip();
        abort_if(RateLimiter::tooManyAttempts($key, self::RENDERS_PER_MINUTE), 429);
        RateLimiter::hit($key, 60);

        return Cache::lock("podium:{$session->id}:{$type}", 30)->block(10, function () use ($disk, $dir, $path, $data, $type): string {
            if ($disk->exists($path) && ($again = $disk->get($path)) !== null) {
                return $again; // another request drew it while we waited
            }

            $bytes = $type === 'gif' ? $this->renderer->gif($data) : $this->renderer->png($data);
            foreach ($disk->files($dir) as $old) {
                if (str_ends_with($old, '.'.$type)) {
                    $disk->delete($old); // an older score or renderer version
                }
            }
            $disk->put($path, $bytes);

            return $bytes;
        });
    }

    private function matches(Request $request, string $etag): bool
    {
        $given = (string) $request->header('If-None-Match', '');
        if ($given === '') {
            return false;
        }
        foreach (explode(',', $given) as $candidate) {
            if (trim(preg_replace('/^\s*W\//', '', $candidate) ?? '') === $etag) {
                return true;
            }
        }

        return false;
    }
}
