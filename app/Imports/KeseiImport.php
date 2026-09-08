<?php

namespace App\Imports;

use App\Models\KeseiPart;
use App\Models\Part;
use App\Models\PatternBoard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Imports a sheet of `part_no | stock_source | closing_time | pattern` into
 * the Kesei list.
 *
 * - part_no is matched to Part List (created automatically if new).
 * - A part already in Kesei is never added a second time (kesei_parts.part_id
 *   is unique): if the row carries any of stock_source / closing_time /
 *   pattern, those fields are updated; otherwise the row is skipped.
 * - stock_source: comma-separated part_no list whose Stock Part All stock is
 *   summed for Timeline Stok. Blank = the row's own part_no.
 * - closing_time: HH:MM. Ignored if not a valid time.
 * - pattern: one or more Pattern Board names, comma-separated (e.g. "A, B").
 *   Names with no matching board are ignored.
 * - Rows with an empty part_no are skipped.
 */
class KeseiImport implements ToCollection, WithHeadingRow
{
    public int $added = 0;

    public int $updated = 0;

    public int $skippedDuplicate = 0;

    public int $skippedEmpty = 0;

    public int $partsCreated = 0;

    /** @var array<int, int|true> part_id => existing kesei_part id (or true once added this run) */
    private array $seen;

    /** @var array<string, int> lowercased board name => id */
    private array $boardIdByName;

    private int $nextUrutan;

    public function __construct()
    {
        $this->nextUrutan = (int) KeseiPart::max('urutan') + 1;
        $this->seen = KeseiPart::pluck('id', 'part_id')->all();
        $this->boardIdByName = PatternBoard::get(['id', 'name'])
            ->mapWithKeys(fn ($board) => [mb_strtolower($board->name) => $board->id])
            ->all();
    }

    public function collection(SupportCollection $rows): void
    {
        foreach ($rows as $row) {
            $partNo = trim((string) ($row['part_no'] ?? ''));

            if ($partNo === '') {
                $this->skippedEmpty++;

                continue;
            }

            $attrs = [
                'stock_source' => $this->cleanStockSource($row['stock_source'] ?? null),
                'closing_time' => $this->parseTime($row['closing_time'] ?? null),
            ];
            $boardIds = $this->resolveBoards($row['pattern'] ?? null);
            $hasAttr = collect($attrs)->contains(fn ($value) => $value !== null) || $boardIds !== [];

            $part = Part::firstOrCreate(['part_no' => $partNo]);
            if ($part->wasRecentlyCreated) {
                $this->partsCreated++;
            }

            if (array_key_exists($part->id, $this->seen)) {
                $keseiId = $this->seen[$part->id];

                if ($keseiId !== true && $hasAttr) {
                    $kesei = KeseiPart::find($keseiId);
                    $kesei->update(array_filter($attrs, fn ($value) => $value !== null));
                    if ($boardIds !== []) {
                        $kesei->patternBoards()->syncWithoutDetaching($boardIds);
                    }
                    $this->updated++;
                } else {
                    $this->skippedDuplicate++;
                }

                continue;
            }

            $kesei = KeseiPart::create($attrs + [
                'part_id' => $part->id,
                'urutan' => $this->nextUrutan++,
            ]);
            $kesei->patternBoards()->sync($boardIds);
            $this->seen[$part->id] = true;
            $this->added++;
        }
    }

    private function cleanStockSource(mixed $raw): ?string
    {
        $parts = collect(explode(',', trim((string) $raw)))
            ->map(fn ($part) => trim($part))
            ->filter()
            ->unique()
            ->values();

        return $parts->isEmpty() ? null : $parts->implode(', ');
    }

    private function parseTime(mixed $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, int>
     */
    private function resolveBoards(mixed $raw): array
    {
        return collect(explode(',', (string) $raw))
            ->map(fn ($name) => mb_strtolower(trim($name)))
            ->filter()
            ->map(fn ($name) => $this->boardIdByName[$name] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
