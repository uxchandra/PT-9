<?php

namespace App\Imports;

use App\Models\LotMaking;
use App\Models\Part;
use Illuminate\Support\Collection as SupportCollection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Imports rows shaped like the template:
 * assy_part_code | part_no | qty_kanban | lot | loading_time | dandori |
 * lot_produksi | safety_stock | total_kanban_edar | next_process | kapasitas_rak.
 *
 * Each row is one Lot Making record, keyed by assy_part_code + part. The part
 * is matched by part_no (created automatically if it doesn't exist yet). A row
 * whose assy_part_code + part already exist is updated in place; a new
 * combination is created. Rows with an empty assy_part_code or part_no are
 * skipped. Numeric columns that are blank or non-numeric are stored as null.
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
            $assyPartCode = trim((string) ($row['assy_part_code'] ?? ''));
            $partNo = trim((string) ($row['part_no'] ?? ''));

            if ($assyPartCode === '' || $partNo === '') {
                $this->rowsSkipped++;

                continue;
            }

            $part = Part::firstOrCreate(['part_no' => $partNo]);
            if ($part->wasRecentlyCreated) {
                $this->partsCreated++;
            }

            $lotMaking = LotMaking::updateOrCreate(
                ['assy_part_code' => $assyPartCode, 'part_id' => $part->id],
                [
                    'qty_kanban' => $this->int($row['qty_kanban'] ?? null),
                    'lot' => $this->int($row['lot'] ?? null),
                    'loading_time' => $this->int($row['loading_time'] ?? null),
                    'dandori' => $this->int($row['dandori'] ?? null),
                    'lot_produksi' => $this->int($row['lot_produksi'] ?? null),
                    'safety_stock' => $this->int($row['safety_stock'] ?? null),
                    'total_kanban_edar' => $this->int($row['total_kanban_edar'] ?? null),
                    'next_process' => $this->str($row['next_process'] ?? null),
                    'kapasitas_rak' => $this->int($row['kapasitas_rak'] ?? null),
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
