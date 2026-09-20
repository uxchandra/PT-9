<?php

namespace App\Services;

use App\Models\KeseiPart;
use App\Models\KeseiScan;
use App\Models\StockSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The "pulling command" a scanner operator works from. Two modes:
 *
 *  - demand (Finish Goods): the target per part rolls with the 15-minute stock
 *    feed. At each capture the unmet target carries forward and the new stock
 *    decrease is added on top, while the scan counter resets:
 *        needed_new = needed_old - scanned_old + decrease_this_interval
 *    The page's "last update" is the time of the newest stock-feed capture (the
 *    same 15-minute cadence as Timeline Stok). Over-scanning is rejected.
 *
 *    The stock decrease itself isn't added to the target the instant it's
 *    captured, though — every part's own Lead Time per Kanban paces it
 *    through KeseiBoard::heijunkaEvents() first (a part with none set, the
 *    common case, releases every kanban immediately — mathematically a
 *    no-op — so this doesn't change anything for it). Only once the
 *    "now"/progress-bar has actually crossed a given kanban's paced release
 *    moment does it count toward `needed` at all.
 *  - free (Store 3): every part with the right `level` is listed with no
 *    target — the operator may scan it any number of times. Scanning a part
 *    that is not on the list is still rejected.
 */
class KeseiPull
{
    /** scanner card slug => name, the KeseiPart.level values it covers, and mode */
    public const LOCATIONS = [
        'finish-goods' => ['name' => 'Finish Goods', 'levels' => ['FINISH GOODS', 'FINISH GOOD', 'FG'], 'mode' => 'demand'],
        'store-3' => ['name' => 'Store 3', 'levels' => ['3', 'STORE 3', 'STORE3', 'STORE-3'], 'mode' => 'free'],
    ];

    public function __construct(private KeseiBoard $board) {}

    public static function isLocation(string $slug): bool
    {
        return array_key_exists($slug, self::LOCATIONS);
    }

    public static function name(string $slug): string
    {
        return self::LOCATIONS[$slug]['name'] ?? $slug;
    }

    public static function isFree(string $slug): bool
    {
        return (self::LOCATIONS[$slug]['mode'] ?? 'demand') === 'free';
    }

    /**
     * @return Collection<int, array{
     *     part_no: string, needed: ?int, scanned: int, remaining: ?int,
     *     last_update: ?string, done: bool
     * }>
     */
    public function list(string $locationSlug): Collection
    {
        $loc = self::LOCATIONS[$locationSlug] ?? null;

        if ($loc === null) {
            return collect();
        }

        $levels = $loc['levels'];
        $free = ($loc['mode'] ?? 'demand') === 'free';
        $data = $this->board->data();

        return $data['keseiRows']
            ->filter(fn (array $row) => in_array(strtoupper(trim((string) $row['level'])), $levels, true))
            ->map(fn (array $row) => $free
                ? $this->freeRow($row)
                : $this->demandRow($row, $this->board->heijunkaEvents(
                    collect($data['stockDecreaseEvents'][$row['id']] ?? []),
                    (float) ($row['lt_per_kbn'] ?? 0)
                )))
            // demand mode only lists parts that still have something to do.
            ->when(! $free, fn (Collection $c) => $c->filter(fn (array $r) => $r['needed'] > 0 || $r['scanned'] > 0))
            ->sortBy('done')
            ->values();
    }

    /**
     * When the stock feed last captured — the same 15-minute cadence Timeline
     * Stok records at. Shown once on the page as "last update", regardless of
     * whether any part actually moved that interval. Null when the feed is empty.
     */
    public function lastStockUpdate(): ?string
    {
        $at = StockSnapshot::max('captured_at');

        return $at ? Carbon::parse($at)->format('H:i') : null;
    }

    /**
     * The one row a scanned part_no maps to at $locationSlug, computed on its
     * own (no full-board rebuild) so a scan stays fast. Null when the part is
     * not part of that location.
     *
     * @return array{part_no: string, needed: ?int, scanned: int, remaining: ?int, last_update: ?string, done: bool}|null
     */
    public function scanRow(string $locationSlug, string $partNo): ?array
    {
        $loc = self::LOCATIONS[$locationSlug] ?? null;

        if ($loc === null) {
            return null;
        }

        $levels = $loc['levels'];
        $free = ($loc['mode'] ?? 'demand') === 'free';

        $part = KeseiPart::with(['part', 'patternBoards', 'closings'])
            ->whereHas('part', fn ($q) => $q->where('part_no', $partNo))
            ->get()
            ->first(fn (KeseiPart $p) => in_array(strtoupper(trim((string) $p->level)), $levels, true));

        if ($part === null || $part->sourcePartNos() === []) {
            return null;
        }

        if ($free) {
            $foldStart = $part->foldBoundaries(now())[0]
                ?? now()->subDays(KeseiPart::FOLD_HISTORY_DAYS);

            $partNos = array_values(array_filter(array_unique(
                array_merge([$part->part?->part_no], $part->sourcePartNos())
            )));

            return [
                'part_no' => $part->part->part_no,
                'needed' => null,
                'scanned' => KeseiScan::whereIn('part_no', $partNos)
                    ->where('scanned_at', '>', $foldStart)
                    ->count(),
                'remaining' => null,
                'last_update' => null,
                'done' => false,
            ];
        }

        $ctx = $this->board->rowContext($part);

        if ($ctx === null) {
            return null;
        }

        $events = $this->board->heijunkaEvents(collect($ctx['events']), (float) ($part->lt_per_kbn ?? 0));

        return $this->demandRow($ctx['row'], $events);
    }

    /**
     * @param  array{label: string, sources: array<int, string>, fold_start: Carbon}  $row
     * @return array{part_no: string, needed: null, scanned: int, remaining: null, last_update: null, done: false}
     */
    private function freeRow(array $row): array
    {
        return [
            'part_no' => $row['label'],
            'needed' => null,
            'scanned' => $this->scanTimes($row)->count(),
            'remaining' => null,
            'last_update' => null,
            'done' => false,
        ];
    }

    /**
     * @param  array{label: string, sources: array<int, string>, fold_start: Carbon, pulling_command?: ?int, pulling_command_set_at?: ?string}  $row
     * @param  Collection<int, array{kanban: int, at: Carbon}>  $events
     * @return array{part_no: string, needed: int, scanned: int, remaining: int, last_update: ?string, done: bool}
     */
    private function demandRow(array $row, Collection $events): array
    {
        // "Perintah Pulling" — a manually-set target that REPLACES whatever
        // had already accumulated before it, exactly like a fresh manual
        // closing/reset: decrease events at/before the moment it was typed
        // are dropped (already superseded by the number typed in), and only
        // ones after it still count on top — same as a real stock decrease
        // would from then on. Ignored once an actual closing happens after
        // it (fold_start would then be later than it, so it's already been
        // dealt with along with the rest of that pile — see KeseiPart::
        // pulling_command).
        $baseline = 0;
        $baselineSetAt = ! empty($row['pulling_command_set_at']) ? Carbon::parse($row['pulling_command_set_at']) : null;
        $useBaseline = $baselineSetAt !== null && $baselineSetAt->gte($row['fold_start']);

        if ($useBaseline) {
            $events = $events->filter(fn (array $e) => $e['at']->gt($baselineSetAt));
            $baseline = (int) ($row['pulling_command'] ?? 0);
        }

        $totalDecrease = (int) $events->sum('kanban');
        $lastUpdateAt = $events->pluck('at')->max();   // Carbon|null

        if ($useBaseline) {
            $lastUpdateAt = $lastUpdateAt === null ? $baselineSetAt : $lastUpdateAt->max($baselineSetAt);
        }

        $scanTimes = $this->scanTimes($row);

        if ($lastUpdateAt !== null) {
            $absorbed = $scanTimes->filter(fn (Carbon $t) => $t->lte($lastUpdateAt))->count();
            $scanned = $scanTimes->filter(fn (Carbon $t) => $t->gt($lastUpdateAt))->count();
        } else {
            $absorbed = 0;
            $scanned = $scanTimes->count();
        }

        $needed = max(0, $totalDecrease + $baseline - $absorbed);

        return [
            'part_no' => $row['label'],
            'needed' => $needed,
            'scanned' => $scanned,
            'remaining' => max(0, $needed - $scanned),
            'last_update' => $lastUpdateAt?->format('H:i'),
            'done' => $needed > 0 && $scanned >= $needed,
        ];
    }

    /**
     * Scan timestamps for a row's part_no(s) since the current cycle started.
     *
     * @param  array{label: string, sources: array<int, string>, fold_start: Carbon}  $row
     * @return Collection<int, Carbon>
     */
    private function scanTimes(array $row): Collection
    {
        $partNos = array_values(array_unique(array_merge([$row['label']], $row['sources'])));

        return KeseiScan::whereIn('part_no', $partNos)
            ->where('scanned_at', '>', $row['fold_start'])
            ->orderBy('scanned_at')
            ->pluck('scanned_at');
    }
}
