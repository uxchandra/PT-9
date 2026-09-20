<?php

namespace App\Exports;

use App\Models\KeseiPart;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Same column layout as KeseiPartsExport (see KeseiImport for how each
 * column is read) — a real Export can be edited and re-imported directly,
 * and this blank template is just that same shape with example rows for a
 * fresh start.
 */
class KeseiImportTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return array_merge(
            ['No', 'Part No', 'Level', 'Perintah Pulling', 'LT/KBN', 'Material No Part', 'Material Level', 'SOS Code', 'Closing Time', 'Closing Mode', 'Pattern'],
            array_map(fn ($cycle) => "C{$cycle}", KeseiPart::CYCLES),
            ['Order/Cycle'],
        );
    }

    public function array(): array
    {
        return [
            array_merge([1, 'GA241-04750', '', '', '', '', '', '', '', '', ''], array_fill(0, 10, ''), ['']),
            array_merge([2, '57453-BZ140', '2', '', '', '', '', '', '14:30', 'end_of_day', 'A'], array_fill(0, 10, ''), ['']),
            array_merge([3, 'B114-97511', '3', '40', '30', '', '', 'B111-97502, B113-97514', '02:00', 'pre_run', 'A, B'], ['07:00', '', '15:00', '', '', '', '', '', '', ''], [25]),
            // Several closing times a day: one closing_mode per closing_time,
            // paired up in order.
            array_merge([4, 'GA241-06050', '2', '', '', '', '', '', '05:00, 15:00', 'pre_run, end_of_day', 'A'], array_fill(0, 10, ''), ['']),
        ];
    }
}
