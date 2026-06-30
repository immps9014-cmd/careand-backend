<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Review;
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

    /**
     * GET /v1/guardians/reviewable
     * 완료된 케어(돌봄전문가)에 대한 보호자 만족도 평가 목록 + 내 기존 평가.
     * 완료 세션이 1건 이상인 매칭만 노출(케어 미수행 매칭 제외).
     */
    public function reviewableCares(Request $request): JsonResponse
    {
        $guardian = $request->user()->guardian;
        if (! $guardian) {
            return response()->json(['success' => false, 'message' => '보호자 회원만 조회 가능합니다.'], 403);
        }
        $uid = $request->user()->id;

        $rows = DB::table('matches as m')
            ->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->join('caregivers as c', 'c.id', '=', 'm.caregiver_id')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->leftJoin('seniors as s', 's.id', '=', 'r.senior_id')
            ->leftJoin('nursing_patients as np', 'np.id', '=', 'r.nursing_patient_id')
            ->leftJoin('service_addresses as sa', 'sa.id', '=', 'r.service_address_id')
            ->leftJoin('reviews as rv', function ($j) use ($uid) {
                $j->on('rv.match_id', '=', 'm.id')->where('rv.reviewer_id', '=', $uid);
            })
            ->where('r.guardian_id', $guardian->id)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('care_sessions as cs')
                    ->whereColumn('cs.match_id', 'm.id')->where('cs.status', 'completed');
            })
            ->orderByDesc('m.scheduled_start')
            ->get([
                'm.id as match_id', 'm.scheduled_start', 'r.service_domain',
                'c.id as caregiver_id', 'u.name as caregiver_name',
                DB::raw('COALESCE(s.name, np.name, sa.label) as recipient_name'),
                'rv.rating', 'rv.comment', 'rv.tags',
            ])
            ->map(fn ($r) => [
                'match_id' => (int) $r->match_id,
                'caregiver_id' => (int) $r->caregiver_id,
                'caregiver_name' => $r->caregiver_name ?? '돌봄전문가',
                'recipient_name' => $r->recipient_name ?? '(미상)',
                'service_domain' => $r->service_domain,
                'scheduled_start' => $r->scheduled_start,
                'rating' => $r->rating !== null ? (int) $r->rating : null,
                'comment' => $r->comment,
                'tags' => $r->tags ? json_decode($r->tags, true) : [],
                'reviewed' => $r->rating !== null,
            ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * POST /v1/guardians/reviews
     * 보호자가 돌봄전문가 케어 만족도(평점·코멘트·태그)를 등록/수정한다.
     */
    public function submitReview(Request $request): JsonResponse
    {
        $guardian = $request->user()->guardian;
        if (! $guardian) {
            return response()->json(['success' => false, 'message' => '보호자 회원만 평가할 수 있습니다.'], 403);
        }

        $v = $request->validate([
            'match_id' => ['required', 'integer'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:30'],
        ]);

        // 본인 매칭 + 완료 케어 확인
        $match = DB::table('matches as m')
            ->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->where('m.id', $v['match_id'])
            ->where('r.guardian_id', $guardian->id)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('care_sessions as cs')
                    ->whereColumn('cs.match_id', 'm.id')->where('cs.status', 'completed');
            })
            ->select('m.id', 'm.caregiver_id')->first();
        if (! $match) {
            return response()->json(['success' => false, 'message' => '평가할 수 없는 케어입니다.'], 404);
        }

        $review = Review::updateOrCreate(
            ['match_id' => $match->id, 'reviewer_id' => $request->user()->id],
            [
                'reviewer_role' => 'guardian',
                'rating' => $v['rating'],
                'comment' => $v['comment'] ?? null,
                'tags' => $v['tags'] ?? [],
            ]
        );

        $this->recomputeCaregiverRating((int) $match->caregiver_id);

        return response()->json([
            'success' => true,
            'message' => '케어 만족도가 등록되었습니다.',
            'data' => ['rating' => $review->rating],
        ]);
    }

    /** 돌봄전문가 평점 평균/건수를 보호자 리뷰 기준으로 재집계. */
    private function recomputeCaregiverRating(int $caregiverId): void
    {
        $agg = DB::table('reviews as rv')
            ->join('matches as m', 'm.id', '=', 'rv.match_id')
            ->where('m.caregiver_id', $caregiverId)
            ->where('rv.reviewer_role', 'guardian')
            ->selectRaw('AVG(rv.rating) as avg_rating, COUNT(*) as cnt')->first();

        DB::table('caregivers')->where('id', $caregiverId)->update([
            'rating_avg' => round((float) ($agg->avg_rating ?? 0), 2),
            'rating_count' => (int) ($agg->cnt ?? 0),
        ]);
    }
}
