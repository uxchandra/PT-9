<?php

use App\Models\HeijunkaBoxSchedule;
use App\Models\Part;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * One-time import of the "Heijunka Box" schedule handed off by Pak Azis —
 * see database/seeders/data/heijunka-box-td9.xlsx, sheet "Heijunka Tandem 9
 * ke FG". The sheet lays out 5 part groups ("cycle issue" pitches), each a
 * block of rows under its own label ("1-2-X", "1-4-X", ...), with a fixed
 * grid of 32 daily time-slot columns (07:10 → 04:55 the next day) and a "1"
 * (or higher, for the 1-5-X group) marking which slots each part releases
 * at. This migration reads those marks directly and stores the resulting
 * per-part time list — it does NOT reimplement the random-number/cycling
 * logic that produced the sheet in the first place (per explicit
 * instruction: the sheet's own numbers are the source of truth, not a
 * formula we'd have to keep in sync with it).
 *
 * Matches every part_no against the EXISTING Part → KeseiPart/LotMaking
 * registrations — every part_no in this sheet was confirmed already
 * registered (see the 2026-09-21 conversation), so nothing new gets
 * created here; an unmatched part_no is skipped and logged rather than
 * silently dropped, in case a future version of the sheet adds one that
 * isn't registered yet.
 *
 * Idempotent: upserts by part_id, so re-running (e.g. after the source file
 * changes) replaces each part's schedule instead of duplicating it.
 */
return new class extends Migration
{
    /**
     * Column letter => "H:i" time-of-day, in the sheet's own left-to-right
     * (chronological) order. Derived from the sheet's own row 4/5 formulas
     * (cumulative time-of-day per slot index) — not something to re-derive
     * by hand if the source file ever changes; re-extract the same way.
     */
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

    /** cycle_issue label => [first data row, last data row] (1-indexed, inclusive). */
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
            Log::warning('Heijunka Box schedule import skipped — source file missing.', ['path' => $path]);

            return;
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName('Heijunka Tandem 9 ke FG');

        $imported = 0;
        $skipped = [];

        foreach (self::GROUPS as $cycleIssue => [$firstRow, $lastRow]) {
            for ($row = $firstRow; $row <= $lastRow; $row++) {
                $partNo = trim((string) $sheet->getCell("B{$row}")->getOldCalculatedValue());

                if ($partNo === '') {
                    continue;
                }

                $part = Part::where('part_no', $partNo)->first();

                if ($part === null) {
                    $skipped[] = $partNo;

                    continue;
                }

                $slots = [];

                foreach (self::SLOT_COLUMNS as $column => $time) {
                    $count = (int) $sheet->getCell("{$column}{$row}")->getValue();

                    for ($i = 0; $i < $count; $i++) {
                        $slots[] = $time;
                    }
                }

                if ($slots === []) {
                    continue;
                }

                HeijunkaBoxSchedule::updateOrCreate(
                    ['part_id' => $part->id],
                    ['cycle_issue' => $cycleIssue, 'slots' => $slots]
                );

                $imported++;
            }
        }

        Log::info('Heijunka Box schedule import complete.', ['imported' => $imported, 'skipped_part_nos' => $skipped]);
    }

    public function down(): void
    {
        // Nothing else writes to this table, so clearing it entirely is
        // equivalent to undoing exactly what up() created.
        HeijunkaBoxSchedule::query()->delete();
    }
};
