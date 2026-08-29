<?php

use App\Http\Controllers\AndonController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\PartController;
use App\Http\Controllers\PartImportController;
use App\Http\Controllers\PatternBoardController;
use App\Http\Controllers\PatternController;
use App\Http\Controllers\PatternGroupItemController;
use App\Http\Controllers\PatternImportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RestController;
use App\Http\Controllers\StockPartAllController;
use App\Models\PatternBoard;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('auth.login');
});

Route::get('/andon', [AndonController::class, 'index'])->name('andon.index');
Route::get('/andon/{patternBoard}', [AndonController::class, 'show'])->name('andon.show');

Route::get('/dashboard', function () {
    $andonPreviewBoard = PatternBoard::where('name', 'A')->first()
        ?? PatternBoard::orderBy('name')->first();

    return view('dashboard', compact('andonPreviewBoard'));
})->middleware(['auth', 'can:view dashboard'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/stock-part-all', [StockPartAllController::class, 'index'])
        ->middleware('can:view stock part all')
        ->name('stock-part-all.index');

    Route::resource('machines', MachineController::class)->middleware('can:manage machines');
    Route::resource('parts', PartController::class)->middleware('can:manage parts');
    Route::resource('rests', RestController::class)->middleware('can:manage rest');

    Route::middleware('can:manage parts')->group(function () {
        Route::get('parts-import', [PartImportController::class, 'create'])->name('parts.import.create');
        Route::post('parts-import', [PartImportController::class, 'store'])->name('parts.import.store');
    });

    Route::middleware('can:manage patterns')->group(function () {
        Route::resource('pattern-boards', PatternBoardController::class)->except(['show']);
        Route::resource('pattern-boards.group-items', PatternGroupItemController::class)
            ->parameters(['group-items' => 'patternGroupItem'])
            ->except(['index', 'show'])->shallow();
        Route::resource('pattern-boards.patterns', PatternController::class)
            ->except(['index', 'show'])->shallow();

        Route::get('pattern-boards/import-template', [PatternImportController::class, 'template'])
            ->name('pattern-boards.import.template');
        Route::get('pattern-boards/{patternBoard}/import', [PatternImportController::class, 'create'])
            ->name('pattern-boards.import.create');
        Route::post('pattern-boards/{patternBoard}/import', [PatternImportController::class, 'store'])
            ->name('pattern-boards.import.store');
    });
});

require __DIR__.'/auth.php';
