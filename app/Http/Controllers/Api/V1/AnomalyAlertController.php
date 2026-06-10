<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnomalyAlertResource;
use App\Models\AnomalyAlert;
use App\Models\Senior;
use App\Services\External\AiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnomalyAlertController extends Controller
{
    public function __construct(private AiService $aiService)
    {
    }

    /**
     * GET /v1/anomaly-alerts
     * 보호자 본인 어르신들의 이상징후 알림 목록
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = AnomalyAlert::with('senior:id,name,care_grade');

        if ($user->isGuardian()) {
            // 보호자: 자신의 어르신들 알림만
            $seniorIds = Senior::where('guardian_id', $user->guardian->id)->pluck('id');
            $query->whereIn('senior_id', $seniorIds);
        } elseif (!$user->isAdmin()) {
            return response()->json([
                'success' => false,
                'error_code' => 'FORBIDDEN',
                'message' => '권한이 없습니다.',
            ], 403);
        }

        // 필터
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        } else {
            $query->unresolved(); // 기본: 미해결만
        }

        if ($severity = $request->input('severity')) {
            $query->where('severity', $severity);
        }

        $alerts = $query->orderByDesc('detected_at')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => AnomalyAlertResource::collection($alerts),
            'meta' => [
                'total' => $alerts->total(),
                'high_count' => AnomalyAlert::high()->unresolved()
                    ->whereIn('senior_id', $seniorIds ?? [])->count(),
            ],
        ]);
    }

    /**
     * GET /v1/seniors/{seniorId}/anomaly-alerts
     */
    public function listBySenior(Request $request, int $seniorId): JsonResponse
    {
        $senior = Senior::findOrFail($seniorId);
        $this->authorize('view', $senior);

        $alerts = AnomalyAlert::where('senior_id', $seniorId)
            ->orderByDesc('detected_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => AnomalyAlertResource::collection($alerts),
            'meta' => [
                'total' => $alerts->total(),
                'unresolved_count' => AnomalyAlert::where('senior_id', $seniorId)
                    ->unresolved()->count(),
            ],
        ]);
    }

    /**
     * POST /v1/seniors/{seniorId}/anomaly-alerts/scan
     * AI 이상징후 즉시 스캔 (관리자/시스템 트리거)
     */
    public function scan(Request $request, int $seniorId): JsonResponse
    {
        $senior = Senior::findOrFail($seniorId);
        $this->authorize('view', $senior);

        $windowDays = $request->input('window_days', 7);

        try {
            $aiResult = $this->aiService->scoreAnomaly($seniorId, $windowDays);

            // 점수가 임계값 이상인 경우만 alert 생성
            if (($aiResult['risk_score'] ?? 0) < 50) {
                return response()->json([
                    'success' => true,
                    'message' => '이상징후가 감지되지 않았습니다.',
                    'risk_score' => $aiResult['risk_score'] ?? 0,
                ]);
            }

            $alert = AnomalyAlert::create([
                'senior_id' => $seniorId,
                'risk_type' => $aiResult['risk_type'] ?? 'other',
                'risk_score' => $aiResult['risk_score'],
                'severity' => $aiResult['severity'] ?? 'mid',
                'trigger_pattern' => $aiResult['trigger_pattern'] ?? [],
                'recommendation' => $aiResult['recommendation'] ?? [],
                'status' => 'new',
                'detected_at' => now(),
            ]);

            // TODO: 보호자에게 FCM 푸시 (severity가 high+ 일 때)

            return response()->json([
                'success' => true,
                'message' => '이상징후가 감지되어 알림이 생성되었습니다.',
                'data' => new AnomalyAlertResource($alert),
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error_code' => 'SCAN_FAILED',
                'message' => 'AI 스캔 중 오류: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /v1/anomaly-alerts/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $alert = AnomalyAlert::with(['senior:id,name,care_grade,guardian_id', 'resolver:id,name'])
            ->findOrFail($id);

        $this->authorize('view', $alert->senior);

        return response()->json([
            'success' => true,
            'data' => new AnomalyAlertResource($alert),
        ]);
    }

    /**
     * POST /v1/anomaly-alerts/{id}/acknowledge
     * 보호자: 알림 확인 (severity=high 이상은 acknowledge 필요)
     */
    public function acknowledge(Request $request, int $id): JsonResponse
    {
        $alert = AnomalyAlert::with('senior')->findOrFail($id);
        $this->authorize('view', $alert->senior);

        if ($alert->status !== 'new') {
            return response()->json([
                'success' => false,
                'error_code' => 'ALREADY_PROCESSED',
                'message' => '이미 처리된 알림입니다.',
            ], 422);
        }

        $alert->update([
            'status' => 'acknowledged',
        ]);

        return response()->json([
            'success' => true,
            'message' => '알림을 확인했습니다.',
            'data' => new AnomalyAlertResource($alert),
        ]);
    }

    /**
     * POST /v1/anomaly-alerts/{id}/resolve
     * 알림 해결 처리 (의료진 상담, 자체 조치 등)
     */
    public function resolve(Request $request, int $id): JsonResponse
    {
        $alert = AnomalyAlert::with('senior')->findOrFail($id);
        $this->authorize('view', $alert->senior);

        $validated = $request->validate([
            'resolution_note' => ['required', 'string', 'max:1000'],
        ]);

        $alert->update([
            'status' => 'resolved',
            'resolution_note' => $validated['resolution_note'],
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => '알림이 해결 처리되었습니다.',
            'data' => new AnomalyAlertResource($alert),
        ]);
    }

    /**
     * POST /v1/anomaly-alerts/{id}/dismiss
     * 오탐 처리
     */
    public function dismiss(Request $request, int $id): JsonResponse
    {
        $alert = AnomalyAlert::with('senior')->findOrFail($id);
        $this->authorize('view', $alert->senior);

        $alert->update([
            'status' => 'dismissed',
            'resolution_note' => $request->input('reason', '오탐 처리'),
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => '알림이 무시 처리되었습니다.',
        ]);
    }
}
