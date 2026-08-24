<?php

namespace App\Http\Controllers;

use App\Models\PatternBoard;
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

        $groupItems = collect();
        $patterns = collect();
        $groupItemsByPart = collect();

        if ($selectedBoard) {
            $groupItems = $selectedBoard->groupItems()->with('part')
                ->paginate(10, ['*'], 'group_items_page')->withQueryString();
            $patterns = $selectedBoard->patterns()->with(['machine', 'part'])->orderBy('machine_id')
                ->paginate(10, ['*'], 'patterns_page')->withQueryString();

            // Unpaginated lookup so the Assignment Mesin table can show "proses/jumlah_proses"
            // even when the matching Kelompok Pattern item isn't on the currently viewed page.
            $groupItemsByPart = $selectedBoard->groupItems()->get()->keyBy('part_id');
        }

        return view('pattern-boards.index', compact('patternBoards', 'selectedBoard', 'groupItems', 'patterns', 'groupItemsByPart'));
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
