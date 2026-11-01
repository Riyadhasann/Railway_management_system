<?php

namespace App\Providers;

use App\Contracts\NidVerifier;
use App\Contracts\SmsSender;
use App\Enums\Role;
use App\Gateways\Nid\FormatNidVerifier;
use App\Gateways\Sms\LogSmsSender;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Integrations live behind contracts so mocks can be swapped for real providers.
        $this->app->singleton(SmsSender::class, fn (): SmsSender => match (config('services.sms.driver')) {
            default => new LogSmsSender,
        });

        $this->app->singleton(NidVerifier::class, fn (): NidVerifier => match (config('services.nid.driver')) {
            default => new FormatNidVerifier,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Admins pass every permission check.
        Gate::before(fn (User $user): ?bool => $user->hasRole(Role::Admin->value) ? true : null);
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
