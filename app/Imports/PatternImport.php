<?php

namespace App\Imports;

use App\Models\Machine;
use App\Models\Part;
use App\Models\Pattern;
use App\Models\PatternBoard;
use App\Models\PatternGroupItem;
use Illuminate\Support\Collection as SupportCollection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Imports rows shaped like: Machine | Item | Jumlah Proses | Proses | loading_time | kanban | dandori | Shift | lot.
 *
 * Each row is one machine+part assignment. Machines and parts are created
 * automatically if they don't exist yet. loading_time/jumlah_proses/dandori/lot
 * belong to the board+part+shift (Kelompok Pattern) and are set from the first
 * row seen for that part+shift; later rows for the same part+shift only add
 * their machine assignment (with that row's own "Proses" number) and are
 * checked against the stored values, reporting a mismatch instead of
 * overwriting. Shift defaults to 1 (07:00-16:00) when the column is left out
 * or has an invalid value; 2 means the 20:00-06:00 shift. lot defaults to 0
 * when left out. total_kanban is never read from the file — it's always
 * derived from lot ÷ the part's Qty Kbn (Part List), rounded up.
 */
class PatternImport implements ToCollection, WithHeadingRow
{
    public int $partsCreated = 0;

    public int $machinesCreated = 0;

    public int $groupItemsCreated = 0;

    public int $assignmentsSaved = 0;

    public int $rowsSkipped = 0;

    /** @var array<int, string> */
    public array $mismatches = [];

    private int $nextUrutan = 1;

    public function __construct(private PatternBoard $patternBoard)
    {
        $this->nextUrutan = (int) $patternBoard->groupItems()->max('urutan') + 1;
    }

    public function collection(SupportCollection $rows): void
    {
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // account for the heading row

            $machineName = trim((string) ($row['machine'] ?? ''));
            $partName = trim((string) ($row['item'] ?? ''));

            if ($machineName === '' || $partName === '') {
                $this->rowsSkipped++;

                continue;
            }

            $jumlahProses = (int) ($row['jumlah_proses'] ?? 0);
            $proses = (int) ($row['proses'] ?? 0);
            $loadingTime = (int) ($row['loading_time'] ?? 0);
            $dandori = (int) ($row['dandori'] ?? 0);
            $lot = (int) ($row['lot'] ?? 0);
            $shift = (int) ($row['shift'] ?? 1);

            if (! in_array($shift, [1, 2], true)) {
                $shift = 1;
            }

            $machine = Machine::firstOrCreate(['name' => $machineName]);
            if ($machine->wasRecentlyCreated) {
                $this->machinesCreated++;
            }

            $part = Part::firstOrCreate(['part_no' => $partName]);
            if ($part->wasRecentlyCreated) {
                $this->partsCreated++;
            }

            $totalKanban = PatternGroupItem::calculateTotalKanban($lot, $part->qty_kbn);

            $groupItem = PatternGroupItem::where('pattern_board_id', $this->patternBoard->id)
                ->where('part_id', $part->id)
                ->where('shift', $shift)
                ->first();

            if (! $groupItem) {
                $groupItem = PatternGroupItem::create([
                    'pattern_board_id' => $this->patternBoard->id,
                    'part_id' => $part->id,
                    'shift' => $shift,
                    'urutan' => $this->nextUrutan++,
                    'lot' => $lot,
                    'loading_time' => $loadingTime,
                    'jumlah_proses' => $jumlahProses,
                    'total_kanban' => $totalKanban,
                    'dandori' => $dandori,
                ]);
                $this->groupItemsCreated++;
            } elseif ($groupItem->lot !== $lot
                || $groupItem->loading_time !== $loadingTime
                || $groupItem->jumlah_proses !== $jumlahProses
                || $groupItem->dandori !== $dandori) {
                $this->mismatches[] = "Baris {$rowNumber} ({$partName}, shift {$shift}): lot/loading_time/jumlah_proses/dandori beda dari yang sudah tersimpan, nilai baris ini diabaikan.";
            }

            Pattern::updateOrCreate(
                [
                    'pattern_board_id' => $this->patternBoard->id,
                    'machine_id' => $machine->id,
                    'part_id' => $part->id,
                    'shift' => $shift,
                ],
                ['proses' => $proses]
            );
            $this->assignmentsSaved++;
        }
    }
}
