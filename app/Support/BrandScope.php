<?php

namespace App\Support;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Parses a `?brand_id=` request parameter into an optional, pre-authorized
 * Brand selection — the Brand half of the same "period AND brand" filter
 * ReportingPeriod already supplies the time half of. Deliberately a separate
 * class: ReportingPeriod stays responsible only for time (see its own
 * docblock), and this stays responsible only for which brand, so the two
 * concerns are never tangled into one.
 *
 * Authorization is never trusted from the request. `$eligible` is the exact
 * Brand collection the caller already built for this screen (its own
 * dropdown source, e.g. Brand::inWorkflow()), so a brand_id outside that set
 * — unauthorized, deleted, or simply made up — can never select anything.
 * Exactly like ReportingPeriod's own invalid-input rule, a bad id never
 * throws or aborts: it silently falls back to "All Brands", so a tampered or
 * stale query string degrades to a safe default instead of a 500 or a leak.
 */
final class BrandScope
{
    private function __construct(
        public readonly ?int $id,
        public readonly ?string $name,
    ) {}

    /** @param  Collection<int, Brand>  $eligible  */
    public static function fromRequest(Request $request, Collection $eligible): self
    {
        $raw = $request->query('brand_id');

        if ($raw === null || $raw === '' || $raw === 'all') {
            return self::all();
        }

        $id = filter_var($raw, FILTER_VALIDATE_INT);
        if ($id === false) {
            return self::all();
        }

        $brand = $eligible->firstWhere('id', $id);

        return $brand ? new self((int) $brand->id, $brand->name) : self::all();
    }

    public static function all(): self
    {
        return new self(null, null);
    }

    public function isAll(): bool
    {
        return $this->id === null;
    }

    /** Applies `WHERE $column = id` only when a specific brand is selected — a no-op for "All Brands". */
    public function apply(Builder|\Illuminate\Database\Query\Builder $query, string $column = 'brand_id'): Builder|\Illuminate\Database\Query\Builder
    {
        return $this->isAll() ? $query : $query->where($column, $this->id);
    }
}
