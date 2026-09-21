<?php

namespace App\Imports;

use App\Models\LotMaking;
use App\Models\Part;
use App\Services\KeseiPull;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Imports rows shaped exactly like the Lot Making Export/Import-template
 * (see LotMakingsExport / LotMakingImportTemplateExport) — so a real Export
 * can be edited and fed straight back in without reshaping it first:
 * `no | row | kolom | part_no | level | material_no_part | material_level |
 *  perintah_pulling | ltkbn | lot_produksi | slot | loading_time | dandori |
 *  jumlah_proses | c1..c10 | ordercycle` (column headers are matched
 * case/spacing-insensitively — "Part No" etc. all slug down to these same
 * keys; note "LT/KBN" and "Order/Cycle" slug to "ltkbn" and "ordercycle"
 * with no separator, since Laravel's slugger drops "/" rather than turning
 * it into "_"). ltkbn is a decimal (e.g. 20.8), not a whole number of
 * minutes — every other numeric column here is a plain integer. "Avg Slot" /
 * "Slot Fix" are ignored even if present — they're always computed from
 * lot_produksi and slot, never stored.
 *
 * Each row is one Lot Making record, keyed by part (one part = one row). The
 * part is matched by part_no (created automatically if it doesn't exist yet).
 * A row for a part that's already in Lot Making is updated in place; a new
 * part creates a new record. Rows with an empty part_no are skipped.
 *
 * Unlike Kesei's import, every recognised field here is fully replaced on
 * every import — a blank cell (empty, "-" or "—" — what the Export itself
 * writes for "no value") clears that field, it does not leave it untouched.
 * `no` is a manually-set position (for arranging rows later), not a row
 * count — it's stored as-is, gaps and all. loading_time/dandori are read by
 * Lot Making Planning when this part gets assigned outside Kelompok Pattern.
 *
 * level accepts the same free-text synonyms as Kesei's own level column
 * (FG/FINISH GOOD(S), 3/STORE 3/STORE3/STORE-3, or the Export's own "Finish
 * Goods"/"Store 3" labels — see KeseiPull::LOCATIONS) and is normalized to
 * the scanner card slug ('finish-goods'/'store-3'); anything else (including
 * blank) is left null — the part just doesn't show up on a scanner card
 * until it's set.
 *
 * c1..c10: an HH:MM time for that production cycle, stored as this row's
 * full cycle set (blank cycle cells just mean "no time for that cycle").
 */
class LotMakingImport implements ToCollection, WithHeadingRow
{
    /** Placeholders the Export itself writes for "no value" — never real data. */
    private const BLANK_MARKERS = ['', '-', '—'];

    public int $created = 0;

    public int $updated = 0;

    public int $partsCreated = 0;

    public int $rowsSkipped = 0;

    public function collection(SupportCollection $rows): void
    {
        foreach ($rows as $row) {
            $partNo = trim((string) ($row['part_no'] ?? ''));

            if ($partNo === '') {
                $this->rowsSkipped++;

                continue;
            }

            $part = Part::firstOrCreate(['part_no' => $partNo]);
            if ($part->wasRecentlyCreated) {
                $this->partsCreated++;
            }

            $pullingCommand = $this->int($row['perintah_pulling'] ?? null);
            $cycles = $this->cycles($row);

            $lotMaking = LotMaking::updateOrCreate(
                ['part_id' => $part->id],
                [
                    'no' => $this->int($row['no'] ?? null),
                    'level' => $this->level($row['level'] ?? null),
                    'row' => $this->str($row['row'] ?? null),
                    'kolom' => $this->str($row['kolom'] ?? null),
                    'material_part_no' => $this->str($row['material_no_part'] ?? null),
                    'material_level' => $this->str($row['material_level'] ?? null),
                    'pulling_command' => $pullingCommand,
                    'pulling_command_set_at' => $pullingCommand !== null ? now() : null,
                    'lt_per_kbn' => $this->float($row['ltkbn'] ?? null),
                    'lot_produksi' => $this->int($row['lot_produksi'] ?? null),
                    'slot' => $this->int($row['slot'] ?? null),
                    'loading_time' => $this->int($row['loading_time'] ?? null),
                    'dandori' => $this->int($row['dandori'] ?? null),
                    'jumlah_proses' => $this->int($row['jumlah_proses'] ?? null),
                    'cycles' => $cycles === [] ? null : $cycles,
                    'order_per_cycle' => $this->int($row['ordercycle'] ?? null),
                ]
            );

            $lotMaking->wasRecentlyCreated ? $this->created++ : $this->updated++;
        }
    }

    private function isBlank(mixed $raw): bool
    {
        return in_array(trim((string) $raw), self::BLANK_MARKERS, true);
    }

    private function int(mixed $value): ?int
    {
        $value = $this->str($value);

        return ($value === null || ! is_numeric($value)) ? null : (int) $value;
    }

    /** Same as int(), but keeps decimals — lt_per_kbn isn't whole minutes (e.g. 20.8). */
    private function float(mixed $value): ?float
    {
        $value = $this->str($value);

        return ($value === null || ! is_numeric($value)) ? null : (float) $value;
    }

    private function str(mixed $value): ?string
    {
        return $this->isBlank($value) ? null : trim((string) $value);
    }

    private function level(mixed $value): ?string
    {
        $value = strtoupper(trim((string) $value));

        if ($value === '' || $value === '-') {
            return null;
        }

        foreach (KeseiPull::LOCATIONS as $slug => $location) {
            if (in_array($value, $location['levels'], true)) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function cycles(SupportCollection $row): array
    {
        $cycles = [];

        foreach (LotMaking::CYCLES as $cycle) {
            $time = $this->parseTime($row['c'.$cycle] ?? null);

            if ($time !== null) {
                $cycles[$cycle] = $time;
            }
        }

        return $cycles;
    }

    private function parseTime(mixed $raw): ?string
    {
        // A cell Excel itself formats as a Time (which happens automatically
        // the moment someone types e.g. "5:05" into it) comes back from
        // Maatwebsite Excel as a raw float — the fraction of a 24h day
        // (05:05 -> ~0.2118), not an "H:i" string — so it needs Excel's own
        // date-serial conversion. Carbon::parse() on that bare decimal
        // string "succeeds" by treating it as a Unix timestamp and silently
        // landing on 00:00 instead of throwing something catchable, which is
        // why every cycle time imported as 00:00 without this check.
        if (is_numeric($raw)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $raw))->format('H:i');
            } catch (\Throwable) {
                return null;
            }
        }

        if ($this->isBlank($raw)) {
            return null;
        }

        try {
            return Carbon::parse(trim((string) $raw))->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }
}
