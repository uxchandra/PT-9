<?php

namespace App\Http\Controllers;

use App\Imports\PartImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

class PartImportController extends Controller
{
    public function create(): View
    {
        return view('part-imports.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        $import = new PartImport;
        Excel::import($import, $request->file('file'));

        $status = "Import selesai: {$import->partsCreated} part baru, {$import->partsUpdated} part diperbarui.";

        if ($import->rowsSkipped > 0) {
            $status .= " {$import->rowsSkipped} baris dilewati (Part_No kosong).";
        }

        return redirect()->route('parts.index')->with('status', $status);
    }
}
