<?php

namespace App\Imports;

use App\Models\LotMaking;
use App\Models\Part;
use App\Services\KeseiPull;
use Illuminate\Support\Collection as SupportCollection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Imports rows shaped like the template: no | row | kolom | part_no | level | lot_produksi | slot | loading_time | dandori.
 *
 * Each row is one Lot Making record, keyed by part (one part = one row). The
 * part is matched by part_no (created automatically if it doesn't exist yet).
 * A row for a part that's already in Lot Making is updated in place; a new
 * part creates a new record. Rows with an empty part_no are skipped. Numeric
 * columns that are blank or non-numeric are stored as null. avg_slot/slot_fix
 * are never imported — they're always computed from lot_produksi and slot.
 * `no` is a manually-set position (for arranging rows later), not a row count
 * — it's stored as-is, gaps and all. loading_time/dandori are read by Lot
 * Making Planning when this part gets assigned outside Kelompok Pattern.
 *
 * level accepts the same free-text synonyms as Kesei's own level column
 * (FG/FINISH GOOD(S), 3/STORE 3/STORE3/STORE-3 — see KeseiPull::LOCATIONS)
 * and is normalized to the scanner card slug ('finish-goods'/'store-3');
 * anything else (including blank) is left null — the part just doesn't show
 * up on a scanner card until it's set.
 */
class LotMakingImport implements ToCollection, WithHeadingRow
{
    public int $created = 0;

    public int $updated = 0;

    public int $partsCreated = 0;

    public int $rowsSkipped = 0;

    public function collection(SupportCollection $rows): void
    {
        foreach ($rows as $row) {
            $partNo = trim((string) ($row['part_no'] ?? ''));

            if ($partNo === '') {
                $this->rowsSkipped++;

                continue;
            }

            $part = Part::firstOrCreate(['part_no' => $partNo]);
            if ($part->wasRecentlyCreated) {
                $this->partsCreated++;
            }

            $lotMaking = LotMaking::updateOrCreate(
                ['part_id' => $part->id],
                [
                    'no' => $this->int($row['no'] ?? null),
                    'level' => $this->level($row['level'] ?? null),
                    'row' => $this->str($row['row'] ?? null),
                    'kolom' => $this->str($row['kolom'] ?? null),
                    'lot_produksi' => $this->int($row['lot_produksi'] ?? null),
                    'slot' => $this->int($row['slot'] ?? null),
                    'loading_time' => $this->int($row['loading_time'] ?? null),
                    'dandori' => $this->int($row['dandori'] ?? null),
                ]
            );

            $lotMaking->wasRecentlyCreated ? $this->created++ : $this->updated++;
        }
    }

    private function int(mixed $value): ?int
    {
        $value = trim((string) $value);

        return ($value === '' || ! is_numeric($value)) ? null : (int) $value;
    }

    private function str(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function level(mixed $value): ?string
    {
        $value = strtoupper(trim((string) $value));

        if ($value === '') {
            return null;
        }

        foreach (KeseiPull::LOCATIONS as $slug => $location) {
            if (in_array($value, $location['levels'], true)) {
                return $slug;
            }
        }

        return null;
    }
}
