<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PatternImportTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return ['Machine', 'Item', 'Jumlah Proses', 'Proses', 'loading_time', 'kanban', 'dandori', 'Shift'];
    }

    public function array(): array
    {
        return [
            ['PT91', 'GA241-04750', 9, 2, 40, 10, 10, 1],
            ['PT92', 'GA241-04750', 9, 3, 40, 10, 10, 1],
            ['PT91', '57453-BZ140', 8, 2, 160, 20, 10, 2],
        ];
    }
}
