<?php

namespace Tests\Concerns;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentItemSubmission;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\User;
use App\Services\ContentItemService;
use App\Services\InvoiceService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Shared fixtures for the Marketing pre-publish workflow and reporting tests:
 * users with the real role/permission shapes, a brand whose checklist is
 * open (its ad budget and content charge are paid), and short helpers that
 * drive the real ContentItemService so every test exercises the same code
 * path production does.
 */
trait ContentWorkflowFixtures
{
    protected function seedWorkflowPermissions(): void
    {
        foreach ([
            'manage payments', 'view brand-checklist-overview',
            'view raw-content-panel', 'manage raw-content',
            'view designer-panel', 'manage designer-content',
            'view smm-panel', 'manage smm-collection', 'manage published-content',
            'manage publishing-review',
        ] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        PaymentCategory::firstOrCreate(['name' => 'Social Media Ads'], ['is_active' => true, 'sort_order' => 10]);
        PaymentCategory::firstOrCreate(['name' => 'Content Production'], ['is_active' => true, 'sort_order' => 20]);
    }

    protected function user(string $role, array $extraPerms = []): User
    {
        $user = User::factory()->create(['is_active' => true]);
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);
        if ($extraPerms) {
            $user->givePermissionTo($extraPerms);
        }

        return $user;
    }

    /** @return array{content: User, design: User, marketing: User, smm: User, manager: User} */
    protected function workflowTeam(): array
    {
        return [
            'content' => $this->user('Content', ['manage raw-content']),
            'design' => $this->user('Design', ['manage designer-content']),
            'marketing' => $this->user('Marketing', ['manage publishing-review']),
            'smm' => $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']),
            'manager' => $this->user('Manager', ['view brand-checklist-overview']),
        ];
    }

    protected function service(): ContentItemService
    {
        return app(ContentItemService::class);
    }

    protected function readyBrand(User $manager, ?Client $client = null): Brand
    {
        $client ??= $this->client();
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);

        $adInvoice = Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Social Media Ads')->value('id'),
            'invoice_number' => 'INV-'.uniqid(), 'total_payable' => 1000, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);
        $this->pay($adInvoice, 1000, $manager);

        Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Content Production')->value('id'),
            'invoice_number' => 'INV-'.uniqid(), 'total_payable' => 300, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);

        return $brand->fresh();
    }

    protected function client(): Client
    {
        $category = Category::create(['name' => 'Cat '.uniqid(), 'slug' => 'cat-'.uniqid(), 'status' => true]);

        return Client::create([
            'dfid_number' => 'DF'.uniqid(), 'client_name' => 'Test Client', 'brand_name' => 'Brand',
            'category_id' => $category->id,
        ]);
    }

    protected function pay(Invoice $invoice, float $amount, User $recorder): Payment
    {
        $payment = Payment::create([
            'client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'payment_category_id' => $invoice->payment_category_id,
            'amount' => $amount, 'status' => 'Paid', 'payment_date' => now(), 'created_by' => $recorder->id,
        ]);
        app(InvoiceService::class)->recalculateStatus($invoice->fresh());

        return $payment;
    }

    protected function newItem(Brand $brand, User $creator, string $category = ContentItem::CATEGORY_RAW_CONTENT): ContentItem
    {
        return $this->service()->create($brand, ['category' => $category, 'title' => 'Item '.uniqid()], $creator);
    }

    /** A fresh submission or resubmission. Always goes to Marketing first. */
    protected function submitVersion(ContentItem $item, User $submitter): ContentItemSubmission
    {
        return $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/'.uniqid().'.jpg'], $submitter);
    }

    protected function approve(ContentItem $item, ContentItemSubmission $submission, User $marketing): void
    {
        $this->service()->approveForHandover($item->fresh(), $submission->fresh(), $marketing);
    }

    protected function collect(ContentItem $item, User $smm): void
    {
        $this->service()->collect($item->fresh(), $smm);
    }

    protected function publish(ContentItem $item, Brand $brand, ContentItemSubmission $submission, User $smm, string $url = 'https://facebook.com/post/1')
    {
        return $this->service()->publish($item->fresh(), $brand, $submission->fresh(), ['facebook_post_url' => $url], $smm);
    }
}
