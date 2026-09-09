<?php

use App\Http\Controllers\AndonController;
use App\Http\Controllers\AndonKeseiController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\KeseiImportController;
use App\Http\Controllers\KeseiPartController;
use App\Http\Controllers\LotMakingController;
use App\Http\Controllers\LotMakingImportController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\PartController;
use App\Http\Controllers\PartImportController;
use App\Http\Controllers\PatternBoardController;
use App\Http\Controllers\PatternController;
use App\Http\Controllers\PatternGroupItemController;
use App\Http\Controllers\PatternImportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RestController;
use App\Http\Controllers\ScannerController;
use App\Http\Controllers\StockPartAllController;
use App\Http\Controllers\StockSnapshotController;
use App\Models\PatternBoard;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('auth.login');
});

Route::get('/andon', [AndonController::class, 'index'])->name('andon.index');
Route::get('/andon-kesei', [AndonKeseiController::class, 'show'])->name('andon-kesei.show');
Route::get('/andon-kesei-scan', [AndonKeseiController::class, 'showScan'])->name('andon-kesei.scan');
Route::get('/andon/{patternBoard}', [AndonController::class, 'show'])->name('andon.show');
Route::get('/andon-planning/{patternBoard}', [AndonController::class, 'planning'])->name('andon.planning');
Route::post('/andon-planning/pattern/{pattern}/actual', [AndonController::class, 'updateActual'])->name('andon.planning.actual.update');

Route::get('/dashboard', function () {
    $user = auth()->user();

    // Scanner operators have no dashboard access — send them to their own
    // screen instead of a dead-end 403 (login always aims here first).
    if (! $user->can('view dashboard')) {
        abort_unless($user->can('use scanner'), 403);

        return redirect()->route('scanner.dashboard');
    }

    $andonPreviewBoard = PatternBoard::where('name', 'A')->first()
        ?? PatternBoard::orderBy('name')->first();

    return view('dashboard', compact('andonPreviewBoard'));
})->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/stock-part-all', [StockPartAllController::class, 'index'])
        ->middleware('can:view stock part all')
        ->name('stock-part-all.index');

    Route::get('/stock-snapshots', [StockSnapshotController::class, 'index'])
        ->middleware('can:view stock snapshot')
        ->name('stock-snapshots.index');

    // Handheld barcode-scanner UI (SEUIC AutoID Q9).
    Route::middleware('can:use scanner')->prefix('scanner')->name('scanner.')->group(function () {
        Route::get('/', [ScannerController::class, 'dashboard'])->name('dashboard');
        Route::get('/{location}', [ScannerController::class, 'location'])->name('location');
        Route::post('/{location}/scan', [ScannerController::class, 'scan'])->name('scan');
    });

    Route::middleware('can:manage planning')->group(function () {
        Route::get('/planning', [AndonController::class, 'planningBoards'])->name('planning.index');
        Route::get('/planning/{patternBoard}', [AndonController::class, 'planningTable'])->name('planning.table');
    });

    Route::resource('machines', MachineController::class)->middleware('can:manage machines');
    Route::resource('parts', PartController::class)->middleware('can:manage parts');
    Route::resource('rests', RestController::class)->middleware('can:manage rest');

    Route::middleware('can:manage parts')->group(function () {
        Route::get('parts-import', [PartImportController::class, 'create'])->name('parts.import.create');
        Route::post('parts-import', [PartImportController::class, 'store'])->name('parts.import.store');
    });

    Route::middleware('can:manage kesei')->group(function () {
        Route::get('kesei', [KeseiPartController::class, 'index'])->name('kesei.index');
        Route::post('kesei', [KeseiPartController::class, 'store'])->name('kesei.store');
        Route::post('kesei/reorder', [KeseiPartController::class, 'reorder'])->name('kesei.reorder');
        Route::get('kesei/import-template', [KeseiImportController::class, 'template'])->name('kesei.import.template');
        Route::get('kesei/import', [KeseiImportController::class, 'create'])->name('kesei.import.create');
        Route::post('kesei/import', [KeseiImportController::class, 'store'])->name('kesei.import.store');
        Route::patch('kesei/{keseiPart}', [KeseiPartController::class, 'update'])->name('kesei.update');
        Route::delete('kesei/{keseiPart}', [KeseiPartController::class, 'destroy'])->name('kesei.destroy');
    });

    Route::middleware('can:manage lot making')->group(function () {
        Route::get('lot-makings/import-template', [LotMakingImportController::class, 'template'])->name('lot-makings.import.template');
        Route::get('lot-makings/import', [LotMakingImportController::class, 'create'])->name('lot-makings.import.create');
        Route::post('lot-makings/import', [LotMakingImportController::class, 'store'])->name('lot-makings.import.store');

        Route::resource('lot-makings', LotMakingController::class)->except(['show']);
    });

    Route::middleware('can:manage patterns')->group(function () {
        Route::get('calendar', [CalendarController::class, 'index'])->name('calendar.index');
        Route::post('calendar', [CalendarController::class, 'store'])->name('calendar.store');
        Route::delete('calendar/{calendarEntry}', [CalendarController::class, 'destroy'])->name('calendar.destroy');

        Route::resource('pattern-boards', PatternBoardController::class)->except(['show']);
        Route::post('pattern-boards/{patternBoard}/group-items/reorder', [PatternGroupItemController::class, 'reorder'])
            ->name('pattern-boards.group-items.reorder');
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
