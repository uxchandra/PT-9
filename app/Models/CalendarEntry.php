<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['date', 'pattern_board_id'])]
class CalendarEntry extends Model
{
    // 'date' is deliberately NOT cast to 'date': a date cast still serializes
    // through the full datetime format on save ("2026-09-03 00:00:00"), which
    // makes updateOrCreate(['date' => 'Y-m-d']) silently miss the existing row
    // and hit the unique index instead. Always read/write it as the plain
    // "Y-m-d" string (same reasoning as PatternActual::produced_on).

    public function patternBoard(): BelongsTo
    {
        return $this->belongsTo(PatternBoard::class);
    }

    /**
     * The pattern board scheduled to run on $date ("Y-m-d"), or null when the
     * day has no assignment yet.
     *
     * Memoised per request: the Kesei board resolves this once per run-day per
     * part (hundreds of identical look-ups on a busy board), and the calendar
     * does not change mid-request. `once()` is flushed between requests/tests.
     */
    public static function patternBoardForDate(string $date): ?PatternBoard
    {
        return once(fn () => static::query()
            ->with('patternBoard')
            ->whereDate('date', $date)
            ->first()?->patternBoard);
    }

    /**
     * Minute-of-day the running calendar pattern rolls onto the new date.
     * 07:00 = start of shift 1; between midnight and 07:00 shift 2 is still
     * running the *previous* calendar day's pattern.
     */
    public const RUNNING_SWITCH_MINUTE = 7 * 60;

    /**
     * 07:00 of the production day that $at (default: now) falls in. Between
     * midnight and 07:00 that is still the *previous* calendar day's 07:00,
     * because shift 2 keeps running the previous day's pattern until shift 1.
     */
    public static function productionDayStart(?Carbon $at = null): Carbon
    {
        $at = $at ? $at->copy() : Carbon::now();
        $start = $at->copy()->startOfDay()->addMinutes(self::RUNNING_SWITCH_MINUTE);

        if ($start->gt($at)) {
            $start->subDay();
        }

        return $start;
    }

    /**
     * The pattern board actually running at $at (default: now), honouring the
     * 07:00 shift-1 rollover — so e.g. Tuesday 04:00 still resolves to Monday's
     * assignment.
     */
    public static function runningPatternBoard(?Carbon $at = null): ?PatternBoard
    {
        return self::patternBoardForDate(self::productionDayStart($at)->toDateString());
    }

    public static function patternBoardForToday(): ?PatternBoard
    {
        return self::patternBoardForDate(Carbon::now()->toDateString());
    }

    public static function patternBoardForTomorrow(): ?PatternBoard
    {
        return self::patternBoardForDate(Carbon::now()->addDay()->toDateString());
    }
}
