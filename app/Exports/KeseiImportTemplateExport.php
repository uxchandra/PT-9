<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class KeseiImportTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return ['part_no', 'level', 'stock_source', 'closing_time', 'closing_mode', 'pattern'];
    }

    public function array(): array
    {
        return [
            ['GA241-04750', '', '', '', '', ''],
            ['57453-BZ140', '2', '', '14:30', 'end_of_day', 'A'],
            ['B114-97511', '3', 'B111-97502, B113-97514', '02:00', 'pre_run', 'A, B'],
            // Several closing times a day: one closing_mode per closing_time,
            // paired up in order.
            ['GA241-06050', '2', '', '05:00, 15:00', 'pre_run, end_of_day', 'A'],
        ];
    }
}
