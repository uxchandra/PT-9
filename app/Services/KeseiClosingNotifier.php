<?php

namespace App\Services;

use App\Models\KeseiClosingNotification;
use App\Models\KeseiPart;
use App\Models\PatternGroupItem;
use App\Models\StockSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * When a Kesei part's closing is reached, WhatsApp a one-line summary (part no,
 * planned pattern, accumulated Qty Kbn) to the configured numbers.
 *
 * Runs off the same 5-minute tick as stock:capture-snapshot, so a message lands
 * within ~5 minutes of the closing. A per-part-per-closing record (keyed by the
 * closing's date) stops it from ever sending twice.
 */
class KeseiClosingNotifier
{
    public function __construct(private FonnteClient $fonnte) {}

    /**
     * @return array{sent: int, skipped: int, failed: int}
     */
    public function run(): array
    {
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        $now = now();

        // Rows whose closing for the current run has passed — the same moment
        // the Andon Kesei board folds its pile into a number. For a pre_run
        // closing this can be hours before the run actually starts.
        $due = KeseiPart::with(['part', 'patternBoards'])
            ->whereNotNull('closing_time')
            ->get()
            ->map(function (KeseiPart $kesei) use ($now) {
                [$foldStart, $cycleStart] = $kesei->foldBoundaries($now);

                return ['kesei' => $kesei, 'foldStart' => $foldStart, 'cycleStart' => $cycleStart];
            })
            ->filter(fn (array $row) => $row['foldStart'] !== null)
            ->values();

        if ($due->isEmpty()) {
            return $result;
        }

        // One WhatsApp per part per closing event, keyed by the closing's date.
        $keseiIds = $due->pluck('kesei.id');
        $floorDate = $now->copy()->subDays(KeseiPart::FOLD_HISTORY_DAYS)->toDateString();
        $notified = KeseiClosingNotification::whereIn('kesei_part_id', $keseiIds)
            ->where('notified_on', '>=', $floorDate)
            ->get(['kesei_part_id', 'notified_on'])
            ->map(fn ($row) => $row->kesei_part_id.'|'.Carbon::parse($row->notified_on)->toDateString())
            ->all();

        $due = $due->reject(
            fn (array $row) => in_array($row['kesei']->id.'|'.$row['foldStart']->toDateString(), $notified, true)
        );
        $result['skipped'] = $keseiIds->count() - $due->count();

        if ($due->isEmpty()) {
            return $result;
        }

        $recipients = collect(explode(',', (string) config('services.kesei_closing.recipients')))
            ->map(fn ($number) => trim($number))
            ->filter()
            ->all();

        if ($recipients === []) {
            Log::warning('KeseiClosingNotifier: KESEI_CLOSING_WA_RECIPIENTS is empty — nothing sent.');

            return $result;
        }

        // Parts that come due together with the same closing time AND the same
        // planned pattern go out as one WhatsApp.
        $groups = $due->groupBy(
            fn (array $row) => $row['kesei']->closing_time->format('H:i').'|'.$row['kesei']->plannedPatternName($now)
        );

        foreach ($groups as $members) {
            $first = $members->first();
            $closing = $first['kesei']->closing_time->format('H:i');
            $pattern = $first['kesei']->plannedPatternName($now);
            $date = $first['foldStart']->translatedFormat('d M Y');

            $lines = $members->map(fn (array $row) => [
                'kesei' => $row['kesei'],
                'foldStart' => $row['foldStart'],
                'partNo' => $row['kesei']->part?->part_no ?? '(part terhapus)',
                'qtyKbn' => $this->accumulatedKanban($row['kesei'], $row['cycleStart'], $row['foldStart']),
            ]);

            $message = $this->message($closing, $pattern, $date, $lines->map(
                fn (array $l) => ['partNo' => $l['partNo'], 'qtyKbn' => $l['qtyKbn']]
            )->all());

            $sentOk = false;
            foreach ($recipients as $to) {
                $sentOk = $this->fonnte->send($to, $message) || $sentOk;
            }

            if ($sentOk) {
                foreach ($lines as $line) {
                    KeseiClosingNotification::create([
                        'kesei_part_id' => $line['kesei']->id,
                        'notified_on' => $line['foldStart']->toDateString(),
                        'qty_kbn' => $line['qtyKbn'],
                    ]);
                }
                $result['sent'] += $lines->count();
            } else {
                // Not recorded — the next tick retries.
                $result['failed'] += $lines->count();
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array{partNo: string, qtyKbn: int}>  $lines
     */
    private function message(string $closing, string $pattern, string $date, array $lines): string
    {
        $header = "[Line 9]  {$date}\nClosing {$closing}";

        $blocks = collect($lines)
            ->map(fn (array $line) => "Part    : {$line['partNo']}\nQty Kbn : {$line['qtyKbn']}");

        // One part: Pattern sits with the part block. Several parts: Pattern sits
        // with the header, and each part block is spaced out.
        if ($blocks->count() === 1) {
            return "{$header}\n\nPattern : {$pattern}\n{$blocks->first()}";
        }

        return "{$header}\nPattern : {$pattern}\n\n".$blocks->implode("\n\n");
    }

    /**
     * Sum of every stock decrease (converted to kanban) across the row's
     * source part_no(s) over one accumulation cycle — from the previous
     * run-day closing ($from) up to the one that just passed ($to). This is
     * exactly the span the Andon Kesei board folds into its green-line number,
     * so the WhatsApp figure matches the screen. Restocks and flat readings
     * are ignored.
     */
    private function accumulatedKanban(KeseiPart $kesei, Carbon $from, Carbon $to): int
    {
        $sources = $kesei->sourcePartNos();

        if ($sources === []) {
            return 0;
        }

        $windowStart = $from->copy()->startOfHour();
        $columns = ['part_no', 'stock', 'captured_at'];

        $seed = StockSnapshot::whereIn('part_no', $sources)
            ->whereBetween('captured_at', [$windowStart->copy()->subDay(), $windowStart->copy()->subSecond()])
            ->orderBy('part_no')
            ->orderByDesc('captured_at')
            ->get($columns)
            ->unique('part_no')
            ->mapWithKeys(fn ($row) => [$row->part_no => (int) $row->stock])
            ->all();

        $byTime = [];
        StockSnapshot::whereIn('part_no', $sources)
            ->whereBetween('captured_at', [$windowStart, $to])
            ->orderBy('captured_at')
            ->get($columns)
            ->each(function ($row) use (&$byTime) {
                $byTime[$row->captured_at->toDateTimeString()][$row->part_no] = (int) $row->stock;
            });

        $qtyKbn = $kesei->part?->qty_kbn;
        $previous = $this->sumPresent($seed, $sources);
        $kanban = 0;

        foreach ($byTime as $timestamp => $map) {
            $current = $this->sumPresent($map, $sources);
            if ($current === null) {
                continue;
            }

            // Readings at or before the previous fold only prime $previous;
            // drops count from strictly after it.
            if (Carbon::parse($timestamp)->lte($from)) {
                $previous = $current;

                continue;
            }

            if ($previous !== null && $previous - $current > 0) {
                $kanban += PatternGroupItem::calculateTotalKanban($previous - $current, $qtyKbn);
            }

            $previous = $current;
        }

        return $kanban;
    }

    /**
     * @param  array<string, int>  $map
     * @param  array<int, string>  $sources
     */
    private function sumPresent(array $map, array $sources): ?int
    {
        $sum = 0;
        $present = false;

        foreach ($sources as $partNo) {
            if (array_key_exists($partNo, $map)) {
                $sum += $map[$partNo];
                $present = true;
            }
        }

        return $present ? $sum : null;
    }
}
