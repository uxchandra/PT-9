<?php

namespace App\Http\Controllers;

use App\Models\KeseiPart;
use App\Models\KeseiPartClosing;
use App\Models\Part;
use App\Models\PatternBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class KeseiPartController extends Controller
{
    public function index(): View
    {
        $keseiParts = KeseiPart::with(['part', 'patternBoards', 'closings'])->orderBy('urutan')->orderBy('id')->get();

        $availableParts = Part::whereNotIn('id', $keseiParts->pluck('part_id'))
            ->orderBy('part_no')
            ->get(['id', 'part_no']);

        $patternBoards = PatternBoard::orderBy('name')->get(['id', 'name']);

        return view('kesei.index', compact('keseiParts', 'availableParts', 'patternBoards'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'part_id' => ['required', 'exists:parts,id', 'unique:kesei_parts,part_id'],
            'stock_source' => ['nullable', 'string', 'max:255'],
            'level' => ['nullable', 'string', 'max:50'],
            'closing_time' => ['nullable', 'date_format:H:i'],
            'closing_mode' => ['nullable', Rule::in(KeseiPart::CLOSING_MODES)],
            'pattern_board_ids' => ['nullable', 'array'],
            'pattern_board_ids.*' => ['integer', 'exists:pattern_boards,id'],
        ], [], ['part_id' => 'part']);

        $keseiPart = KeseiPart::create([
            'part_id' => $validated['part_id'],
            'stock_source' => $this->cleanStockSource($validated['stock_source'] ?? null),
            'level' => $validated['level'] ?? null,
            'urutan' => (int) KeseiPart::max('urutan') + 1,
        ]);

        if (! empty($validated['closing_time'])) {
            $keseiPart->addClosing($validated['closing_time'], $validated['closing_mode'] ?? KeseiPart::CLOSING_PRE_RUN);
        }

        $keseiPart->patternBoards()->sync($validated['pattern_board_ids'] ?? []);

        return redirect()->route('kesei.index')->with('status', 'Part berhasil ditambahkan ke Kesei.');
    }

    public function update(Request $request, KeseiPart $keseiPart): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'stock_source' => ['sometimes', 'nullable', 'string', 'max:255'],
            'level' => ['sometimes', 'nullable', 'string', 'max:50'],
            // The Finish Goods target as of right now — see KeseiPull::
            // demandRow() for how it seeds/corrects the rolling stock-based
            // count. Editable any time, not just once.
            'pulling_command' => ['sometimes', 'nullable', 'integer', 'min:0'],
            // Lead Time per Kanban (minutes) — how long a heijunka-paced
            // release queue takes to mature one kanban. Blank/0 = no pacing
            // (see KeseiBoard::heijunkaRelease()).
            'lt_per_kbn' => ['sometimes', 'nullable', 'integer', 'min:0'],
            // Material (RM): the raw material this part is built from — a
            // free-form part_no + level, not tied to the Part List. Its
            // stock is looked up by part_no for the Andon Kesei Closing
            // Time panel's Ready/Empty status.
            'material_part_no' => ['sometimes', 'nullable', 'string', 'max:255'],
            'material_level' => ['sometimes', 'nullable', 'string', 'max:50'],
            // The full set of closing times for this row — sending it replaces
            // whatever was there before (same "sync" shape as pattern_board_ids).
            'closings' => ['sometimes', 'array'],
            'closings.*.closing_time' => ['required', 'date_format:H:i'],
            'closings.*.closing_mode' => ['nullable', Rule::in(KeseiPart::CLOSING_MODES)],
            'pattern_board_ids' => ['sometimes', 'nullable', 'array'],
            'pattern_board_ids.*' => ['integer', 'exists:pattern_boards,id'],
        ]);

        // Each inline field auto-saves on its own change event — only touch the
        // field actually sent.
        $updates = [];
        if (array_key_exists('stock_source', $validated)) {
            $updates['stock_source'] = $this->cleanStockSource($validated['stock_source']);
        }
        if (array_key_exists('level', $validated)) {
            $updates['level'] = $validated['level'] ?: null;
        }
        if (array_key_exists('pulling_command', $validated)) {
            // Clearing it back to blank drops the set-at too, so it stops
            // contributing anything rather than lingering with no value.
            $updates['pulling_command'] = $validated['pulling_command'];
            $updates['pulling_command_set_at'] = $validated['pulling_command'] !== null ? now() : null;
        }
        if (array_key_exists('lt_per_kbn', $validated)) {
            $updates['lt_per_kbn'] = $validated['lt_per_kbn'];
        }
        if (array_key_exists('material_part_no', $validated)) {
            $updates['material_part_no'] = $validated['material_part_no'] ?: null;
        }
        if (array_key_exists('material_level', $validated)) {
            $updates['material_level'] = $validated['material_level'] ?: null;
        }
        if ($updates !== []) {
            $keseiPart->update($updates);
        }

        if (array_key_exists('closings', $validated)) {
            $keseiPart->syncClosings(collect($validated['closings'])
                ->map(fn (array $c) => ['time' => $c['closing_time'], 'mode' => $c['closing_mode'] ?? KeseiPart::CLOSING_PRE_RUN])
                ->all());
        }

        if (array_key_exists('pattern_board_ids', $validated)) {
            $keseiPart->patternBoards()->sync($validated['pattern_board_ids'] ?? []);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'stock_source' => $keseiPart->stock_source,
                'level' => $keseiPart->level,
                'pulling_command' => $keseiPart->pulling_command,
                'lt_per_kbn' => $keseiPart->lt_per_kbn,
                'material_part_no' => $keseiPart->material_part_no,
                'material_level' => $keseiPart->material_level,
                'closings' => $keseiPart->closings->map(fn (KeseiPartClosing $c) => [
                    'closing_time' => $c->closing_time->format('H:i'),
                    'closing_mode' => $c->closing_mode,
                ])->all(),
                'closing_label' => $keseiPart->closingTimesLabel(),
                'pattern_board_ids' => $keseiPart->patternBoards()->pluck('pattern_boards.id'),
            ]);
        }

        return redirect()->route('kesei.index')->with('status', 'Data Kesei diperbarui.');
    }

    /**
     * Normalise the comma-separated part_no list: trim each entry, drop blanks
     * and duplicates, or null when nothing is left (falls back to own part_no).
     */
    private function cleanStockSource(?string $raw): ?string
    {
        $parts = collect(explode(',', (string) $raw))
            ->map(fn ($part) => trim($part))
            ->filter()
            ->unique()
            ->values();

        return $parts->isEmpty() ? null : $parts->implode(', ');
    }

    public function destroy(KeseiPart $keseiPart): RedirectResponse
    {
        $keseiPart->delete();

        return redirect()->route('kesei.index')->with('status', 'Part berhasil dihapus dari Kesei.');
    }

    /**
     * Persist a drag-and-drop reorder. `order` is the desired sequence of
     * kesei_part ids; every row is renumbered 1..N (ids left out keep their
     * relative order after the listed ones) so `urutan` never collides.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $allIds = KeseiPart::orderBy('urutan')->orderBy('id')->pluck('id')->all();
        $listed = array_values(array_intersect($validated['order'], $allIds));
        $final = array_merge($listed, array_values(array_diff($allIds, $listed)));

        DB::transaction(function () use ($final) {
            foreach ($final as $index => $id) {
                KeseiPart::whereKey($id)->update(['urutan' => $index + 1]);
            }
        });

        return response()->json(['ok' => true, 'count' => count($final)]);
    }
}
