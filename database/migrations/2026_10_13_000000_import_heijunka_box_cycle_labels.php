<?php

use App\Models\HeijunkaBoxCycleGroup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reads each group's "Cyc-1 / Cyc-2 / ..." sub-cycle markers off the same
 * row as its cycle_issue label (row `firstRow - 1` — one above the random
 * number row at `firstRow - 2`, same GROUPS ranges as the other Heijunka
 * Box import migrations) and stores them on HeijunkaBoxCycleGroup.
 *
 * Idempotent: upserts by cycle_issue every run.
 */
return new class extends Migration
{
    /** Column letter => "H:i" time-of-day — must stay in sync with the other Heijunka Box import migrations. */
    private const SLOT_COLUMNS = [
        'G' => '07:10', 'H' => '07:40', 'I' => '08:10', 'J' => '08:40', 'K' => '09:10', 'L' => '09:40',
        'N' => '10:20', 'O' => '10:50', 'P' => '11:20', 'Q' => '11:50',
        'S' => '13:05', 'T' => '13:35', 'U' => '14:05', 'V' => '14:35',
        'X' => '15:15', 'Y' => '15:45',
        'AH' => '20:05', 'AI' => '20:35', 'AJ' => '21:05', 'AK' => '21:35', 'AL' => '22:05', 'AM' => '22:35',
        'AN' => '23:05', 'AO' => '23:35',
        'AQ' => '00:45', 'AR' => '01:15', 'AS' => '01:45', 'AT' => '02:15', 'AU' => '02:45',
        'AW' => '03:55', 'AX' => '04:25', 'AY' => '04:55',
    ];

    /** cycle_issue label => [first data row, last data row] (1-indexed, inclusive) — same as the other Heijunka Box import migrations. */
    private const GROUPS = [
        '1-2-X' => [9, 18],
        '1-4-X' => [22, 37],
        '1-5-X' => [41, 43],
        '1-10-X' => [47, 47],
        '1-1-X' => [51, 58],
    ];

    public function up(): void
    {
        $path = database_path('seeders/data/heijunka-box-td9.xlsx');

        if (! file_exists($path)) {
            Log::warning('Heijunka Box cycle label import skipped — source file missing.', ['path' => $path]);

            return;
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName('Heijunka Tandem 9 ke FG');

        foreach (self::GROUPS as $cycleIssue => [$firstRow, $lastRow]) {
            $labelRow = $firstRow - 1;
            $cycleLabels = [];

            foreach (self::SLOT_COLUMNS as $column => $time) {
                $raw = trim((string) $sheet->getCell("{$column}{$labelRow}")->getValue());

                if ($raw !== '') {
                    $cycleLabels[$time] = $raw;
                }
            }

            HeijunkaBoxCycleGroup::where('cycle_issue', $cycleIssue)->update(['cycle_labels' => $cycleLabels]);
        }
    }

    public function down(): void
    {
        HeijunkaBoxCycleGroup::query()->update(['cycle_labels' => null]);
    }
};
