<?php

namespace App\Http\Controllers;

use App\Models\Part;
use App\Models\PatternBoard;
use App\Models\PatternGroupItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PatternGroupItemController extends Controller
{
    public function create(PatternBoard $patternBoard): View
    {
        $parts = Part::orderBy('name')->get();

        return view('pattern-group-items.create', compact('patternBoard', 'parts'));
    }

    public function store(Request $request, PatternBoard $patternBoard): RedirectResponse
    {
        $validated = $this->validated($request, $patternBoard);
        $validated['pattern_board_id'] = $patternBoard->id;

        PatternGroupItem::create($validated);

        return redirect()->route('pattern-boards.index', ['board' => $patternBoard->id])
            ->with('status', 'Item kelompok pattern berhasil ditambahkan.');
    }

    public function edit(PatternGroupItem $patternGroupItem): View
    {
        $parts = Part::orderBy('name')->get();

        return view('pattern-group-items.edit', compact('patternGroupItem', 'parts'));
    }

    public function update(Request $request, PatternGroupItem $patternGroupItem): RedirectResponse
    {
        $validated = $this->validated($request, $patternGroupItem->patternBoard, $patternGroupItem);

        $patternGroupItem->update($validated);

        return redirect()->route('pattern-boards.index', ['board' => $patternGroupItem->pattern_board_id])
            ->with('status', 'Item kelompok pattern berhasil diperbarui.');
    }

    public function destroy(PatternGroupItem $patternGroupItem): RedirectResponse
    {
        $boardId = $patternGroupItem->pattern_board_id;

        $patternGroupItem->delete();

        return redirect()->route('pattern-boards.index', ['board' => $boardId])
            ->with('status', 'Item kelompok pattern berhasil dihapus.');
    }

    private function validated(Request $request, PatternBoard $patternBoard, ?PatternGroupItem $ignore = null): array
    {
        return $request->validate([
            'part_id' => [
                'required',
                'exists:parts,id',
                'unique:pattern_group_items,part_id,'.($ignore?->id ?? 'NULL').',id,pattern_board_id,'.$patternBoard->id,
            ],
            'urutan' => ['required', 'integer', 'min:1'],
            'loading_time' => ['required', 'integer', 'min:0'],
            'jumlah_proses' => ['required', 'integer', 'min:1'],
            'total_kanban' => ['required', 'integer', 'min:0'],
            'dandori' => ['required', 'integer', 'min:0'],
        ]);
    }
}
