<?php

namespace App\Http\Controllers;

use App\Models\CalendarEntry;
use App\Models\LotMaking;
use App\Models\LotMakingAssignment;
use App\Models\LotMakingPlanning;
use App\Models\Pattern;
use App\Models\PatternGroupItem;
use App\Services\AndonScheduleBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * A completed Lot Making lot lands here (see LotMakingCycleTracker) waiting
 * to be scheduled onto a machine's Andon timeline. Assigning one creates a
 * real Pattern (Assignment Mesin) row — the same thing "Tambah Assignment"
 * on the Pattern page creates — so the part shows up on Andon filling a FREE
 * TIME slot with no extra rendering logic needed. Finishing one deletes that
 * Pattern row (the slot goes back to FREE TIME) but keeps this row around,
 * marked finished, as a history trail.
 *
 * Unlike a normal Assignment Mesin, this Pattern row is NOT required to have
 * a matching Kelompok Pattern (PatternGroupItem) entry — a Lot Making part
 * floats between boards/machines day to day rather than sitting on one fixed
 * line, so it isn't forced into Kelompok Pattern's permanent-schedule model.
 * When no matching entry exists, the Pattern row carries its own
 * loading_time/jumlah_proses/total_kanban instead (see the patterns table
 * migration and AndonScheduleBuilder::buildShiftBlocks) — loading_time,
 * dandori and jumlah_proses come from the Lot Making part record itself, and
 * which machine + proses step come from Assignment Machine (see the
 * lot_making_assignments migration) — so staff never has to retype any of it
 * here; they just pick a registered machine, a shift, and an open slot.
 *
 * The Pattern Board itself is never picked manually either — it's always
 * whichever board the Calendar has running right now (same resolution the
 * Andon board itself uses), since a Lot Making part is only ever slotted
 * into *today's* running schedule.
 */
class LotMakingPlanningController extends Controller
{
    public function __construct(private AndonScheduleBuilder $scheduleBuilder)
    {
    }

    public function index(Request $request): View
    {
        $status = $request->query('status', LotMakingPlanning::STATUS_OPEN);

        if (! array_key_exists($status, LotMakingPlanning::STATUS_LABELS)) {
            $status = LotMakingPlanning::STATUS_OPEN;
        }

        $plannings = LotMakingPlanning::with(['part.lotMaking', 'patternBoard', 'machine'])
            ->when(
                $status === LotMakingPlanning::STATUS_CLOSE,
                fn ($q) => $q->whereNotNull('finished_at'),
                fn ($q) => $q->whereNull('finished_at')->when(
                    $status === LotMakingPlanning::STATUS_IN_PROGRESS,
                    fn ($q2) => $q2->whereNotNull('pattern_id'),
                    fn ($q2) => $q2->whereNull('pattern_id'),
                )
            )
            ->orderBy($status === LotMakingPlanning::STATUS_CLOSE ? 'finished_at' : 'created_at', $status === LotMakingPlanning::STATUS_CLOSE ? 'desc' : 'asc')
            ->paginate(20)
            ->withQueryString();

        $todaysBoard = CalendarEntry::runningPatternBoard();

        // Every open FREE TIME window on today's board, up front — the
        // "Assign ke" form picks Machine (from that part's own registered
        // Assignment Machine rows) then Shift, and this list narrows down to
        // whatever's actually still free for that combination.
        $freeWindows = $todaysBoard ? $this->scheduleBuilder->freeWindows($todaysBoard) : [];

        // Every Open row's own Assignment Machine options, batched into one
        // query instead of one per row.
        $partIds = $status === LotMakingPlanning::STATUS_OPEN
            ? $plannings->pluck('part_id')->unique()->values()
            : collect();

        $assignmentsByPart = LotMakingAssignment::whereIn('part_id', $partIds)
            ->with('machine')
            ->orderBy('proses')
            ->get()
            ->groupBy('part_id');

        return view('lot-making-plannings.index', compact(
            'plannings', 'status', 'todaysBoard', 'freeWindows', 'assignmentsByPart'
        ));
    }

