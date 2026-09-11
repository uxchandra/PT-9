<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

#[Fillable(['part_id', 'stock_source', 'level', 'urutan'])]
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
     * A Kesei row can carry more than one closing time (e.g. 05:00 and
     * 15:00) — each one folds the pile and notifies independently.
     */
    public function closings(): HasMany
    {
        return $this->hasMany(KeseiPartClosing::class)->orderBy('closing_time');
    }

    /**
     * Convenience for adding a single closing time (the common case — one
     * part, one cutoff). For several at once, see syncClosings().
     */
    public function addClosing(string $time, string $mode = self::CLOSING_PRE_RUN): KeseiPartClosing
    {
        return $this->closings()->create(['closing_time' => $time, 'closing_mode' => $mode]);
    }

    /**
     * Replace this row's full set of closing times with the given ones.
     *
     * @param  array<int, array{time: string, mode?: string}>  $closings
     */
    public function syncClosings(array $closings): void
    {
        $this->closings()->delete();

        foreach ($closings as $closing) {
            $this->addClosing($closing['time'], $closing['mode'] ?? self::CLOSING_PRE_RUN);
        }
    }

    /**
     * A comma-separated "05:00, 15:00" for compact display — empty dash when
     * the part has no closing time at all.
     */
    public function closingTimesLabel(): string
    {
        $label = $this->closings->pluck('closing_time')->map(fn (Carbon $t) => $t->format('H:i'))->implode(', ');

        return $label !== '' ? $label : '—';
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
     * The wall-clock instant one closing definition falls on for the 07:00
     * production run that starts at $runDayStart.
     *
     *  - pre_run    : the closing sits in the 24h BEFORE the run. A time < 07:00
     *                 lands the same morning; a time >= 07:00 the evening before.
     *  - end_of_day : the closing sits inside the run's own 07:00 → 07:00 day.
     *                 A time >= 07:00 lands that day; a time < 07:00 the next
     *                 calendar morning.
     */
    public function closingInstantForRunDay(Carbon $runDayStart, KeseiPartClosing $closing): Carbon
    {
        $at = $runDayStart->copy()->setTime((int) $closing->closing_time->hour, (int) $closing->closing_time->minute);

        if ($closing->isPreRunClosing()) {
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
     * Every (closing instant, its run-day start) pair that has already passed
     * as of $now, across EVERY closing definition and every recent run-day —
     * newest instant first. This is the one place the "which closing is the
     * most recent" question gets answered, so foldBoundaries(),
     * plannedRunDayStart() and closedForCurrentRun() all agree with each other
     * regardless of how many closing times the row carries.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    private function pastClosingInstants(Carbon $now): array
    {
        $closings = $this->closings;

        if ($closings->isEmpty()) {
            return [];
        }

        $pairs = [];

        foreach ($this->recentRunDayStarts($now) as $runDayStart) {
            foreach ($closings as $closing) {
                $at = $this->closingInstantForRunDay($runDayStart, $closing);

                if ($at->lte($now)) {
                    $pairs[] = [$at, $runDayStart];
                }
            }
        }

        usort($pairs, fn (array $a, array $b) => $b[0] <=> $a[0]);

        return $pairs;
    }

    /**
     * The run-day (07:00 start) whose closing is the most recent one at/before
     * $now — i.e. the run this row is currently planning for / has just closed.
     * Null when no closing has passed within FOLD_HISTORY_DAYS.
     */
    public function plannedRunDayStart(Carbon $now): ?Carbon
    {
        return $this->pastClosingInstants($now)[0][1] ?? null;
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
     * this row? True only when the row is running now and AT LEAST ONE of its
     * closing definitions has, for TODAY's run specifically, passed. This is
     * what puts a row into the Andon Closing Time table — the table is empty
     * until a part reaches a closing, and (with several closing times) shows
     * whichever is the freshest via foldBoundaries()'s own numbers.
     */
    public function closedForCurrentRun(Carbon $now): bool
    {
        if (! $this->isRunningNow($now)) {
            return false;
        }

        $productionDayStart = CalendarEntry::productionDayStart($now);

        return $this->closings->contains(
            fn (KeseiPartClosing $closing) => $now->gte($this->closingInstantForRunDay($productionDayStart, $closing))
        );
    }

    /**
     * [foldStart, cycleStart] for this row at $now:
     *  - foldStart  — the most recent closing (across every closing time this
     *                 row carries) at/before $now, or null when none has
     *                 passed. Red ticks after it are the live pile; ticks
     *                 before it have folded.
     *  - cycleStart — the closing before that (or the history floor when there
     *                 is none). The span (cycleStart, foldStart] is what folded
     *                 into the accumulated-kanban number.
     *
     * @return array{0: ?Carbon, 1: Carbon}
     */
    public function foldBoundaries(Carbon $now): array
    {
        $historyFloor = $now->copy()->subDays(self::FOLD_HISTORY_DAYS);
        $pairs = $this->pastClosingInstants($now);

        return [$pairs[0][0] ?? null, $pairs[1][0] ?? $historyFloor];
    }
}
