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
use Illuminate\Support\Facades\Gate;
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
        Gate::policy(Server::class, ServerPolicy::class);
        Gate::policy(ServerToken::class, ServerTokenPolicy::class);
        Gate::policy(Alert::class, AlertPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Setting::class, SettingPolicy::class);
        Gate::policy(AuditEvent::class, AuditPolicy::class);
    }
}
