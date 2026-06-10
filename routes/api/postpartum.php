<?php

use App\Domains\Postpartum\Controllers\EpdsController;
use App\Domains\Postpartum\Controllers\NewbornAnomalyController;
use App\Domains\Postpartum\Controllers\NewbornController;
use App\Domains\Postpartum\Controllers\PostpartumChatbotController;
use App\Domains\Postpartum\Controllers\PostpartumClientController;
use App\Domains\Postpartum\Controllers\PostpartumMatchingController;
use App\Domains\Postpartum\Controllers\VoucherController;
use Illuminate\Support\Facades\Route;

/**
 * Phase 2 산후 도메인 API 라우트
 *
 * 베이스: /api/v1
 * 인증: JWT Bearer (Auth middleware 적용)
 *
 * 통합 OpenAPI 명세서 §D2 산후 케어와 1:1 대응.
 */

Route::middleware(['auth:api', 'throttle:api'])
    ->prefix('postpartum')
    ->group(function () {

        // ===== 산모 클라이언트 =====
        Route::prefix('clients')->group(function () {
            Route::get('/', [PostpartumClientController::class, 'index']);
            Route::post('/', [PostpartumClientController::class, 'store']);
            Route::get('/{client}', [PostpartumClientController::class, 'show']);
            Route::put('/{client}', [PostpartumClientController::class, 'update']);

            Route::post('/{client}/newborns', [NewbornController::class, 'store']);
            Route::get('/{client}/voucher/inquire', [PostpartumClientController::class, 'voucherInquire']);
        });

        // ===== 신생아 =====
        Route::prefix('newborns')->group(function () {
            Route::get('/{newborn}', [NewbornController::class, 'show']);
            Route::get('/{newborn}/daily-logs', [NewbornController::class, 'dailyLogs']);
            Route::post('/{newborn}/daily-logs', [NewbornController::class, 'storeDailyLog']);
            Route::get('/{newborn}/anomaly-alerts', [NewbornAnomalyController::class, 'index']);
        });
        Route::post('/newborns/anomaly-alerts/{alert}/resolve',
            [NewbornAnomalyController::class, 'resolve']);

        // ===== EPDS =====
        Route::prefix('epds')->group(function () {
            Route::post('/assessments', [EpdsController::class, 'submit']);
            Route::get('/clients/{client}/history', [EpdsController::class, 'history']);
        });

        // ===== 바우처 =====
        Route::prefix('voucher-transactions')->group(function () {
            Route::get('/', [VoucherController::class, 'index']);
            Route::post('/use', [VoucherController::class, 'use']);
        });

        // ===== 산후 매칭 =====
        Route::prefix('match-requests')->group(function () {
            Route::post('/', [PostpartumMatchingController::class, 'store']);
            Route::get('/{id}', [PostpartumMatchingController::class, 'show']);
        });

        // ===== 산후 RAG 챗봇 =====
        Route::prefix('chatbot')->group(function () {
            Route::post('/sessions', [PostpartumChatbotController::class, 'startSession']);
            Route::post('/sessions/{id}/messages', [PostpartumChatbotController::class, 'sendMessage']);
        });
    });
