<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Parses a Daily / Monthly / Yearly reporting-period request into UTC
 * [since, until) query boundaries for the business's Asia/Dhaka calendar.
 *
 * `?period=daily&date=2026-10-06` means midnight-to-midnight Dhaka time on
 * that CALENDAR date — never "the last 24 hours". The app and database stay
 * on UTC throughout (APP_TIMEZONE is never touched); only the two resolved
 * boundary instants are ever compared against stored UTC timestamps, the
 * same approach the checklist's own `$dhaka` display closure already uses
 * for rendering, just inverted for querying.
 *
 * Invalid/missing input never throws — it silently falls back to the
 * current Dhaka day/month/year, so a malformed query string degrades to a
 * safe default instead of a 500 or an unfiltered report.
 */
final class ReportingPeriod
{
    public const TIMEZONE = 'Asia/Dhaka';

    public const PERIODS = ['daily', 'monthly', 'yearly'];

    private function __construct(
        public readonly string $period,
        public readonly string $selected,
        public readonly string $label,
        public readonly Carbon $since,
        public readonly Carbon $until,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $period = in_array($request->query('period'), self::PERIODS, true)
            ? $request->query('period')
            : 'monthly';

        return match ($period) {
            'daily' => self::daily((string) $request->query('date', '')),
            'yearly' => self::yearly((string) $request->query('year', '')),
            default => self::monthly((string) $request->query('month', '')),
        };
    }

    public static function daily(string $date): self
    {
        $start = self::parseOrNow($date, 'Y-m-d')->startOfDay();

        return new self('daily', $start->format('Y-m-d'), $start->format('d M Y'), self::toUtc($start), self::toUtc($start->copy()->addDay()));
    }

    public static function monthly(string $month): self
    {
        $start = self::parseOrNow($month, 'Y-m')->startOfMonth();

        return new self('monthly', $start->format('Y-m'), $start->format('F Y'), self::toUtc($start), self::toUtc($start->copy()->addMonthNoOverflow()));
    }

    public static function yearly(string $year): self
    {
        $start = self::parseOrNow($year, 'Y')->startOfYear();

        return new self('yearly', $start->format('Y'), $start->format('Y'), self::toUtc($start), self::toUtc($start->copy()->addYear()));
    }

    /** @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon} */
    public function bounds(): array
    {
        return [$this->since, $this->until];
    }

    private static function parseOrNow(string $value, string $format): Carbon
    {
        if ($value === '') {
            return Carbon::now(self::TIMEZONE);
        }

        try {
            $parsed = Carbon::createFromFormat('!'.$format, $value, self::TIMEZONE);

            // createFromFormat is lenient about out-of-range values (e.g.
            // "2026-13" silently rolls into the next year) — round-tripping
            // back through the same format and comparing catches that.
            if (! $parsed || $parsed->format($format) !== $value) {
                return Carbon::now(self::TIMEZONE);
            }

            return $parsed;
        } catch (\Throwable) {
            return Carbon::now(self::TIMEZONE);
        }
    }

    private static function toUtc(Carbon $dhaka): Carbon
    {
        return $dhaka->copy()->setTimezone('UTC');
    }
}
