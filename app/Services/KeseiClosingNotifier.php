<?php

namespace App\Services;

use App\Models\CalendarEntry;
use App\Models\KeseiClosingNotification;
use App\Models\KeseiPart;
use App\Models\PatternGroupItem;
use App\Models\StockSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * When a Kesei part's closing time is reached, WhatsApp a one-line summary
 * (part no, running pattern, accumulated Qty Kbn) to the configured numbers.
 *
 * Runs off the same 5-minute tick as stock:capture-snapshot, so a message
 * lands within ~5 minutes of the closing time. A per-part-per-day record
 * stops it from ever sending twice.
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
        $today = $now->toDateString();

        // Only rows whose closing time has passed on a day they actually run
        // (their board is the Calendar pattern) — matching when the Andon Kesei
        // board folds the pile into a number.
        $due = KeseiPart::with(['part', 'patternBoards'])
            ->whereNotNull('closing_time')
            ->get()
            ->filter(fn (KeseiPart $kesei) => $kesei->closingReached($now));

        if ($due->isEmpty()) {
            return $result;
        }

        $alreadyNotified = KeseiClosingNotification::where('notified_on', $today)
            ->whereIn('kesei_part_id', $due->pluck('id'))
            ->pluck('kesei_part_id')
            ->all();

        $due = $due->reject(fn (KeseiPart $kesei) => in_array($kesei->id, $alreadyNotified, true));
        $result['skipped'] = count($alreadyNotified);

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

        // Parts that come due together and share the same clock time go out as
        // one WhatsApp, not one per part.
        $groups = $due->groupBy(fn (KeseiPart $kesei) => $kesei->closing_time->format('H:i'));

        foreach ($groups as $closing => $members) {
            $groupClosingAt = $now->copy()->setTime(
                (int) $members->first()->closing_time->hour,
                (int) $members->first()->closing_time->minute,
            );
            // Pattern running at the closing moment (Calendar rolls over at 07:00).
            $pattern = CalendarEntry::runningPatternBoard($groupClosingAt)?->name ?? '-';

            $lines = $members->map(function (KeseiPart $kesei) use ($now) {
                [$foldStart, $cycleStart] = $kesei->foldBoundaries($now);

                return [
                    'partNo' => $kesei->part?->part_no ?? '(part terhapus)',
                    'qtyKbn' => $this->accumulatedKanban($kesei, $cycleStart, $foldStart),
                ];
            })->all();

            $message = $this->message($closing, $pattern, $lines);

            $sentOk = false;
            foreach ($recipients as $to) {
                $sentOk = $this->fonnte->send($to, $message) || $sentOk;
            }

            if ($sentOk) {
                foreach ($members as $kesei) {
                    KeseiClosingNotification::create(['kesei_part_id' => $kesei->id, 'notified_on' => $today]);
                }
                $result['sent'] += $members->count();
            } else {
                // Not recorded — the next tick retries.
                $result['failed'] += $members->count();
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array{partNo: string, qtyKbn: int}>  $lines
     */
    private function message(string $closing, string $pattern, array $lines): string
    {
        $body = collect($lines)
            ->map(fn (array $line) => "Part    : {$line['partNo']}\nQty Kbn : {$line['qtyKbn']}")
            ->implode("\n\n");

        return "[PT-9] Closing {$closing}\n"
            ."Pattern : {$pattern}\n\n"
            .$body;
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
