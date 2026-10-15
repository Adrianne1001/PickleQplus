<?php

namespace App\Support;

use App\Models\Club;

/**
 * Who holds the guest organizer cookie for this request: set by EnsureClubAccess once
 * the cookie has been verified against an unexpired guest club, empty otherwise.
 * Registered as a scoped singleton, so it never leaks into another request.
 */
class GuestOwnerContext
{
    private ?Club $club = null;

    private ?string $path = null;

    public function club(): ?Club
    {
        return $this->club;
    }

    /** The request path (no leading slash) that the context was verified for, when known. */
    public function path(): ?string
    {
        return $this->path;
    }

    public function set(?Club $club, ?string $path = null): void
    {
        $this->club = $club;
        $this->path = $path;
    }

    public function clear(): void
    {
        $this->club = null;
        $this->path = null;
    }
}
