<?php

use App\Domains\Nursing\Controllers\NursingPatientController;
use Illuminate\Support\Facades\Route;

/**
 * 간병(nursing) 도메인 API 라우트
 *
 * 베이스: /api/v1/nursing
 * 매칭 요청 자체는 공용 /v1/matching/requests (service_domain=nursing)
 */
Route::middleware(['auth:api', 'throttle:api'])
    ->prefix('nursing')
    ->group(function () {

        Route::prefix('patients')->group(function () {
            Route::get('/', [NursingPatientController::class, 'index']);
            Route::post('/', [NursingPatientController::class, 'store']);
            Route::get('/{id}', [NursingPatientController::class, 'show'])->whereNumber('id');
            Route::patch('/{id}', [NursingPatientController::class, 'update'])->whereNumber('id');
            Route::delete('/{id}', [NursingPatientController::class, 'destroy'])->whereNumber('id');
        });
    });
