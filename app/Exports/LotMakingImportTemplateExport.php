<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class LotMakingImportTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return [
            'assy_part_code', 'part_no', 'qty_kanban', 'lot', 'loading_time', 'dandori',
            'lot_produksi', 'safety_stock', 'total_kanban_edar', 'next_process', 'kapasitas_rak',
        ];
    }

    public function array(): array
    {
        return [
            ['ASSY-001', 'GA241-04750', 20, 100, 40, 10, 200, 50, 12, 'Assembly', 30],
            ['ASSY-002', '57453-BZ140', 16, 50, 160, 10, 120, 24, 8, 'Welding', 24],
        ];
    }
}
