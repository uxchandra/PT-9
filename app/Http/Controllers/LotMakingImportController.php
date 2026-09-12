<?php

namespace App\Http\Controllers;

use App\Exports\LotMakingImportTemplateExport;
use App\Imports\LotMakingImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The import form itself lives in a modal on the Lot Making index page (see
 * lot-makings/_import-modal.blade.php) — this controller only serves the
 * template download and the submit endpoint. A validation failure on store()
 * falls back to Laravel's default redirect()->back(), which lands right back
 * on the index page with the modal auto-reopened (see index.blade.php).
 */
class LotMakingImportController extends Controller
{
    public function template(): BinaryFileResponse
    {
        return Excel::download(new LotMakingImportTemplateExport, 'template-import-lot-making.xlsx');
    }

    public function store(Request $request): RedirectResponse
    {
        // extensions (client-provided) rather than mimes (content-sniffed): a
        // .csv exported from Excel is often sniffed as text/plain and would be
        // rejected by mimes:csv.
        $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv'],
        ]);

        $import = new LotMakingImport;
        Excel::import($import, $request->file('file'));

        $status = "Import selesai: {$import->created} data baru, {$import->updated} data diperbarui, {$import->partsCreated} part baru.";

        if ($import->rowsSkipped > 0) {
            $status .= " {$import->rowsSkipped} baris dilewati (part_no kosong).";
        }

        return redirect()->route('lot-makings.index')->with('status', $status);
    }
}
