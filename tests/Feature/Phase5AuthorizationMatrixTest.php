<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\User;
use App\Services\ContentItemService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 5 — authorization-matrix gaps the survey flagged as untested: a
 * negative case for content-item submit, content-item publish, advertising-
 * expenditure create/update/delete, and the Brand public-toggle, each tried
 * by a role that holds a NEARBY but wrong permission (not just an entirely
 * unrelated one), plus a bare, permission-less user throughout.
 */
class Phase5AuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'manage payments', 'manage ads', 'manage raw-content', 'manage designer-content',
            'manage smm-collection', 'manage published-content', 'manage advertising-expenditure',
        ] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        PaymentCategory::firstOrCreate(['name' => 'Social Media Ads'], ['is_active' => true, 'sort_order' => 10]);
        PaymentCategory::firstOrCreate(['name' => 'Content Production'], ['is_active' => true, 'sort_order' => 20]);
    }

    private function user(string $role, array $extraPerms = []): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);
        if ($extraPerms) {
            $user->givePermissionTo($extraPerms);
        }

        return $user;
    }

    /** An ordinary, otherwise-unrelated staff account: a real role, zero Phase 0-4 permissions. */
    private function ordinaryUser(): User
    {
        return $this->user('Website');
    }

    private function readyBrand(User $manager): Brand
    {
        $category = Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);
        $client = Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => 'Test Client', 'brand_name' => 'Brand',
            'category_id' => $category->id,
        ]);
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand ' . uniqid()]);

        $budget = Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Social Media Ads')->value('id'),
            'invoice_number' => 'INV-' . uniqid(), 'total_payable' => 1000, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);
        Payment::create([
            'client_id' => $client->id, 'invoice_id' => $budget->id, 'payment_category_id' => $budget->payment_category_id,
            'amount' => 1000, 'status' => 'Paid', 'payment_date' => now(), 'created_by' => $manager->id,
        ]);
        app(InvoiceService::class)->recalculateStatus($budget->fresh());

        Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Content Production')->value('id'),
            'invoice_number' => 'INV-' . uniqid(), 'total_payable' => 300, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);

        return $brand->fresh();
    }

    public function test_designer_cannot_submit_to_a_raw_content_item(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $design = $this->user('Design', ['manage designer-content']); // adjacent permission, wrong category
        $brand = $this->readyBrand($manager);
        $item = app(ContentItemService::class)->create($brand, ['category' => 'raw_content', 'title' => 'Raw item'], $content);

        $response = $this->actingAs($design)->postJson(route('marketing.content-items.submit', [$brand, $item]), [
            'link_url' => 'https://example.com/v1.jpg',
        ]);

        $response->assertForbidden();
    }

    public function test_an_ordinary_user_cannot_submit_to_a_content_item(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $brand = $this->readyBrand($manager);
        $item = app(ContentItemService::class)->create($brand, ['category' => 'raw_content', 'title' => 'Raw item'], $content);

        $response = $this->actingAs($this->ordinaryUser())->postJson(route('marketing.content-items.submit', [$brand, $item]), [
            'link_url' => 'https://example.com/v1.jpg',
        ]);

        $response->assertForbidden();
    }

    public function test_an_smm_user_who_can_collect_but_not_publish_is_refused_at_publish(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection']); // no 'manage published-content'
        $brand = $this->readyBrand($manager);
        $service = app(ContentItemService::class);
        $item = $service->create($brand, ['category' => 'raw_content', 'title' => 'Item'], $content);
        $submission = $service->submit($item, ['link_url' => 'https://example.com/v1.jpg'], $content);
        $service->collect($item->fresh(), $smm);

        $response = $this->actingAs($smm)->postJson(route('marketing.content-items.publish', [$brand, $item]), [
            'submission_id' => $submission->id, 'facebook_post_url' => 'https://facebook.com/post',
        ]);

        $response->assertForbidden();
    }

    public function test_an_smm_user_cannot_create_advertising_expenditure(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $brand = $this->readyBrand($manager);

        $response = $this->actingAs($smm)->postJson(route('marketing.expenditures.store', $brand), [
            'amount' => 100, 'reporting_date' => now()->toDateString(),
        ]);

        $response->assertForbidden();
    }

    public function test_an_ordinary_user_cannot_request_an_expenditure_correction(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage advertising-expenditure']);
        $brand = $this->readyBrand($manager);
        $expenditure = app(\App\Services\AdvertisingExpenditureService::class)->create($brand->fresh(), [
            'amount' => 100, 'reporting_date' => now()->toDateString(),
        ], $marketing);

        $response = $this->actingAs($this->ordinaryUser())->putJson(
            route('marketing.expenditures.update', [$brand, $expenditure]),
            ['amount' => 150, 'reason' => 'Trying to sneak a correction in.']
        );

        $response->assertForbidden();
    }

    public function test_an_ordinary_user_cannot_request_an_expenditure_deletion(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $marketing = $this->user('Marketing', ['manage advertising-expenditure']);
        $brand = $this->readyBrand($manager);
        $expenditure = app(\App\Services\AdvertisingExpenditureService::class)->create($brand->fresh(), [
            'amount' => 100, 'reporting_date' => now()->toDateString(),
        ], $marketing);

        $response = $this->actingAs($this->ordinaryUser())->deleteJson(
            route('marketing.expenditures.destroy', [$brand, $expenditure]),
            ['reason' => 'Trying to sneak a deletion in.']
        );

        $response->assertForbidden();
    }

    public function test_a_content_role_user_cannot_toggle_a_brands_public_visibility(): void
    {
        $content = $this->user('Content', ['manage raw-content']); // real role, no 'manage ads'
        $category = Category::create(['name' => 'Cat ' . uniqid(), 'slug' => 'cat-' . uniqid(), 'status' => true]);
        $client = Client::create([
            'dfid_number' => 'DF' . uniqid(), 'client_name' => 'Client', 'brand_name' => 'Brand',
            'category_id' => $category->id,
        ]);
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand ' . uniqid()]);

        $response = $this->actingAs($content)->putJson(route('clients.brands.update', [$client, $brand]), [
            'name' => $brand->name, 'is_public' => true,
        ]);

        $response->assertForbidden();
        $this->assertFalse($brand->fresh()->is_public);
    }
}
