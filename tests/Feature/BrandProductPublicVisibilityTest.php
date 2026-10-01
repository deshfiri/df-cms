<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4 — who may publish/unpublish a Brand/Product on the public landing
 * page. `manage ads` (gates Brand::update) is held only by Manager and
 * Marketing, so it's used as-is. `manage products` (gates Product::update)
 * is also held by the unrelated 'Product' role (internal sourcing/upload),
 * so setting a product's public-facing fields additionally requires
 * Manager/Marketing — see ProductController::update().
 */
class BrandProductPublicVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'manage ads', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'manage products', 'guard_name' => 'web']);
    }

    private function user(string $role, array $perms): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);
        $user->givePermissionTo($perms);

        return $user;
    }

    private function brand(): Brand
    {
        $category = Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);
        $client = Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => 'Client', 'brand_name' => 'Brand',
            'category_id' => $category->id,
        ]);

        return Brand::create(['client_id' => $client->id, 'name' => 'Brand ' . uniqid()]);
    }

    public function test_marketing_can_make_a_brand_public(): void
    {
        $marketing = $this->user('Marketing', ['manage ads']);
        $brand = $this->brand();

        $response = $this->actingAs($marketing)->putJson(route('clients.brands.update', [$brand->client, $brand]), [
            'name' => $brand->name, 'is_public' => true, 'description' => 'Public blurb',
        ]);

        $response->assertOk();
        $this->assertTrue($brand->fresh()->is_public);
    }

    public function test_product_role_cannot_make_a_product_public_despite_holding_manage_products(): void
    {
        $productUser = $this->user('Product', ['manage products']);
        $brand = $this->brand();
        $product = Product::create(['brand_id' => $brand->id, 'name' => 'Widget']);

        $response = $this->actingAs($productUser)->putJson(route('marketing.products.update', [$brand, $product]), [
            'name' => 'Widget', 'is_public' => true,
        ]);

        $response->assertForbidden();
        $this->assertFalse($product->fresh()->is_public);
    }

    public function test_product_role_can_still_rename_a_product_without_touching_public_fields(): void
    {
        $productUser = $this->user('Product', ['manage products']);
        $brand = $this->brand();
        $product = Product::create(['brand_id' => $brand->id, 'name' => 'Widget']);

        $response = $this->actingAs($productUser)->putJson(route('marketing.products.update', [$brand, $product]), [
            'name' => 'Renamed Widget',
        ]);

        $response->assertOk();
        $this->assertSame('Renamed Widget', $product->fresh()->name);
    }

    public function test_marketing_can_make_a_product_public(): void
    {
        $marketing = $this->user('Marketing', ['manage products']);
        $brand = $this->brand();
        $product = Product::create(['brand_id' => $brand->id, 'name' => 'Widget']);

        $response = $this->actingAs($marketing)->putJson(route('marketing.products.update', [$brand, $product]), [
            'name' => 'Widget', 'is_public' => true, 'description' => 'A great widget.',
        ]);

        $response->assertOk();
        $this->assertTrue($product->fresh()->is_public);
    }
}
