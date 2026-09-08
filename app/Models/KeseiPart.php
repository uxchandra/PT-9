<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

#[Fillable(['part_id', 'stock_source', 'closing_time', 'urutan'])]
class KeseiPart extends Model
{
    /**
     * How far back a row's red-tick pile may reach when it has not hit a
     * run-day closing recently. Also the hard cap on the stock lookback.
     */
    public const FOLD_HISTORY_DAYS = 8;

    protected function casts(): array
    {
        return [
            'closing_time' => 'datetime:H:i',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    /**
     * A Kesei row can belong to more than one Pattern Board.
     */
    public function patternBoards(): BelongsToMany
    {
        return $this->belongsToMany(PatternBoard::class)->orderBy('name');
    }

    /**
     * The part_no(s) whose Stock Part All readings feed this row's Timeline
     * Stok, summed. Falls back to this row's own part_no when no override is
     * set.
     *
     * @return array<int, string>
     */
    public function sourcePartNos(): array
    {
        $raw = trim((string) $this->stock_source);

        if ($raw !== '') {
            return Str::of($raw)
                ->explode(',')
                ->map(fn ($part) => trim($part))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        $own = $this->part?->part_no;

        return $own !== null && $own !== '' ? [$own] : [];
    }

    /**
     * The moment this row's closing time falls on for the production day that
     * starts at $prodDayStart (07:00). Times before 07:00 belong to the tail of
     * that production day, i.e. the following calendar morning. Null when the
     * row has no closing time.
     */
    public function closingAtFor(Carbon $prodDayStart): ?Carbon
    {
        if ($this->closing_time === null) {
            return null;
        }

        $at = $prodDayStart->copy()->setTime((int) $this->closing_time->hour, (int) $this->closing_time->minute);

        if ($at->lt($prodDayStart)) {
            $at->addDay();
        }

        return $at;
    }

    /**
     * Is the production day starting at $prodDayStart a run-day for this row —
     * i.e. is the Calendar's pattern that day one of this row's boards? A row
     * with no boards runs every day (so its pile folds daily, not forever).
     * Requires the `patternBoards` relation to be loaded.
     */
    public function isRunDay(Carbon $prodDayStart): bool
    {
        $boardIds = $this->patternBoards->pluck('id')->all();

        if ($boardIds === []) {
            return true;
        }

        $boardId = CalendarEntry::patternBoardForDate($prodDayStart->toDateString())?->id;

        return $boardId !== null && in_array($boardId, $boardIds, true);
    }

    /**
     * Has this row's closing time already passed on the current run-day? False
     * on non-run-days and for rows with no closing time.
     */
    public function closingReached(Carbon $now): bool
    {
        if ($this->closing_time === null) {
            return false;
        }

        $prodDayStart = CalendarEntry::productionDayStart($now);

        return $this->isRunDay($prodDayStart) && $now->gte($this->closingAtFor($prodDayStart));
    }

    /**
     * [foldStart, cycleStart] for this row at $now:
     *  - foldStart  — the most recent run-day closing at/before $now. Red ticks
     *                 after it are the live pile; ticks before it have folded.
     *  - cycleStart — the run-day closing before that (or the history floor when
     *                 there is none). The span (cycleStart, foldStart] is what
     *                 folded into the accumulated-kanban number.
     * Rows with no closing time get [historyFloor, historyFloor].
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function foldBoundaries(Carbon $now): array
    {
        $historyFloor = $now->copy()->subDays(self::FOLD_HISTORY_DAYS);

        if ($this->closing_time === null) {
            return [$historyFloor, $historyFloor];
        }

        $prodDayStart = CalendarEntry::productionDayStart($now);
        $found = [];

        for ($k = 0; $k <= self::FOLD_HISTORY_DAYS + 1; $k++) {
            $dayStart = $prodDayStart->copy()->subDays($k);
            $closingAt = $this->closingAtFor($dayStart);

            if ($closingAt->gt($now)) {
                continue;
            }

            if ($this->isRunDay($dayStart)) {
                $found[] = $closingAt;

                if (count($found) === 2) {
                    break;
                }
            }
        }

        if ($found === []) {
            return [$historyFloor, $historyFloor];
        }

        return [$found[0], $found[1] ?? $historyFloor];
    }
}
