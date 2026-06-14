<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 보호자(guardian) 전용 엔드포인트.
 * Phase 2: 보호자 AI 케어일지 수신·열람.
 */
class GuardianController extends Controller
{
    /**
     * GET /v1/guardians/me/sessions
     * 보호자 본인 매칭의 케어 세션 목록 — AI 일지 수신/열람 진입점.
     * 인력 CaregiverController::mySessions 미러 + review_status / has_summary 추가.
     * 권한: 본인(guardian_id) 매칭 세션만. 인력 mySessions와 동일하게 비페이지(보호자 세션 수 적음).
     */
    public function mySessions(Request $request): JsonResponse
    {
        $guardian = $request->user()->guardian;
        if (! $guardian) {
            return response()->json(['success' => false, 'message' => '보호자 회원만 조회 가능합니다.'], 403);
        }

        $rows = DB::table('care_sessions as cs')
            ->join('matches as m', 'm.id', '=', 'cs.match_id')
            ->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->leftJoin('seniors as s', 's.id', '=', 'r.senior_id')
            ->leftJoin('nursing_patients as np', 'np.id', '=', 'r.nursing_patient_id')
            ->leftJoin('service_addresses as sa', 'sa.id', '=', 'r.service_address_id')
            ->where('r.guardian_id', $guardian->id)
            ->select(
                'cs.id',
                'cs.status',
                'cs.review_status',
                'cs.duration_min',
                'cs.actual_start',
                'cs.actual_end',
                DB::raw('COALESCE(cs.scheduled_start, m.scheduled_start) as scheduled_start'),
                DB::raw('COALESCE(cs.scheduled_end, m.scheduled_end) as scheduled_end'),
                'r.service_domain',
                DB::raw('COALESCE(s.name, np.name, sa.label) as recipient_name'),
                DB::raw('EXISTS(SELECT 1 FROM ai_log_summaries als WHERE als.session_id = cs.id) as has_summary')
            )
            ->orderByDesc(DB::raw('COALESCE(cs.scheduled_start, m.scheduled_start)'))
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,                  // scheduled|in_progress|completed|cancelled
                'review_status' => $r->review_status,    // pending|approved|rejected (일지 검수 상태)
                'has_summary' => (bool) $r->has_summary,  // AI 일지 생성 여부
                'service_domain' => $r->service_domain,
                'recipient_name' => $r->recipient_name ?? '(미상)',
                'scheduled_start' => $r->scheduled_start,
                'scheduled_end' => $r->scheduled_end,
                'actual_start' => $r->actual_start,
                'actual_end' => $r->actual_end,
                'duration_min' => $r->duration_min,
            ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }
}
