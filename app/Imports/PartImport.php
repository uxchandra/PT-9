<?php

namespace App\Imports;

use App\Models\Part;
use Illuminate\Support\Collection as SupportCollection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Imports the Part List export used by the company's other system.
 *
 * The sheet has a title/banner on row 1, so the real headers (ID, Level,
 * Customer_Code, Model, Part_No, Part_No_Fg, ...) sit on row 2 starting at
 * column B, and data rows follow from row 3. Parts are matched by Part_No
 * (created if new, updated if it already exists). The row's position in the
 * file is stored as import_order, so the Part List can be shown in the same
 * order as the imported spreadsheet.
 */
class PartImport implements ToCollection, WithCustomCsvSettings, WithHeadingRow
{
    public int $partsCreated = 0;

    public int $partsUpdated = 0;

    public int $rowsSkipped = 0;

    private const array FIELDS = [
        'part_no_fg', 'level', 'customer_code', 'model', 'job_no', 'part_name',
        'type_box', 'qty_kbn', 'process', 'line', 'line_code', 'rack_no',
        'cap_rack', 'jig_no', 'qty_lot', 'stock_min', 'stock_max', 'image_name',
        'last_routing', 'remark', 'lt_pull', 'lt_prod', 'code_partset',
        'set_label', 'prod_point', 'cat_machine', 'spm', 'dandory',
        'cek_startfinish', 'update_by', 'update_time',
    ];

    public function headingRow(): int
    {
        return 2;
    }

    public function getCsvSettings(): array
    {
        return ['delimiter' => ','];
    }

    public function collection(SupportCollection $rows): void
    {
        foreach ($rows as $index => $row) {
            $partNo = trim((string) ($row['part_no'] ?? ''));

            if ($partNo === '') {
                $this->rowsSkipped++;

                continue;
            }

            $attributes = ['import_order' => $index + 1];
            foreach (self::FIELDS as $field) {
                $value = trim((string) ($row[$field] ?? ''));
                $attributes[$field] = $value === '' ? null : $value;
            }

            $part = Part::updateOrCreate(['part_no' => $partNo], $attributes);

            if ($part->wasRecentlyCreated) {
                $this->partsCreated++;
            } else {
                $this->partsUpdated++;
            }
        }
    }
}
