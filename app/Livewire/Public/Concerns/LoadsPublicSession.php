<?php

namespace App\Livewire\Public\Concerns;

use App\Models\Club;
use App\Models\PlaySession;
use App\Services\PublicSessionView;
use Livewire\Attributes\Locked;

/**
 * Shared by the public queue page and the TV. Keeps only the club slug and
 * public id in component state (never a model, so no sequential id reaches the
 * browser), and re-reads the public snapshot on every request.
 *
 * @phpstan-import-type PublicSnapshot from PublicSessionView
 */
trait LoadsPublicSession
{
    #[Locked]
    public string $clubSlug = '';

    #[Locked]
    public string $publicId = '';

    /** @var PublicSnapshot|null */
    protected ?array $snapshot = null;

    protected ?PlaySession $resolvedSession = null;

    protected function resolveSession(): PlaySession
    {
        if ($this->resolvedSession !== null) {
            return $this->resolvedSession;
        }

        $club = Club::query()->where('slug', $this->clubSlug)->firstOrFail();

        return $this->resolvedSession = PlaySession::findByPublicIdOrFail($club, $this->publicId);
    }

    /** @return PublicSnapshot */
    protected function loadSnapshot(): array
    {
        return $this->loadSnapshotFor($this->resolveSession());
    }

    /** @return PublicSnapshot */
    protected function loadSnapshotFor(PlaySession $session): array
    {
        $this->resolvedSession = $session;

        return $this->snapshot = app(PublicSessionView::class)->snapshot($session);
    }

    /**
     * Re-render when the session changes. The 30s poll in the view is the
     * fallback when the socket drops.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            'echo:play-session.'.$this->publicId.',.session.updated' => '$refresh',
        ];
    }
}
