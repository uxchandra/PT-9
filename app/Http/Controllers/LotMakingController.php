<?php

namespace App\Http\Controllers;

use App\Models\LotMaking;
use App\Models\LotMakingAssignment;
use App\Models\Machine;
use App\Models\Part;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LotMakingController extends Controller
{
    private const PER_PAGE_OPTIONS = [10, 15, 25, 50, 100];

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $perPage = (int) $request->query('per_page', 10);

        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = 10;
        }

        $query = LotMaking::query()->with('part');

        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('row', 'like', "%{$search}%")
                    ->orWhere('kolom', 'like', "%{$search}%")
                    ->orWhereHas('part', fn (Builder $p) => $p->where('part_no', 'like', "%{$search}%"));
            });
        }

        // `no` is the manually-set position — the primary sort, so it's the
        // one field that actually decides where a row lands in the listing.
        $lotMakings = $query->orderBy('no')->orderBy('row')->orderBy('kolom')->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        // Shared by both the create modal and every row's edit modal.
        $parts = $this->partOptions();

        if ($request->ajax()) {
            return view('lot-makings._results', compact('lotMakings', 'parts'));
        }

        // "Assignment Machine" — mirrors the Pattern page's own Assignment
        // Mesin table, just for Lot Making parts. Plainly paginated (no AJAX
        // search) since it's a much smaller, slower-changing list.
        $assignments = LotMakingAssignment::with(['part.lotMaking', 'machine'])
            ->orderBy('id', 'desc')
            ->paginate(10, ['*'], 'assignments_page')
            ->withQueryString();

        // Shared by the create modal and every row's edit modal — see
        // LotMakingAssignmentController.
        $assignmentParts = Part::whereIn('id', LotMaking::query()->select('part_id'))->orderBy('part_no')->get();
        $assignmentMachines = Machine::orderBy('name')->get();

        return view('lot-makings.index', compact('lotMakings', 'search', 'perPage', 'parts', 'assignments', 'assignmentParts', 'assignmentMachines'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $pullingCommand = $validated['pulling_command'] ?? null;
        $validated['pulling_command_set_at'] = $pullingCommand !== null ? now() : null;

        LotMaking::create($validated);

        return redirect()->route('lot-makings.index')->with('status', 'Lot Making berhasil ditambahkan.');
    }

    public function update(Request $request, LotMaking $lotMaking): RedirectResponse
    {
        $validated = $this->validated($request);
        $pullingCommand = $validated['pulling_command'] ?? null;

        // Only bump the "set at" timestamp when the value actually changes —
        // saving the rest of the form (Row, Kolom, ...) must not silently
        // reset an unrelated Perintah Pulling baseline (see LotMakingPull::
        // demandRow()).
        if ($pullingCommand !== $lotMaking->pulling_command) {
            $validated['pulling_command'] = $pullingCommand;
            $validated['pulling_command_set_at'] = $pullingCommand !== null ? now() : null;
        }

        $lotMaking->update($validated);

        return redirect()->route('lot-makings.index')->with('status', 'Lot Making berhasil diperbarui.');
    }

    public function destroy(LotMaking $lotMaking): RedirectResponse
    {
        $lotMaking->delete();

        return redirect()->route('lot-makings.index')->with('status', 'Lot Making berhasil dihapus.');
    }

    private function partOptions()
    {
        return Part::orderBy('part_no')->get(['id', 'part_no']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'no' => ['nullable', 'integer', 'min:0'],
            'part_id' => ['required', 'exists:parts,id'],
            'level' => ['nullable', Rule::in(array_keys(LotMaking::LEVELS))],
            // The Finish Goods target as of right now — see LotMakingPull::
            // demandRow() for how it seeds/corrects the rolling stock-based
            // count. Editable any time, not just once.
            'pulling_command' => ['nullable', 'integer', 'min:0'],
            'row' => ['nullable', 'string', 'max:50'],
            'kolom' => ['nullable', 'string', 'max:50'],
            'lot_produksi' => ['nullable', 'integer', 'min:0'],
            // A divisor — 0 would make avg_slot/slot_fix meaningless.
            'slot' => ['nullable', 'integer', 'min:1'],
            // Read by Lot Making Planning when assigning this part to a
            // machine that isn't already registered in Kelompok Pattern.
            'loading_time' => ['nullable', 'integer', 'min:0'],
            'dandori' => ['nullable', 'integer', 'min:0'],
            // The denominator in the "2/3" label on the Andon block — total
            // process steps this part goes through.
            'jumlah_proses' => ['nullable', 'integer', 'min:1'],
        ], [], ['part_id' => 'part']);
    }
}
