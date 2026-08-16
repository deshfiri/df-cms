<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Sweeps every authenticated GET route and asserts a user holding no
 * permissions cannot reach it.
 *
 * This exists because the same bug kept recurring one page at a time: a
 * controller would gate store/update/destroy and the sidebar would hide the
 * link, while the index route stayed open to anyone with a login. Hiding a
 * link is not access control, and each gap was only found when somebody
 * happened to click.
 *
 * The allowlist below is the policy statement: these routes are deliberately
 * reachable by any signed-in staff member. Anything not listed must be gated.
 * A new route is therefore closed by default — if it is genuinely public to
 * staff, it has to be named here, which forces the decision to be explicit.
 */
class RouteAuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Routes every authenticated user may reach, with the reason.
     *
     * @var array<string,string>
     */
    private const OPEN_TO_ALL_STAFF = [
        'dashboard'                 => 'Landing page; content is scoped per user',
        'chat.index'                => 'Everyone can chat',
        'chat.conversations'        => 'Only the signed-in user\'s own conversations',
        'chat.users'                => 'Directory for starting a chat; names only',
        'calls.ice'                 => 'ICE config for the signed-in user',
        'calls.history'             => 'Scoped to the signed-in user\'s own calls',
        'flow.queue'                => 'Personal work queue; empty unless assigned to a stage',
        'flow.history'              => 'Scoped to items the user created or moved',
        'notifications.index'       => 'The user\'s own notifications',
        'requests.index'            => 'Every employee files and sees their own requests',
        'reviews.index'             => 'Anyone may post a review; reading others is gated by "view reviews" in the controller and the page',
        'password.confirm'          => 'Part of the authentication flow',
    ];

    /**
     * Routes that need query parameters to do anything and would error rather
     * than authorize. Covered by their own tests instead.
     *
     * @var array<int,string>
     */
    private const NEEDS_PARAMETERS = [
        'file-manager.download',
        'file-manager.preview',
    ];

    /** @return array<int,\Illuminate\Routing\Route> */
    private function authenticatedGetRoutes(): array
    {
        return collect(Route::getRoutes())
            ->filter(function ($route) {
                if (!in_array('GET', $route->methods(), true)) {
                    return false;
                }
                if (str_contains($route->uri(), '{') || str_starts_with($route->uri(), 'portal')) {
                    return false;
                }

                $middleware = implode(',', array_filter($route->gatherMiddleware(), 'is_string'));

                return str_contains($middleware, 'auth')
                    && !str_contains($middleware, 'auth:client_portal');
            })
            ->values()
            ->all();
    }

    public function test_every_authenticated_route_is_either_gated_or_explicitly_public(): void
    {
        $user = User::factory()->create(['is_active' => true]);   // no roles, no permissions

        $reachable = [];

        foreach ($this->authenticatedGetRoutes() as $route) {
            $name = $route->getName();

            if (!$name || isset(self::OPEN_TO_ALL_STAFF[$name]) || in_array($name, self::NEEDS_PARAMETERS, true)) {
                continue;
            }

            $status = $this->actingAs($user)->get('/' . ltrim($route->uri(), '/'))->getStatusCode();

            // 403 is the expected answer; a redirect is acceptable where the
            // app bounces rather than refuses. A 200 means the page rendered.
            if ($status === 200) {
                $reachable[] = $name . '  (' . $route->uri() . ')';
            }
        }

        $this->assertSame([], $reachable, sprintf(
            "These routes rendered for a user with no permissions:\n  %s\n\n"
            . "Either gate the controller, or add the route to OPEN_TO_ALL_STAFF with a reason.",
            implode("\n  ", $reachable)
        ));
    }

    public function test_the_allowlist_only_names_routes_that_still_exist(): void
    {
        $names = collect($this->authenticatedGetRoutes())->map->getName()->filter()->all();

        foreach (array_keys(self::OPEN_TO_ALL_STAFF) as $allowed) {
            $this->assertContains(
                $allowed,
                $names,
                "'{$allowed}' is allowlisted but no longer exists — remove it so the list stays honest."
            );
        }
    }

    public function test_routes_open_to_all_staff_really_are_reachable(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach (array_keys(self::OPEN_TO_ALL_STAFF) as $name) {
            if ($name === 'password.confirm') {
                continue;   // renders a form, no meaningful assertion
            }

            $status = $this->actingAs($user)->get(route($name))->getStatusCode();

            $this->assertLessThan(
                400,
                $status,
                "'{$name}' is documented as open to all staff but returned {$status}."
            );
        }
    }
}
