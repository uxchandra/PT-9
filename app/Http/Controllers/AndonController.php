<?php

namespace App\Http\Controllers;

use App\Models\CalendarEntry;
use App\Models\Pattern;
use App\Models\PatternActual;
use App\Models\PatternBoard;
use App\Models\PatternGroupItem;
use App\Models\Rest;
use App\Models\StockSnapshot;
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

        [$rows, $timelineEnd, $groupItems, $koseiParts, $partColors, $restIntervals] = $this->buildScheduleRows($patternBoard);

        // Tick marks drawn directly on each part's Kosei row wherever its stock
        // dropped between two 5-minute captures, converted to kanban.
        $stockDecreaseEvents = $this->buildStockDecreaseEvents($koseiParts);

        // Timeline Stok: every part's stock side by side, sharing one time
        // column, instead of only showing whichever part was last clicked.
        $stockHistoryRows = $this->buildStockHistoryRows($koseiParts);

        // Andon Planning card: the exact same schedule, but its kanban counts
        // come from Kesei demand rather than total_kanban — see
        // applyPlanningKanban(). Built from a copy of $rows so the Pattern
        // card's own total_kanban-based blocks are untouched.
        $planningRows = $this->applyPlanningKanban($rows, $koseiParts);

        // Marks each part's closing time(s) on its Kesei row, so it's visible
        // exactly which stock-decrease tick feeds the Planning card's kanban.
        $closingTimeMarkers = $this->buildClosingTimeMarkers($rows);

        // Where "now" sits on the chart's own minute scale, so the browser can
        // auto-scroll each panel to the current time instead of starting at 07:00.
        [$windowStart] = $this->currentStockWindow();
        $nowMinute = self::DAY_START + (int) $windowStart->diffInMinutes(now());

        // Accumulated kanban (the red Kesei ticks) up to each part's closing
        // time — shown in the Kesei "CT" column and as a number on the green
        // closing-time line, with the folded-in red ticks then hidden.
        $closingKanban = $this->buildClosingTimeKanban($closingTimeMarkers, $koseiParts, $windowStart);

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
        ];

        if ($request->ajax()) {
            // Periodic refresh fetches this instead of reloading the page, so
            // the panels update in place with no visible tab reload. boardId
            // lets the browser notice a Calendar rollover (or an override that
            // should snap back) and do a full reload only then.
            return response()->json([
                'timeline' => view('andon._timeline', $viewData)->render(),
                'kosei' => view('andon._kosei-timeline', $viewData)->render(),
                'stockTimeline' => view('andon._stock-timeline', $viewData)->render(),
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
        [$rows, $timelineEnd, , $koseiParts, $partColors, $restIntervals] = $this->buildScheduleRows($patternBoard);

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

        [$rows, , , $koseiParts] = $this->buildScheduleRows($patternBoard);

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
    private function applyPlanningKanban(array $rows, $koseiParts, ?Carbon $windowStart = null, ?Carbon $windowEnd = null): array
    {
        if ($windowStart === null || $windowEnd === null) {
            [$windowStart, $windowEnd] = $this->currentStockWindow();
        }

        // A part scheduled right at the top of the day (07:00) has a closing
        // time of 03:00 that same calendar day — before this window even
        // starts — so the lookup range is padded 4h earlier to still catch it.
        $decreaseEventsByPart = $this->buildDecreaseEventsInRange(
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
     * @return array<int, int>
     */
    private function buildClosingTimeKanban(array $markers, $koseiParts, Carbon $windowStart): array
    {
        if ($koseiParts->isEmpty() || $markers === []) {
            return [];
        }

        $events = $this->buildDecreaseEventsInRange(
            $koseiParts, $windowStart->copy()->subHours(4), $windowStart->copy()->addHours(24)
        );

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
     * The machine/part schedule shared by both the Pattern board and Andon
     * Planning: which parts run on which machines and when, for both shifts.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int, 2: Collection, 3: Collection, 4: array<int, string>, 5: Collection}
     */
    private function buildScheduleRows(PatternBoard $patternBoard): array
    {
        $groupItems = PatternGroupItem::where('pattern_board_id', $patternBoard->id)->get();

        // A part can have its own group item per shift, so lookups during block
        // building are keyed by part_id+shift rather than part_id alone.
        $groupItemsByPartShift = $groupItems->keyBy(fn ($item) => $item->part_id.'-'.$item->shift);

        // Ordered by id (creation order) rather than the part's Kelompok Pattern
        // "urutan", so each machine's sequence of blocks follows the order rows
        // were entered/imported for that machine, not a shared part-level order.
        $filteredPatterns = Pattern::where('pattern_board_id', $patternBoard->id)
            ->with(['machine', 'part'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Pattern $pattern) => $groupItemsByPartShift->has($pattern->part_id.'-'.$pattern->shift));

        $patterns = $filteredPatterns->groupBy('machine_id');

        // Part list for the Kosei stock view — one row per part assigned to this
        // board, independent of which machine(s) or shift run it.
        $koseiParts = $filteredPatterns->pluck('part')->unique('id')->sortBy('part_no')->values();

        // Rest bands are shown for reference at their real clock time, but only the
        // shift-change gap actually pauses production — regular rests no longer
        // split/pause a running process, so loading-time bars stay unbroken.
        $restIntervals = $this->buildRestIntervals();
        $pauseIntervals = $restIntervals->filter(fn ($r) => $r['name'] === self::SHIFT_GAP_LABEL)->values();

        $timelineEnd = self::DAY_END;
        $rows = [];

        foreach ($patterns as $machineId => $machinePatterns) {
            $machine = $machinePatterns->first()->machine;

            // Shift 1 and shift 2 are scheduled independently, each from its own
            // start of window, so shift 2 gets filled instead of always sitting
            // empty behind the shift-change gap.
            [$shift1Blocks, $cursor1] = $this->buildShiftBlocks(
                $machinePatterns->where('shift', 1), $groupItemsByPartShift, self::DAY_START, $pauseIntervals
            );
            [$shift2Blocks, $cursor2] = $this->buildShiftBlocks(
                $machinePatterns->where('shift', 2), $groupItemsByPartShift, self::SHIFT_GAP_END, $pauseIntervals
            );

            $blocks = array_merge($shift1Blocks, $shift2Blocks);

            foreach ($this->splitAroundRests($cursor1, self::SHIFT_GAP_START, $pauseIntervals) as [$start, $end]) {
                $blocks[] = ['type' => 'free', 'start' => $start, 'end' => $end, 'label' => 'FREE TIME'];
            }

            $filledThrough = max($cursor2, self::SHIFT_GAP_END);
            foreach ($this->splitAroundRests($cursor2, self::DAY_END, $pauseIntervals) as [$start, $end]) {
                $blocks[] = ['type' => 'free', 'start' => $start, 'end' => $end, 'label' => 'FREE TIME'];
            }

            foreach ($blocks as $block) {
                $timelineEnd = max($timelineEnd, $block['end']);
            }

            $rows[] = ['machine' => $machine, 'blocks' => $blocks, 'filledThrough' => $filledThrough];
        }

        // Machine grouping above follows part order, not machine name, so rows would
        // otherwise land in an arbitrary order. Sort naturally (PT91, PT92, ..., PT100)
        // so the andon board reads top-to-bottom in a predictable sequence.
        usort($rows, fn ($a, $b) => strnatcasecmp($a['machine']->name, $b['machine']->name));

        // Stretch each machine's trailing free time to match the widest row so every row ends flush.
        foreach ($rows as &$row) {
            foreach ($this->splitAroundRests($row['filledThrough'], $timelineEnd, $pauseIntervals) as [$start, $end]) {
                $row['blocks'][] = ['type' => 'free', 'start' => $start, 'end' => $end, 'label' => ''];
            }
        }
        unset($row);

        $partColors = $this->assignPartColors($groupItems);

        return [$rows, $timelineEnd, $groupItems, $koseiParts, $partColors, $restIntervals];
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
    private function buildStockDecreaseEvents($koseiParts): array
    {
        [$windowStart, $windowEnd] = $this->currentStockWindow();

        $eventsByPart = $this->buildDecreaseEventsInRange($koseiParts, $windowStart, $windowEnd);

        $events = [];

        foreach ($koseiParts as $part) {
            $events[$part->id] = $eventsByPart->get($part->id, collect())->map(fn ($event) => [
                'minute' => self::DAY_START + (int) $windowStart->diffInMinutes($event['at']),
                'kanban' => $event['kanban'],
                'pcs' => $event['pcs'],
                'time' => $event['at']->format('H:i'),
            ])->all();
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

        // Fetched in bulk (2 queries total) rather than per part, since these
        // pages reload themselves every 60 seconds.
        $seedSnapshots = StockSnapshot::whereIn('part_no', $partNumbers)
            ->where('captured_at', '<', $rangeStart)
            ->orderBy('part_no')
            ->orderByDesc('captured_at')
            ->get()
            ->unique('part_no')
            ->keyBy('part_no');

        $snapshotsByPart = StockSnapshot::whereIn('part_no', $partNumbers)
            ->whereBetween('captured_at', [$rangeStart, $rangeEnd])
            ->orderBy('captured_at')
            ->get()
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

    /**
     * Assign each part a stable, visually distinct color (ordered by Kelompok
     * Pattern urutan) so the same part reads consistently across every machine row.
     * A part with a group item in both shifts only gets one color, taken from
     * whichever shift's urutan sorts first.
     *
     * @return array<int, string>
     */
    private function assignPartColors($groupItems): array
    {
        $palette = [
            '#f59e0b', '#3b82f6', '#10b981', '#ef4444', '#8b5cf6',
            '#ec4899', '#06b6d4', '#84cc16', '#f97316', '#6366f1',
            '#14b8a6', '#eab308', '#d946ef', '#0ea5e9', '#22c55e',
        ];

        $colors = [];
        $i = 0;

        foreach ($groupItems->sortBy('urutan') as $groupItem) {
            if (isset($colors[$groupItem->part_id])) {
                continue;
            }

            $colors[$groupItem->part_id] = $palette[$i % count($palette)];
            $i++;
        }

        return $colors;
    }

    /**
     * Place one shift's dandori+loading_time blocks back to back starting at
     * $windowStart, in $patterns' order. Returns the blocks plus the cursor
     * reached after the last one (or $windowStart unchanged if $patterns is empty).
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function buildShiftBlocks($patterns, $groupItemsByPartShift, int $windowStart, $pauseIntervals): array
    {
        $cursor = $windowStart;
        $blocks = [];

        foreach ($patterns as $pattern) {
            $groupItem = $groupItemsByPartShift->get($pattern->part_id.'-'.$pattern->shift);

            if (! $groupItem) {
                continue;
            }

            $label = $pattern->part->part_no.' '.$pattern->proses.'/'.$groupItem->jumlah_proses;

            // Andon Planning's "closing time" (4h before this part starts)
            // counts from the very start of this instance — its dandori if
            // it has one, otherwise its loading start.
            $productionStart = $cursor;

            if ($groupItem->dandori > 0) {
                [$segments, $cursor] = $this->placeSegment($cursor, $groupItem->dandori, $pauseIntervals);
                foreach ($segments as $i => [$start, $end]) {
                    $blocks[] = [
                        'type' => 'dandori',
                        'start' => $start,
                        'end' => $end,
                        'label' => (string) $groupItem->dandori,
                        'showLabel' => $i === 0,
                    ];
                }
            }

            [$segments, $cursor] = $this->placeSegment($cursor, $groupItem->loading_time, $pauseIntervals);
            foreach ($segments as $i => [$start, $end]) {
                $blocks[] = [
                    'type' => 'loading',
                    'start' => $start,
                    'end' => $end,
                    'label' => $label,
                    'kanban' => $groupItem->total_kanban,
                    'showLabel' => $i === 0,
                    'part_id' => $pattern->part_id,
                    'pattern_id' => $pattern->id,
                    'production_start' => $productionStart,
                    'shift' => $pattern->shift,
                ];
            }
        }

        return [$blocks, $cursor];
    }

    /**
     * Rest windows from the database, plus the fixed shift-change gap, expressed
     * as minutes since 00:00 of the andon's start day. A rest that falls before
     * DAY_START (e.g. an early-morning break) is assumed to belong to the
     * following calendar day, since the timeline runs 07:00 through 06:00+1.
     */
    private function buildRestIntervals()
    {
        $restIntervals = Rest::orderBy('start_time')->get()->map(function (Rest $rest) {
            $start = $rest->start_time->hour * 60 + $rest->start_time->minute;
            $end = $rest->end_time->hour * 60 + $rest->end_time->minute;

            if ($start < self::DAY_START) {
                $start += 1440;
            }
            if ($end <= $start) {
                $end += 1440;
            }

            return ['start' => $start, 'end' => $end, 'name' => $rest->name];
        });

        $restIntervals->push([
            'start' => self::SHIFT_GAP_START,
            'end' => self::SHIFT_GAP_END,
            'name' => self::SHIFT_GAP_LABEL,
        ]);

        return $restIntervals->sortBy('start')->values();
    }

    /**
     * Place `$duration` minutes of working time starting at `$cursor`, jumping over
     * any interval in `$pauseIntervals` encountered so production pauses only for
     * those (the shift-change gap), resuming right after. Returns the (possibly
     * split) segments plus the new cursor.
     *
     * @return array{0: array<int, array{0: int, 1: int}>, 1: int}
     */
    private function placeSegment(int $cursor, int $duration, $pauseIntervals): array
    {
        $segments = [];
        $remaining = $duration;
        $pos = $cursor;
        $guard = 0;

        while ($remaining > 0 && $guard++ < 1000) {
            $activePause = $pauseIntervals->first(fn ($r) => $pos >= $r['start'] && $pos < $r['end']);

            if ($activePause) {
                $pos = $activePause['end'];

                continue;
            }

            $nextPauseStart = $pauseIntervals->first(fn ($r) => $r['start'] > $pos)['start'] ?? null;
            $available = $nextPauseStart !== null ? min($remaining, $nextPauseStart - $pos) : $remaining;

            if ($available <= 0) {
                $pos++;

                continue;
            }

            $segments[] = [$pos, $pos + $available];
            $pos += $available;
            $remaining -= $available;
        }

        return [$segments, $pos];
    }

    /**
     * Split [$start, $end) into the sub-ranges not covered by any pause interval,
     * so a "free time" fill never visually overlaps the shift-gap band.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private function splitAroundRests(int $start, int $end, $pauseIntervals): array
    {
        $segments = [];
        $pos = $start;

        foreach ($pauseIntervals as $rest) {
            if ($rest['end'] <= $pos || $rest['start'] >= $end) {
                continue;
            }

            if ($rest['start'] > $pos) {
                $segments[] = [$pos, $rest['start']];
            }

            $pos = max($pos, $rest['end']);
        }

        if ($pos < $end) {
            $segments[] = [$pos, $end];
        }

        return $segments;
    }
}
