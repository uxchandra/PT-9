<?php

namespace App\Http\Controllers;

use App\Models\Rest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RestController extends Controller
{
    public function index(): View
    {
        $rests = Rest::orderBy('start_time')->paginate(15);

        return view('rests.index', compact('rests'));
    }

    public function create(): View
    {
        return view('rests.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ]);

        Rest::create($validated);

        return redirect()->route('rests.index')->with('status', 'Rest berhasil ditambahkan.');
    }

    public function edit(Rest $rest): View
    {
        return view('rests.edit', compact('rest'));
    }

    public function update(Request $request, Rest $rest): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ]);

        $rest->update($validated);

        return redirect()->route('rests.index')->with('status', 'Rest berhasil diperbarui.');
    }

    public function destroy(Rest $rest): RedirectResponse
    {
        $rest->delete();

        return redirect()->route('rests.index')->with('status', 'Rest berhasil dihapus.');
    }
}
