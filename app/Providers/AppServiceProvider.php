<?php

namespace App\Providers;

use App\Models\Alert;
use App\Models\AuditEvent;
use App\Models\Server;
use App\Models\ServerToken;
use App\Models\Setting;
use App\Models\User;
use App\Policies\AlertPolicy;
use App\Policies\AuditPolicy;
use App\Policies\ServerPolicy;
use App\Policies\ServerTokenPolicy;
use App\Policies\SettingPolicy;
use App\Policies\UserPolicy;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

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
        View::share('appVersion', 'v1.2.1');

        Gate::policy(Server::class, ServerPolicy::class);
        Gate::policy(ServerToken::class, ServerTokenPolicy::class);
        Gate::policy(Alert::class, AlertPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Setting::class, SettingPolicy::class);
        Gate::policy(AuditEvent::class, AuditPolicy::class);

        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }

            app(AuditLogger::class)->log(
                'auth.login',
                $event->user instanceof Model ? $event->user : null,
                [],
                $event->user instanceof User ? (int) $event->user->getKey() : null,
            );
        });

        Event::listen(Logout::class, function (Logout $event): void {
            app(AuditLogger::class)->log(
                'auth.logout',
                $event->user instanceof Model ? $event->user : null,
                [],
                $event->user instanceof User ? (int) $event->user->getKey() : null,
            );
        });

        Event::listen(Failed::class, function (Failed $event): void {
            $email = $event->credentials[config('fortify.username', 'email')] ?? null;

            app(AuditLogger::class)->log('auth.failed', null, array_filter(
                ['email' => is_string($email) ? '[redacted]' : null],
                static fn (mixed $value): bool => $value !== null,
            ));
        });
    }
}
