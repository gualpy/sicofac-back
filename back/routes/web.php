<?php

use App\Http\Controllers\Web\PanelHealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->prefix('panel')->group(function () {
    Route::get('/', function () {
        return response()->json([
            'message' => 'Panel protegido por autenticacion de sesion.',
        ]);
    })->name('panel.home');

    Route::get('/health', PanelHealthController::class)->name('panel.health');
});
