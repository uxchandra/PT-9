<?php

namespace App\Http\Controllers;

use App\Models\Part;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PartController extends Controller
{
    private const PER_PAGE_OPTIONS = [10, 15, 25, 50, 100];

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $perPage = (int) $request->query('per_page', 15);

        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = 15;
        }

        $query = Part::query();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('part_no', 'like', "%{$search}%")
                    ->orWhere('part_no_fg', 'like', "%{$search}%")
                    ->orWhere('part_name', 'like', "%{$search}%")
                    ->orWhere('customer_code', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('job_no', 'like', "%{$search}%")
                    ->orWhere('line', 'like', "%{$search}%")
                    ->orWhere('rack_no', 'like', "%{$search}%");
            });
        }

        $parts = $query->orderByRaw('import_order is null')
            ->orderBy('import_order')
            ->orderBy('part_no')
            ->paginate($perPage)
            ->withQueryString();

        if ($request->ajax()) {
            return view('parts._results', compact('parts'));
        }

        return view('parts.index', compact('parts', 'search', 'perPage'));
    }

    public function create(): View
    {
        return view('parts.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'part_no' => ['required', 'string', 'max:255'],
        ]);

        Part::create($validated);

        return redirect()->route('parts.index')->with('status', 'Part berhasil ditambahkan.');
    }

    public function edit(Part $part): View
    {
        return view('parts.edit', compact('part'));
    }

    public function update(Request $request, Part $part): RedirectResponse
    {
        $validated = $request->validate([
            'part_no' => ['required', 'string', 'max:255'],
        ]);

        $part->update($validated);

        return redirect()->route('parts.index')->with('status', 'Part berhasil diperbarui.');
    }

    public function destroy(Part $part): RedirectResponse
    {
        $part->delete();

        return redirect()->route('parts.index')->with('status', 'Part berhasil dihapus.');
    }
}
