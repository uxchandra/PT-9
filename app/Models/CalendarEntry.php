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
     */
    public static function patternBoardForDate(string $date): ?PatternBoard
    {
        return static::query()
            ->with('patternBoard')
            ->whereDate('date', $date)
            ->first()?->patternBoard;
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
