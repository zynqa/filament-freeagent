<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Zynqa\FilamentFreeAgent\Http\Controllers\FreeAgentOAuthController;

Route::middleware(['web', 'auth'])->prefix('freeagent')->name('freeagent.')->group(function () {
    Route::get('/connect', [FreeAgentOAuthController::class, 'redirect'])->name('connect');
    Route::get('/callback', [FreeAgentOAuthController::class, 'callback'])->name('callback');
    Route::post('/disconnect', [FreeAgentOAuthController::class, 'disconnect'])->name('disconnect');
    Route::get('/invoice/{invoice}/pdf', [FreeAgentOAuthController::class, 'downloadInvoicePdf'])->name('invoice.pdf');
});
