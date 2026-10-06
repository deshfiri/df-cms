<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentItemSubmission;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Models\User;
use App\Services\BrandChecklistProjectionService;
use App\Services\ContentItemService;
use App\Services\InvoiceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Shared Brand Content Checklist — a read-only projection of the real
 * ContentItem/ContentItemSubmission/ContentItemCollection/PublishedContent/
 * ContentItemRevision workflow (see App\Services\
 * BrandChecklistProjectionService), never a second source of truth. Every
 * assertion here checks that an action performed on an operational panel
 * (Content, Designer, SMM, Marketing) shows up automatically — there is no
 * manual checklist-update step to test, because none exists.
 */
class BrandChecklistProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'manage payments', 'manage raw-content', 'manage designer-content',
            'manage smm-collection', 'manage published-content', 'manage publishing-review',
            'view brand-checklist-overview', 'view raw-content-panel', 'view designer-panel', 'view smm-panel',
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

    private function readyBrand(User $manager): Brand
    {
        $category = Category::create(['name' => 'Cat '.uniqid(), 'slug' => 'cat-'.uniqid(), 'status' => true]);
        $client = Client::create([
            'dfid_number' => 'DF'.uniqid(), 'client_name' => 'Test Client', 'brand_name' => 'Brand',
            'category_id' => $category->id,
        ]);
        $brand = Brand::create(['client_id' => $client->id, 'name' => 'Brand '.uniqid()]);

        $budget = Invoice::create([
            'client_id' => $client->id, 'brand_id' => $brand->id,
            'payment_category_id' => PaymentCategory::where('name', 'Social Media Ads')->value('id'),
            'invoice_number' => 'INV-'.uniqid(), 'total_payable' => 1000, 'status' => Invoice::STATUS_UNPAID,
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
            'invoice_number' => 'INV-'.uniqid(), 'total_payable' => 300, 'status' => Invoice::STATUS_UNPAID,
            'issued_by' => $manager->id, 'issued_date' => now(),
        ]);

        return $brand->fresh();
    }

    private function service(): ContentItemService
    {
        return app(ContentItemService::class);
    }

    // ── TEST 1 — Manager can access the Brand shared checklist ─────────────

    public function test_1_manager_can_access_brand_shared_checklist(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $brand = $this->readyBrand($manager);

        $this->actingAs($manager)->get(route('marketing.checklist', $brand))->assertOk();
    }

    // ── TEST 2 — Unauthorized/guest cannot access the checklist ────────────

    public function test_2_unauthorized_and_guest_cannot_access_checklist(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $brand = $this->readyBrand($manager);
        $noPerm = User::factory()->create(['is_active' => true]);

        $this->get(route('marketing.checklist', $brand))->assertRedirect(route('login'));
        $this->actingAs($noPerm)->get(route('marketing.checklist', $brand))->assertForbidden();
    }

    // ── TEST 3/4/5 — Content/Designer submissions appear automatically ─────

    public function test_3_4_5_submissions_from_every_department_appear_automatically(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $design = $this->user('Design', ['manage designer-content']);
        $brand = $this->readyBrand($manager);

        $raw = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Product Description'], $content);
        $this->service()->submit($raw->fresh(), ['link_url' => 'https://example.com/raw.jpg'], $content);

        $ad = $this->service()->create($brand, ['category' => 'advertising_content', 'title' => 'Facebook Ad Copy'], $content);
        $this->service()->submit($ad->fresh(), ['link_url' => 'https://example.com/ad.jpg'], $content);

        $poster = $this->service()->create($brand, ['category' => 'poster', 'title' => 'Campaign Poster'], $design);
        $this->service()->submit($poster->fresh(), ['link_url' => 'https://example.com/poster.jpg'], $design);

        $page = $this->actingAs($manager)->get(route('marketing.checklist', $brand));
        $page->assertOk();
        $page->assertSee('Product Description');
        $page->assertSee('Facebook Ad Copy');
        $page->assertSee('Campaign Poster');
    }

    // ── TEST 6 — Multiple items in the same category all appear ────────────

    public function test_6_multiple_items_in_the_same_category_all_appear(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $brand = $this->readyBrand($manager);

        foreach (['Item A', 'Item B', 'Item C'] as $title) {
            $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => $title], $content);
            $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/'.$title.'.jpg'], $content);
        }

        $page = $this->actingAs($manager)->get(route('marketing.checklist', $brand));
        $page->assertSee('Item A');
        $page->assertSee('Item B');
        $page->assertSee('Item C');
        $page->assertViewHas('counts', fn ($counts) => $counts['raw_content'] === 3);
    }

    // ── TEST 7/8 — Protected Download works; guest direct download blocked ─

    public function test_7_8_submitted_file_has_protected_download_guest_is_blocked(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $brand = $this->readyBrand($manager);

        $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Has File'], $content);
        $submission = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/has-file.jpg'], $content);

        // Simulate a real uploaded file (submit() above only recorded a link).
        $submission->update(['file_path' => 'content-items/test.jpg', 'disk' => 'local']);
        Storage::disk('local')->put('content-items/test.jpg', 'fake-bytes');

        $downloadUrl = route('marketing.content-items.submissions.download', [$brand, $item, $submission]);

        // Guest first — actingAs() would otherwise keep the session
        // authenticated for the rest of this test.
        $this->get($downloadUrl)->assertRedirect(route('login'));
        $this->actingAs($manager)->get($downloadUrl)->assertOk();
    }

    // ── TEST 9/10 — SMM collection/publication reflected automatically ─────

    public function test_9_10_smm_collection_and_publication_reflected_automatically(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $brand = $this->readyBrand($manager);

        $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Collect Me'], $content);
        $submission = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/x.jpg'], $content);
        $this->service()->collect($item->fresh(), $smm);

        $page = $this->actingAs($manager)->get(route('marketing.checklist', $brand));
        $page->assertSee('Collected by SMM');

        $this->service()->publish($item->fresh(), $brand, $submission->fresh(), ['facebook_post_url' => 'https://facebook.com/x'], $smm);

        $page = $this->actingAs($manager)->get(route('marketing.checklist', $brand));
        $page->assertSee('Published');
        $page->assertSee('Awaiting review');
    }

    // ── TEST 11/12 — Marketing review / revision-requested reflected ───────

    public function test_11_12_marketing_review_and_revision_requested_reflected(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);

        $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Review Me'], $content);
        $submission = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/x.jpg'], $content);
        $this->service()->collect($item->fresh(), $smm);
        $published = $this->service()->publish($item->fresh(), $brand, $submission->fresh(), ['facebook_post_url' => 'https://facebook.com/x'], $smm);

        $this->actingAs($marketing)->postJson(route('marketing.published-contents.review', [$brand, $published]))->assertOk();
        $this->actingAs($manager)->get(route('marketing.checklist', $brand))->assertSee('Reviewed');

        // A second item, sent back for revision instead.
        $item2 = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Revise Me'], $content);
        $submission2 = $this->service()->submit($item2->fresh(), ['link_url' => 'https://example.com/y.jpg'], $content);
        $this->service()->collect($item2->fresh(), $smm);
        $this->service()->publish($item2->fresh(), $brand, $submission2->fresh(), ['facebook_post_url' => 'https://facebook.com/y'], $smm);
        $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item2]), ['note' => 'Redo.']
        )->assertOk();

        $this->actingAs($manager)->get(route('marketing.checklist', $brand))->assertSee('Revision requested');
    }

    // ── TEST 13/14 — V1 + V2 history present; V1's state never leaks onto V2 ─

    public function test_13_14_v1_and_v2_history_present_and_isolated(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);

        $item = $this->service()->create($brand, ['category' => 'poster', 'title' => 'Versioned Poster'], $content);
        $submission1 = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/v1.jpg'], $content);
        $this->service()->collect($item->fresh(), $smm);
        $published1 = $this->service()->publish($item->fresh(), $brand, $submission1->fresh(), ['facebook_post_url' => 'https://facebook.com/v1'], $smm);

        $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]), ['note' => 'Redo.']
        )->assertOk();

        $this->travel(1)->seconds();
        $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/v2.jpg'], $content);
        $submission2 = ContentItemSubmission::where('content_item_id', $item->id)->where('id', '!=', $submission1->id)->first();
        $this->travel(1)->seconds();
        $this->service()->collect($item->fresh(), $smm);
        $this->travel(1)->seconds();
        $this->service()->publish($item->fresh(), $brand, $submission2->fresh(), ['facebook_post_url' => 'https://facebook.com/v2'], $smm);

        $page = $this->actingAs($manager)->get(route('marketing.checklist', $brand));
        $page->assertOk();

        $viewData = $page->viewData('categories')['poster'];
        $row = $viewData->firstWhere(fn ($r) => $r['item']->id === $item->id);
        $this->assertCount(2, $row['history']);

        $v1 = $row['history'][0];
        $v2 = $row['history'][1];
        $this->assertSame('V1', $v1['version_label']);
        $this->assertSame('V2', $v2['version_label']);
        $this->assertSame('revision_requested', $v1['review_state']);
        $this->assertSame('awaiting_review', $v2['review_state']);
        // V1's publication must never be the one attached to V2's row.
        $this->assertNotEquals($v1['publication']->id, $v2['publication']->id);
        $this->assertSame($submission1->id, $v1['submission']->id);
        $this->assertSame($submission2->id, $v2['submission']->id);
    }

    // ── Published By — each version's history shows ITS OWN publisher ──────

    public function test_published_by_shows_the_actual_publisher_and_v1_v2_differ(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $smmOne = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $smmTwo = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);

        $item = $this->service()->create($brand, ['category' => 'poster', 'title' => 'Two Publishers'], $content);
        $submission1 = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/v1.jpg'], $content);
        $this->service()->collect($item->fresh(), $smmOne);
        $this->service()->publish($item->fresh(), $brand, $submission1->fresh(), ['facebook_post_url' => 'https://facebook.com/v1'], $smmOne);

        $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]), ['note' => 'Redo.']
        )->assertOk();

        $this->travel(1)->seconds();
        $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/v2.jpg'], $content);
        $submission2 = ContentItemSubmission::where('content_item_id', $item->id)->where('id', '!=', $submission1->id)->first();
        $this->travel(1)->seconds();
        $this->service()->collect($item->fresh(), $smmTwo);
        $this->travel(1)->seconds();
        $this->service()->publish($item->fresh(), $brand, $submission2->fresh(), ['facebook_post_url' => 'https://facebook.com/v2'], $smmTwo);

        $page = $this->actingAs($manager)->get(route('marketing.checklist', $brand));
        $page->assertOk();
        // Default (escaping) assertSee — a generated name can contain an
        // apostrophe, which Blade's {{ }} output escapes.
        $page->assertSee('published by '.$smmOne->name);
        $page->assertSee('published by '.$smmTwo->name);

        $row = $page->viewData('categories')['poster']->firstWhere(fn ($r) => $r['item']->id === $item->id);
        $v1 = $row['history'][0];
        $v2 = $row['history'][1];

        // Each version's publisher comes from THAT version's own publication
        // row — never the item's latest, never the collector or reviewer.
        $this->assertSame($smmOne->id, $v1['publication']->publishedBy->id);
        $this->assertSame($smmTwo->id, $v2['publication']->publishedBy->id);
        $this->assertNotSame($v1['publication']->publishedBy->id, $v2['publication']->publishedBy->id);

        // Everything else already on the row stays intact alongside the new field.
        $this->assertSame($smmOne->id, $v1['collection']->collectedBy->id);
        $this->assertSame($content->id, $v1['submission']->submittedBy->id);
        $this->assertNotNull($v1['publication']->published_at);
        $this->assertSame('https://facebook.com/v1', $v1['publication']->facebook_post_url);
        $this->assertSame('revision_requested', $v1['review_state']);
    }

    // ── Published By — a missing/deleted publisher never crashes the page ──

    public function test_missing_publisher_does_not_crash_the_checklist(): void
    {
        // published_by is actually a required, cascade-on-delete column
        // (confirmed in the migration), so a row with no publisher at all
        // can't be produced through normal use or even through
        // ->update(['published_by' => null]) — SQLite's NOT NULL
        // constraint refuses it. The requirement is still that the view
        // must not crash if a publisher relation is ever unavailable, so
        // this exercises that defensive `?->name ?? '—'` fallback directly
        // by nulling the already-loaded, in-memory relation only — nothing
        // is written to the database.
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);

        // Two versions, so the collapsible History section (which only
        // renders once count() > 1 — the single-version summary line is
        // untouched by this fix) is actually reached.
        $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Orphaned Publisher'], $content);
        $submission1 = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/v1.jpg'], $content);
        $this->service()->collect($item->fresh(), $smm);
        $this->service()->publish($item->fresh(), $brand, $submission1->fresh(), ['facebook_post_url' => 'https://facebook.com/v1'], $smm);
        $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]), ['note' => 'Redo.']
        )->assertOk();
        $this->travel(1)->seconds();
        $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/v2.jpg'], $content);

        $data = app(BrandChecklistProjectionService::class)->detail($brand->fresh());
        $row = $data['categories']['raw_content']->firstWhere(fn ($r) => $r['item']->id === $item->id);
        $this->assertCount(2, $row['history']);
        $row['history'][0]['publication']->setRelation('publishedBy', null);

        $this->actingAs($manager);
        $html = view('checklist.show', $data)->render();
        $this->assertStringContainsString('published by —', $html);
    }

    // ── Timezone — stored UTC timestamps display as Asia/Dhaka (UTC+06:00) ──

    public function test_submission_timestamp_displays_in_asia_dhaka_not_utc(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $brand = $this->readyBrand($manager);

        // config('app.timezone') is UTC, so now() at this frozen instant is
        // exactly the stored created_at — the worked example from the spec.
        $this->travelTo(Carbon::parse('2026-10-06 03:50:00', 'UTC'));
        $item = $this->service()->create($brand, ['category' => 'poster', 'title' => 'Timezone Poster'], $content);
        $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/tz.jpg'], $content);

        $page = $this->actingAs($manager)->get(route('marketing.checklist', $brand));
        $page->assertOk();
        $page->assertDontSee('03:50 AM');
        $page->assertSee('06 Oct 2026, 09:50 AM');
    }

    public function test_collection_and_publication_timestamps_display_in_asia_dhaka(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);

        // A second version so the collapsible History section — where
        // collected_at/published_at render — is actually reached (a
        // single-submission item never shows it; see the "missing
        // publisher" test above for the same lesson).
        $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Collect Publish TZ'], $content);
        $submission1 = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/v1.jpg'], $content);
        $this->service()->collect($item->fresh(), $smm);
        $this->service()->publish($item->fresh(), $brand, $submission1->fresh(), ['facebook_post_url' => 'https://facebook.com/v1'], $smm);
        $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]), ['note' => 'Redo.']
        )->assertOk();
        $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/v2.jpg'], $content);
        $submission2 = ContentItemSubmission::where('content_item_id', $item->id)->where('id', '!=', $submission1->id)->first();

        $this->travelTo(Carbon::parse('2026-10-06 04:15:00', 'UTC'));
        $this->service()->collect($item->fresh(), $smm);

        $this->travelTo(Carbon::parse('2026-10-06 05:30:00', 'UTC'));
        $this->service()->publish($item->fresh(), $brand, $submission2->fresh(), ['facebook_post_url' => 'https://facebook.com/v2'], $smm);

        $page = $this->actingAs($manager)->get(route('marketing.checklist', $brand));
        $page->assertOk();
        // 04:15 UTC -> 10:15 AM Dhaka; 05:30 UTC -> 11:30 AM Dhaka.
        $page->assertSee('10:15 AM');
        $page->assertSee('11:30 AM');
        $page->assertDontSee('04:15 AM');
        $page->assertDontSee('05:30 AM');
    }

    public function test_v1_and_v2_history_timestamps_both_convert_to_asia_dhaka(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);

        $item = $this->service()->create($brand, ['category' => 'poster', 'title' => 'TZ History Poster'], $content);
        $this->travelTo(Carbon::parse('2026-10-06 00:00:00', 'UTC'));
        $submission1 = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/v1.jpg'], $content);
        $this->service()->collect($item->fresh(), $smm);
        $this->service()->publish($item->fresh(), $brand, $submission1->fresh(), ['facebook_post_url' => 'https://facebook.com/v1'], $smm);
        $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]), ['note' => 'Redo.']
        )->assertOk();

        // 18:00 UTC -> 07 Oct 2026, 12:00 AM Dhaka — also proves the DATE,
        // not just the hour, shifts across midnight correctly.
        $this->travelTo(Carbon::parse('2026-10-06 18:00:00', 'UTC'));
        $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/v2.jpg'], $content);
        $submission2 = ContentItemSubmission::where('content_item_id', $item->id)->where('id', '!=', $submission1->id)->first();
        $this->service()->collect($item->fresh(), $smm);
        $this->service()->publish($item->fresh(), $brand, $submission2->fresh(), ['facebook_post_url' => 'https://facebook.com/v2'], $smm);

        $page = $this->actingAs($manager)->get(route('marketing.checklist', $brand));
        $page->assertOk();
        // V1's whole cluster of events happened at UTC midnight -> 06:00 AM Dhaka.
        $page->assertSee('06 Oct 2026, 06:00 AM');
        // V2's happened at 18:00 UTC -> past midnight Dhaka, the next calendar day.
        $page->assertSee('07 Oct 2026, 12:00 AM');
        // The un-converted raw-UTC rendering of that same instant must not leak through.
        $page->assertDontSee('06 Oct 2026, 06:00 PM');

        // Submitted-by/collected-by/published-by relationships are unaffected by this change.
        $row = $page->viewData('categories')['poster']->firstWhere(fn ($r) => $r['item']->id === $item->id);
        $this->assertSame($content->id, $row['history'][0]['submission']->submittedBy->id);
        $this->assertSame($smm->id, $row['history'][0]['collection']->collectedBy->id);
        $this->assertSame($smm->id, $row['history'][0]['publication']->publishedBy->id);
        $this->assertSame('revision_requested', $row['history'][0]['review_state']);
        $this->assertSame('awaiting_review', $row['history'][1]['review_state']);
    }

    // ── TEST 15 — Old submitted file remains downloadable after V2 exists ──

    public function test_15_old_submitted_file_remains_downloadable_after_v2_exists(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $marketing = $this->user('Marketing', ['manage publishing-review']);
        $brand = $this->readyBrand($manager);

        $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'V1 File'], $content);
        $submission1 = $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/v1.jpg'], $content);
        $submission1->update(['file_path' => 'content-items/v1.jpg', 'disk' => 'local']);
        Storage::disk('local')->put('content-items/v1.jpg', 'v1-bytes');

        $this->service()->collect($item->fresh(), $smm);
        $this->service()->publish($item->fresh(), $brand, $submission1->fresh(), ['facebook_post_url' => 'https://facebook.com/v1'], $smm);
        $this->actingAs($marketing)->postJson(
            route('marketing.content-items.request-revision', [$brand, $item]), ['note' => 'Redo.']
        )->assertOk();
        $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/v2.jpg'], $content);

        $downloadUrl = route('marketing.content-items.submissions.download', [$brand, $item, $submission1]);
        $this->actingAs($manager)->get($downloadUrl)->assertOk();
    }

    // ── TEST 16 — On Hold checklist remains readable ────────────────────────

    public function test_16_on_hold_checklist_remains_readable(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $brand = $this->readyBrand($manager);

        $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Pre-hold item'], $content);
        $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/x.jpg'], $content);

        $brand->checklist->update(['on_hold_at' => now(), 'on_hold_reason' => 'Payment reversed.']);

        $page = $this->actingAs($manager)->get(route('marketing.checklist', $brand));
        $page->assertOk();
        $page->assertSee('On hold');
        $page->assertSee('Pre-hold item');
        $page->assertSee('Payment reversed.');
    }

    // ── TEST 17 — Public pages never expose checklist/submission data ──────

    public function test_17_public_pages_do_not_expose_checklist_data(): void
    {
        $manager = $this->user('Manager', ['manage payments']);
        $content = $this->user('Content', ['manage raw-content']);
        $brand = $this->readyBrand($manager);
        $brand->update(['is_public' => true, 'is_active' => true, 'slug' => 'public-brand-'.uniqid()]);

        $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => 'Secret Internal Title'], $content);
        $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/secret.jpg'], $content);

        $response = $this->get(route('landing.brand', $brand));
        $response->assertOk();
        $response->assertDontSee('Secret Internal Title');
        $response->assertDontSee('checklist', false);
    }

    // ── TEST 18 — Manager cross-brand summary counts are correct ───────────

    public function test_18_manager_cross_brand_summary_counts_are_correct(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $design = $this->user('Design', ['manage designer-content']);
        $brandA = $this->readyBrand($manager);
        $brandB = $this->readyBrand($manager);

        foreach (range(1, 3) as $i) {
            $item = $this->service()->create($brandA, ['category' => 'raw_content', 'title' => "A Raw $i"], $content);
            $this->service()->submit($item->fresh(), ['link_url' => 'https://example.com/'.$i.'.jpg'], $content);
        }
        $posterItem = $this->service()->create($brandB, ['category' => 'poster', 'title' => 'B Poster'], $design);
        $this->service()->submit($posterItem->fresh(), ['link_url' => 'https://example.com/p.jpg'], $design);

        $page = $this->actingAs($manager)->get(route('manager.oversight'));
        $page->assertOk();
        $page->assertSee('3'); // Brand A's raw content count appears somewhere in the table
        $page->assertSee($brandA->name);
        $page->assertSee($brandB->name);
    }

    // ── TEST 19 — Read access never grants cross-department write access ───

    public function test_19_operational_role_read_access_does_not_grant_write_access(): void
    {
        $content = $this->user('Content', ['view raw-content-panel', 'manage raw-content']);
        $manager = $this->user('Manager', ['manage payments']);
        $design = $this->user('Design', ['manage designer-content']);
        $brand = $this->readyBrand($manager);

        // Content can read the shared checklist...
        $this->actingAs($content)->get(route('marketing.checklist', $brand))->assertOk();

        // ...but still cannot perform a Designer (poster) submission.
        $poster = $this->service()->create($brand, ['category' => 'poster', 'title' => 'Not Yours'], $design);
        $this->actingAs($content)->postJson(route('marketing.content-items.submit', [$brand, $poster]), [
            'link_url' => 'https://example.com/x.jpg',
        ])->assertForbidden();
    }

    // ── TEST: query count stays bounded (N+1 guard) ─────────────────────────

    public function test_27_checklist_detail_query_count_stays_bounded_regardless_of_item_count(): void
    {
        $manager = $this->user('Manager', ['manage payments', 'view brand-checklist-overview']);
        $content = $this->user('Content', ['manage raw-content']);
        $design = $this->user('Design', ['manage designer-content']);
        $smm = $this->user('Social Media Manager', ['manage smm-collection', 'manage published-content']);
        $brand = $this->readyBrand($manager);

        foreach (range(1, 8) as $i) {
            $cat = ['raw_content', 'advertising_content', 'poster'][$i % 3];
            $creator = $cat === 'poster' ? $design : $content;
            $item = $this->service()->create($brand, ['category' => $cat, 'title' => "Item $i"], $creator);
            $submission = $this->service()->submit($item->fresh(), ['link_url' => "https://example.com/$i.jpg"], $creator);
            if ($i % 2 === 0) {
                $this->service()->collect($item->fresh(), $smm);
                $this->service()->publish($item->fresh(), $brand, $submission->fresh(), ['facebook_post_url' => "https://facebook.com/$i"], $smm);
            }
        }

        DB::enableQueryLog();
        $this->actingAs($manager)->get(route('marketing.checklist', $brand))->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::flushQueryLog();

        // A second, much larger pass — the count must not grow proportionally.
        foreach (range(9, 20) as $i) {
            $item = $this->service()->create($brand, ['category' => 'raw_content', 'title' => "Item $i"], $content);
            $this->service()->submit($item->fresh(), ['link_url' => "https://example.com/$i.jpg"], $content);
        }

        // Flush again — the item-creation calls above ran their own queries
        // and logging was never turned off, only cleared once before them.
        DB::flushQueryLog();
        $this->actingAs($manager)->get(route('marketing.checklist', $brand))->assertOk();
        $queryCountLarger = count(DB::getQueryLog());

        $this->assertLessThan($queryCount + 5, $queryCountLarger, 'Query count grew with item count — likely an N+1.');
    }
}
