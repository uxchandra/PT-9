<?php

namespace App\Imports;

use App\Models\KeseiPart;
use App\Models\Part;
use App\Models\PatternBoard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Imports a sheet shaped exactly like the Kesei Export/Import-template
 * (see KeseiPartsExport / KeseiImportTemplateExport) — so a real Export can
 * be edited and fed straight back in without reshaping it first:
 * `part_no | level | perintah_pulling | ltkbn | material_no_part |
 *  material_level | sos_code | closing_time | closing_mode | pattern |
 *  c1..c10 | ordercycle` (column headers are matched
 * case/spacing-insensitively — "Part No", "SOS Code" etc. all slug down to
 * these same keys; note "LT/KBN" and "Order/Cycle" slug to "ltkbn" and
 * "ordercycle" with no separator, since Laravel's slugger drops "/" rather
 * than turning it into "_").
 * "stock_source" is still accepted as an alias for sos_code, for any
 * older template files already in the wild.
 *
 * - part_no is matched to Part List (created automatically if new).
 * - A part already in Kesei is never added a second time (kesei_parts.part_id
 *   is unique): if the row carries any recognised field, those are updated;
 *   otherwise the row is skipped.
 * - Every field below follows the same rule: a blank cell (empty, "-" or
 *   "—" — what the Export itself writes for "no value") leaves the existing
 *   value untouched; a non-blank cell overwrites it.
 * - sos_code: comma-separated part_no list whose Stock Part All stock is
 *   summed for Timeline Stok. Blank = the row's own part_no.
 * - closing_time: HH:MM, or several comma-separated (e.g. "05:00, 15:00") — a
 *   part can fold/notify at more than one closing a day. A non-empty cell
 *   REPLACES the row's full set of closing times, it does not add to it.
 *   Invalid entries are dropped.
 * - closing_mode: "pre_run" (default) or "end_of_day" — whether closing_time is
 *   a planning cutoff before the 07:00 run or the close of the run's own day.
 *   One value applies to every closing_time in the row; a comma-separated list
 *   the same length as closing_time pairs up positionally instead.
 * - pattern: one or more Pattern Board names, comma-separated (e.g. "A, B").
 *   Names with no matching board are ignored.
 * - c1..c10: an HH:MM time for that production cycle. Any non-blank cycle
 *   cell in the row REPLACES the row's full cycle set with exactly what's
 *   in c1..c10 (same "replace, don't merge" rule as closing_time).
 * - perintah_pulling / ordercycle: plain integers. ordercycle is the largest
 *   number of orders this part can carry in one production cycle. ltkbn is a
 *   decimal (e.g. 20.8), not a whole number of minutes.
 * - Rows with an empty part_no are skipped.
 */
class KeseiImport implements ToCollection, WithHeadingRow
{
    /** Placeholders the Export itself writes for "no value" — never real data. */
    private const BLANK_MARKERS = ['', '-', '—'];

    public int $added = 0;

    public int $updated = 0;

    public int $skippedDuplicate = 0;

    public int $skippedEmpty = 0;

    public int $partsCreated = 0;

    /** @var array<int, int|true> part_id => existing kesei_part id (or true once added this run) */
    private array $seen;

    /** @var array<string, int> lowercased board name => id */
    private array $boardIdByName;

    private int $nextUrutan;

    public function __construct()
    {
        $this->nextUrutan = (int) KeseiPart::max('urutan') + 1;
        $this->seen = KeseiPart::pluck('id', 'part_id')->all();
        $this->boardIdByName = PatternBoard::get(['id', 'name'])
            ->mapWithKeys(fn ($board) => [mb_strtolower($board->name) => $board->id])
            ->all();
    }

    public function collection(SupportCollection $rows): void
    {
        foreach ($rows as $row) {
            $partNo = trim((string) ($row['part_no'] ?? ''));

            if ($partNo === '') {
                $this->skippedEmpty++;

                continue;
            }

            $attrs = [
                'stock_source' => $this->cleanStockSource($row['sos_code'] ?? $row['stock_source'] ?? null),
                'level' => $this->cleanText($row['level'] ?? null),
                'pulling_command' => $this->parseInt($row['perintah_pulling'] ?? null),
                'lt_per_kbn' => $this->parseFloat($row['ltkbn'] ?? null),
                'material_part_no' => $this->cleanText($row['material_no_part'] ?? null),
                'material_level' => $this->cleanText($row['material_level'] ?? null),
                'cycles' => $this->parseCycles($row),
                'order_per_cycle' => $this->parseInt($row['ordercycle'] ?? null),
            ];
            $closings = $this->parseClosings($row['closing_time'] ?? null, $row['closing_mode'] ?? null);
            $boardIds = $this->resolveBoards($row['pattern'] ?? null);
            $hasAttr = collect($attrs)->contains(fn ($value) => $value !== null) || $closings !== [] || $boardIds !== [];

            $part = Part::firstOrCreate(['part_no' => $partNo]);
            if ($part->wasRecentlyCreated) {
                $this->partsCreated++;
            }

            if (array_key_exists($part->id, $this->seen)) {
                $keseiId = $this->seen[$part->id];

                if ($keseiId !== true && $hasAttr) {
                    $kesei = KeseiPart::find($keseiId);
                    $kesei->update($this->withPullingCommandTimestamp(array_filter($attrs, fn ($value) => $value !== null)));
                    if ($closings !== []) {
                        $kesei->syncClosings($closings);
                    }
                    if ($boardIds !== []) {
                        $kesei->patternBoards()->syncWithoutDetaching($boardIds);
                    }
                    $this->updated++;
                } else {
                    $this->skippedDuplicate++;
                }

                continue;
            }

            $kesei = KeseiPart::create($this->withPullingCommandTimestamp(array_filter($attrs, fn ($value) => $value !== null)) + [
                'part_id' => $part->id,
                'urutan' => $this->nextUrutan++,
            ]);
            $kesei->syncClosings($closings);
            $kesei->patternBoards()->sync($boardIds);
            $this->seen[$part->id] = true;
            $this->added++;
        }
    }

