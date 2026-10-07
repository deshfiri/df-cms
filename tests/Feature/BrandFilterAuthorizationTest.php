<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ContentWorkflowFixtures;
use Tests\TestCase;

/**
 * Brand filter authorization (sections 13–14, 26–28). There is no per-user
 * Brand authorization layer finer than the panel's own permission check in
 * this app — see Brand::scopeInWorkflow()'s docblock — so the eligible set a
 * brand_id is validated against is exactly the same "has a checklist" rule
 * every Brand filter dropdown is already built from. A brand_id outside that
 * set — never created, soft-deleted, or simply a brand with no checklist —
 * can never select anything: BrandScope::fromRequest() silently falls back
 * to "All Brands", exactly like ReportingPeriod's own invalid-input rule.
 */
class BrandFilterAuthorizationTest extends TestCase
{
    use ContentWorkflowFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorkflowPermissions();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /** @return array<int, int> */
    private function availableIds(User $viewer, array $query): array
    {
        return collect($this->actingAs($viewer)->getJson(route('panels.smm.available', $query))->json('data'))->pluck('id')->all();
    }

    public function test_a_brand_id_that_does_not_exist_is_ignored_not_trusted(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->handedOverAt('2026-10-06 10:00', $brand, $t['content'], $t['marketing']);

        $query = $this->monthly('2026-10');
        $query['brand_id'] = 999999;
        $idsForFakeBrand = $this->availableIds($t['smm'], $query);

        $this->assertContains($item->id, $idsForFakeBrand, 'A nonexistent brand_id falls back to All Brands, not to an empty result.');
    }

    /**
     * A real brand that exists in the database but has no checklist (never
     * reachable through any "New Item"/panel brand picker) is not a brand the
     * SMM/Marketing/Content/Designer/Manager panels consider "in workflow".
     * Even if a row is somehow tagged to it, selecting its id can never
     * single it out — the id simply is not in the eligible set, so the
     * request degrades to "All Brands" instead of leaking that brand's data
     * or erroring.
     */
    public function test_a_brand_with_no_checklist_cannot_be_selected_even_by_its_real_id(): void
    {
        $t = $this->workflowTeam();
        $eligibleBrand = $this->readyBrand($t['manager']);
        $eligibleItem = $this->handedOverAt('2026-10-06 10:00', $eligibleBrand, $t['content'], $t['marketing']);

        // A brand with no checklist at all — never paid, never onboarded.
        $outsiderBrand = Brand::create(['client_id' => $this->client()->id, 'name' => 'Outsider Brand']);
        $this->assertFalse($outsiderBrand->fresh()->checklist()->exists());

        $query = $this->monthly('2026-10');
        $query['brand_id'] = $outsiderBrand->id;
        $ids = $this->availableIds($t['smm'], $query);

        // Falls back to All Brands: the eligible brand's own item still shows.
        $this->assertContains($eligibleItem->id, $ids);
    }

    public function test_a_non_integer_brand_id_is_handled_safely(): void
    {
        $t = $this->workflowTeam();
        $brand = $this->readyBrand($t['manager']);
        $item = $this->handedOverAt('2026-10-06 10:00', $brand, $t['content'], $t['marketing']);

        $query = $this->monthly('2026-10');
        $query['brand_id'] = ['array', 'instead', 'of', 'scalar'];
        $response = $this->actingAs($t['smm'])->getJson(route('panels.smm.available', $query));

        $response->assertOk();
        $this->assertContains($item->id, collect($response->json('data'))->pluck('id')->all());
    }

    public function test_an_authorized_brand_id_still_correctly_scopes_the_query(): void
    {
        $t = $this->workflowTeam();
        $brandA = $this->readyBrand($t['manager']);
        $brandB = $this->readyBrand($t['manager']);
        $itemA = $this->handedOverAt('2026-10-06 10:00', $brandA, $t['content'], $t['marketing']);
        $itemB = $this->handedOverAt('2026-10-06 10:05', $brandB, $t['content'], $t['marketing']);

        $ids = $this->availableIds($t['smm'], $this->withBrand($this->monthly('2026-10'), $brandA));

        $this->assertContains($itemA->id, $ids);
        $this->assertNotContains($itemB->id, $ids, 'A valid, eligible brand_id must still narrow the query — the fallback rule is only for invalid/ineligible ids.');
    }
}
