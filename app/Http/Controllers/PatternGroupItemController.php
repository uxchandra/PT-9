<?php

namespace App\Http\Controllers;

use App\Models\Part;
use App\Models\PatternBoard;
use App\Models\PatternGroupItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PatternGroupItemController extends Controller
{
    public function create(PatternBoard $patternBoard): View
    {
        $parts = Part::orderBy('part_no')->get();

        return view('pattern-group-items.create', compact('patternBoard', 'parts'));
    }

    public function store(Request $request, PatternBoard $patternBoard): RedirectResponse
    {
        $validated = $this->validated($request, $patternBoard);
        $validated['pattern_board_id'] = $patternBoard->id;
        $validated['total_kanban'] = PatternGroupItem::calculateTotalKanban(
            $validated['lot'], Part::find($validated['part_id'])->qty_kbn
        );

        PatternGroupItem::create($validated);

        return redirect()->route('pattern-boards.index', ['board' => $patternBoard->id])
            ->with('status', 'Item kelompok pattern berhasil ditambahkan.');
    }

    public function edit(PatternGroupItem $patternGroupItem): View
    {
        $parts = Part::orderBy('part_no')->get();

        return view('pattern-group-items.edit', compact('patternGroupItem', 'parts'));
    }

    public function update(Request $request, PatternGroupItem $patternGroupItem): RedirectResponse
    {
        $validated = $this->validated($request, $patternGroupItem->patternBoard, $patternGroupItem);
        $validated['total_kanban'] = PatternGroupItem::calculateTotalKanban(
            $validated['lot'], Part::find($validated['part_id'])->qty_kbn
        );

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

    /**
     * Persist a drag-and-drop reorder of this board's Kelompok Pattern rows.
     * `order` is the desired sequence of group-item ids; every row on the
     * board is renumbered 1..N (ids not in `order` — e.g. from another page —
     * keep their relative order after the listed ones) so `urutan` never
     * collides. That `urutan` is what drives each machine's block sequence on
     * the Andon board.
     */
    public function reorder(Request $request, PatternBoard $patternBoard): JsonResponse
    {
        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $ownIds = $patternBoard->groupItems()->orderBy('urutan')->pluck('id')->all();
        $listed = array_values(array_intersect($validated['order'], $ownIds));
        $final = array_merge($listed, array_values(array_diff($ownIds, $listed)));

        DB::transaction(function () use ($final) {
            foreach ($final as $index => $id) {
                PatternGroupItem::whereKey($id)->update(['urutan' => $index + 1]);
            }
        });

        return response()->json(['ok' => true, 'count' => count($final)]);
    }

    private function validated(Request $request, PatternBoard $patternBoard, ?PatternGroupItem $ignore = null): array
    {
        return $request->validate([
            'part_id' => [
                'required',
                'exists:parts,id',
                Rule::unique('pattern_group_items', 'part_id')
                    ->where(fn ($query) => $query
                        ->where('pattern_board_id', $patternBoard->id)
                        ->where('shift', $request->input('shift')))
                    ->ignore($ignore?->id),
            ],
            'shift' => ['required', Rule::in([1, 2])],
            'urutan' => ['required', 'integer', 'min:1'],
            'lot' => ['required', 'integer', 'min:0'],
            'loading_time' => ['required', 'integer', 'min:0'],
            'jumlah_proses' => ['required', 'integer', 'min:1'],
            'dandori' => ['required', 'integer', 'min:0'],
        ], [
            'part_id.unique' => 'Part ini sudah terdaftar di Kelompok Pattern untuk shift yang dipilih pada board ini.',
        ]);
    }
}
