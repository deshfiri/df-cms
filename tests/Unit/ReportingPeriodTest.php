<?php

namespace Tests\Unit;

use App\Support\ReportingPeriod;
use Carbon\Carbon;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The exact UTC [since, until) instants each calendar period resolves to
 * for the Asia/Dhaka business calendar. Dhaka is UTC+6 with no DST, so a
 * Dhaka midnight is always 18:00 UTC on the previous calendar day.
 */
class ReportingPeriodTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function utc(ReportingPeriod $period): array
    {
        [$since, $until] = $period->bounds();

        return [$since->toDateTimeString(), $until->toDateTimeString()];
    }

    public function test_a_daily_period_is_the_dhaka_calendar_day_in_utc(): void
    {
        $period = ReportingPeriod::daily('2026-10-06');

        $this->assertSame(['2026-10-05 18:00:00', '2026-10-06 18:00:00'], $this->utc($period));
        $this->assertSame('2026-10-06', $period->selected);
        $this->assertSame('UTC', $period->since->timezoneName);
    }

    public function test_a_monthly_period_rolls_over_correctly_across_the_year_boundary(): void
    {
        $this->assertSame(['2026-11-30 18:00:00', '2026-12-31 18:00:00'], $this->utc(ReportingPeriod::monthly('2026-12')));
    }

    public function test_a_february_period_respects_leap_years(): void
    {
        $this->assertSame(['2028-01-31 18:00:00', '2028-02-29 18:00:00'], $this->utc(ReportingPeriod::monthly('2028-02')));
    }

    public function test_a_yearly_period_is_the_dhaka_calendar_year_in_utc(): void
    {
        $this->assertSame(['2025-12-31 18:00:00', '2026-12-31 18:00:00'], $this->utc(ReportingPeriod::yearly('2026')));
    }

    public function test_a_request_selects_the_period_named_by_its_parameters(): void
    {
        $daily = ReportingPeriod::fromRequest(Request::create('/x', 'GET', ['period' => 'daily', 'date' => '2026-10-06']));
        $monthly = ReportingPeriod::fromRequest(Request::create('/x', 'GET', ['period' => 'monthly', 'month' => '2026-09']));
        $yearly = ReportingPeriod::fromRequest(Request::create('/x', 'GET', ['period' => 'yearly', 'year' => '2025']));

        $this->assertSame('2026-10-06', $daily->selected);
        $this->assertSame('2026-09', $monthly->selected);
        $this->assertSame('2025', $yearly->selected);
    }

    public function test_an_unknown_period_name_falls_back_to_monthly(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-06 10:00', 'Asia/Dhaka'));

        $period = ReportingPeriod::fromRequest(Request::create('/x', 'GET', ['period' => 'weekly']));

        $this->assertSame('monthly', $period->period);
        $this->assertSame('2026-10', $period->selected);
    }

    public function test_an_impossible_calendar_value_is_not_rolled_over_silently(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-06 10:00', 'Asia/Dhaka'));

        // createFromFormat would happily turn 2026-02-30 into 1 March; the round-trip check rejects it.
        $this->assertSame('2026-10-06', ReportingPeriod::daily('2026-02-30')->selected);
        $this->assertSame('2026-10', ReportingPeriod::monthly('2026-13')->selected);
        $this->assertSame('2026', ReportingPeriod::yearly('2026-99')->selected);
    }

    public function test_a_missing_value_uses_the_current_dhaka_period(): void
    {
        // 2026-10-07 00:30 Dhaka is still 2026-10-06 18:30 UTC: the report must name the Dhaka day.
        Carbon::setTestNow(Carbon::parse('2026-10-07 00:30', 'Asia/Dhaka'));

        $this->assertSame('2026-10-07', ReportingPeriod::daily('')->selected);
    }
}