    public function assign(Request $request, LotMakingPlanning $planning): RedirectResponse
    {
        if ($planning->isAssigned() || $planning->isFinished()) {
            return back()->with('status', 'Item ini sudah di-assign atau sudah selesai.');
        }

        $patternBoard = CalendarEntry::runningPatternBoard();

        if (! $patternBoard) {
            return back()->withErrors(['lot_making_assignment_id' => 'Belum ada pattern yang jalan hari ini di Calendar.'])->withInput();
        }

        $validated = $request->validate([
            'lot_making_assignment_id' => [
                'required',
                Rule::exists('lot_making_assignments', 'id')->where('part_id', $planning->part_id),
            ],
            'shift' => ['required', Rule::in([1, 2])],
            // Purely a "did you actually see an open slot" confirmation —
            // the real check is the fresh freeWindows() lookup below, since
            // the schedule could have changed since the page loaded.
            'window' => ['required', 'regex:/^\d+:\d+$/'],
        ], [
            'lot_making_assignment_id.required' => 'Pilih machine.',
            'lot_making_assignment_id.exists' => 'Assignment machine tidak valid untuk part ini.',
            'window.regex' => 'Pilih slot waktu free time yang tersedia.',
        ]);

        $assignment = LotMakingAssignment::find($validated['lot_making_assignment_id']);
        $shift = (int) $validated['shift'];

        $stillFree = collect($this->scheduleBuilder->freeWindows($patternBoard))
            ->contains(fn (array $w) => $w['machine_id'] === $assignment->machine_id && $w['shift'] === $shift);

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
            'machine_id' => $assignment->machine_id,
            'part_id' => $planning->part_id,
            'shift' => $shift,
            'proses' => $assignment->proses,
        ];
        $planningData = [
            'pattern_board_id' => $patternBoard->id,
            'machine_id' => $assignment->machine_id,
            'shift' => $shift,
            'proses' => $assignment->proses,
        ];

        if ($groupItem) {
            // Genuinely registered in Kelompok Pattern for this exact
            // board+shift — its own numbers take precedence, same as a
            // normal Assignment Mesin.
            if ($assignment->proses > $groupItem->jumlah_proses) {
                return back()->withErrors([
                    'lot_making_assignment_id' => "Proses tidak boleh lebih dari jumlah proses part ini di Kelompok Pattern ({$groupItem->jumlah_proses}).",
                ])->withInput();
            }
        } else {
            // Not registered — expected for a Lot Making part, which floats
            // between boards/machines rather than sitting on one fixed line.
            // The Pattern row carries its own scheduling data instead of
            // Kelompok Pattern's, read straight from the Lot Making part
            // record so staff never has to retype any of it here.
            $lotMaking = LotMaking::where('part_id', $planning->part_id)->first();

            if ($lotMaking?->loading_time === null || $lotMaking?->jumlah_proses === null) {
                return back()->withErrors([
                    'lot_making_assignment_id' => 'Part ini belum terdaftar di Kelompok Pattern, dan Loading Time / Jumlah Proses-nya juga belum lengkap di menu Lot Making > Part — isi dulu di sana supaya baloknya bisa muncul di Andon.',
                ])->withInput();
            }

            if ($assignment->proses > $lotMaking->jumlah_proses) {
                return back()->withErrors([
                    'lot_making_assignment_id' => "Proses tidak boleh lebih dari jumlah proses part ini ({$lotMaking->jumlah_proses}).",
                ])->withInput();
            }

            $totalKanban = PatternGroupItem::calculateTotalKanban($planning->lot, $planning->part?->qty_kbn);

            $patternData += [
                'loading_time' => $lotMaking->loading_time,
                'jumlah_proses' => $lotMaking->jumlah_proses,
                'dandori' => $lotMaking->dandori ?? 0,
                'total_kanban' => $totalKanban,
            ];
            $planningData['loading_time'] = $lotMaking->loading_time;
        }

        $pattern = Pattern::create($patternData);

        $planning->update($planningData + ['pattern_id' => $pattern->id]);

        return redirect()->route('lot-making-plannings.index')
            ->with('status', 'Berhasil di-assign — part akan muncul di Andon Pattern.');
    }

    public function finish(LotMakingPlanning $planning): RedirectResponse
    {
        if ($planning->isFinished()) {
            return back()->with('status', 'Item ini sudah selesai.');
        }

        if ($planning->pattern_id !== null) {
            Pattern::whereKey($planning->pattern_id)->delete();
        }

        $planning->update(['finished_at' => now(), 'pattern_id' => null]);

        return redirect()->route('lot-making-plannings.index')
            ->with('status', 'Planning diselesaikan — slot Andon kembali FREE TIME.');
    }

    /**
     * The In Progress -> Open undo: an assignment picked in error (wrong
     * machine/shift/slot) shouldn't have to be Closed — Close is for real
     * completions and keeps a finished_at history trail, which a mis-assign
     * doesn't deserve. Cancel just deletes the Pattern row (same as Finish,
     * so the Andon slot reverts to FREE TIME) and clears every assignment
     * field on the planning row itself, dropping it straight back into Open
     * with no history trace, ready to be assigned again from scratch.
     */
    public function cancel(LotMakingPlanning $planning): RedirectResponse
    {
        if (! $planning->isAssigned() || $planning->isFinished()) {
            return back()->with('status', 'Item ini belum di-assign atau sudah selesai.');
        }

        Pattern::whereKey($planning->pattern_id)->delete();

        $planning->update([
            'pattern_id' => null,
            'pattern_board_id' => null,
            'machine_id' => null,
            'shift' => null,
            'proses' => null,
            'loading_time' => null,
        ]);

        return redirect()->route('lot-making-plannings.index')
            ->with('status', 'Assignment dibatalkan — item kembali ke Open, slot Andon kembali FREE TIME.');
    }
}
