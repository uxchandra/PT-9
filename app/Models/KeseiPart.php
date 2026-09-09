<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

#[Fillable(['part_id', 'stock_source', 'level', 'closing_time', 'closing_mode', 'urutan'])]
class KeseiPart extends Model
{
    /**
     * How far back a row's red-tick pile may reach when it has not hit a
     * run-day closing recently. Also the hard cap on the stock lookback.
     */
    public const FOLD_HISTORY_DAYS = 8;

    /** closing_time is a planning cutoff in the 24h BEFORE the 07:00 run —
     *  the demand piled up by then becomes that run's production plan. */
    public const CLOSING_PRE_RUN = 'pre_run';

    /** closing_time closes the run's own production day (07:00 → 07:00). */
    public const CLOSING_END_OF_DAY = 'end_of_day';

    public const CLOSING_MODES = [self::CLOSING_PRE_RUN, self::CLOSING_END_OF_DAY];

    protected function casts(): array
    {
        return [
            'closing_time' => 'datetime:H:i',
        ];
    }

    public function isPreRunClosing(): bool
    {
        // Anything but an explicit end_of_day counts as pre-run (the default).
        return $this->closing_mode !== self::CLOSING_END_OF_DAY;
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
     * The wall-clock instant this row's closing time falls on for the 07:00
     * production run that starts at $runDayStart. Null when the row has no
     * closing time.
     *
     *  - pre_run    : the closing sits in the 24h BEFORE the run. A time < 07:00
     *                 lands the same morning; a time >= 07:00 the evening before.
     *  - end_of_day : the closing sits inside the run's own 07:00 → 07:00 day.
     *                 A time >= 07:00 lands that day; a time < 07:00 the next
     *                 calendar morning.
     */
    public function closingInstantForRunDay(Carbon $runDayStart): ?Carbon
    {
        if ($this->closing_time === null) {
            return null;
        }

        $at = $runDayStart->copy()->setTime((int) $this->closing_time->hour, (int) $this->closing_time->minute);

        if ($this->isPreRunClosing()) {
            if ($at->gte($runDayStart)) {
                $at->subDay();
            }
        } elseif ($at->lt($runDayStart)) {
            $at->addDay();
        }

        return $at;
    }

    /**
     * Is the day whose production run starts at $runDayStart (07:00) a run-day
     * for this row — i.e. is the Calendar's pattern that day one of this row's
     * boards? A row with no boards runs every day (so its pile folds regularly,
     * not forever). Requires the `patternBoards` relation to be loaded.
     */
    public function isRunDay(Carbon $runDayStart): bool
    {
        $boardIds = $this->patternBoards->pluck('id')->all();

        if ($boardIds === []) {
            return true;
        }

        $boardId = CalendarEntry::patternBoardForDate($runDayStart->toDateString())?->id;

        return $boardId !== null && in_array($boardId, $boardIds, true);
    }

    /**
     * Run-day starts (07:00), newest first, from the NEXT one down to
     * FOLD_HISTORY_DAYS back. The next run-day is included because a pre_run
     * closing for it can already be in the past.
     *
     * @return array<int, Carbon>
     */
    private function recentRunDayStarts(Carbon $now): array
    {
        $anchor = CalendarEntry::productionDayStart($now);
        $starts = [];

        for ($k = -1; $k <= self::FOLD_HISTORY_DAYS + 1; $k++) {
            $start = $anchor->copy()->subDays($k);

            if ($this->isRunDay($start)) {
                $starts[] = $start;
            }
        }

        return $starts;
    }

    /**
     * The run-day (07:00 start) whose closing is the most recent one at/before
     * $now — i.e. the run this row is currently planning for / has just closed.
     * Null when no closing has passed within FOLD_HISTORY_DAYS.
     */
    public function plannedRunDayStart(Carbon $now): ?Carbon
    {
        if ($this->closing_time === null) {
            return null;
        }

        foreach ($this->recentRunDayStarts($now) as $runDayStart) {
            if ($this->closingInstantForRunDay($runDayStart)->lte($now)) {
                return $runDayStart;
            }
        }

        return null;
    }

    /**
     * The Calendar pattern name of the run this closing is for (pre_run: the
     * upcoming run; end_of_day: the run whose day just closed). Falls back to
     * the pattern running right now, then '-'.
     */
    public function plannedPatternName(Carbon $now): string
    {
        $runDayStart = $this->plannedRunDayStart($now);

        $board = $runDayStart !== null
            ? CalendarEntry::patternBoardForDate($runDayStart->toDateString())
            : CalendarEntry::runningPatternBoard($now);

        return $board?->name ?? '-';
    }

    /**
     * Has a closing for this row already passed within FOLD_HISTORY_DAYS? False
     * for rows with no closing time.
     */
    public function closingReached(Carbon $now): bool
    {
        return $this->foldBoundaries($now)[0] !== null;
    }

    /**
     * Is this row actively running right now — it has at least one pattern board
     * AND that board is the Calendar's pattern for the current production day.
     * A row with no board is NOT "running" (it belongs to no pattern), even
     * though its pile still folds daily.
     */
    public function isRunningNow(Carbon $now): bool
    {
        return $this->patternBoards->isNotEmpty()
            && $this->isRunDay(CalendarEntry::productionDayStart($now));
    }

    /**
     * Has the closing for the CURRENT production-day cycle already passed for
     * this row? True only when the row is running now and its closing instant
     * for that run is in the past. This is what puts a row into the Andon
     * Closing Time table — the table is empty until a part reaches its closing.
     */
    public function closedForCurrentRun(Carbon $now): bool
    {
        if ($this->closing_time === null || ! $this->isRunningNow($now)) {
            return false;
        }

        return $now->gte($this->closingInstantForRunDay(CalendarEntry::productionDayStart($now)));
    }

    /**
     * [foldStart, cycleStart] for this row at $now:
     *  - foldStart  — the most recent closing at/before $now, or null when none
     *                 has passed. Red ticks after it are the live pile; ticks
     *                 before it have folded.
     *  - cycleStart — the closing before that (or the history floor when there is
     *                 none). The span (cycleStart, foldStart] is what folded into
     *                 the accumulated-kanban number.
     *
     * @return array{0: ?Carbon, 1: Carbon}
     */
    public function foldBoundaries(Carbon $now): array
    {
        $historyFloor = $now->copy()->subDays(self::FOLD_HISTORY_DAYS);

        if ($this->closing_time === null) {
            return [null, $historyFloor];
        }

        $found = [];

        foreach ($this->recentRunDayStarts($now) as $runDayStart) {
            $closingAt = $this->closingInstantForRunDay($runDayStart);

            if ($closingAt->gt($now)) {
                continue;
            }

            $found[] = $closingAt;

            if (count($found) === 2) {
                break;
            }
        }

        return [$found[0] ?? null, $found[1] ?? $historyFloor];
    }
}
