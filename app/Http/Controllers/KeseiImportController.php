<?php

namespace App\Http\Controllers;

use App\Exports\KeseiImportTemplateExport;
use App\Imports\KeseiImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class KeseiImportController extends Controller
{
    public function create(): View
    {
        return view('kesei-imports.create');
    }

    public function template(): BinaryFileResponse
    {
        return Excel::download(new KeseiImportTemplateExport, 'template-import-kesei.xlsx');
    }

    public function store(Request $request): RedirectResponse
    {
        // extensions (client-provided) rather than mimes (content-sniffed): a
        // .csv from Excel is often sniffed as text/plain and rejected by mimes.
        $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv'],
        ]);

        $import = new KeseiImport;
        Excel::import($import, $request->file('file'));

        $status = "Import selesai: {$import->added} part ditambahkan, {$import->updated} sumber stok diperbarui, {$import->skippedDuplicate} duplikat dilewati.";

        if ($import->partsCreated > 0) {
            $status .= " {$import->partsCreated} part baru dibuat di Part List.";
        }

        if ($import->skippedEmpty > 0) {
            $status .= " {$import->skippedEmpty} baris kosong dilewati.";
        }

        return redirect()->route('kesei.index')->with('status', $status);
    }
}
