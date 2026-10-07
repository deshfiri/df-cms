{{--
    Daily / Monthly / Yearly picker for the activity sections. It is a plain GET
    form, so the chosen period lives in the URL and survives reloads. Values are
    server-rendered: the selected period when one is in the URL, otherwise the
    current Asia/Dhaka day, month and year. The browser clock is never used.
--}}
@php
    $dhakaNow = now('Asia/Dhaka');
    $dateValue = $period->period === 'daily' ? $period->selected : $dhakaNow->format('Y-m-d');
    $monthValue = $period->period === 'monthly' ? $period->selected : $dhakaNow->format('Y-m');
    $yearValue = $period->period === 'yearly' ? $period->selected : $dhakaNow->format('Y');
@endphp
<form method="GET" action="{{ url()->current() }}" class="activity-period-form d-flex align-items-center gap-2 flex-wrap">
    <select name="period" class="form-select form-select-sm activity-period-type" style="width:130px" aria-label="Reporting period">
        @foreach (['daily' => 'Daily', 'monthly' => 'Monthly', 'yearly' => 'Yearly'] as $value => $label)
            <option value="{{ $value }}" @selected($period->period === $value)>{{ $label }}</option>
        @endforeach
    </select>
    <input type="date" name="date" value="{{ $dateValue }}" data-period="daily" aria-label="Selected day"
           class="form-control form-control-sm activity-period-input" style="width:160px">
    <input type="month" name="month" value="{{ $monthValue }}" data-period="monthly" aria-label="Selected month"
           class="form-control form-control-sm activity-period-input" style="width:160px">
    <input type="number" name="year" value="{{ $yearValue }}" min="2000" max="2100" data-period="yearly" aria-label="Selected year"
           class="form-control form-control-sm activity-period-input" style="width:110px">
    <button type="submit" class="btn btn-sm btn-outline-secondary">Show</button>
    <span class="small" style="color:var(--text3)">{{ $period->label }}</span>
</form>

@once
    @push('scripts')
        <script>
            (function () {
                const form = document.querySelector('.activity-period-form');
                if (!form) return;
                const type = form.querySelector('.activity-period-type');
                const sync = () => form.querySelectorAll('.activity-period-input').forEach(el => {
                    el.classList.toggle('d-none', el.dataset.period !== type.value);
                });
                type.addEventListener('change', () => { sync(); form.submit(); });
                form.querySelectorAll('.activity-period-input').forEach(el => el.addEventListener('change', () => form.submit()));
                sync();
            })();
        </script>
    @endpush
@endonce
