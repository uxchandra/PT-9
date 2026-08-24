<?php

namespace App\Http\Controllers;

use App\Models\Part;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PartController extends Controller
{
    public function index(): View
    {
        $parts = Part::orderBy('name')->paginate(15);

        return view('parts.index', compact('parts'));
    }

    public function create(): View
    {
        return view('parts.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
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
            'name' => ['required', 'string', 'max:255'],
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
