<?php

namespace Tests\Unit;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Laravel's own Authenticate middleware calls Auth::shouldUse($guard) the
 * moment a request authenticates on ANY guard — including 'client_portal' on
 * every portal route. From that point on, the unguarded Auth::id() no longer
 * means "the signed-in staff member"; it means "whoever most recently
 * authenticated on this request, on whatever guard." ActivityLog.user_id is
 * a staff-only FK (constrained to users), so a portal request reaching this
 * service must never let Auth::id() leak a portal user's id into it — it
 * used to crash outright, since that id essentially never matches a real
 * users row.
 */
class ActivityLogGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_logging_during_a_portal_guarded_request_does_not_crash_or_misattribute(): void
    {
        $category = Category::create(['name' => 'Cat', 'slug' => 'cat-' . uniqid(), 'status' => true]);
        $client = Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => 'ACME', 'brand_name' => 'ACME',
            'category_id' => $category->id,
        ]);
        $portalUser = ClientPortalUser::create([
            'client_id' => $client->id, 'name' => 'Portal User', 'email' => 'portal@example.com',
            'password' => 'Password123!', 'status' => 'Active',
        ]);

        // Mirrors exactly what Authenticate::authenticate() does on a real
        // portal request once the client_portal guard passes — no staff
        // 'web' session exists at all here.
        Auth::guard('client_portal')->login($portalUser);
        Auth::shouldUse('client_portal');
        $this->assertSame($portalUser->id, Auth::id(), 'sanity check: the default guard really did switch');

        app(ActivityLogService::class)->log('Document', 'Uploaded', null, null, ['x' => 1]);

        $log = ActivityLog::latest('id')->firstOrFail();
        $this->assertNull($log->user_id, 'a portal id must never be written into a staff-only column');
    }

    public function test_logging_during_a_normal_staff_request_is_unaffected(): void
    {
        $staff = User::factory()->create();
        Auth::guard('web')->login($staff);

        app(ActivityLogService::class)->log('Client', 'Updated', null, null, ['x' => 1]);

        $log = ActivityLog::latest('id')->firstOrFail();
        $this->assertSame($staff->id, $log->user_id);
    }
}
