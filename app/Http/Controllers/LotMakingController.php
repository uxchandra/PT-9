<?php

namespace App\Http\Controllers;

use App\Models\LotMaking;
use App\Models\Part;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LotMakingController extends Controller
{
    private const PER_PAGE_OPTIONS = [10, 15, 25, 50, 100];

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $perPage = (int) $request->query('per_page', 15);

        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = 15;
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

        if ($request->ajax()) {
            return view('lot-makings._results', compact('lotMakings'));
        }

        return view('lot-makings.index', compact('lotMakings', 'search', 'perPage'));
    }

    public function create(): View
    {
        return view('lot-makings.create', ['parts' => $this->partOptions()]);
    }

    public function store(Request $request): RedirectResponse
    {
        LotMaking::create($this->validated($request));

        return redirect()->route('lot-makings.index')->with('status', 'Lot Making berhasil ditambahkan.');
    }

    public function edit(LotMaking $lotMaking): View
    {
        return view('lot-makings.edit', ['lotMaking' => $lotMaking, 'parts' => $this->partOptions()]);
    }

    public function update(Request $request, LotMaking $lotMaking): RedirectResponse
    {
        $lotMaking->update($this->validated($request));

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
            'row' => ['nullable', 'string', 'max:50'],
            'kolom' => ['nullable', 'string', 'max:50'],
            'lot_produksi' => ['nullable', 'integer', 'min:0'],
            // A divisor — 0 would make avg_slot/slot_fix meaningless.
            'slot' => ['nullable', 'integer', 'min:1'],
        ], [], ['part_id' => 'part']);
    }
}
