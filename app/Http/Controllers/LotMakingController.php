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
                $q->where('assy_part_code', 'like', "%{$search}%")
                    ->orWhere('next_process', 'like', "%{$search}%")
                    ->orWhereHas('part', fn (Builder $p) => $p->where('part_no', 'like', "%{$search}%"));
            });
        }

        $lotMakings = $query->orderBy('assy_part_code')->orderBy('id')
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
            'assy_part_code' => ['required', 'string', 'max:255'],
            'part_id' => ['required', 'exists:parts,id'],
            'qty_kanban' => ['nullable', 'integer', 'min:0'],
            'lot' => ['nullable', 'integer', 'min:0'],
            'loading_time' => ['nullable', 'integer', 'min:0'],
            'dandori' => ['nullable', 'integer', 'min:0'],
            'lot_produksi' => ['nullable', 'integer', 'min:0'],
            'safety_stock' => ['nullable', 'integer', 'min:0'],
            'total_kanban_edar' => ['nullable', 'integer', 'min:0'],
            'next_process' => ['nullable', 'string', 'max:255'],
            'kapasitas_rak' => ['nullable', 'integer', 'min:0'],
        ], [], [
            'assy_part_code' => 'assy part code',
            'part_id' => 'part',
        ]);
    }
}
