<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class KeseiImportTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return ['part_no', 'stock_source', 'closing_time', 'pattern'];
    }

    public function array(): array
    {
        return [
            ['GA241-04750', '', '', ''],
            ['57453-BZ140', '', '14:30', 'A'],
            ['B114-97511', 'B111-97502, B113-97514', '02:00', 'A, B'],
        ];
    }
}
