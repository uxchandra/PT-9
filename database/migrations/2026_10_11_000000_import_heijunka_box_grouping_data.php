<?php

use App\Models\HeijunkaBoxCycleGroup;
use App\Models\HeijunkaBoxSchedule;
use App\Models\Part;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Fills in the grouping schema (see the previous migration) with the two
 * pieces of the source sheet needed to render the board grouped the way the
 * sheet itself is laid out: each "cycle issue" pitch is its own block, with
 * a "RANDOM NUMBER" row of fixed per-slot numbers directly above its parts
 * (row `firstRow - 2` of each group — same GROUPS ranges as the schedule
 * import), and the parts keep the sheet's own row order rather than being
 * sorted alphabetically.
 *
 * Same principle as the schedule import: these numbers are read directly
 * off the sheet, not derived from any random/round-robin algorithm.
 *
 * Idempotent: upserts the group by cycle_issue and re-sets each schedule's
 * sort_order every run.
 */
return new class extends Migration
{
    /** Column letter => "H:i" time-of-day — must stay in sync with the schedule import migration's own copy. */
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

    /** cycle_issue label => [first data row, last data row] (1-indexed, inclusive) — same as the schedule import migration. */
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
            Log::warning('Heijunka Box grouping import skipped — source file missing.', ['path' => $path]);

            return;
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName('Heijunka Tandem 9 ke FG');

        $groupIndex = 0;

        foreach (self::GROUPS as $cycleIssue => [$firstRow, $lastRow]) {
            $randomRow = $firstRow - 2;
            $randomNumbers = [];

            foreach (self::SLOT_COLUMNS as $column => $time) {
                // Plain literal numbers, not formulas — getValue() (not
                // getOldCalculatedValue(), which only resolves for formula
                // cells and returns null here).
                $raw = trim((string) $sheet->getCell("{$column}{$randomRow}")->getValue());

                if ($raw !== '' && is_numeric($raw)) {
                    $randomNumbers[$time] = (int) $raw;
                }
            }

            HeijunkaBoxCycleGroup::updateOrCreate(
                ['cycle_issue' => $cycleIssue],
                ['sort_order' => $groupIndex, 'random_numbers' => $randomNumbers]
            );

            $rowOrder = 0;

            for ($row = $firstRow; $row <= $lastRow; $row++) {
                $partNo = trim((string) $sheet->getCell("B{$row}")->getOldCalculatedValue());

                if ($partNo === '') {
                    continue;
                }

                $part = Part::where('part_no', $partNo)->first();

                if ($part === null) {
                    continue;
                }

                HeijunkaBoxSchedule::where('part_id', $part->id)->update(['sort_order' => $rowOrder]);

                $rowOrder++;
            }

            $groupIndex++;
        }
    }

    public function down(): void
    {
        HeijunkaBoxCycleGroup::query()->delete();
        HeijunkaBoxSchedule::query()->update(['sort_order' => null]);
    }
};
