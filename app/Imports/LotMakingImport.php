<?php

namespace App\Imports;

use App\Models\LotMaking;
use App\Models\Part;
use Illuminate\Support\Collection as SupportCollection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Imports rows shaped like the template: no | row | kolom | part_no | lot_produksi | slot.
 *
 * Each row is one Lot Making record, keyed by part (one part = one row). The
 * part is matched by part_no (created automatically if it doesn't exist yet).
 * A row for a part that's already in Lot Making is updated in place; a new
 * part creates a new record. Rows with an empty part_no are skipped. Numeric
 * columns that are blank or non-numeric are stored as null. avg_slot/slot_fix
 * are never imported — they're always computed from lot_produksi and slot.
 * `no` is a manually-set position (for arranging rows later), not a row count
 * — it's stored as-is, gaps and all.
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
                    'row' => $this->str($row['row'] ?? null),
                    'kolom' => $this->str($row['kolom'] ?? null),
                    'lot_produksi' => $this->int($row['lot_produksi'] ?? null),
                    'slot' => $this->int($row['slot'] ?? null),
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
}
