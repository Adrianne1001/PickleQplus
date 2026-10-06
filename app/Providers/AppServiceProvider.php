<?php

namespace App\Providers;

use App\Http\Middleware\EnsureClubMember;
use App\Services\Dupr\CsvPublisher;
use App\Services\Dupr\DuprPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();

        // Re-run membership checks on /livewire/update, so a removed member cannot
        // keep calling actions on an already-mounted component.
        Livewire::addPersistentMiddleware([EnsureEmailIsVerified::class, EnsureClubMember::class]);
    }

    /**
     * Named limiters. Invite throttling lives in InvitationService.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('registration', fn (Request $request): Limit => Limit::perHour((int) config('pickleq.registrations_per_hour'))
            ->by('registration:'.$request->ip()));
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
