<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_root_url_shows_a_guest_the_public_landing_page(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertViewIs('public.landing');
    }

    public function test_the_root_url_redirects_an_authenticated_user_to_the_dashboard(): void
    {
        $user = \App\Models\User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->get('/');

        $response->assertRedirect(route('dashboard'));
    }
}
