<?php

namespace App\Http\Controllers;

use App\Models\CalendarEntry;
use App\Models\Pattern;
use App\Models\PatternActual;
use App\Models\PatternBoard;
use App\Models\PatternGroupItem;
use App\Models\StockSnapshot;
use App\Services\AndonScheduleBuilder;
use App\Services\KeseiBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AndonController extends Controller
{
    private const DAY_START = 7 * 60;

    // 07:00 the following day — a full 24h window so nothing (least of all a
    // closing time, which can sit 4h before a part starts) gets clipped off
    // the right edge. Shift 1 (07:00-16:00) + gap (16:00-20:00) + shift 2
    // (20:00-06:00) all fit with an hour to spare.
    private const DAY_END = 7 * 60 + 24 * 60;

    private const SHIFT_GAP_START = 16 * 60;

    private const SHIFT_GAP_END = 20 * 60;

    private const SHIFT_GAP_LABEL = 'Pergantian Shift';

    private const PX_PER_MINUTE = 1.8;

    // The Andon board follows the Calendar: it shows whichever pattern board is
    // assigned to the current "production day", and that day rolls over at
    // 06:30 — 30 minutes before shift 1 (07:00) — so the board has already
    // switched to the new pattern by the time the shift starts.
    private const BOARD_SWITCH_MINUTE = 6 * 60 + 30;

    public function __construct(private AndonScheduleBuilder $scheduleBuilder)
    {
    }

    /**
     * The live Andon board. No board in the URL — it auto-resolves to the
     * pattern the Calendar has assigned to the current production day (see
     * andonProductionDate()), and the browser re-checks this endpoint every
     * 60s so the switch is automatic.
     */
    public function index(Request $request): View|JsonResponse
    {
        $productionDate = $this->andonProductionDate();

        return $this->renderBoard(
            $request,
            CalendarEntry::patternBoardForDate($productionDate),
            true,
            $productionDate,
        );
    }

    /**
     * A specific board, picked from the header buttons. This is only a
     * temporary override: the 60s refresh always polls index() (the auto
     * endpoint), so the display returns to the Calendar's board on the next
     * tick.
     */
    public function show(Request $request, PatternBoard $patternBoard): View|JsonResponse
    {
        return $this->renderBoard($request, $patternBoard, false, $this->andonProductionDate());
    }

    /**
     * The calendar date whose Calendar-assigned pattern the board should show
     * right now. Rolls over at BOARD_SWITCH_MINUTE (06:30) rather than
     * midnight, so between 06:30 and 07:00 the board is already on the new
     * day's pattern, ready for the shift.
     */
    private function andonProductionDate(): string
    {
        $now = now();
        $switch = $now->copy()->startOfDay()->addMinutes(self::BOARD_SWITCH_MINUTE);

        return ($now->lt($switch) ? $now->copy()->subDay() : $now)->toDateString();
    }

    private function renderBoard(Request $request, ?PatternBoard $patternBoard, bool $auto, string $productionDate): View|JsonResponse
    {
        $patternBoards = PatternBoard::orderBy('name')->get();
        $productionLabel = Carbon::parse($productionDate)->locale('id')->translatedFormat('l, d F Y');

        // No pattern assigned to this production day in the Calendar — show a
        // clear "set it in Calendar" message instead of a blank board. The
        // 60s poll returns {reload: true} so the board appears on its own
        // once a Calendar entry is added.
        if (! $patternBoard) {
            if ($request->ajax()) {
                return response()->json(['reload' => true, 'boardId' => null]);
            }

            return view('andon.show', [
                'patternBoard' => null,
                'patternBoards' => $patternBoards,
                'rows' => [],
                'auto' => $auto,
                'productionLabel' => $productionLabel,
                'noBoardMessage' => 'Belum ada pattern untuk '.$productionLabel.'. Set dulu lewat menu Calendar.',
            ]);
        }

        [$rows, $timelineEnd, $groupItems, $koseiParts, $partColors, $restIntervals] = $this->scheduleBuilder->build($patternBoard);

        [$windowStart, $windowEnd] = $this->currentStockWindow();

        // One stock-decrease scan for the whole page. The Kesei ticks, the
        // Planning kanban and the closing-time totals all derive from it —
        // computing it once here instead of three times (against a 2M-row
        // table) is the difference between the board loading instantly and it
        // spinning. Widest range any card needs starts 4h before the window
        // (the closing-time lookback).
        $decreaseEvents = $this->buildDecreaseEventsInRange(
            $koseiParts, $windowStart->copy()->subHours(4), $windowEnd
        );

        // Tick marks drawn directly on each part's Kosei row wherever its stock
        // dropped between two 5-minute captures, converted to kanban.
        $stockDecreaseEvents = $this->buildStockDecreaseEvents($koseiParts, $windowStart, $decreaseEvents);

        // Timeline Stok: every part's stock side by side, sharing one time
        // column, instead of only showing whichever part was last clicked.
        $stockHistoryRows = $this->buildStockHistoryRows($koseiParts);

        // Andon Planning card: the exact same schedule, but its kanban counts
        // come from Kesei demand rather than total_kanban — see
        // applyPlanningKanban(). Built from a copy of $rows so the Pattern
        // card's own total_kanban-based blocks are untouched.
        $planningRows = $this->applyPlanningKanban($rows, $koseiParts, $windowStart, $windowEnd, $decreaseEvents);

        // Marks each part's closing time(s) on its Kesei row, so it's visible
        // exactly which stock-decrease tick feeds the Planning card's kanban.
        $closingTimeMarkers = $this->buildClosingTimeMarkers($rows);

        // Where "now" sits on the chart's own minute scale, so the browser can
        // auto-scroll each panel to the current time instead of starting at 07:00.
        $nowMinute = self::DAY_START + (int) $windowStart->diffInMinutes(now());

        // Accumulated kanban (the red Kesei ticks) up to each part's closing
        // time — shown in the Kesei "CT" column and as a number on the green
        // closing-time line, with the folded-in red ticks then hidden.
        $closingKanban = $this->buildClosingTimeKanban($closingTimeMarkers, $windowStart, $decreaseEvents);

        // The KESEI card renders the same standalone Kesei board component
        // (dark variant — this whole page is dark now), pre-rendered to HTML
        // so its own view data can't collide with the pattern board's
        // ($timelineEnd, $stockDecreaseEvents, …).
        $keseiBoardHtml = view('andon-kesei._board-dark', app(KeseiBoard::class)->data())->render();

        $viewData = [
            'patternBoard' => $patternBoard,
            'patternBoards' => $patternBoards,
            'auto' => $auto,
            'productionLabel' => $productionLabel,
            'rows' => $rows,
            'planningRows' => $planningRows,
            'koseiParts' => $koseiParts,
            'restIntervals' => $restIntervals,
            'dayStart' => self::DAY_START,
            'timelineEnd' => $timelineEnd,
            'pxPerMinute' => self::PX_PER_MINUTE,
            'partColors' => $partColors,
            'stockDecreaseEvents' => $stockDecreaseEvents,
            'stockHistoryRows' => $stockHistoryRows,
            'closingTimeMarkers' => $closingTimeMarkers,
            'closingKanban' => $closingKanban,
            'nowMinute' => $nowMinute,
            'keseiBoardHtml' => $keseiBoardHtml,
        ];

        if ($request->ajax()) {
            // Periodic refresh fetches this instead of reloading the page, so
            // the panels update in place with no visible tab reload. boardId
            // lets the browser notice a Calendar rollover (or an override that
            // should snap back) and do a full reload only then.
            return response()->json([
                'timeline' => view('andon._timeline', $viewData)->render(),
                'kosei' => $keseiBoardHtml,
                'planning' => view('andon._timeline', ['rows' => $planningRows, 'isPlanning' => true, 'editable' => false] + $viewData)->render(),
                'nowMinute' => $nowMinute,
                'serverTime' => now()->format('H:i:s'),
                'boardId' => $patternBoard->id,
                'boardName' => $patternBoard->name,
            ]);
        }

        return view('andon.show', $viewData);
    }

    /**
     * Standalone full-page Andon Planning, for a kiosk that only ever needs
     * to show this one board. The same Planning card also lives inline on
     * the main Andon page (show()) as a collapsible 3rd card.
     */
    public function planning(Request $request, PatternBoard $patternBoard): View|JsonResponse
    {
        [$rows, $timelineEnd, , $koseiParts, $partColors, $restIntervals] = $this->scheduleBuilder->build($patternBoard);

        $rows = $this->applyPlanningKanban($rows, $koseiParts);

        [$windowStart] = $this->currentStockWindow();
        $nowMinute = self::DAY_START + (int) $windowStart->diffInMinutes(now());

        $viewData = [
            'patternBoard' => $patternBoard,
            'patternBoards' => PatternBoard::orderBy('name')->get(),
            'rows' => $rows,
            'restIntervals' => $restIntervals,
            'dayStart' => self::DAY_START,
            'timelineEnd' => $timelineEnd,
            'pxPerMinute' => self::PX_PER_MINUTE,
            'partColors' => $partColors,
            'nowMinute' => $nowMinute,
            'isPlanning' => true,
            'editable' => true,
        ];

        if ($request->ajax()) {
            return response()->json([
                'timeline' => view('andon._timeline', $viewData)->render(),
                'nowMinute' => $nowMinute,
                'serverTime' => now()->format('H:i:s'),
            ]);
        }

        return view('andon.planning', $viewData);
    }

    /**
     * Board picker for the Planning table (a normal in-app page, not the
     * Andon-style kiosk timeline) — same list as andon.index, but linking
     * into planningTable() below.
     */
    public function planningBoards(): View
    {
        $patternBoards = PatternBoard::withCount('patterns')->orderBy('name')->get();

        return view('planning.index', compact('patternBoards'));
    }

    /**
     * Planning as a plain searchable/paginated table — same schedule and
     * kanban data as the Andon Planning timeline, just laid out like every
     * other admin table (Stock Part All, Pattern) instead of a Gantt chart.
     * Supports browsing/editing a date other than today via ?date=.
     */
    public function planningTable(Request $request, PatternBoard $patternBoard): View
    {
        $date = $this->resolvePlanningDate($request->query('date'));

        [$rows, , , $koseiParts] = $this->scheduleBuilder->build($patternBoard);

        [$windowStart, $windowEnd] = $this->stockWindowForDate($date);
        $rows = $this->applyPlanningKanban($rows, $koseiParts, $windowStart, $windowEnd);

        $items = collect();
        foreach ($rows as $row) {
            foreach ($row['blocks'] as $block) {
                if ($block['type'] !== 'loading' || ! ($block['showLabel'] ?? true) || ! isset($block['pattern_id'])) {
                    continue;
                }

                $items->push((object) [
                    'machine' => $row['machine']->name,
                    'label' => $block['label'],
                    'shift' => $block['shift'] ?? null,
                    'pattern_id' => $block['pattern_id'],
                    'kanban_auto' => $block['kanban_auto'],
                    'kanban_override' => $block['kanban_override'],
                    'kanban' => $block['kanban'],
                    'actual_kanban' => $block['actual_kanban'],
                ]);
            }
        }

        // All shift 1 assignments first, then all shift 2 — across every
        // machine, not grouped machine-by-machine. Stable sort, so machines
        // still list in their existing (schedule) order within each shift.
        $items = $items->sortBy('shift')->values();

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $needle = Str::lower($search);
            $items = $items->filter(fn ($item) => str_contains(Str::lower($item->machine.' '.$item->label), $needle))->values();
        }

        $perPageOptions = [10, 25, 50, 100];
        $perPage = (int) $request->query('per_page', 25);
        if (! in_array($perPage, $perPageOptions, true)) {
            $perPage = 25;
        }
        $page = LengthAwarePaginator::resolveCurrentPage();

        $planningRows = new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $viewData = [
            'patternBoard' => $patternBoard,
            'planningRows' => $planningRows,
            'date' => $date,
            'search' => $search,
            'perPage' => $perPage,
        ];

        if ($request->ajax()) {
            return view('planning._results', $viewData);
        }

        return view('planning.show', $viewData + ['patternBoards' => PatternBoard::orderBy('name')->get()]);
    }

    /**
     * A ?date= query value if it parses, otherwise today's production date
     * (same rule as currentStockWindow: before 06:00 still counts as
     * yesterday).
     */
    private function resolvePlanningDate(?string $date): string
    {
        if ($date) {
            try {
                return Carbon::parse($date)->toDateString();
            } catch (\Exception) {
                // Fall through to today's production date below.
            }
        }

        return $this->currentStockWindow()[0]->toDateString();
    }

    /**
     * Replaces every loading block's kanban count with the actual demand
     * pulled from Kesei — the kanban that had already come out of stock
     * (buildDecreaseEventsInRange) as of that block's "closing time" (4
     * hours before the part starts, i.e. its dandori start, or its loading
     * start when there's no dandori). A part with no stock decrease recorded
     * at/before its closing time plans for 0 kanban. Operates on (and
     * returns) a copy of $rows — the caller's original is left untouched.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function applyPlanningKanban(array $rows, $koseiParts, ?Carbon $windowStart = null, ?Carbon $windowEnd = null, $decreaseEventsByPart = null): array
    {
        if ($windowStart === null || $windowEnd === null) {
            [$windowStart, $windowEnd] = $this->currentStockWindow();
        }

        // A part scheduled right at the top of the day (07:00) has a closing
        // time of 03:00 that same calendar day — before this window even
        // starts — so the lookup range is padded 4h earlier to still catch it.
        // The caller (show()) passes a shared scan so this isn't re-queried.
        $decreaseEventsByPart ??= $this->buildDecreaseEventsInRange(
            $koseiParts, $windowStart->copy()->subHours(4), $windowEnd
        );

        // Manually-entered "actual kanban produced" and manual kanban
        // overrides, one row per pattern for today (the board's own day,
        // keyed by its start date). whereDate() (not where()) because the
        // date cast stores produced_on with a "00:00:00" time part, which a
        // plain string match won't hit.
        $actualsByPatternId = PatternActual::whereDate('produced_on', $windowStart->toDateString())
            ->get()
            ->keyBy('pattern_id');

        foreach ($rows as &$row) {
            foreach ($row['blocks'] as &$block) {
                if ($block['type'] !== 'loading' || ! isset($block['production_start'])) {
                    continue;
                }

                $productionTime = $windowStart->copy()->addMinutes($block['production_start'] - self::DAY_START);
                $closingTime = $productionTime->copy()->subHours(4);

                $autoKanban = $this->kanbanAsOf($decreaseEventsByPart->get($block['part_id'], collect()), $closingTime);
                $actual = $actualsByPatternId->get($block['pattern_id']);

                // A manually-entered kanban always wins over the Kesei-computed
                // demand — it's how a planner corrects the plan when Kesei data
                // is late, wrong, or simply not there yet.
                $override = $actual?->kanban_override;
                $block['kanban'] = $override ?? $autoKanban;
                $block['kanban_is_override'] = $override !== null;
                $block['kanban_override'] = $override;
                $block['kanban_auto'] = $autoKanban;
                $block['actual_kanban'] = $actual?->actual_kanban;
            }
            unset($block);
        }
        unset($row);

        return $rows;
    }

    /**
     * Where each part's "closing time" (4h before its production start, same
     * rule as applyPlanningKanban) falls on the chart's own minute scale, so
     * Kesei can mark it — making it visible exactly which stock-decrease
     * tick feeds the Planning card's kanban for that part. A part scheduled
     * more than once (different shifts/machines) only gets one marker, from
     * its earliest production start. A closing time that lands before the
     * timeline's left edge (07:00 — a shift-1 part starting at 07:00 closes at
     * 03:00) is not dropped: it's pinned to the edge with 'clamped' set, and
     * the tooltip still shows the true time.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<int, array{minute: int, real: int, clamped: bool}>>
     */
    private function buildClosingTimeMarkers(array $rows): array
    {
        $earliest = [];

        foreach ($rows as $row) {
            foreach ($row['blocks'] as $block) {
                if ($block['type'] !== 'loading' || ! isset($block['production_start']) || ! ($block['showLabel'] ?? true)) {
                    continue;
                }

                $closingMinute = $block['production_start'] - 4 * 60;
                $partId = $block['part_id'];

                if (! isset($earliest[$partId]) || $closingMinute < $earliest[$partId]) {
                    $earliest[$partId] = $closingMinute;
                }
            }
        }

        $markers = [];

        foreach ($earliest as $partId => $real) {
            $pinned = max($real, self::DAY_START);

            $markers[$partId] = [[
                'minute' => $pinned,
                'real' => $real,
                'clamped' => $pinned !== $real,
            ]];
        }

        return $markers;
    }

    /**
     * The accumulated kanban for each Kosei part at its closing time: every
     * stock-decrease (the red Kesei ticks) at or before that part's earliest
     * closing time, summed. Feeds the Kesei "CT" column and the number drawn
     * on the green closing-time line — after which those folded-in red ticks
     * are hidden and accumulation starts over.
     *
     * @param  array<int, array<int, array{minute: int, real: int, clamped: bool}>>  $markers
     * @param  Collection  $events  the page's shared decrease-event scan, keyed by part id
     * @return array<int, int>
     */
    private function buildClosingTimeKanban(array $markers, Carbon $windowStart, $events): array
    {
        if ($markers === []) {
            return [];
        }

        $out = [];

        foreach ($markers as $partId => $list) {
            $closingTime = $windowStart->copy()->addMinutes($list[0]['real'] - self::DAY_START);

            $out[$partId] = (int) $events->get($partId, collect())
                ->filter(fn ($event) => $event['at']->lte($closingTime))
                ->sum('kanban');
        }

        return $out;
    }

    /**
     * Saves the actual kanban really produced (and/or a manual kanban
     * override) for one machine assignment — entered inline on the Andon
     * Planning board (always "today"), or from the Planning table, which can
     * pass an explicit produced_on to edit a different date.
     */
    public function updateActual(Request $request, Pattern $pattern): JsonResponse
    {
        $validated = $request->validate([
            'actual_kanban' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'kanban_override' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'produced_on' => ['sometimes', 'date'],
        ]);

        // Each input auto-saves independently on its own change event, so
        // only the field actually sent should be touched — otherwise saving
        // one would silently null out the other.
        $updates = array_intersect_key($validated, array_flip(['actual_kanban', 'kanban_override']));

        if (isset($validated['produced_on'])) {
            $producedOn = Carbon::parse($validated['produced_on'])->toDateString();
        } else {
            [$windowStart] = $this->currentStockWindow();
            $producedOn = $windowStart->toDateString();
        }

        $actual = PatternActual::updateOrCreate(
            ['pattern_id' => $pattern->id, 'produced_on' => $producedOn],
            $updates
        );

        return response()->json([
            'ok' => true,
            'actual_kanban' => $actual->actual_kanban,
            'kanban_override' => $actual->kanban_override,
        ]);
    }

    /**
     * The last kanban decrease event at or before $closingTime, or 0 when
     * there's no decrease recorded by then (either none happened yet, or the
     * part has no usable Qty Kbn).
     *
     * @param  Collection<int, array{at: Carbon, kanban: int, pcs: int}>  $events
     */
    private function kanbanAsOf($events, Carbon $closingTime): int
    {
        $applicable = $events->filter(fn ($event) => $event['at']->lte($closingTime));

        return $applicable->isEmpty() ? 0 : $applicable->last()['kanban'];
    }

    /**
     * Timeline Stok, all parts at once: one row per captured_at timestamp
     * (all parts share the same captures since CaptureStockSnapshot writes
     * them in the same run), each row carrying every part's stock reading at
     * that moment keyed by part id — a part missing a reading for a given
     * timestamp (e.g. it dropped out of the source feed that cycle) is simply
     * absent from that row's values. Scoped to the same 07:00–06:00(+1)
     * window as the stock-decrease ticks.
     *
     * @return array<int, array{time: string, values: array<int, array{stock: int, under_min: bool}>}>
     */
    private function buildStockHistoryRows($koseiParts): array
    {
        if ($koseiParts->isEmpty()) {
            return [];
        }

        // A full 24h here (07:00 to 07:00 the next day), not the 23h
        // schedule window everything else uses — Timeline Stok is a plain
        // stock log, not tied to the shift schedule, so the 06:00-07:00 hour
        // (which sits outside every shift, hence outside the 23h window)
        // still belongs on it.
        [$windowStart] = $this->currentStockWindow();
        $windowEnd = $windowStart->copy()->addHours(24);
        $partIdByPartNo = $koseiParts->pluck('id', 'part_no');

        $snapshots = StockSnapshot::whereIn('part_no', $partIdByPartNo->keys())
            ->whereBetween('captured_at', [$windowStart, $windowEnd])
            ->orderBy('captured_at')
            ->get();

        $rows = [];

        foreach ($snapshots->groupBy(fn (StockSnapshot $s) => $s->captured_at->toDateTimeString()) as $timestamp => $group) {
            $values = [];

            foreach ($group as $snapshot) {
                $partId = $partIdByPartNo->get($snapshot->part_no);

                if ($partId !== null) {
                    $values[$partId] = [
                        'stock' => $snapshot->stock,
                        'under_min' => $snapshot->stock < $snapshot->std_min,
                    ];
                }
            }

            // groupBy preserves first-seen order, and $snapshots was already
            // fetched ordered by captured_at, so this is already chronological.
            $rows[] = ['time' => Carbon::parse($timestamp)->format('H:i'), 'values' => $values];
        }

        return $rows;
    }

    /**
     * For each Kosei part, every moment its TD-process stock (StockSnapshot,
     * captured from Stock Part All every 5 minutes) dropped between one
     * capture and the next — the same window as stockHistory()'s "Timeline
     * Stok" — converted from pcs to kanban via lot÷qty_kbn rounded up, the
     * same rule as PatternGroupItem::calculateTotalKanban (so any decrease,
     * even smaller than one full Qty Kbn, still shows at least 1 tick). Only
     * decreases produce a tick; a flat or increasing (restock) reading is
     * ignored, and a part with no Qty Kbn set produces no tick either. Thin
     * wrapper over buildDecreaseEventsInRange() that converts each event's
     * real timestamp into the chart's minute coordinate for positioning.
     *
     * @return array<int, array<int, array{minute: int, kanban: int, pcs: int, time: string}>>
     */
    private function buildStockDecreaseEvents($koseiParts, ?Carbon $windowStart = null, $eventsByPart = null): array
    {
        if ($windowStart === null) {
            [$windowStart] = $this->currentStockWindow();
        }

        $eventsByPart ??= $this->buildDecreaseEventsInRange(
            $koseiParts, $windowStart, $windowStart->copy()->addHours(24)
        );

        $events = [];

        foreach ($koseiParts as $part) {
            // The shared scan can reach 4h before the window; only ticks from
            // 07:00 onward have a place on this chart.
            $events[$part->id] = $eventsByPart->get($part->id, collect())
                ->filter(fn ($event) => $event['at']->gte($windowStart))
                ->map(fn ($event) => [
                    'minute' => self::DAY_START + (int) $windowStart->diffInMinutes($event['at']),
                    'kanban' => $event['kanban'],
                    'pcs' => $event['pcs'],
                    'time' => $event['at']->format('H:i'),
                ])
                ->values()
                ->all();
        }

        return $events;
    }

    /**
     * Core kanban-decrease detection, decoupled from chart positioning: for
     * each part, every moment its stock (StockSnapshot) dropped between one
     * capture and the next within [$rangeStart, $rangeEnd], converted from
     * pcs to kanban via lot÷qty_kbn rounded up (PatternGroupItem::calculateTotalKanban).
     * A "seed" snapshot from just before $rangeStart is used so the first
     * in-range reading still has something to compare against. Only
     * decreases produce an event; flat/increasing readings and parts with no
     * usable Qty Kbn produce none.
     *
     * @return Collection<int, Collection<int, array{at: Carbon, kanban: int, pcs: int}>>
     */
    private function buildDecreaseEventsInRange($parts, Carbon $rangeStart, Carbon $rangeEnd)
    {
        if ($parts->isEmpty()) {
            return collect();
        }

        $partNumbers = $parts->pluck('part_no')->all();
        $columns = ['part_no', 'stock', 'captured_at'];

        // Seed = the last reading just before the range, so the first in-range
        // reading still has something to diff against. Bounded to 1 day back
        // (the feed captures every 5 min): without an upper *and* lower bound
        // this pulls the part's entire history (the snapshot table has millions
        // of rows) just to keep one row per part.
        $seedSnapshots = StockSnapshot::whereIn('part_no', $partNumbers)
            ->whereBetween('captured_at', [$rangeStart->copy()->subDay(), $rangeStart->copy()->subSecond()])
            ->orderBy('part_no')
            ->orderByDesc('captured_at')
            ->get($columns)
            ->unique('part_no')
            ->keyBy('part_no');

        $snapshotsByPart = StockSnapshot::whereIn('part_no', $partNumbers)
            ->whereBetween('captured_at', [$rangeStart, $rangeEnd])
            ->orderBy('captured_at')
            ->get($columns)
            ->groupBy('part_no');

        $events = collect();

        foreach ($parts as $part) {
            $previous = $seedSnapshots->get($part->part_no);
            $partEvents = collect();

            foreach ($snapshotsByPart->get($part->part_no, collect()) as $snapshot) {
                $decreasePcs = $previous ? $previous->stock - $snapshot->stock : 0;

                if ($decreasePcs > 0) {
                    $kanban = PatternGroupItem::calculateTotalKanban($decreasePcs, $part->qty_kbn);

                    if ($kanban > 0) {
                        $partEvents->push([
                            'at' => $snapshot->captured_at,
                            'kanban' => $kanban,
                            'pcs' => $decreasePcs,
                        ]);
                    }
                }

                $previous = $snapshot;
            }

            $events[$part->id] = $partEvents;
        }

        return $events;
    }

    /**
     * The current production day's 07:00 → 07:00(+1) window — same day-rollover
     * rule as the Andon board itself (andonProductionDate(): before 06:30 still
     * belongs to yesterday's window).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function currentStockWindow(): array
    {
        return $this->stockWindowForDate($this->andonProductionDate());
    }

    /**
     * Same 07:00-anchored 24h production window as currentStockWindow(), but
     * for an explicitly chosen calendar date instead of "now" — used by the
     * Planning table, where a planner can browse/edit a different day.
     */
    private function stockWindowForDate(string $date): array
    {
        $windowStart = Carbon::parse($date)->setTime(7, 0);
        $windowEnd = $windowStart->copy()->addHours(24);

        return [$windowStart, $windowEnd];
    }
}
