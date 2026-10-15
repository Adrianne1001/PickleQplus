<?php

namespace App\Providers;

use App\Http\Middleware\EnsureClubAccess;
use App\Http\Middleware\EnsureClubMember;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\User;
use App\Services\Dupr\CsvPublisher;
use App\Services\Dupr\DuprPublisher;
use App\Support\GuestOwnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

use function Livewire\before;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // v1 publishes DUPR matches as a CSV file. The Phase 7 PartnerApiPublisher will be
        // selected here behind a config flag.
        $this->app->bind(DuprPublisher::class, CsvPublisher::class);

        // Which guest club the request's organizer cookie unlocked (Phase 14). Scoped, so it never outlives a request.
        $this->app->scoped(GuestOwnerContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureGuestAccess();

        // Re-run membership checks on /livewire/update, so a removed member cannot
        // keep calling actions on an already-mounted component.
        Livewire::addPersistentMiddleware([EnsureEmailIsVerified::class, EnsureClubMember::class, EnsureClubAccess::class]);

        // A batched /livewire/update carries several components. The guest context belongs to one
        // page, so drop it whenever the next component comes from another page. Same-page
        // components keep it (the persistent middleware only re-runs once per page). Real update
        // requests only, so Livewire::test() with a hand-set context is left alone.
        before('snapshot-verified', function (array $snapshot): void {
            if (! app(HandleRequests::class)->isLivewireRoute()) {
                return;
            }

            $context = app(GuestOwnerContext::class);
            $path = isset($snapshot['memo']['path']) ? ltrim((string) $snapshot['memo']['path'], '/') : null;
            // A context without a path was set by hand (tests), not by the middleware: leave it.
            if ($context->club() !== null && $context->path() !== null && $context->path() !== $path) {
                $context->clear();
            }
        });
    }

    /**
     * Named limiters. Invite throttling lives in InvitationService.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('registration', fn (Request $request): Limit => Limit::perHour((int) config('pickleq.registrations_per_hour'))
            ->by('registration:'.$request->ip()));

        // Guest sessions (P14.3): organizer-link visits per minute. The start limit (per hour) is
        // applied in GuestSessionController@start, so it can answer with a friendly error.
        RateLimiter::for('guest-link', fn (Request $request): Limit => Limit::perMinute(20)->by('guest-link:'.$request->ip()));

        // Public podium images (P11.3): drawing is CPU work, so 300 a minute per IP (renders are limited to 30 a minute in the controller).
        RateLimiter::for('podium', fn (Request $request): Limit => Limit::perMinute(300)->by('podium:'.$request->ip()));
    }

    /**
     * The only way a guest (organizer cookie, no account) gets any permission: PlaySession
     * view, update and manage, and Player create, and only inside the club that the cookie
     * was verified for (EnsureClubAccess). It grants or abstains, and never denies, so it
     * cannot change what a signed-in member is allowed.
     */
    protected function configureGuestAccess(): void
    {
        Gate::before(function (?User $user, string $ability, array $arguments): ?bool {
            $club = app(GuestOwnerContext::class)->club();
            if ($club === null) {
                return null;
            }

            $subject = $arguments[0] ?? null;

            if (in_array($ability, ['view', 'update', 'manage'], true) && $subject instanceof PlaySession) {
                return (int) $subject->club_id === (int) $club->id ? true : null;
            }

            $target = $arguments[1] ?? null;
            if ($ability === 'create' && $subject === Player::class && $target instanceof Club) {
                return (int) $target->id === (int) $club->id ? true : null;
            }

            return null;
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
