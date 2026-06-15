<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiInferenceLog;
use App\Models\AiModel;
use App\Models\AuditLog;
use App\Models\MatchCandidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiModelController extends Controller
{
    /**
     * GET /v1/admin/ai-models
     * 5개 AI 모델 일람 + 상태
     */
    public function index(Request $request): JsonResponse
    {
        $models = AiModel::orderBy('model_name')
            ->orderBy('version', 'desc')
            ->get();

        $summary = [
            'total' => $models->count(),
            'active' => $models->where('status', 'active')->count(),
            'shadow' => $models->where('status', 'shadow')->count(),
            'deprecated' => $models->where('status', 'deprecated')->count(),
        ];

        return response()->json([
            'success' => true,
            'summary' => $summary,
            'data' => $models->map(fn ($m) => [
                'id' => $m->id,
                'model_name' => $m->model_name,
                'version' => $m->version,
                'status' => $m->status,
                'accuracy' => (float) $m->accuracy,
                'avg_latency_ms' => (float) $m->avg_latency_ms,
                'audited_at' => $m->audited_at?->toIso8601String(),
                'has_bias_report' => !empty($m->bias_report),
            ]),
        ]);
    }

    /**
     * GET /v1/admin/ai-models/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $model = AiModel::findOrFail($id);

        // 최근 7일 추론 통계
        $recentStats = AiInferenceLog::where('model_id', $id)
            ->where('inferred_at', '>=', now()->subDays(7))
            ->selectRaw('
                COUNT(*) as total,
                SUM(success) as success_count,
                AVG(latency_ms) as avg_latency,
                MAX(latency_ms) as max_latency
            ')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $model->id,
                'model_name' => $model->model_name,
                'version' => $model->version,
                'status' => $model->status,
                'accuracy' => (float) $model->accuracy,
                'avg_latency_ms' => (float) $model->avg_latency_ms,
                'metadata' => $model->metadata,
                'audited_at' => $model->audited_at?->toIso8601String(),
                'bias_report' => $model->bias_report,
                'recent_stats_7d' => [
                    'total_inferences' => $recentStats?->total ?? 0,
                    'success_rate_pct' => $recentStats?->total > 0
                        ? round(($recentStats->success_count / $recentStats->total) * 100, 2)
                        : 0,
                    'avg_latency_ms' => round($recentStats?->avg_latency ?? 0, 2),
                    'max_latency_ms' => round($recentStats?->max_latency ?? 0, 2),
                ],
                // 최근 추천 결과 검수 이력(매칭 모델 전용 실데이터). FE의 mock 배열을 대체.
                'recent_review_history' => $this->recentReviewHistory($model),
            ],
        ]);
    }

    /**
     * 최근 매칭 추천·배정 결과 이력(실데이터). matching 모델일 때만 채운다.
     * status: 'intervened' = 운영자 수동 매칭 개입, 'approved' = AI 자동 매칭.
     * 매칭 모델이 아니면 빈 배열을 반환한다.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentReviewHistory(AiModel $model): array
    {
        if ($model->model_name !== 'matching') {
            return [];
        }

        return DB::table('match_requests as mr')
            ->leftJoin('seniors as s', 's.id', '=', 'mr.senior_id')
            ->leftJoin('nursing_patients as np', 'np.id', '=', 'mr.nursing_patient_id')
            ->leftJoin('service_addresses as sa', 'sa.id', '=', 'mr.service_address_id')
            ->leftJoin('match_candidates as mc', 'mc.request_id', '=', 'mr.id')
            ->where('mr.status', 'matched')
            ->groupBy('mr.id', 'mr.matched_at', 's.name', 'np.name', 'sa.label')
            ->orderByDesc('mr.matched_at')
            ->limit(8)
            ->selectRaw("
                mr.id as request_id,
                COALESCE(s.name, np.name, sa.label) as senior_name,
                mr.matched_at as reviewed_at,
                COUNT(mc.id) as candidate_count,
                MAX(CASE WHEN mc.ai_reasons LIKE '%운영자 수동 매칭%' THEN 1 ELSE 0 END) as intervened
            ")
            ->get()
            ->map(fn ($r) => [
                'request_id' => (int) $r->request_id,
                'senior_name' => $r->senior_name ?? '(미상)',
                'candidate_count' => (int) $r->candidate_count,
                'status' => $r->intervened ? 'intervened' : 'approved',
                'reviewed_at' => $r->reviewed_at
                    ? \Illuminate\Support\Carbon::parse($r->reviewed_at)->toIso8601String()
                    : null,
            ])
            ->all();
    }

    /**
     * POST /v1/admin/ai-models/{id}/promote
     * SHADOW → ACTIVE 승격 (카나리 배포)
     */
    public function promote(Request $request, int $id): JsonResponse
    {
        $model = AiModel::findOrFail($id);

        if ($model->status !== 'shadow') {
            return response()->json([
                'success' => false,
                'error_code' => 'INVALID_STATUS',
                'message' => 'SHADOW 상태의 모델만 승격 가능합니다.',
            ], 422);
        }

        DB::transaction(function () use ($model, $request) {
            // 같은 model_name의 기존 active를 deprecated 처리
            $demotedIds = AiModel::where('model_name', $model->model_name)
                ->where('status', 'active')
                ->pluck('id')
                ->all();
            AiModel::whereIn('id', $demotedIds)->update(['status' => 'deprecated']);

            // 본 모델 승격
            $model->update(['status' => 'active']);

            // INV-7: 파괴적 액션 자체의 감사기록(편향성 bias_report와 분리)
            $this->recordAction($request, 'ai_model.promote', $model, [
                'model_name' => $model->model_name,
                'version' => $model->version,
                'from_status' => 'shadow',
                'to_status' => 'active',
                'demoted_model_ids' => $demotedIds,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => "{$model->model_name} v{$model->version}이(가) ACTIVE로 승격되었습니다.",
        ]);
    }

    /**
     * POST /v1/admin/ai-models/{id}/rollback
     * ACTIVE → DEPRECATED + 이전 버전을 ACTIVE로 (롤백)
     */
    public function rollback(Request $request, int $id): JsonResponse
    {
        $model = AiModel::findOrFail($id);

        if ($model->status !== 'active') {
            return response()->json([
                'success' => false,
                'error_code' => 'INVALID_STATUS',
                'message' => 'ACTIVE 상태의 모델만 롤백 가능합니다.',
            ], 422);
        }

        // 이전 버전 찾기 (같은 model_name, version 내림차순)
        $previousVersion = AiModel::where('model_name', $model->model_name)
            ->where('id', '!=', $id)
            ->orderByDesc('version')
            ->first();

        if (!$previousVersion) {
            return response()->json([
                'success' => false,
                'error_code' => 'NO_PREVIOUS_VERSION',
                'message' => '롤백할 이전 버전이 없습니다.',
            ], 422);
        }

        DB::transaction(function () use ($model, $previousVersion, $request) {
            $model->update(['status' => 'deprecated']);
            $previousVersion->update(['status' => 'active']);

            // INV-7: 파괴적 액션 자체의 감사기록(편향성 bias_report와 분리)
            $this->recordAction($request, 'ai_model.rollback', $model, [
                'model_name' => $model->model_name,
                'from_version' => $model->version,
                'to_version' => $previousVersion->version,
                'restored_model_id' => $previousVersion->id,
                'from_status' => 'active',
                'to_status' => 'deprecated',
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => "롤백 완료: v{$model->version} → v{$previousVersion->version}",
        ]);
    }

    /**
     * POST /v1/admin/ai-models/{id}/audit
     * 편향성 감사 실행 (성별·연령별 매칭율 분석)
     */
    public function audit(Request $request, int $id): JsonResponse
    {
        $model = AiModel::findOrFail($id);

        if ($model->model_name !== 'matching') {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_AUDITABLE',
                'message' => '매칭 모델만 편향성 감사가 가능합니다.',
            ], 422);
        }

        // 최근 30일 매칭 후보 통계
        $stats = MatchCandidate::join('caregivers', 'match_candidates.caregiver_id', '=', 'caregivers.id')
            ->where('match_candidates.created_at', '>=', now()->subDays(30))
            ->selectRaw("
                caregivers.gender,
                CASE
                    WHEN TIMESTAMPDIFF(YEAR, caregivers.birth_date, CURDATE()) BETWEEN 30 AND 39 THEN '30s'
                    WHEN TIMESTAMPDIFF(YEAR, caregivers.birth_date, CURDATE()) BETWEEN 40 AND 49 THEN '40s'
                    WHEN TIMESTAMPDIFF(YEAR, caregivers.birth_date, CURDATE()) BETWEEN 50 AND 59 THEN '50s'
                    ELSE '60s+'
                END as age_group,
                COUNT(*) as total,
                SUM(CASE WHEN response = 'accepted' THEN 1 ELSE 0 END) as accepted
            ")
            ->groupBy('caregivers.gender', 'age_group')
            ->get();

        $byGender = [];
        $byAge = [];

        foreach ($stats as $s) {
            $rate = $s->total > 0 ? round(($s->accepted / $s->total) * 100, 1) : 0;
            $byGender[$s->gender] = ($byGender[$s->gender] ?? 0) + $s->accepted;
            $byAge[$s->age_group] = ($byAge[$s->age_group] ?? 0) + $s->accepted;
        }

        $maxAcceptance = max($byGender + [0]);
        $minAcceptance = min($byGender + [0]);
        $maxDiff = $maxAcceptance > 0 ? round((($maxAcceptance - $minAcceptance) / $maxAcceptance) * 100, 1) : 0;

        $biasReport = [
            'audited_at' => now()->toIso8601String(),
            'window_days' => 30,
            'gender_distribution' => $byGender,
            'age_distribution' => $byAge,
            'max_diff_pct' => $maxDiff,
            'max_diff_threshold_pct' => 15,
            'passed' => $maxDiff <= 15,
        ];

        $model->update([
            'audited_at' => now(),
            'bias_report' => $biasReport,
        ]);

        return response()->json([
            'success' => true,
            'message' => $biasReport['passed']
                ? '편향성 감사 통과 (정상)'
                : '편향성 감사 경고 — 임계값 초과',
            'data' => $biasReport,
        ]);
    }

    /**
     * GET /v1/admin/ai-models/{id}/audit-log
     * 해당 모델의 promote/rollback 행위 감사기록(INV-7). 편향성 통계(bias_report)와 분리.
     */
    public function auditLog(Request $request, int $id): JsonResponse
    {
        AiModel::findOrFail($id);
        $limit = min((int) $request->input('limit', 20), 100);

        $logs = AuditLog::where('entity_type', 'ai_model')
            ->where('entity_id', $id)
            ->whereIn('action', ['ai_model.promote', 'ai_model.rollback'])
            ->with('actor:id,name')
            ->orderByDesc('logged_at')
            ->limit($limit)
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor_id' => $log->actor_id,
                'actor_name' => $log->actor?->name,
                'details' => $log->details,
                'logged_at' => $log->logged_at?->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }

    /**
     * 파괴적 ai-model 액션의 감사기록을 audit_logs에 남긴다(INV-7).
     * promote/rollback 트랜잭션 내부에서 호출되어 상태 변경과 원자적으로 기록된다.
     *
     * @param  array<string, mixed>  $details
     */
    private function recordAction(Request $request, string $action, AiModel $model, array $details): void
    {
        AuditLog::create([
            'actor_id' => $request->user()?->id,
            'action' => $action,
            'entity_type' => 'ai_model',
            'entity_id' => $model->id,
            'details' => $details,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'logged_at' => now(),
        ]);
    }
}