    /**
     * Setting pulling_command also bumps its "set at" timestamp — same rule
     * KeseiPartController::update() follows for the inline field.
     *
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function withPullingCommandTimestamp(array $attrs): array
    {
        if (array_key_exists('pulling_command', $attrs)) {
            $attrs['pulling_command_set_at'] = now();
        }

        return $attrs;
    }

    private function isBlank(mixed $raw): bool
    {
        return in_array(trim((string) $raw), self::BLANK_MARKERS, true);
    }

    /**
     * Trims a cell and treats the Export's own "-" / "—" placeholders (and a
     * genuinely empty cell) as no value at all.
     */
    private function cleanText(mixed $raw): ?string
    {
        return $this->isBlank($raw) ? null : trim((string) $raw);
    }

    private function parseInt(mixed $raw): ?int
    {
        $value = $this->cleanText($raw);

        return ($value !== null && is_numeric($value)) ? (int) $value : null;
    }

    /** Same as parseInt(), but keeps decimals — lt_per_kbn isn't whole minutes (e.g. 20.8). */
    private function parseFloat(mixed $raw): ?float
    {
        $value = $this->cleanText($raw);

        return ($value !== null && is_numeric($value)) ? (float) $value : null;
    }

    private function cleanStockSource(mixed $raw): ?string
    {
        if ($this->isBlank($raw)) {
            return null;
        }

        $parts = collect(explode(',', trim((string) $raw)))
            ->map(fn ($part) => trim($part))
            ->filter(fn ($part) => $part !== '' && ! $this->isBlank($part))
            ->unique()
            ->values();

        return $parts->isEmpty() ? null : $parts->implode(', ');
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
        // why every cycle/closing time imported as 00:00 without this check.
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

    /**
     * 'pre_run' / 'end_of_day' (or the Indonesian labels). Blank or unknown
     * falls back to CLOSING_PRE_RUN.
     */
    private function parseClosingMode(mixed $raw): string
    {
        return match (mb_strtolower(trim((string) $raw))) {
            'end_of_day', 'akhir', 'akhir produksi', 'after' => KeseiPart::CLOSING_END_OF_DAY,
            default => KeseiPart::CLOSING_PRE_RUN,
        };
    }

    /**
     * closing_time may hold several comma-separated times (e.g. "05:00, 15:00")
     * — a part can fold/notify more than once a day. closing_mode pairs up
     * positionally when it has the same count, otherwise one mode applies to
     * every time in the row. Invalid times are dropped; an all-invalid or
     * empty closing_time cell yields no closings (row's existing ones untouched).
     *
     * @return array<int, array{time: string, mode: string}>
     */
    private function parseClosings(mixed $timeRaw, mixed $modeRaw): array
    {
        $times = collect(explode(',', (string) $timeRaw))
            ->map(fn ($t) => $this->parseTime($t))
            ->filter()
            ->values();

        if ($times->isEmpty()) {
            return [];
        }

        $modes = collect(explode(',', (string) $modeRaw))
            ->map(fn ($m) => trim((string) $m))
            ->filter(fn ($m) => $m !== '')
            ->values();

        $pairPositionally = $modes->count() === $times->count();

        return $times->map(fn ($time, $i) => [
            'time' => $time,
            'mode' => $this->parseClosingMode($pairPositionally ? $modes[$i] : $modes->first()),
        ])->all();
    }

    /**
     * c1..c10 columns — any non-blank one REPLACES the row's full cycle set
     * with exactly what's in c1..c10 (blank cycle cells in a row that has
     * at least one filled-in cycle simply mean "no time for that cycle").
     * Null return means none of the ten cells carried a value, so the row's
     * existing cycles (if any) are left untouched.
     *
     * @return array<int, string>|null
     */
    private function parseCycles(SupportCollection $row): ?array
    {
        $cycles = [];

        foreach (KeseiPart::CYCLES as $cycle) {
            $time = $this->parseTime($row['c'.$cycle] ?? null);
            if ($time !== null) {
                $cycles[$cycle] = $time;
            }
        }

        return $cycles === [] ? null : $cycles;
    }

    /**
     * @return array<int, int>
     */
    private function resolveBoards(mixed $raw): array
    {
        return collect(explode(',', (string) $raw))
            ->map(fn ($name) => mb_strtolower(trim($name)))
            ->filter()
            ->map(fn ($name) => $this->boardIdByName[$name] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
