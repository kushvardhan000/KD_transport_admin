<?php

use App\Http\Controllers\LogsheetController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'no.cache', 'role:super_admin'])->group(function () {
    Route::get('/logsheets', [LogsheetController::class, 'index'])->name('logsheets.index');
    Route::get('/logsheets/records', [LogsheetController::class, 'records'])->name('logsheets.records');
    Route::get('/logsheets/export', [LogsheetController::class, 'export'])->name('logsheets.export');
    Route::get('/logsheets/imports/{import}', [LogsheetController::class, 'importShow'])->name('logsheets.imports.show');
    Route::get('/logsheets/imports/{import}/export', [LogsheetController::class, 'importExport'])->name('logsheets.imports.export');
    Route::post('/logsheets', [LogsheetController::class, 'store'])->name('logsheets.store');
    Route::post('/logsheets/clear/preview', [LogsheetController::class, 'clearPreview'])->name('logsheets.clear.preview');
    Route::post('/logsheets/clear/bulk', [LogsheetController::class, 'clearBulk'])->name('logsheets.clear.bulk');
    Route::post('/logsheets/clear', [LogsheetController::class, 'clearLogsheet'])->name('logsheets.clear');
    Route::get('/logsheets/{logsheet}', [LogsheetController::class, 'show'])->name('logsheets.show');
    Route::delete('/logsheets/{logsheet}', [LogsheetController::class, 'destroy'])->name('logsheets.destroy');
    Route::delete('/logsheets/imports/{import}', [LogsheetController::class, 'destroyImport'])->name('logsheets.imports.destroy');
});