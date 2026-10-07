{{--
    Historical activity for the selected Daily / Monthly / Yearly period AND
    selected Brand. Whether the row list below it follows the same filters is
    per-panel — see $caption, which each page sets to describe its own queue
    correctly.
    Expects: $title, $caption, $period (ReportingPeriod), $rowHeading,
    $columns ([key, label, help]), $rows ([label, values, strong?]).
    Optional: $brands (eligible Brand collection) + $brand (BrandScope) — a
    page with no Brand concept simply omits both.
--}}
<div class="card section-card mb-3">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
            <div>
                <div class="fw-semibold" style="font-size:.9rem">{{ $title }} <span class="small fw-normal" style="color:var(--text3)">· Activity / performance</span></div>
                <div class="small" style="color:var(--text3)">{{ $caption }}</div>
            </div>
            @include('partials.activity-period', ['period' => $period, 'brands' => $brands ?? null, 'brand' => $brand ?? null])
        </div>
        @include('partials.activity-table', ['rowHeading' => $rowHeading, 'columns' => $columns, 'rows' => $rows])
    </div>
</div>
