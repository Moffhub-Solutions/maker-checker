<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Moffhub\MakerChecker\Http\Controllers\MakerCheckerConfigController;
use Moffhub\MakerChecker\Http\Controllers\MakerCheckerRequestController;

/*
|--------------------------------------------------------------------------
| Maker-Checker API Routes
|--------------------------------------------------------------------------
|
| Include these routes in your application by adding this to your routes file:
|
| Route::middleware(['api', 'auth:sanctum'])->group(function () {
|     require __DIR__ . '/vendor/moffhub/maker-checker/src/Http/routes.php';
| });
|
| Or register them manually with custom middleware:
|
| use Moffhub\MakerChecker\Http\Controllers\MakerCheckerConfigController;
| use Moffhub\MakerChecker\Http\Controllers\MakerCheckerRequestController;
|
| Route::prefix('api/maker-checker')->group(function () {
|     // Config routes
|     Route::apiResource('configs', MakerCheckerConfigController::class);
|     // ... etc
| });
|
*/

Route::prefix('maker-checker')->group(function () {
    // Request management routes
    Route::get('requests/statistics', [MakerCheckerRequestController::class, 'statistics']);
    Route::get('requests/statuses', [MakerCheckerRequestController::class, 'statuses']);
    Route::get('requests', [MakerCheckerRequestController::class, 'index']);
    Route::get('requests/{id}', [MakerCheckerRequestController::class, 'show']);
    Route::get('requests/{id}/approvals', [MakerCheckerRequestController::class, 'approvals']);
    Route::post('requests/{id}/approve', [MakerCheckerRequestController::class, 'approve']);
    Route::post('requests/{id}/reject', [MakerCheckerRequestController::class, 'reject']);
    Route::post('requests/{id}/cancel', [MakerCheckerRequestController::class, 'cancel']);

    // Config management routes (for database driver)
    Route::get('configs/actions', [MakerCheckerConfigController::class, 'actions']);
    Route::get('configs/types', [MakerCheckerConfigController::class, 'types']);
    Route::get('configs/export', [MakerCheckerConfigController::class, 'export']);
    Route::post('configs/import', [MakerCheckerConfigController::class, 'import']);
    Route::post('configs/{config}/enable', [MakerCheckerConfigController::class, 'enable']);
    Route::post('configs/{config}/disable', [MakerCheckerConfigController::class, 'disable']);
    Route::apiResource('configs', MakerCheckerConfigController::class);
});
