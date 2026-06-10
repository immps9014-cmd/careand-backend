<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\StoreVitalRequest;
use App\Http\Resources\VitalRecordResource;
use App\Models\HealthTimeseries;
use App\Models\Senior;
use App\Models\VitalRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VitalRecordController extends Controller
{
    /**
     * GET /v1/seniors/{seniorId}/vitals
     */
    public function index(Request $request, int $seniorId): JsonResponse
    {
        $senior = Senior::findOrFail($seniorId);
        $this->authorize('view', $senior);

        $period = $request->input('period', '7d'); // 7d, 30d, 90d
        $days = match ($period) {
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
            default => 7,
        };

        $vitals = VitalRecord::where('senior_id', $seniorId)
            ->where('measured_at', '>=', now()->subDays($days))
            ->orderBy('measured_at', 'desc')
            ->limit(500)
            ->get();

        // 통계 요약
        $summary = [
            'count' => $vitals->count(),
            'avg_bp_sys' => round($vitals->avg('blood_pressure_sys') ?? 0, 1),
            'avg_bp_dia' => round($vitals->avg('blood_pressure_dia') ?? 0, 1),
            'avg_blood_sugar' => round($vitals->avg('blood_sugar') ?? 0, 1),
            'avg_heart_rate' => round($vitals->avg('heart_rate') ?? 0, 1),
            'last_measured_at' => $vitals->first()?->measured_at?->toIso8601String(),
        ];

        return response()->json([
            'success' => true,
            'period' => $period,
            'summary' => $summary,
            'data' => VitalRecordResource::collection($vitals),
        ]);
    }

    /**
     * POST /v1/seniors/{seniorId}/vitals
     * 인력이 케어 세션 중 활력징후 기록
     */
    public function store(StoreVitalRequest $request, int $seniorId): JsonResponse
    {
        $senior = Senior::findOrFail($seniorId);

        // 인력 또는 보호자만 기록 가능
        if (!$request->user()->caregiver && !$request->user()->guardian) {
            return response()->json([
                'success' => false,
                'error_code' => 'FORBIDDEN',
                'message' => '활력징후를 기록할 권한이 없습니다.',
            ], 403);
        }

        // 본인이 담당하는 어르신만 (Policy)
        $this->authorize('view', $senior);

        $data = $request->validated();
        $data['senior_id'] = $seniorId;
        $data['measured_at'] ??= now();

        $vital = DB::transaction(function () use ($data, $seniorId) {
            $vital = VitalRecord::create($data);

            // 시계열 DB에도 동시 기록 (대시보드 차트용)
            $this->writeTimeseries($seniorId, $data);

            return $vital;
        });

        return response()->json([
            'success' => true,
            'message' => '활력징후가 기록되었습니다.',
            'data' => new VitalRecordResource($vital),
        ], 201);
    }

    /**
     * GET /v1/seniors/{seniorId}/health-timeseries
     * 메트릭별 시계열 데이터 (차트용)
     */
    public function timeseries(Request $request, int $seniorId): JsonResponse
    {
        $senior = Senior::findOrFail($seniorId);
        $this->authorize('view', $senior);

        $validated = $request->validate([
            'metric' => ['required', 'string', 'in:meal_pct,sleep_hours,mood_score,activity_minutes,bp_sys,bp_dia,blood_sugar,heart_rate'],
            'days' => ['nullable', 'integer', 'between:1,365'],
        ]);

        $days = $validated['days'] ?? 7;

        $data = HealthTimeseries::where('senior_id', $seniorId)
            ->where('metric_name', $validated['metric'])
            ->where('recorded_at', '>=', now()->subDays($days))
            ->orderBy('recorded_at')
            ->get(['value', 'recorded_at']);

        return response()->json([
            'success' => true,
            'metric' => $validated['metric'],
            'days' => $days,
            'data' => $data->map(fn ($t) => [
                'recorded_at' => $t->recorded_at->toIso8601String(),
                'value' => (float) $t->value,
            ]),
        ]);
    }

    /**
     * 시계열 DB에 활력징후 분해 기록
     */
    private function writeTimeseries(int $seniorId, array $data): void
    {
        $now = $data['measured_at'] ?? now();
        $metrics = [
            'bp_sys' => $data['blood_pressure_sys'] ?? null,
            'bp_dia' => $data['blood_pressure_dia'] ?? null,
            'blood_sugar' => $data['blood_sugar'] ?? null,
            'heart_rate' => $data['heart_rate'] ?? null,
            'body_temperature' => $data['body_temperature'] ?? null,
        ];

        foreach ($metrics as $name => $value) {
            if ($value === null) continue;

            HealthTimeseries::create([
                'senior_id' => $seniorId,
                'metric_name' => $name,
                'value' => $value,
                'recorded_at' => $now,
            ]);
        }
    }
}
