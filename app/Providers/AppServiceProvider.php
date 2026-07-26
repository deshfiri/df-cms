<?php

namespace App\Providers;

use App\Models\AdCampaign;
use App\Models\Client;
use App\Models\EmployeeRequest;
use App\Models\Task;
use App\Models\User;
use App\Policies\AdCampaignPolicy;
use App\Policies\ClientPolicy;
use App\Policies\EmployeeRequestPolicy;
use App\Policies\TaskPolicy;
use App\Services\Contracts\GoogleCalendarServiceInterface;
use App\Services\GoogleCalendarService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        Gate::policy(EmployeeRequest::class, EmployeeRequestPolicy::class);
        Gate::policy(AdCampaign::class, AdCampaignPolicy::class);

        // Super Admins bypass all gates. Guarded with an instanceof check because
        // ClientPortalUser (the client-portal auth principal) has no HasRoles trait —
        // portal authorization never routes through Gate anyway (see app/Policies/Portal/),
        // but this keeps the closure safe regardless of what guard resolved "the user".
        Gate::before(function ($user, $ability) {
            if ($user instanceof User && $user->hasRole('Super Admin')) {
                return true;
            }
        });

        RateLimiter::for('client-portal-login', function ($request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
