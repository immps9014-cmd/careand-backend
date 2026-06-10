<?php

namespace App\Domains\Postpartum\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Postpartum\Models\Newborn;
use App\Domains\Postpartum\Models\NewbornAnomalyAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 신생아 발달 이상 알림 컨트롤러
 *
 * 체중 감소, 황달, 수유 부족, 체온 이상 등을 자동 탐지하여 알림 생성.
 * 본 컨트롤러는 조회 + 처리 완료(resolve) 만 담당하고,
 * 알림 생성 자체는 NewbornAnomalyDetectorService가 배치로 수행.
 */
class NewbornAnomalyController extends Controller
{
    /**
     * GET /api/v1/newborns/{id}/anomaly-alerts
     */
    public function index(Request $request, int $newbornId): JsonResponse
    {
        $newborn = Newborn::findOrFail($newbornId);
        $this->authorize('view', $newborn->postpartumClient);

        $query = NewbornAnomalyAlert::where('newborn_id', $newbornId);

        if ($request->filled('severity')) {
            $request->validate(['severity' => 'in:low,medium,high,critical']);
            $query->where('severity', $request->string('severity'));
        }

        if ($request->has('resolved')) {
            $resolved = filter_var($request->input('resolved'), FILTER_VALIDATE_BOOLEAN);
            $resolved
                ? $query->whereNotNull('resolved_at')
                : $query->whereNull('resolved_at');
        }

        $alerts = $query->orderByDesc('detected_at')->paginate(20);

        return response()->json([
            'success' => true,
            'code'    => 'OK',
            'data'    => $alerts->items(),
            'meta'    => [
                'page'       => $alerts->currentPage(),
                'page_size'  => $alerts->perPage(),
                'total'      => $alerts->total(),
            ],
        ]);
    }

    /**
     * POST /api/v1/newborns/anomaly-alerts/{alert_id}/resolve
     *
     * 알림 처리 완료 표시 (본사 매니저 또는 매칭된 인력)
     */
    public function resolve(Request $request, int $alertId): JsonResponse
    {
        $validated = $request->validate([
            'resolution_note' => 'required|string|min:5|max:2000',
        ]);

        $alert = NewbornAnomalyAlert::findOrFail($alertId);
        $newborn = $alert->newborn;
        $this->authorize('manage', $newborn->postpartumClient);

        if ($alert->resolved_at !== null) {
            return response()->json([
                'success' => false,
                'code'    => 'ALREADY_RESOLVED',
                'message' => '이미 처리된 알림입니다.',
            ], 409);
        }

        $alert->update([
            'resolved_at'     => now(),
            'resolved_by'     => $request->user()->id,
            'resolution_note' => $validated['resolution_note'],
        ]);

        return response()->json([
            'success' => true,
            'code'    => 'OK',
            'message' => '처리 완료로 표시되었습니다.',
            'data'    => $alert->fresh(),
        ]);
    }
}
