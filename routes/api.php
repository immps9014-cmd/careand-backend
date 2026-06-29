<?php

use App\Http\Controllers\Api\V1\Admin\AiModelController;
use App\Http\Controllers\Api\V1\Admin\CsController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\OperationsController;
use App\Http\Controllers\Api\V1\AnomalyAlertController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CaregiverController;
use App\Http\Controllers\Api\V1\CareSessionController;
use App\Http\Controllers\Api\V1\GuardianController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\ChatbotController;
use App\Http\Controllers\Api\V1\MatchRequestController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PublicController;
use App\Http\Controllers\Api\V1\SeniorController;
use App\Http\Controllers\Api\V1\SettlementController;
use App\Http\Controllers\Api\V1\VitalRecordController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Care& v1 (Phase 0 + 1 + 2)
|--------------------------------------------------------------------------
|
| Phase 0: 인증
| Phase 1: 어르신·인력·매칭·세션·결제·정산
| Phase 2: 건강 모니터링·챗봇·알림·관리자
|
*/

Route::prefix('v1')->group(function () {

    // ========== Phase 0: 인증 (비로그인) ==========
    Route::prefix('auth')->group(function () {
        Route::post('otp/send', [AuthController::class, 'sendOtp'])->middleware('throttle:30,1');
        Route::post('otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:30,1');
        Route::post('signup', [AuthController::class, 'signup'])->middleware('throttle:10,1');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
        Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:20,1');
    });

    // ========== 공개 웹(/www, 비로그인) 읽기 전용 ==========
    // 돌봄전문가 개별 노출은 회원 전용(/app)으로 전환 — 비로그인은 집계 통계만.
    Route::prefix('public')->middleware('throttle:60,1')->group(function () {
        Route::get('stats', [PublicController::class, 'stats']);
    });

    // ========== Phase 1+2: 인증 필요 ==========
    Route::middleware('auth:api')->group(function () {

        Route::prefix('auth')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
            Route::patch('me', [AuthController::class, 'updateMe']);
        });

        // === Phase 1 ===

        // 어르신
        Route::prefix('seniors')->group(function () {
            Route::get('/', [SeniorController::class, 'index']);
            Route::post('/', [SeniorController::class, 'store']);
            Route::get('{id}', [SeniorController::class, 'show'])->whereNumber('id');
            Route::patch('{id}', [SeniorController::class, 'update'])->whereNumber('id');
            Route::delete('{id}', [SeniorController::class, 'destroy'])->whereNumber('id');
            Route::post('{id}/refresh-voucher', [SeniorController::class, 'refreshVoucher'])->whereNumber('id');

            // Phase 2: 건강 모니터링
            Route::get('{seniorId}/vitals', [VitalRecordController::class, 'index'])->whereNumber('seniorId');
            Route::post('{seniorId}/vitals', [VitalRecordController::class, 'store'])->whereNumber('seniorId');
            Route::get('{seniorId}/health-timeseries', [VitalRecordController::class, 'timeseries'])->whereNumber('seniorId');
            Route::get('{seniorId}/anomaly-alerts', [AnomalyAlertController::class, 'listBySenior'])->whereNumber('seniorId');
            Route::post('{seniorId}/anomaly-alerts/scan', [AnomalyAlertController::class, 'scan'])->whereNumber('seniorId');
        });

        // 인력
        Route::prefix('caregivers')->group(function () {
            Route::get('me', [CaregiverController::class, 'me']);
            Route::get('me/matches', [CaregiverController::class, 'myMatches']);
            Route::get('me/sessions', [CaregiverController::class, 'mySessions']);
            Route::get('recommended', [CaregiverController::class, 'recommended']);
            Route::get('/', [CaregiverController::class, 'index']);
            Route::post('register', [CaregiverController::class, 'register']);
            Route::patch('me/profile', [CaregiverController::class, 'updateProfile']);
            Route::post('me/leave', [CaregiverController::class, 'requestLeave']);
            Route::post('me/return', [CaregiverController::class, 'requestReturn']);
            Route::get('{id}', [CaregiverController::class, 'show'])->whereNumber('id');
        });

        // 보호자
        Route::prefix('guardians')->group(function () {
            Route::get('me/sessions', [GuardianController::class, 'mySessions']);
        });

        // 기관 (에이전시) — 간병인을 구하는 매칭요청 발주. 요청자 프로필은 guardian 재사용.
        Route::prefix('organizations')->group(function () {
            Route::get('me', [OrganizationController::class, 'me']);
            Route::post('register', [OrganizationController::class, 'register']);
            // 소속 간병인 관리
            Route::get('me/caregivers', [OrganizationController::class, 'caregivers']);
            Route::post('me/caregivers/invite', [OrganizationController::class, 'inviteCaregiver']);
            Route::delete('me/caregivers/{caregiverId}', [OrganizationController::class, 'removeCaregiver'])->whereNumber('caregiverId');
            Route::delete('me/invites/{inviteId}', [OrganizationController::class, 'cancelInvite'])->whereNumber('inviteId');
        });

        // 매칭
        Route::prefix('matching')->group(function () {
            Route::get('categories', [MatchRequestController::class, 'categories']);
            // 서비스 도메인 레지스트리(SSOT) — 역할별 활성 도메인 + 카테고리
            Route::get('service-domains', [MatchRequestController::class, 'serviceDomains']);
            // 산모 선택기(통합 요청 폼) — 본인 소유 산모 목록/간이등록
            Route::get('postpartum-clients', [MatchRequestController::class, 'postpartumClients']);
            Route::post('postpartum-clients', [MatchRequestController::class, 'storePostpartumClient']);
            // 적정 간병비 미리보기(요청 생성 전)
            Route::get('pricing/estimate', [MatchRequestController::class, 'pricingEstimate']);
            // 돌봄전문가 주도(pull): 열린 요청 탐색 / 직접 지원 / 기피(차단)
            Route::get('open-requests', [MatchRequestController::class, 'openRequests']);
            Route::post('requests/{id}/apply', [MatchRequestController::class, 'apply'])->whereNumber('id');
            Route::get('blocks', [MatchRequestController::class, 'myBlocks']);
            Route::post('blocks', [MatchRequestController::class, 'blockTarget']);
            Route::delete('blocks/{blockId}', [MatchRequestController::class, 'unblock'])->whereNumber('blockId');
            Route::get('requests', [MatchRequestController::class, 'index']);
            Route::post('requests', [MatchRequestController::class, 'store']);
            Route::get('requests/{id}/candidates', [MatchRequestController::class, 'candidates'])
                ->whereNumber('id')->name('api.v1.matching.candidates');
            Route::post('requests/{id}/select', [MatchRequestController::class, 'selectCandidate'])->whereNumber('id');
            Route::post('candidates/{candidateId}/bid', [MatchRequestController::class, 'submitBid'])->whereNumber('candidateId');
            Route::post('candidates/{candidateId}/accept', [MatchRequestController::class, 'acceptByCaregiver'])->whereNumber('candidateId');
            Route::post('candidates/{candidateId}/reject', [MatchRequestController::class, 'rejectByCaregiver'])->whereNumber('candidateId');
        });

        // 케어 세션
        Route::prefix('care-sessions')->group(function () {
            Route::get('{id}', [CareSessionController::class, 'show'])->whereNumber('id');
            Route::post('{id}/checkin', [CareSessionController::class, 'checkin'])->whereNumber('id');
            Route::post('{id}/checkout', [CareSessionController::class, 'checkout'])->whereNumber('id');
            Route::post('{id}/activities', [CareSessionController::class, 'storeActivity'])->whereNumber('id');
            Route::post('{id}/voice-log', [CareSessionController::class, 'uploadVoiceLog'])->whereNumber('id');
            Route::post('{id}/photos', [CareSessionController::class, 'uploadPhoto'])->whereNumber('id');
            Route::get('{id}/ai-summary', [CareSessionController::class, 'getAiSummary'])->whereNumber('id');
        });

        // 결제
        Route::prefix('payments')->group(function () {
            Route::get('/', [PaymentController::class, 'index']);
            Route::post('calculate', [PaymentController::class, 'calculate']);
            Route::post('approve', [PaymentController::class, 'approve']);
            Route::post('{id}/cancel', [PaymentController::class, 'cancel'])->whereNumber('id');
        });

        // 정산
        Route::prefix('settlements')->group(function () {
            Route::get('/', [SettlementController::class, 'index']);
            Route::post('preview', [SettlementController::class, 'preview']);
            Route::get('{id}', [SettlementController::class, 'show'])->whereNumber('id');
        });

        // === Phase 2 신규 ===

        // 이상징후 알림 (전체)
        Route::prefix('anomaly-alerts')->group(function () {
            Route::get('/', [AnomalyAlertController::class, 'index']);
            Route::get('{id}', [AnomalyAlertController::class, 'show'])->whereNumber('id');
            Route::post('{id}/acknowledge', [AnomalyAlertController::class, 'acknowledge'])->whereNumber('id');
            Route::post('{id}/resolve', [AnomalyAlertController::class, 'resolve'])->whereNumber('id');
            Route::post('{id}/dismiss', [AnomalyAlertController::class, 'dismiss'])->whereNumber('id');
        });

        // 챗봇 (보호자)
        Route::prefix('chatbot')->group(function () {
            Route::get('sessions', [ChatbotController::class, 'sessions']);
            Route::post('sessions', [ChatbotController::class, 'startSession']);
            Route::get('sessions/{id}/messages', [ChatbotController::class, 'messages'])->whereNumber('id');
            Route::post('sessions/{id}/ask', [ChatbotController::class, 'ask'])->whereNumber('id');
            Route::post('sessions/{id}/end', [ChatbotController::class, 'endSession'])->whereNumber('id');
        });

        // 알림 (FCM)
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::post('{id}/read', [NotificationController::class, 'markAsRead'])->whereNumber('id');
            Route::post('read-all', [NotificationController::class, 'markAllAsRead']);
            Route::post('fcm-token', [NotificationController::class, 'updateFcmToken']);
        });

        // === 관리자 전용 ===
        Route::prefix('admin')->middleware('role:admin')->group(function () {
            // 정산
            Route::post('settlements/run', [SettlementController::class, 'runWeekly']);
            Route::post('settlements/file-tax', [SettlementController::class, 'fileTax']);
            Route::post('settlements/bulk-confirm', [SettlementController::class, 'bulkConfirm']);
            Route::post('settlements/{id}/confirm', [SettlementController::class, 'confirm'])->whereNumber('id');

            // Phase 2: 대시보드 KPI
            Route::prefix('dashboard')->group(function () {
                Route::get('kpi', [DashboardController::class, 'kpi']);
                Route::get('hourly-requests', [DashboardController::class, 'hourlyRequests']);
                Route::get('regional-demand', [DashboardController::class, 'regionalDemand']);
                Route::get('recent-alerts', [DashboardController::class, 'recentAlerts']);
            });

            // Phase 2: AI 모델 운영
            Route::prefix('ai-models')->group(function () {
                Route::get('/', [AiModelController::class, 'index']);
                Route::get('{id}', [AiModelController::class, 'show'])->whereNumber('id');
                Route::post('{id}/promote', [AiModelController::class, 'promote'])->whereNumber('id');
                Route::post('{id}/rollback', [AiModelController::class, 'rollback'])->whereNumber('id');
                Route::post('{id}/audit', [AiModelController::class, 'audit'])->whereNumber('id');
                Route::get('{id}/audit-log', [AiModelController::class, 'auditLog'])->whereNumber('id');
            });

            // 후기·CS 관리 (#24)
            Route::prefix('cs')->group(function () {
                Route::get('stats', [CsController::class, 'stats']);
                Route::get('reviews', [CsController::class, 'reviews']);
                Route::post('reviews/{id}/reply', [CsController::class, 'replyReview'])->whereNumber('id');
                Route::get('chatbot-sessions', [CsController::class, 'chatbotSessions']);
            });

            // 인력 자격검증 (#20)
            Route::prefix('caregivers')->group(function () {
                Route::get('/', [OperationsController::class, 'caregivers']);
                Route::post('{id}/approve', [OperationsController::class, 'approveCaregiver'])->whereNumber('id');
                Route::post('{id}/reject', [OperationsController::class, 'rejectCaregiver'])->whereNumber('id');
                Route::patch('{id}', [OperationsController::class, 'updateCaregiver'])->whereNumber('id');
            });

            // 계약·일정 관리 (#21)
            Route::get('contracts', [OperationsController::class, 'contracts']);

            // 케어 진행 현황(Working List)
            Route::get('care-sessions', [OperationsController::class, 'careSessions']);

            // AI 일지 검수·승인 (#22)
            Route::prefix('care-logs')->group(function () {
                Route::get('/', [OperationsController::class, 'careLogs']);
                Route::get('{id}', [OperationsController::class, 'careLogDetail'])->whereNumber('id');
                Route::post('{id}/approve', [OperationsController::class, 'approveCareLog'])->whereNumber('id');
                Route::post('{id}/reject', [OperationsController::class, 'rejectCareLog'])->whereNumber('id');
            });

            // 공지·푸시 알림 (#25)
            Route::prefix('announcements')->group(function () {
                Route::get('/', [OperationsController::class, 'announcements']);
                Route::post('/', [OperationsController::class, 'broadcast']);
            });

            // 매칭 모니터링 + 수동매칭 (#18)
            Route::get('matching/requests', [OperationsController::class, 'matchingRequests']);
            Route::get('matching/requests/{id}', [OperationsController::class, 'matchingRequestDetail'])->whereNumber('id');
            Route::post('matching/requests/{id}/manual-assign', [OperationsController::class, 'manualAssign'])->whereNumber('id');
            Route::patch('matching/requests/{id}/match', [OperationsController::class, 'updateMatch'])->whereNumber('id');

            // 회원·인력 통합관리 (#19)
            Route::get('members', [OperationsController::class, 'members']);
            Route::post('members', [OperationsController::class, 'createMember']);
            Route::patch('members/{id}/status', [OperationsController::class, 'updateMemberStatus'])->whereNumber('id');
            Route::get('members/{id}', [OperationsController::class, 'memberDetail'])->whereNumber('id');
            Route::patch('organizations/{id}', [OperationsController::class, 'updateOrganization'])->whereNumber('id');
            Route::patch('members/{id}/credentials', [OperationsController::class, 'updateMemberCredentials'])->whereNumber('id');
            Route::get('monitoring/alerts', [OperationsController::class, 'monitoringAlerts']);
            Route::post('monitoring/alerts/{id}/acknowledge', [OperationsController::class, 'acknowledgeAlert'])->whereNumber('id');
            Route::post('monitoring/alerts/{id}/resolve', [OperationsController::class, 'resolveAlert'])->whereNumber('id');

            // 정산 (#23)
            Route::get('settlements', [OperationsController::class, 'settlements']);
            Route::get('settlements/{id}', [OperationsController::class, 'settlementDetail'])->whereNumber('id');
        });
    });

    // 웹훅
    Route::prefix('webhooks')->group(function () {
        Route::post('pg', [PaymentController::class, 'pgWebhook']);
    });

    // === Phase 2 산후 도메인 ===
    require __DIR__ . '/api/postpartum.php';

    // === 간병 도메인 ===
    require __DIR__ . '/api/nursing.php';

    // === 가사 도메인 ===
    require __DIR__ . '/api/housekeeping.php';
});

// Health check (모니터링/배포 검증용 — DB/Redis 포함, /api/health + /api/v1/health)
$careandHealth = function () {
    $checks = [];
    try {
        \Illuminate\Support\Facades\DB::select('SELECT 1');
        $checks['db'] = 'ok';
    } catch (\Throwable $e) {
        $checks['db'] = 'fail';
    }
    try {
        \Illuminate\Support\Facades\Redis::connection()->ping();
        $checks['redis'] = 'ok';
    } catch (\Throwable $e) {
        $checks['redis'] = 'fail';
    }
    $ok = !in_array('fail', $checks, true);
    return response()->json([
        'status' => $ok ? 'ok' : 'degraded',
        'service' => 'careand-backend',
        'checks' => $checks,
        'timestamp' => now()->toIso8601String(),
    ], $ok ? 200 : 503);
};
Route::get('health', $careandHealth)->middleware('throttle:60,1');
Route::get('v1/health', $careandHealth)->middleware('throttle:60,1');
