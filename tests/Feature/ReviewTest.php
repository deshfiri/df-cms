<?php

namespace Tests\Feature;

use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'view reviews', 'guard_name' => 'web']);
    }

    private function makeUser(?string $role = null): User
    {
        $user = User::factory()->create();
        if ($role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            $user->assignRole($role);
        }

        return $user;
    }

    public function test_any_authenticated_user_can_post_a_review(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->postJson(route('reviews.store'), [
            'type'         => 'review',
            'subject_type' => 'general',
            'title'        => 'Great teamwork',
            'message'      => 'The team did a great job this sprint.',
            'is_anonymous' => 0,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('reviews', [
            'title'      => 'Great teamwork',
            'posted_by'  => $user->id,
            'is_anonymous' => false,
        ]);
    }

    public function test_an_anonymous_review_never_records_who_posted_it(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson(route('reviews.store'), [
            'type'         => 'report',
            'subject_type' => 'department',
            'subject_department' => 'Sales',
            'title'        => 'Concern about process',
            'message'      => 'Something is off with the sales process.',
            'is_anonymous' => 1,
        ])->assertOk();

        $review = Review::first();
        $this->assertTrue($review->is_anonymous);
        $this->assertNull($review->posted_by);
    }

    public function test_a_user_without_permission_cannot_list_reviews(): void
    {
        $viewer = $this->makeUser();

        $response = $this->actingAs($viewer)->getJson(route('reviews.index'), ['X-Requested-With' => 'XMLHttpRequest']);
        $response->assertForbidden();
    }

    public function test_a_user_with_permission_can_list_reviews_and_anonymous_identity_is_hidden(): void
    {
        $poster = $this->makeUser();
        $manager = $this->makeUser('Manager');
        $manager->givePermissionTo('view reviews');

        $this->actingAs($poster)->postJson(route('reviews.store'), [
            'type'         => 'review',
            'subject_type' => 'general',
            'title'        => 'Anonymous feedback',
            'message'      => 'This is anonymous.',
            'is_anonymous' => 1,
        ])->assertOk();

        $response = $this->actingAs($manager)->getJson(route('reviews.index'), ['X-Requested-With' => 'XMLHttpRequest']);
        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertTrue($data[0]['is_anonymous']);
        $this->assertNull($data[0]['posted_by_name']);
    }

    public function test_a_super_admin_bypasses_the_permission_check_via_gate_before(): void
    {
        $admin = $this->makeUser('Super Admin');

        $response = $this->actingAs($admin)->getJson(route('reviews.index'), ['X-Requested-With' => 'XMLHttpRequest']);
        $response->assertOk();
    }

    public function test_store_returns_a_poster_token_the_requester_can_use_to_track_it_later(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->postJson(route('reviews.store'), [
            'type'         => 'report',
            'subject_type' => 'general',
            'title'        => 'Anonymous concern',
            'message'      => 'Something to flag.',
            'is_anonymous' => 1,
        ]);

        $response->assertOk();
        $token = $response->json('poster_token');
        $this->assertNotEmpty($token);

        $mine = $this->actingAs($user)->postJson(route('reviews.mine'), ['tokens' => [$token]]);
        $mine->assertOk();
        $data = $mine->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('Anonymous concern', $data[0]['title']);
        $this->assertTrue($data[0]['is_mine']);
    }

    public function test_mine_returns_nothing_for_an_unknown_token(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->postJson(route('reviews.mine'), ['tokens' => ['not-a-real-token']]);
        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_a_user_with_permission_can_delete_a_review(): void
    {
        $poster = $this->makeUser();
        $manager = $this->makeUser('Manager');
        $manager->givePermissionTo('view reviews');

        $this->actingAs($poster)->postJson(route('reviews.store'), [
            'type' => 'review', 'subject_type' => 'general',
            'title' => 'To be deleted', 'message' => 'Delete me', 'is_anonymous' => 0,
        ]);
        $review = Review::first();

        $response = $this->actingAs($manager)->deleteJson(route('reviews.destroy', $review));
        $response->assertOk();
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }

    public function test_a_user_without_permission_cannot_delete_a_review(): void
    {
        $poster = $this->makeUser();

        $this->actingAs($poster)->postJson(route('reviews.store'), [
            'type' => 'review', 'subject_type' => 'general',
            'title' => 'Should stay', 'message' => 'Keep me', 'is_anonymous' => 0,
        ]);
        $review = Review::first();

        // The poster themselves has no 'view reviews' permission, so they
        // cannot delete it either — only admin/authorized viewers can.
        $response = $this->actingAs($poster)->deleteJson(route('reviews.destroy', $review));
        $response->assertForbidden();
        $this->assertDatabaseHas('reviews', ['id' => $review->id]);
    }
}
