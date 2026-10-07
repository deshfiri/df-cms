{{--
    Historical activity for the selected Daily / Monthly / Yearly period. It is
    separate from the current queue below it, which is never filtered by this.
    Expects: $title, $caption, $period (ReportingPeriod), $rowHeading,
    $columns ([key, label, help]), $rows ([label, values, strong?]).
--}}
<div class="card section-card mb-3">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
            <div>
                <div class="fw-semibold" style="font-size:.9rem">{{ $title }} <span class="small fw-normal" style="color:var(--text3)">· Activity / performance</span></div>
                <div class="small" style="color:var(--text3)">{{ $caption }}</div>
            </div>
            @include('partials.activity-period', ['period' => $period])
        </div>
        @include('partials.activity-table', ['rowHeading' => $rowHeading, 'columns' => $columns, 'rows' => $rows])
    </div>
</div>
