<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\UnitController;
use App\Http\Controllers\Api\V1\InvoiceController;


Route::prefix('v1')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', fn (Request $request) => $request->user());
        Route::get('/committee-only', fn () => ['ok' => true])->middleware('role:committee');
        Route::apiResource('units', UnitController::class)->middleware('role:committee');
        Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('role:resident,committee');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('role:resident,committee');
    });
});
