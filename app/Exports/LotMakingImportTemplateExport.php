<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LotMakingImportTemplateExport implements FromArray, WithColumnWidths, WithEvents, WithHeadings, WithStyles
{
    public function headings(): array
    {
        return ['no', 'row', 'kolom', 'part_no', 'lot_produksi', 'slot'];
    }

    public function array(): array
    {
        return [
            [1, '1', '1', 'GA241-04750', 200, 40],
            [2, '1', '2', '57453-BZ140', 120, 30],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 8,   // no
            'B' => 10,  // row
            'C' => 10,  // kolom
            'D' => 22,  // part_no
            'E' => 15,  // lot_produksi
            'F' => 10,  // slot
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

                // Header stays visible while scrolling through a large sheet.
                $sheet->freezePane('A2');
                $sheet->getRowDimension(1)->setRowHeight(20);

                // Thin borders on every cell that actually has content.
                $sheet->getStyle("A1:{$lastColumn}{$lastRow}")
                    ->getBorders()->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->getColor()->setRGB('D1D5DB');

                // Short/numeric columns read better centred; part_no stays
                // left-aligned since it's the one free-text-length column.
                $sheet->getStyle("A2:C{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("E2:F{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            },
        ];
    }
}
