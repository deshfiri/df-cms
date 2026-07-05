<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\Task;
use App\Policies\ClientPolicy;
use App\Policies\TaskPolicy;
use App\Services\Contracts\GoogleCalendarServiceInterface;
use App\Services\GoogleCalendarService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(RepositoryServiceProvider::class);
        $this->app->singleton(GoogleCalendarServiceInterface::class, GoogleCalendarService::class);
    }

    public function boot(): void
    {
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);

        // Super Admins bypass all gates
        Gate::before(function ($user, $ability) {
            if ($user->hasRole('Super Admin')) {
                return true;
            }
        });
    }
}
