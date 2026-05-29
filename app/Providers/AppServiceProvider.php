<?php

namespace App\Providers;

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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::before(function ($user, $ability) {
            $admins = explode(',', config('panopticon.admin'));
            return in_array($user->id, $admins) ? true : null;
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        $this->configureMail();

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

    /**
     * Configure mail defaults for local development.
     */
    private function configureMail(): void
    {
        if (
            app()->isProduction()
            || config('mail.default') !== 'mailgun'
            || $this->hasMailgunConfiguration()
        ) {
            return;
        }

        config(['mail.default' => 'log']);

        if ($this->app->bound('mail.manager')) {
            $this->app->make('mail.manager')->forgetMailers();
        }
    }

    private function hasMailgunConfiguration(): bool
    {
        return filled(config('services.mailgun.domain'))
            && filled(config('services.mailgun.secret'));
    }
}
