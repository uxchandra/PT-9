<?php

namespace App\Http\Controllers;

use App\Models\LotMaking;
use App\Models\LotMakingAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Assignment Machine" — see the lot_making_assignments migration. Shown as
 * a second table under the Lot Making listing, mirroring Assignment Mesin on
 * the Pattern page; create and edit both happen in modals there (see
 * lot-makings/_assignment-create-modal.blade.php and
 * _assignment-edit-modal.blade.php), same as Pattern's own edit modal.
 */
class LotMakingAssignmentController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        LotMakingAssignment::create($this->validated($request));

        return redirect()->route('lot-makings.index')->with('status', 'Assignment machine berhasil ditambahkan.');
    }

    public function update(Request $request, LotMakingAssignment $lotMakingAssignment): RedirectResponse
    {
        $lotMakingAssignment->update($this->validated($request, $lotMakingAssignment));

        return redirect()->route('lot-makings.index')->with('status', 'Assignment machine berhasil diperbarui.');
    }

    public function destroy(LotMakingAssignment $lotMakingAssignment): RedirectResponse
    {
        $lotMakingAssignment->delete();

        return redirect()->route('lot-makings.index')->with('status', 'Assignment machine berhasil dihapus.');
    }

    private function validated(Request $request, ?LotMakingAssignment $ignore = null): array
    {
        return $request->validate([
            'part_id' => [
                'required',
                'exists:parts,id',
                Rule::exists('lot_makings', 'part_id'),
            ],
            'machine_id' => ['required', 'exists:machines,id'],
            'proses' => [
                'required',
                'integer',
                'min:1',
                Rule::unique('lot_making_assignments', 'proses')
                    ->where(fn ($query) => $query->where('part_id', $request->input('part_id')))
                    ->ignore($ignore?->id),
                function ($attribute, $value, $fail) use ($request) {
                    $lotMaking = LotMaking::where('part_id', $request->input('part_id'))->first();

                    if ($lotMaking?->jumlah_proses !== null && $value > $lotMaking->jumlah_proses) {
                        $fail("Proses tidak boleh lebih dari jumlah proses part ini ({$lotMaking->jumlah_proses}).");
                    }
                },
            ],
        ], [
            'part_id.exists' => 'Part ini belum terdaftar di Lot Making.',
            'proses.unique' => 'Proses ini sudah terdaftar untuk part tersebut — edit yang sudah ada, jangan duplikat.',
        ]);
    }
}
