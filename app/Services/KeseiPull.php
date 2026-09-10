<?php

namespace App\Services;

use App\Models\KeseiScan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The "pulling command" a scanner operator works from. Two modes:
 *
 *  - demand (Finish Goods): the target per part is how many kanban of demand
 *    has built up from the Stock Part All feed; over-scanning is rejected.
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
     *     part_no: string, needed: ?int, scanned: int, remaining: ?int, done: bool
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
            ->map(function (array $row) use ($data, $free) {
                $scanned = $this->scanCount($row, $row['fold_start']);

                if ($free) {
                    return [
                        'part_no' => $row['label'],
                        'needed' => null,       // no target
                        'scanned' => $scanned,
                        'remaining' => null,    // unlimited
                        'done' => false,
                    ];
                }

                $needed = (int) collect($data['stockDecreaseEvents'][$row['id']] ?? [])->sum('kanban');

                return [
                    'part_no' => $row['label'],
                    'needed' => $needed,
                    'scanned' => $scanned,
                    'remaining' => max(0, $needed - $scanned),
                    'done' => $needed > 0 && $scanned >= $needed,
                ];
            })
            // demand mode only lists parts that actually have demand.
            ->when(! $free, fn (Collection $c) => $c->filter(fn (array $r) => $r['needed'] > 0))
            ->sortBy('done')
            ->values();
    }

    /**
     * The single row for one part, or null when the part is not on the list
     * for this location.
     *
     * @return array{part_no: string, needed: ?int, scanned: int, remaining: ?int, done: bool}|null
     */
    public function rowFor(string $locationSlug, string $partNo): ?array
    {
        return $this->list($locationSlug)->firstWhere('part_no', $partNo);
    }

    /**
     * @param  array{label: string, sources: array<int, string>}  $row
     */
    private function scanCount(array $row, Carbon $foldStart): int
    {
        $partNos = array_values(array_unique(array_merge([$row['label']], $row['sources'])));

        return KeseiScan::whereIn('part_no', $partNos)
            ->where('scanned_at', '>', $foldStart)
            ->count();
    }
}
