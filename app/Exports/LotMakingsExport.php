<?php

namespace App\Exports;

use App\Models\LotMaking;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Exports the Lot Making list exactly as filtered by the index page's search
 * box (row / kolom / part no / level) — same fields the table itself shows,
 * including one column per cycle (C1..C10). Not limited by "per_page" —
 * that's a viewing convenience, not a filter on what counts as "shown".
 */
class LotMakingsExport implements FromCollection, WithColumnWidths, WithEvents, WithHeadings, WithMapping, WithStyles
{
    public function __construct(private readonly string $search = '') {}

    public function collection(): Collection
    {
        return LotMaking::query()->with('part')->search($this->search)
            ->orderBy('no')->orderBy('row')->orderBy('kolom')->orderBy('id')
            ->get();
    }

    public function headings(): array
    {
        return array_merge(
            ['No', 'Row', 'Kolom', 'Part No', 'Level', 'Material No Part', 'Material Level', 'Perintah Pulling', 'LT/KBN', 'Lot Produksi', 'Slot', 'Avg Slot', 'Slot Fix', 'Loading Time', 'Dandori', 'Jumlah Proses'],
            array_map(fn ($cycle) => "C{$cycle}", LotMaking::CYCLES),
            ['Order/Cycle'],
        );
    }

    public function map($lotMaking): array
    {
        return array_merge([
            $lotMaking->no ?? '-',
            $lotMaking->row ?? '-',
            $lotMaking->kolom ?? '-',
            $lotMaking->part?->part_no ?? '-',
            LotMaking::LEVELS[$lotMaking->level] ?? '-',
            $lotMaking->material_part_no ?? '-',
            $lotMaking->material_level ?? '-',
            $lotMaking->pulling_command ?? '-',
            $lotMaking->lt_per_kbn ?? '-',
            $lotMaking->lot_produksi ?? '-',
            $lotMaking->slot ?? '-',
            $lotMaking->avg_slot !== null ? round($lotMaking->avg_slot, 2) : '-',
            $lotMaking->slot_fix ?? '-',
            $lotMaking->loading_time ?? '-',
            $lotMaking->dandori ?? '-',
            $lotMaking->jumlah_proses ?? '-',
        ], array_map(fn ($cycle) => $lotMaking->cycleTime($cycle) ?? '-', LotMaking::CYCLES), [
            $lotMaking->order_per_cycle ?? '-',
        ]);
    }

    public function columnWidths(): array
    {
        return [
            'A' => 6,
            'B' => 10,
            'C' => 10,
            'D' => 18,
            'E' => 14,
            'F' => 18,
            'G' => 16,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '5D4037'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = $sheet->getHighestRow();
                $lastColumn = $sheet->getHighestColumn();

                $sheet->freezePane('A2');
                $sheet->getRowDimension(1)->setRowHeight(20);

                $sheet->getStyle("A1:{$lastColumn}{$lastRow}")
                    ->getBorders()->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->getColor()->setRGB('D1D5DB');

                $sheet->getStyle("A2:C{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            },
        ];
    }
}
