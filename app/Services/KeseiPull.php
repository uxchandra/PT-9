<?php

namespace App\Services;

use App\Models\KeseiScan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The "pulling command" a scanner operator works from: for every Kesei part
 * at a given location (matched by its `level`), how many kanban of demand has
 * built up from the Stock Part All feed, and how many labels have already
 * been scanned this cycle.
 */
class KeseiPull
{
    /** scanner card slug => the KeseiPart.level values it covers (upper-cased) */
    public const LOCATIONS = [
        'finish-goods' => ['FINISH GOODS', 'FINISH GOOD', 'FG'],
        'store-3' => ['3', 'STORE 3', 'STORE3', 'STORE-3'],
    ];

    public function __construct(private KeseiBoard $board) {}

    public static function isLocation(string $slug): bool
    {
        return array_key_exists($slug, self::LOCATIONS);
    }

    /**
     * @return Collection<int, array{
     *     part_no: string, needed: int, scanned: int, remaining: int, done: bool
     * }>
     */
    public function list(string $locationSlug): Collection
    {
        $levels = self::LOCATIONS[$locationSlug] ?? [];
        $data = $this->board->data();

        return $data['keseiRows']
            ->filter(fn (array $row) => in_array(strtoupper(trim((string) $row['level'])), $levels, true))
            ->map(function (array $row) use ($data) {
                $needed = collect($data['stockDecreaseEvents'][$row['id']] ?? [])->sum('kanban');
                $scanned = $this->scanCount($row, $row['fold_start']);

                return [
                    'part_no' => $row['label'],
                    'needed' => (int) $needed,
                    'scanned' => $scanned,
                    'remaining' => max(0, (int) $needed - $scanned),
                    'done' => $scanned >= (int) $needed && $needed > 0,
                ];
            })
            ->filter(fn (array $r) => $r['needed'] > 0)
            ->sortBy('done')
            ->values();
    }

    /**
     * The single row for one part (or null when the part is not on the list
     * for this location, or already fully scanned).
     *
     * @return array{part_no: string, needed: int, scanned: int, remaining: int, done: bool}|null
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
