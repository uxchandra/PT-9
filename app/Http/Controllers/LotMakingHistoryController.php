<?php

namespace App\Http\Controllers;

use App\Models\LotMakingCycle;
use App\Models\LotMakingPlanning;
use App\Models\LotMakingScan;
use App\Models\Part;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class LotMakingHistoryController extends Controller
{
    private const PER_PAGE = [10, 25, 50, 100];

    public function scans(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $date = $this->date($request);
        $perPage = $this->perPage($request);

        $scans = LotMakingScan::query()
            ->with('scannedBy')
            ->when($search !== '', fn ($q) => $q->where('part_no', 'like', "%{$search}%"))
            ->when($date !== '', fn ($q) => $q->whereDate('scanned_at', $date))
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $viewData = [
            'scans' => $scans, 'search' => $search, 'date' => $date, 'perPage' => $perPage,
            // part_no => Part::qty_kbn for just this page's rows.
            'qtyKbn' => Part::whereIn('part_no', $scans->pluck('part_no')->unique()->all())->pluck('qty_kbn', 'part_no'),
        ];

        return $request->ajax()
            ? view('lot-makings._history-scan-results', $viewData)
            : view('lot-makings.history-scan', $viewData);
    }

    /**
     * Every completed lot cycle from the operator-scan board — logged the
     * instant a part's slots all fill up (see LotMakingCycleTracker), which
     * is exactly the moment a new entry lands in the Andon roller/Antrian
     * queue. The roller itself only ever shows the most recent ~30 still-
     * relevant ones; this is the full, permanent, searchable history of
     * every one of them, scan-sourced only (see LotMakingCycle::SOURCE_SCAN)
     * — the SOS/demand board (Lot Making 2) has its own separate tally, not
     * a Planning-driven queue, so it isn't part of this history.
     */
    public function cycles(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $date = $this->date($request);
        $perPage = $this->perPage($request);

        $cycles = LotMakingCycle::query()
            ->where('source', LotMakingCycle::SOURCE_SCAN)
            ->with(['planning.part.lotMaking', 'planning.assignments.machine'])
            ->when($search !== '', fn ($q) => $q->where('part_no', 'like', "%{$search}%"))
            ->when($date !== '', fn ($q) => $q->whereDate('completed_at', $date))
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $cycles->getCollection()->transform(fn (LotMakingCycle $cycle) => $this->withStatus($cycle));

        $viewData = ['cycles' => $cycles, 'search' => $search, 'date' => $date, 'perPage' => $perPage];

        return $request->ajax()
            ? view('lot-makings._history-cycle-results', $viewData)
            : view('lot-makings.history-cycle', $viewData);
    }

    /**
     * Rolls a lot's per-proses-step statuses (see LotMakingPlanningController
     * ::index() for the same step-level Open/In Progress/Close derivation)
     * into one summary status for this one history row, plus every distinct
     * machine any step has been assigned to.
     */
    private function withStatus(LotMakingCycle $cycle): LotMakingCycle
    {
        $planning = $cycle->planning;

        if ($planning === null) {
            // A cycle from before lot_making_plannings existed — nothing to
            // derive a status from.
            $cycle->setAttribute('history_status', null);
            $cycle->setAttribute('history_machines', collect());

            return $cycle;
        }

        $jumlahProses = $planning->part?->lotMaking?->jumlah_proses;

        if ($jumlahProses === null) {
            $cycle->setAttribute('history_status', $planning->assignments->isEmpty()
                ? LotMakingPlanning::STATUS_OPEN
                : LotMakingPlanning::STATUS_IN_PROGRESS);
        } else {
            $byProses = $planning->assignments->keyBy('proses');
            $stepStatuses = collect(range(1, $jumlahProses))->map(fn (int $n) => match (true) {
                ! $byProses->has($n) => LotMakingPlanning::STATUS_OPEN,
                $byProses->get($n)->isFinished() => LotMakingPlanning::STATUS_CLOSE,
                default => LotMakingPlanning::STATUS_IN_PROGRESS,
            });

            $cycle->setAttribute('history_status', match (true) {
                $stepStatuses->every(fn ($s) => $s === LotMakingPlanning::STATUS_CLOSE) => LotMakingPlanning::STATUS_CLOSE,
                $stepStatuses->every(fn ($s) => $s === LotMakingPlanning::STATUS_OPEN) => LotMakingPlanning::STATUS_OPEN,
                default => LotMakingPlanning::STATUS_IN_PROGRESS,
            });
        }

        $cycle->setAttribute('history_machines', $planning->assignments->pluck('machine.name')->filter()->unique()->sort()->values());

        return $cycle;
    }

    private function perPage(Request $request): int
    {
        $value = (int) $request->query('per_page', 50);

        return in_array($value, self::PER_PAGE, true) ? $value : 50;
    }

    private function date(Request $request): string
    {
        $raw = trim((string) $request->query('date', ''));

        try {
            return $raw === '' ? '' : Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return '';
        }
    }
}
