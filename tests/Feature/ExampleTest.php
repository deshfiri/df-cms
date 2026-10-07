<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_root_url_sends_a_guest_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_the_root_url_redirects_an_authenticated_user_to_the_dashboard(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_the_public_brand_catalogue_urls_no_longer_exist(): void
    {
        $this->get('/brands/1')->assertNotFound();
    }
}
