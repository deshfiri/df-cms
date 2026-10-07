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
use Carbon\Carbon;
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
            'content' => $this->user('Content', ['manage raw-content', 'view raw-content-panel']),
            'design' => $this->user('Design', ['manage designer-content', 'view designer-panel']),
            'marketing' => $this->user('Marketing', ['manage publishing-review']),
            'smm' => $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content', 'view smm-panel']),
            'manager' => $this->user('Manager', ['view brand-checklist-overview']),
        ];
    }

    /** Pins "now" to a Dhaka wall-clock time, so every event created next is stamped with it. */
    protected function atDhaka(string $when): void
    {
        $this->travelTo(Carbon::parse($when, 'Asia/Dhaka'));
    }

    /** Submits one raw/advertising/poster item at the given Dhaka time, leaving it waiting for Marketing. */
    protected function submittedAt(string $when, Brand $brand, User $content, string $category = ContentItem::CATEGORY_RAW_CONTENT): ContentItem
    {
        $this->atDhaka($when);
        $item = $this->newItem($brand, $content, $category);
        $this->submitVersion($item, $content);

        return $item->fresh();
    }

    /** Submits at the given Dhaka time and hands the exact version to SMM at the same instant. */
    protected function handedOverAt(string $when, Brand $brand, User $content, User $marketing, string $category = ContentItem::CATEGORY_RAW_CONTENT): ContentItem
    {
        $this->atDhaka($when);
        $item = $this->newItem($brand, $content, $category);
        $submission = $this->submitVersion($item, $content);
        $this->approve($item, $submission, $marketing);

        return $item->fresh();
    }

    /** Hands over, collects and publishes one version at the given Dhaka time. @return array{0: ContentItem, 1: \App\Models\PublishedContent} */
    protected function publishedAt(string $when, Brand $brand, User $content, User $marketing, User $smm, string $category = ContentItem::CATEGORY_RAW_CONTENT): array
    {
        $item = $this->handedOverAt($when, $brand, $content, $marketing, $category);
        $submission = $item->latestSubmission();
        $this->collect($item, $smm);
        $published = $this->publish($item, $brand, $submission, $smm);

        return [$item->fresh(), $published->fresh()];
    }

    /** @return array<string, mixed> */
    protected function report(User $viewer, array $query = []): array
    {
        $response = $this->actingAs($viewer)->getJson(route('panels.marketing.workload', $query));
        $response->assertOk();

        return $response->json();
    }

    protected function row(array $report, Brand $brand): array
    {
        return collect($report['data'])->firstWhere('brand_id', $brand->id);
    }

    protected function daily(string $date): array
    {
        return ['period' => 'daily', 'date' => $date];
    }

    protected function monthly(string $month): array
    {
        return ['period' => 'monthly', 'month' => $month];
    }

    protected function yearly(string $year): array
    {
        return ['period' => 'yearly', 'year' => $year];
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
