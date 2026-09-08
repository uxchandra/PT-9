<?php

namespace App\Http\Controllers;

use App\Models\PatternBoard;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PatternBoardController extends Controller
{
    public function index(Request $request): View
    {
        $patternBoards = PatternBoard::withCount(['groupItems', 'patterns'])->orderBy('name')->get();

        $selectedBoard = $patternBoards->firstWhere('id', (int) $request->query('board'))
            ?? $patternBoards->first();

        $search = trim((string) $request->query('q', ''));

        $groupItems = collect();
        $patterns = collect();
        $groupItemsByPart = collect();

        if ($selectedBoard) {
            $groupItemsQuery = $selectedBoard->groupItems()->with('part');
            $patternsQuery = $selectedBoard->patterns()->with(['machine', 'part'])->orderBy('machine_id');

            if ($search !== '') {
                $groupItemsQuery->whereHas('part', fn (Builder $q) => $q->where('part_no', 'like', "%{$search}%"));
                $patternsQuery->where(function (Builder $q) use ($search) {
                    $q->whereHas('part', fn (Builder $p) => $p->where('part_no', 'like', "%{$search}%"))
                        ->orWhereHas('machine', fn (Builder $m) => $m->where('name', 'like', "%{$search}%"));
                });
            }

            // A high page size so the whole board's Kelompok Pattern list is on
            // one page — drag-and-drop reorder needs every row visible at once.
            $groupItems = $groupItemsQuery->paginate(100, ['*'], 'group_items_page')->withQueryString();
            $patterns = $patternsQuery->paginate(25, ['*'], 'patterns_page')->withQueryString();

            // Unpaginated lookup so the Assignment Mesin table can show "proses/jumlah_proses"
            // even when the matching Kelompok Pattern item isn't on the currently viewed page.
            // Keyed by part_id+shift since a part can have a separate item per shift.
            $groupItemsByPart = $selectedBoard->groupItems()->get()
                ->keyBy(fn ($item) => $item->part_id.'-'.$item->shift);
        }

        if ($request->ajax()) {
            return view('pattern-boards._results', compact('selectedBoard', 'groupItems', 'patterns', 'groupItemsByPart', 'search'));
        }

        return view('pattern-boards.index', compact('patternBoards', 'selectedBoard', 'groupItems', 'patterns', 'groupItemsByPart', 'search'));
    }

    public function create(): View
    {
        return view('pattern-boards.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:pattern_boards,name'],
        ]);

        $patternBoard = PatternBoard::create($validated);

        return redirect()->route('pattern-boards.index', ['board' => $patternBoard->id])
            ->with('status', 'Pattern board berhasil ditambahkan.');
    }

    public function edit(PatternBoard $patternBoard): View
    {
        return view('pattern-boards.edit', compact('patternBoard'));
    }

    public function update(Request $request, PatternBoard $patternBoard): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:pattern_boards,name,'.$patternBoard->id],
        ]);

        $patternBoard->update($validated);

        return redirect()->route('pattern-boards.index', ['board' => $patternBoard->id])
            ->with('status', 'Pattern board berhasil diperbarui.');
    }

    public function destroy(PatternBoard $patternBoard): RedirectResponse
    {
        $patternBoard->delete();

        return redirect()->route('pattern-boards.index')->with('status', 'Pattern board berhasil dihapus.');
    }
}
