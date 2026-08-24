<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MachineController extends Controller
{
    public function index(): View
    {
        $machines = Machine::orderBy('name')->paginate(15);

        return view('machines.index', compact('machines'));
    }

    public function create(): View
    {
        return view('machines.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        Machine::create($validated);

        return redirect()->route('machines.index')->with('status', 'Machine berhasil ditambahkan.');
    }

    public function edit(Machine $machine): View
    {
        return view('machines.edit', compact('machine'));
    }

    public function update(Request $request, Machine $machine): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $machine->update($validated);

        return redirect()->route('machines.index')->with('status', 'Machine berhasil diperbarui.');
    }

    public function destroy(Machine $machine): RedirectResponse
    {
        $machine->delete();

        return redirect()->route('machines.index')->with('status', 'Machine berhasil dihapus.');
    }
}
