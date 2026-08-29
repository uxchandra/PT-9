<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PatternImportTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        // No "kanban" column — total_kanban is derived from lot ÷ the part's
        // Qty Kbn (Part List) and is never read from the imported file.
        return ['Machine', 'Item', 'Jumlah Proses', 'Proses', 'loading_time', 'dandori', 'Shift', 'lot'];
    }

    public function array(): array
    {
        return [
            ['PT91', 'GA241-04750', 9, 2, 40, 10, 1, 100],
            ['PT92', 'GA241-04750', 9, 3, 40, 10, 1, 100],
            ['PT91', '57453-BZ140', 8, 2, 160, 10, 2, 50],
        ];
    }
}
