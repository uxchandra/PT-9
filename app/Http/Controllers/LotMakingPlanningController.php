<?php

namespace App\Http\Controllers;

use App\Models\CalendarEntry;
use App\Models\LotMakingAssignment;
use App\Models\LotMakingPlanning;
use App\Models\LotMakingPlanningAssignment;
use App\Models\Machine;
use App\Models\Pattern;
use App\Models\PatternGroupItem;
use App\Services\AndonScheduleBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * A completed Lot Making lot lands here (see LotMakingCycleTracker) waiting
 * to be scheduled onto a machine's Andon timeline, one proses step at a time
 * (see LotMakingPlanningAssignment — a lot goes through jumlah_proses steps,
 * each independently assignable AND independently closable). Assigning a
 * step creates a real Pattern (Assignment Mesin) row — the same thing
 * "Tambah Assignment" on the Pattern page creates — so the part shows up on
 * Andon filling a FREE TIME slot with no extra rendering logic needed.
 * Closing a step deletes its Pattern row (its slot goes back to FREE TIME)
 * but keeps the assignment row around, marked finished, as a history trail.
 *
 * Unlike a normal Assignment Mesin, these Pattern rows are NOT required to
 * have a matching Kelompok Pattern (PatternGroupItem) entry — a Lot Making
 * part floats between boards/machines day to day rather than sitting on one
 * fixed line, so it isn't forced into Kelompok Pattern's permanent-schedule
 * model. When no matching entry exists, the Pattern row carries its own
 * loading_time/jumlah_proses/total_kanban instead (see the patterns table
 * migration and AndonScheduleBuilder::buildShiftBlocks) — loading_time and
 * dandori come from the Lot Making part record itself, jumlah_proses too, so
 * staff never has to retype any of it here; they just pick a shift, a
 * machine (defaulted from Assignment Machine, but freely overridable to any
 * machine — it's a suggestion, not a requirement) and an actual open Waktu
 * Free Time slot, all per step — two steps of the same lot can run on
 * different shifts.
 *
 * The Pattern Board itself is never picked manually either — it's always
 * whichever board the Calendar has running right now (same resolution the
 * Andon board itself uses), since a Lot Making part is only ever slotted
 * into *today's* running schedule.
 */
class LotMakingPlanningController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private AndonScheduleBuilder $scheduleBuilder)
    {
    }

    public function index(Request $request): View
    {
        $status = $request->query('status', LotMakingPlanning::STATUS_OPEN);

        if (! array_key_exists($status, LotMakingPlanning::STATUS_LABELS)) {
            $status = LotMakingPlanning::STATUS_OPEN;
        }

        $todaysBoard = CalendarEntry::runningPatternBoard();
        $freeWindows = $todaysBoard ? $this->scheduleBuilder->freeWindows($todaysBoard) : [];
        $allMachines = Machine::orderBy('name')->get();

        // Every step of every lot is walked in PHP (not a single SQL WHERE)
        // because a step's state — Open/In Progress/Close — isn't a stored
        // column, it's derived per (planning, proses) pair from whether an
        // assignment row exists for it and whether that row is finished. A
        // lot with 3 steps can contribute rows to 3 different tabs at once.
        $plannings = LotMakingPlanning::with(['part.lotMaking', 'assignments.machine'])
            ->orderBy('created_at')
            ->get();

        $groups = collect();

        foreach ($plannings as $planning) {
            $jumlahProses = $planning->part?->lotMaking?->jumlah_proses;

            if ($jumlahProses === null) {
                // No steps definable yet — always sits in Open until Jumlah
                // Proses is filled in on the part.
                if ($status === LotMakingPlanning::STATUS_OPEN) {
                    $groups->push(['planning' => $planning, 'steps' => [['proses' => null, 'assignment' => null]]]);
                }

                continue;
            }

            $byProses = $planning->assignments->keyBy('proses');
            $steps = [];

            for ($n = 1; $n <= $jumlahProses; $n++) {
                $assignment = $byProses->get($n);
                $stepStatus = match (true) {
                    $assignment === null => LotMakingPlanning::STATUS_OPEN,
                    $assignment->isFinished() => LotMakingPlanning::STATUS_CLOSE,
                    default => LotMakingPlanning::STATUS_IN_PROGRESS,
                };

                if ($stepStatus === $status) {
                    $steps[] = ['proses' => $n, 'jumlahProses' => $jumlahProses, 'assignment' => $assignment];
                }
            }

            if ($steps !== []) {
                $groups->push(['planning' => $planning, 'steps' => $steps]);
            }
        }

        // Close reads like a history list (newest finished first); the two
        // active tabs read like a queue (oldest waiting first).
        $groups = $status === LotMakingPlanning::STATUS_CLOSE
            ? $groups->sortByDesc(fn (array $g) => collect($g['steps'])->max(fn (array $s) => $s['assignment']?->finished_at))->values()
            : $groups->values();

        $page = (int) $request->query('page', 1);
        $groupsPage = new LengthAwarePaginator(
            $groups->forPage($page, self::PER_PAGE)->values(),
            $groups->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // Every listed row's own Assignment Machine defaults, batched into
        // one query instead of one per row — keyed part_id -> proses so a
        // pending step's dropdown can pre-select its standard machine.
        $partIds = $groupsPage->getCollection()->pluck('planning.part_id')->unique()->values();
        $defaultsByPart = LotMakingAssignment::whereIn('part_id', $partIds)
            ->get()
            ->groupBy('part_id')
            ->map(fn ($rows) => $rows->keyBy('proses'));

        return view('lot-making-plannings.index', compact(
            'groupsPage', 'status', 'todaysBoard', 'freeWindows', 'allMachines', 'defaultsByPart'
        ));
    }

    public function assign(Request $request, LotMakingPlanning $planning): RedirectResponse
    {
        $patternBoard = CalendarEntry::runningPatternBoard();

        if (! $patternBoard) {
            return back()->withErrors(['machine_id' => 'Belum ada pattern yang jalan hari ini di Calendar.'])->withInput();
        }

        $jumlahProses = $planning->part?->lotMaking?->jumlah_proses;

        if ($jumlahProses === null) {
            return back()->withErrors(['machine_id' => 'Jumlah Proses part ini belum diisi di menu Lot Making > Part.'])->withInput();
        }

        $validated = $request->validate([
            'proses' => ['required', 'integer', 'min:1', "max:{$jumlahProses}"],
            'machine_id' => ['required', 'exists:machines,id'],
            'shift' => ['required', Rule::in([1, 2])],
            // Purely a "did you actually see an open slot" confirmation —
            // the real check is the fresh freeWindows() lookup below, since
            // the schedule could have changed since the page loaded.
            'window' => ['required', 'regex:/^\d+:\d+$/'],
        ], [], ['machine_id' => 'machine']);

        $proses = (int) $validated['proses'];

        if ($planning->assignments()->where('proses', $proses)->exists()) {
            return back()->with('status', "Proses {$proses} sudah di-assign.");
        }

        $shift = (int) $validated['shift'];
        $machineId = (int) $validated['machine_id'];
        [$windowStart, $windowEnd] = array_map('intval', explode(':', $validated['window']));

        $stillFree = collect($this->scheduleBuilder->freeWindows($patternBoard))
            ->contains(fn (array $w) => $w['machine_id'] === $machineId && $w['shift'] === $shift
                && $w['start'] === $windowStart && $w['end'] === $windowEnd);

        if (! $stillFree) {
            return back()->withErrors([
                'window' => 'Slot free time untuk mesin & shift ini sudah tidak tersedia — refresh halaman dan coba lagi.',
            ])->withInput();
        }

        $groupItem = PatternGroupItem::where('pattern_board_id', $patternBoard->id)
            ->where('part_id', $planning->part_id)
            ->where('shift', $shift)
            ->first();

        $patternData = [
            'pattern_board_id' => $patternBoard->id,
            'machine_id' => $machineId,
            'part_id' => $planning->part_id,
            'shift' => $shift,
            'proses' => $proses,
        ];

        if (! $groupItem) {
            // Not registered in Kelompok Pattern — expected for a Lot Making
            // part, which floats between boards/machines rather than
            // sitting on one fixed line. The Pattern row carries its own
            // scheduling data instead, read straight from the Lot Making
            // part record so staff never has to retype any of it here.
            $lotMaking = $planning->part->lotMaking;

            if ($lotMaking?->loading_time === null) {
                return back()->withErrors([
                    'machine_id' => 'Loading Time part ini belum diisi di menu Lot Making > Part — isi dulu di sana supaya baloknya bisa muncul di Andon.',
                ])->withInput();
            }

            $patternData += [
                'loading_time' => $lotMaking->loading_time,
                'jumlah_proses' => $jumlahProses,
                'dandori' => $lotMaking->dandori ?? 0,
                'total_kanban' => PatternGroupItem::calculateTotalKanban($planning->lot, $planning->part?->qty_kbn),
            ];
        }

        $pattern = Pattern::create($patternData);

        $planning->assignments()->create([
            'proses' => $proses,
            'machine_id' => $machineId,
            'shift' => $shift,
            'pattern_id' => $pattern->id,
        ]);

        return redirect()->route('lot-making-plannings.index')
            ->with('status', "Proses {$proses} berhasil di-assign — part akan muncul di Andon Pattern.");
    }

    /**
     * Undoes one proses step outright — a step assigned in error (wrong
     * machine/slot) shouldn't leave a history trail behind; it just goes
     * back to Open, ready to be assigned again from scratch. Only works
     * before the step is Closed — once closed, use nothing (it's done).
     */
    public function cancel(LotMakingPlanningAssignment $assignment): RedirectResponse
    {
        if ($assignment->isFinished()) {
            return back()->with('status', 'Proses ini sudah selesai.');
        }

        if ($assignment->pattern_id !== null) {
            Pattern::whereKey($assignment->pattern_id)->delete();
        }

        $assignment->delete();

        return redirect()->route('lot-making-plannings.index')
            ->with('status', 'Assignment proses dibatalkan — slot Andon kembali FREE TIME.');
    }

    /**
     * Closes one proses step — its Pattern row is deleted (its slot reverts
     * to FREE TIME) but the assignment row stays, finished_at set, as a
     * record of what ran where. The lot itself has no separate "closed"
     * state: once every one of its steps is closed this way, it simply has
     * nothing left to show on the Open or In Progress tabs.
     */
    public function close(LotMakingPlanningAssignment $assignment): RedirectResponse
    {
        if ($assignment->isFinished()) {
            return back()->with('status', 'Proses ini sudah selesai.');
        }

        if ($assignment->pattern_id !== null) {
            Pattern::whereKey($assignment->pattern_id)->delete();
        }

        $assignment->update(['pattern_id' => null, 'finished_at' => now()]);

        return redirect()->route('lot-making-plannings.index')
            ->with('status', 'Proses diselesaikan — slot Andon kembali FREE TIME.');
    }
}
