<?php

namespace App\Exports;

use App\Models\KeseiPart;
use Illuminate\Contracts\Database\Eloquent\Builder;
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
 * Exports the Kesei list exactly as filtered by the index page's search box
 * (part no / SOS code / level) — same fields the table itself shows,
 * including one column per cycle (C1..C10).
 */
class KeseiPartsExport implements FromCollection, WithColumnWidths, WithEvents, WithHeadings, WithMapping, WithStyles
{
    private int $row = 0;

    public function __construct(private readonly string $search = '') {}

    public function collection(): Collection
    {
        $query = KeseiPart::with(['part', 'patternBoards', 'closings'])->orderBy('urutan')->orderBy('id');

        if ($this->search !== '') {
            $like = "%{$this->search}%";
            $query->where(function (Builder $q) use ($like) {
                $q->where('level', 'like', $like)
                    ->orWhere('stock_source', 'like', $like)
                    ->orWhereHas('part', fn (Builder $p) => $p->where('part_no', 'like', $like));
            });
        }

        return $query->get();
    }

    public function headings(): array
    {
        return array_merge(
            ['No', 'Part No', 'Level', 'Perintah Pulling', 'LT/KBN', 'Material No Part', 'Material Level', 'SOS Code', 'Closing Time', 'Closing Mode', 'Pattern'],
            array_map(fn ($cycle) => "C{$cycle}", KeseiPart::CYCLES),
            ['Order/Cycle'],
        );
    }

    public function map($keseiPart): array
    {
        $this->row++;

        return array_merge([
            $this->row,
            $keseiPart->part?->part_no ?? '-',
            $keseiPart->level ?? '-',
            $keseiPart->pulling_command ?? '-',
            $keseiPart->lt_per_kbn ?? '-',
            $keseiPart->material_part_no ?? '-',
            $keseiPart->material_level ?? '-',
            $keseiPart->stock_source ?? '-',
            // Raw times/modes (not closingTimesLabel()'s display string) so
            // re-importing this same file reconstructs the exact closings —
            // one closing_mode per closing_time, paired up positionally.
            $keseiPart->closings->pluck('closing_time')->map(fn ($t) => $t->format('H:i'))->implode(', ') ?: '-',
            $keseiPart->closings->pluck('closing_mode')->implode(', ') ?: '-',
            $keseiPart->patternBoards->pluck('name')->implode(', ') ?: '-',
        ], array_map(fn ($cycle) => $keseiPart->cycleTime($cycle) ?? '-', KeseiPart::CYCLES), [
            $keseiPart->order_per_cycle ?? '-',
        ]);
    }

    public function columnWidths(): array
    {
        return [
            'A' => 6,
            'B' => 18,
            'C' => 10,
            'D' => 16,
            'E' => 10,
            'F' => 18,
            'G' => 16,
            'H' => 22,
            'I' => 16,
            'J' => 16,
            'K' => 16,
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

                $sheet->getStyle("A2:A{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            },
        ];
    }
}
