<?php

use App\Domains\Housekeeping\Controllers\ServiceAddressController;
use Illuminate\Support\Facades\Route;

/**
 * 가사(housekeeping) 도메인 API 라우트
 *
 * 베이스: /api/v1/housekeeping
 * 매칭 요청 자체는 공용 /v1/matching/requests (service_domain=housekeeping)
 */
Route::middleware(['auth:api', 'throttle:api'])
    ->prefix('housekeeping')
    ->group(function () {

        Route::prefix('addresses')->group(function () {
            Route::get('/', [ServiceAddressController::class, 'index']);
            Route::post('/', [ServiceAddressController::class, 'store']);
            Route::get('/{id}', [ServiceAddressController::class, 'show'])->whereNumber('id');
            Route::patch('/{id}', [ServiceAddressController::class, 'update'])->whereNumber('id');
            Route::delete('/{id}', [ServiceAddressController::class, 'destroy'])->whereNumber('id');
        });
    });
