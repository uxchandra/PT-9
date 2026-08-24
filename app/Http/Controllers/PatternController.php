<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\Part;
use App\Models\Pattern;
use App\Models\PatternBoard;
use App\Models\PatternGroupItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PatternController extends Controller
{
    public function create(PatternBoard $patternBoard): View
    {
        $machines = Machine::orderBy('name')->get();
        $parts = $this->availableParts($patternBoard);

        return view('patterns.create', compact('patternBoard', 'machines', 'parts'));
    }

    public function store(Request $request, PatternBoard $patternBoard): RedirectResponse
    {
        $validated = $this->validated($request, $patternBoard);
        $validated['pattern_board_id'] = $patternBoard->id;

        Pattern::create($validated);

        return redirect()->route('pattern-boards.index', ['board' => $patternBoard->id])
            ->with('status', 'Assignment mesin berhasil ditambahkan.');
    }

    public function edit(Pattern $pattern): View
    {
        $machines = Machine::orderBy('name')->get();
        $parts = $this->availableParts($pattern->patternBoard);

        return view('patterns.edit', compact('pattern', 'machines', 'parts'));
    }

    public function update(Request $request, Pattern $pattern): RedirectResponse
    {
        $validated = $this->validated($request, $pattern->patternBoard);

        $pattern->update($validated);

        return redirect()->route('pattern-boards.index', ['board' => $pattern->pattern_board_id])
            ->with('status', 'Assignment mesin berhasil diperbarui.');
    }

    public function destroy(Pattern $pattern): RedirectResponse
    {
        $boardId = $pattern->pattern_board_id;

        $pattern->delete();

        return redirect()->route('pattern-boards.index', ['board' => $boardId])
            ->with('status', 'Assignment mesin berhasil dihapus.');
    }

    private function availableParts(PatternBoard $patternBoard)
    {
        return Part::whereIn('id', $patternBoard->groupItems()->pluck('part_id'))->orderBy('name')->get();
    }

    private function validated(Request $request, PatternBoard $patternBoard): array
    {
        return $request->validate([
            'machine_id' => ['required', 'exists:machines,id'],
            'part_id' => [
                'required',
                'exists:parts,id',
                Rule::exists('pattern_group_items', 'part_id')
                    ->where('pattern_board_id', $patternBoard->id),
            ],
            'proses' => [
                'required',
                'integer',
                'min:1',
                function ($attribute, $value, $fail) use ($request, $patternBoard) {
                    $groupItem = PatternGroupItem::where('pattern_board_id', $patternBoard->id)
                        ->where('part_id', $request->input('part_id'))
                        ->first();

                    if ($groupItem && $value > $groupItem->jumlah_proses) {
                        $fail("Proses tidak boleh lebih dari jumlah proses part ini ({$groupItem->jumlah_proses}).");
                    }
                },
            ],
        ], [
            'part_id.exists' => 'Part ini belum terdaftar di Kelompok Pattern untuk board ini.',
        ]);
    }
}
