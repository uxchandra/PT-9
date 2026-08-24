<?php

namespace App\Http\Controllers;

use App\Exports\PatternImportTemplateExport;
use App\Imports\PatternImport;
use App\Models\PatternBoard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PatternImportController extends Controller
{
    public function create(PatternBoard $patternBoard): View
    {
        return view('pattern-imports.create', compact('patternBoard'));
    }

    public function template(): BinaryFileResponse
    {
        return Excel::download(new PatternImportTemplateExport, 'template-import-pattern.xlsx');
    }

    public function store(Request $request, PatternBoard $patternBoard): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        $import = new PatternImport($patternBoard);
        Excel::import($import, $request->file('file'));

        $status = "Import selesai: {$import->machinesCreated} machine baru, {$import->partsCreated} part baru, "
            ."{$import->groupItemsCreated} item Kelompok Pattern baru, {$import->assignmentsSaved} assignment mesin disimpan.";

        if ($import->rowsSkipped > 0) {
            $status .= " {$import->rowsSkipped} baris dilewati (Machine/Item kosong).";
        }

        return redirect()->route('pattern-boards.index', ['board' => $patternBoard->id])
            ->with('status', $status)
            ->with('importMismatches', $import->mismatches);
    }
}
