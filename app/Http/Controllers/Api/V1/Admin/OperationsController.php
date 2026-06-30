<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\NotificationService;

/**
 * 관리자 - 운영 관리
 *  #20 인력 자격검증 워크플로
 *  #21 계약·일정 관리
 *  #22 AI 일지 검수·승인
 *  #25 공지·푸시 알림
 */
class OperationsController extends Controller
{
    /* ===================== #20 인력 자격검증 ===================== */

    /** GET /v1/admin/caregivers — 인력 목록 (status 필터) */
    public function caregivers(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $query = DB::table('caregivers as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->select(
                'c.id', 'c.user_id', 'u.name', 'u.phone', 'u.email',
                'c.specialties', 'c.service_domains', 'c.status',
                'c.license_no', 'c.license_type', 'c.license_verified_at',
                'c.career_track', 'c.rating_avg',
                'c.completed_sessions', 'c.rejection_reason', 'c.created_at'
            )
            ->whereNull('c.deleted_at');

        if ($request->filled('status')) {
            $query->where('c.status', $request->input('status'));
        }

        $paginated = $query->orderByDesc('c.created_at')->paginate($perPage);

        $items = collect($paginated->items())->map(function ($r) {
            $spec = $r->specialties ? json_decode($r->specialties, true) : [];

            return [
                'id' => $r->id,
                'name' => $r->name ?? '(미상)',
                'phone' => $r->phone,
                'email' => $r->email,
                'specialties' => is_array($spec) ? $spec : [],
                'service_domains' => $r->service_domains,
                'status' => $r->status,
                'license_no' => $r->license_no,
                'license_type' => $r->license_type,
                'license_verified' => $r->license_verified_at !== null,
                'career_track' => $r->career_track,
                'rating_avg' => (float) $r->rating_avg,
                'completed_sessions' => (int) $r->completed_sessions,
                'rejection_reason' => $r->rejection_reason,
                'created_at' => $r->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => $this->meta($paginated),
        ]);
    }

    /** POST /v1/admin/caregivers/{id}/approve */
    public function approveCaregiver(int $id): JsonResponse
    {
        $updated = DB::table('caregivers')->where('id', $id)->update([
            'status' => 'active',
            'rejection_reason' => null,
            // 승인 = 자격 검수 완료. 매칭 풀은 license_verified_at NOT NULL을 요구하므로
            // 무자격 도메인(가사 등)·MoHW 진위확인 보류 건도 승인 시점에 충족시킨다.
            'license_verified_at' => DB::raw('COALESCE(license_verified_at, NOW())'),
            'updated_at' => now(),
        ]);

        if (! $updated) {
            return response()->json(['success' => false, 'message' => '인력을 찾을 수 없습니다.'], 404);
        }

        // 자격 심사 완료 알림 (DB 기록 + FCM 푸시)
        $cg = DB::table('caregivers as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.id', $id)
            ->first(['c.user_id', 'u.name']);
        if ($cg) {
            try {
                app(NotificationService::class)->notify(
                    (int) $cg->user_id,
                    NotificationService::TYPE_CAREGIVER_APPROVED,
                    ['caregiver_name' => $cg->name, 'caregiver_id' => $id]
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('자격 승인 알림 실패', ['caregiver_id' => $id, 'error' => $e->getMessage()]);
            }
        }

        return response()->json(['success' => true, 'message' => '승인되었습니다.']);
    }

    /** POST /v1/admin/caregivers/{id}/reject */
    public function rejectCaregiver(Request $request, int $id): JsonResponse
    {
        $request->validate(['reason' => 'required|string|max:500']);

        $updated = DB::table('caregivers')->where('id', $id)->update([
            'status' => 'rejected',
            'rejection_reason' => $request->input('reason'),
            'updated_at' => now(),
        ]);

        if (! $updated) {
            return response()->json(['success' => false, 'message' => '인력을 찾을 수 없습니다.'], 404);
        }

        // 자격 심사 반려 알림 (DB 기록 + FCM 푸시)
        $cg = DB::table('caregivers as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.id', $id)
            ->first(['c.user_id', 'u.name']);
        if ($cg) {
            try {
                app(NotificationService::class)->notify(
                    (int) $cg->user_id,
                    NotificationService::TYPE_CAREGIVER_REJECTED,
                    ['caregiver_name' => $cg->name, 'caregiver_id' => $id, 'reason' => $request->input('reason')]
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('자격 반려 알림 실패', ['caregiver_id' => $id, 'error' => $e->getMessage()]);
            }
        }

        return response()->json(['success' => true, 'message' => '반려되었습니다.']);
    }

    /* ===================== #21 계약·일정 관리 ===================== */

    /** GET /v1/admin/contracts — 계약(매칭) 목록 */
    public function contracts(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $query = DB::table('matches as m')
            ->leftJoin('caregivers as c', 'c.id', '=', 'm.caregiver_id')
            ->leftJoin('users as cu', 'cu.id', '=', 'c.user_id')
            ->leftJoin('match_requests as r', 'r.id', '=', 'm.request_id')
            ->leftJoin('seniors as s', 's.id', '=', 'r.senior_id')
            ->leftJoin('nursing_patients as np', 'np.id', '=', 'r.nursing_patient_id')
            ->leftJoin('service_addresses as sa', 'sa.id', '=', 'r.service_address_id')
            ->select(
                'm.id', 'm.request_id', 'm.caregiver_id',
                'cu.name as caregiver_name',
                DB::raw('COALESCE(s.name, np.name, sa.label) as senior_name'),
                'r.service_domain', 'r.mode',
                'm.scheduled_start', 'm.scheduled_end',
                'm.estimated_amount', 'm.status', 'm.created_at'
            );

        if ($request->filled('status')) {
            $query->where('m.status', $request->input('status'));
        }
        if ($request->filled('domain')) {
            $query->where('r.service_domain', $request->input('domain'));
        }

        $paginated = $query->orderByDesc('m.scheduled_start')->paginate($perPage);

        $items = collect($paginated->items())->map(fn ($r) => [
            'id' => $r->id,
            'request_id' => $r->request_id,
            'caregiver_name' => $r->caregiver_name ?? '(미배정)',
            'senior_name' => $r->senior_name ?? '(미상)',
            'service_domain' => $r->service_domain,
            'mode' => $r->mode,
            'scheduled_start' => $r->scheduled_start,
            'scheduled_end' => $r->scheduled_end,
            'estimated_amount' => (int) $r->estimated_amount,
            'status' => $r->status,
            'created_at' => $r->created_at,
        ]);

        $countsQ = DB::table('matches as m');
        if ($request->filled('domain')) {
            $countsQ->leftJoin('match_requests as r', 'r.id', '=', 'm.request_id')
                ->where('r.service_domain', $request->input('domain'));
        }
        $byStatus = (clone $countsQ)->select('m.status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('m.status')->pluck('cnt', 'status');
        $counts = [
            'all' => (int) array_sum($byStatus->all()),
            'confirmed' => (int) ($byStatus['confirmed'] ?? 0),
            'in_progress' => (int) ($byStatus['in_progress'] ?? 0),
            'completed' => (int) ($byStatus['completed'] ?? 0),
            'cancelled' => (int) ($byStatus['cancelled'] ?? 0),
            'no_show' => (int) ($byStatus['no_show'] ?? 0),
        ];

        return response()->json([
            'success' => true,
            'data' => $items,
            'counts' => $counts,
            'meta' => $this->meta($paginated),
        ]);
    }

    /* ===================== #22 AI 일지 검수·승인 ===================== */

    /** GET /v1/admin/care-logs — 검수 큐 (care_sessions) */
    /**
     * GET /v1/admin/care-sessions
     * 진행 현황(Working List): 매칭 완료~AI 일지 검수 전 단계의 케어 세션.
     * status=scheduled|in_progress|completed(검수전) | 미지정=active(예정+진행중+완료검수전).
     */
    public function careSessions(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);
        $status = $request->input('status');

        $query = DB::table('care_sessions as cs')
            ->leftJoin('matches as m', 'm.id', '=', 'cs.match_id')
            ->leftJoin('caregivers as c', 'c.id', '=', 'm.caregiver_id')
            ->leftJoin('users as cu', 'cu.id', '=', 'c.user_id')
            ->leftJoin('match_requests as r', 'r.id', '=', 'm.request_id')
            ->leftJoin('seniors as s', 's.id', '=', 'r.senior_id')
            ->leftJoin('nursing_patients as np', 'np.id', '=', 'r.nursing_patient_id')
            ->leftJoin('service_addresses as sa', 'sa.id', '=', 'r.service_address_id')
            ->leftJoin('guardians as g', 'g.id', '=', 'r.guardian_id')
            ->leftJoin('users as gu', 'gu.id', '=', 'g.user_id');

        if (in_array($status, ['scheduled', 'in_progress', 'completed'], true)) {
            $query->where('cs.status', $status);
            if ($status === 'completed') {
                $query->where('cs.review_status', 'pending');
            }
        } else {
            $query->where(function ($w) {
                $w->whereIn('cs.status', ['scheduled', 'in_progress'])
                  ->orWhere(function ($w2) {
                      $w2->where('cs.status', 'completed')->where('cs.review_status', 'pending');
                  });
            });
        }

        $paginated = $query->select(
                'cs.id', 'cs.status', 'cs.review_status', 'cs.duration_min',
                'cs.scheduled_start', 'cs.scheduled_end', 'cs.actual_start', 'cs.actual_end',
                'r.service_domain', 'm.is_manual',
                DB::raw('cu.name as caregiver_name'),
                DB::raw('COALESCE(s.name, np.name, sa.label) as recipient_name'),
                DB::raw('gu.name as guardian_name'),
                DB::raw('EXISTS(SELECT 1 FROM ai_log_summaries als WHERE als.session_id = cs.id) as has_summary')
            )
            ->orderByRaw("FIELD(cs.status, 'in_progress', 'scheduled', 'completed')")
            ->orderBy('cs.scheduled_start')
            ->paginate($perPage);

        $items = collect($paginated->items())->map(fn ($r) => [
            'id' => $r->id,
            'status' => $r->status,
            'review_status' => $r->review_status,
            'caregiver_name' => $r->caregiver_name ?? '(미배정)',
            'recipient_name' => $r->recipient_name ?? '(미상)',
            'guardian_name' => $r->guardian_name,
            'service_domain' => $r->service_domain,
            'scheduled_start' => $r->scheduled_start,
            'scheduled_end' => $r->scheduled_end,
            'actual_start' => $r->actual_start,
            'actual_end' => $r->actual_end,
            'duration_min' => $r->duration_min !== null ? (int) $r->duration_min : null,
            'is_manual' => (bool) $r->is_manual,
            'has_summary' => (bool) $r->has_summary,
        ]);

        $summary = [
            'scheduled' => (int) DB::table('care_sessions')->where('status', 'scheduled')->count(),
            'in_progress' => (int) DB::table('care_sessions')->where('status', 'in_progress')->count(),
            'completed_pending' => (int) DB::table('care_sessions')->where('status', 'completed')->where('review_status', 'pending')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $items,
            'summary' => $summary,
            'meta' => $this->meta($paginated),
        ]);
    }

    public function careLogs(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $query = DB::table('care_sessions as cs')
            ->leftJoin('matches as m', 'm.id', '=', 'cs.match_id')
            ->leftJoin('caregivers as c', 'c.id', '=', 'm.caregiver_id')
            ->leftJoin('users as cu', 'cu.id', '=', 'c.user_id')
            ->leftJoin('match_requests as r', 'r.id', '=', 'm.request_id')
            ->leftJoin('seniors as s', 's.id', '=', 'r.senior_id')
            ->leftJoin('nursing_patients as np', 'np.id', '=', 'r.nursing_patient_id')
            ->leftJoin('service_addresses as sa', 'sa.id', '=', 'r.service_address_id')
            ->leftJoin('guardians as g', 'g.id', '=', 'r.guardian_id')
            ->leftJoin('users as gu', 'gu.id', '=', 'g.user_id')
            ->select(
                'cs.id', 'cs.match_id', 'cs.status', 'cs.review_status',
                'cs.review_note', 'cs.duration_min',
                'cs.actual_start', 'cs.actual_end', 'cs.reviewed_at',
                'cu.name as caregiver_name',
                DB::raw('COALESCE(s.name, np.name, sa.label) as senior_name'),
                DB::raw('gu.name as guardian_name'),
                'r.service_domain'
            );

        if ($request->filled('review_status')) {
            $query->where('cs.review_status', $request->input('review_status'));
        }

        $paginated = $query->orderByDesc('cs.actual_end')->paginate($perPage);

        $items = collect($paginated->items())->map(fn ($r) => [
            'id' => $r->id,
            'match_id' => $r->match_id,
            'caregiver_name' => $r->caregiver_name ?? '(미상)',
            'senior_name' => $r->senior_name ?? '(미상)',
            'guardian_name' => $r->guardian_name,
            'service_domain' => $r->service_domain,
            'session_status' => $r->status,
            'review_status' => $r->review_status,
            'review_note' => $r->review_note,
            'duration_min' => (int) $r->duration_min,
            'actual_start' => $r->actual_start,
            'actual_end' => $r->actual_end,
            'reviewed_at' => $r->reviewed_at,
        ]);

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => $this->meta($paginated),
        ]);
    }

    /**
     * GET /v1/admin/care-logs/{id}
     * 검수용 AI 일지 본문(보호자용·의료용 요약) + 음성 전사 원문.
     */
    public function careLogDetail(Request $request, int $id): JsonResponse
    {
        $session = DB::table('care_sessions')->where('id', $id)->first();
        if (!$session) {
            return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => '세션을 찾을 수 없습니다.'], 404);
        }
        $summary = DB::table('ai_log_summaries')->where('session_id', $id)->orderByDesc('id')->first();
        $voice = DB::table('voice_logs')->where('session_id', $id)->orderByDesc('id')->first();

        return response()->json(['success' => true, 'data' => [
            'session_id' => $id,
            'guardian_version' => $summary->guardian_version ?? null,
            'medical_version' => $summary->medical_version ?? null,
            'confidence' => $summary ? (float) $summary->confidence : null,
            'llm_model' => $summary->llm_model ?? null,
            'transcript' => $voice->stt_text ?? null,
            'stt_confidence' => ($voice && $voice->stt_confidence !== null) ? (float) $voice->stt_confidence : null,
            'voice_duration_sec' => $voice->duration_sec ?? null,
        ]]);
    }

    /** POST /v1/admin/care-logs/{id}/approve */
    public function approveCareLog(int $id): JsonResponse
    {
        return $this->setReview($id, 'approved', null);
    }

    /** POST /v1/admin/care-logs/{id}/reject */
    public function rejectCareLog(Request $request, int $id): JsonResponse
    {
        $request->validate(['reason' => 'required|string|max:500']);

        return $this->setReview($id, 'rejected', $request->input('reason'));
    }

    private function setReview(int $id, string $status, ?string $note): JsonResponse
    {
        // 승인 전 상태 — 재승인 시 보호자 중복 알림 방지용
        $prevStatus = DB::table('care_sessions')->where('id', $id)->value('review_status');
        if ($prevStatus === null) {
            return response()->json(['success' => false, 'message' => '일지를 찾을 수 없습니다.'], 404);
        }

        DB::table('care_sessions')->where('id', $id)->update([
            'review_status' => $status,
            'review_note' => $note,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);

        // 최초 승인 시에만 보호자에게 '케어 일지 도착' 알림
        if ($status === 'approved' && $prevStatus !== 'approved') {
            $this->notifyGuardianSummaryReady($id);
        }

        return response()->json(['success' => true, 'message' => $status === 'approved' ? '승인되었습니다.' : '반려되었습니다.']);
    }

    /** 승인된 케어 일지를 보호자에게 알림(FCM+DB). 실패해도 승인 응답은 유지. */
    private function notifyGuardianSummaryReady(int $sessionId): void
    {
        try {
            $ctx = DB::table('care_sessions as cs')
                ->join('matches as m', 'm.id', '=', 'cs.match_id')
                ->join('match_requests as r', 'r.id', '=', 'm.request_id')
                ->join('guardians as g', 'g.id', '=', 'r.guardian_id')
                ->leftJoin('seniors as s', 's.id', '=', 'r.senior_id')
                ->leftJoin('nursing_patients as np', 'np.id', '=', 'r.nursing_patient_id')
                ->leftJoin('service_addresses as sa', 'sa.id', '=', 'r.service_address_id')
                ->where('cs.id', $sessionId)
                ->selectRaw('g.user_id as guardian_user_id, COALESCE(s.name, np.name, sa.label) as recipient_name')
                ->first();

            if (! $ctx || ! $ctx->guardian_user_id) {
                return;
            }

            app(NotificationService::class)->notify(
                (int) $ctx->guardian_user_id,
                NotificationService::TYPE_CARE_SUMMARY_READY,
                [
                    'senior_name' => $ctx->recipient_name ?? '어르신',
                    'session_id' => $sessionId,
                ],
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('케어일지 승인 알림 발송 실패', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* ===================== #25 공지·푸시 알림 ===================== */

    /** GET /v1/admin/announcements — 발송 공지 이력 */
    public function announcements(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        // 같은 title+body+created_at(분 단위) 으로 묶어 발송 단위로 집계
        $paginated = DB::table('notifications')
            ->where('type', 'announcement')
            ->select(
                'title',
                'body',
                DB::raw('COUNT(*) as recipients'),
                DB::raw('SUM(is_read) as read_count'),
                DB::raw('MAX(created_at) as sent_at')
            )
            ->groupBy('title', 'body')
            ->orderByDesc('sent_at')
            ->paginate($perPage);

        $items = collect($paginated->items())->map(fn ($r) => [
            'title' => $r->title,
            'body' => $r->body,
            'recipients' => (int) $r->recipients,
            'read_count' => (int) $r->read_count,
            'read_rate' => $r->recipients > 0 ? round($r->read_count / $r->recipients * 100, 1) : 0,
            'sent_at' => $r->sent_at,
        ]);

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => $this->meta($paginated),
        ]);
    }

    /** POST /v1/admin/announcements — 공지 발송 (대상: all|guardian|caregiver) */
    public function broadcast(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:200',
            'body' => 'required|string|max:2000',
            'target' => 'required|in:all,guardian,caregiver',
        ]);

        $userQuery = DB::table('users')->where('status', 'active')->whereNull('deleted_at');
        if ($validated['target'] !== 'all') {
            $userQuery->where('role', $validated['target']);
        }
        $userIds = $userQuery->pluck('id');

        if ($userIds->isEmpty()) {
            return response()->json(['success' => false, 'message' => '대상 사용자가 없습니다.'], 422);
        }

        $now = now();
        $rows = $userIds->map(fn ($uid) => [
            'user_id' => $uid,
            'type' => 'announcement',
            'title' => $validated['title'],
            'body' => $validated['body'],
            'data' => json_encode(['target' => $validated['target']]),
            'is_read' => 0,
            'sent_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('notifications')->insert($chunk);
        }

        return response()->json([
            'success' => true,
            'message' => "{$userIds->count()}명에게 공지를 발송했습니다.",
            'data' => ['recipients' => $userIds->count()],
        ]);
    }

    /* ===================== #18 매칭 모니터링 ===================== */

    /** GET /v1/admin/matching/requests — 전체 매칭 요청 + 후보 수 */
    /**
     * GET /v1/admin/matching/requests/{id}
     * 매칭 요청 상세 (대상자·후보·매칭된 돌봄전문가)
     */
    public function matchingRequestDetail(Request $request, int $id): JsonResponse
    {
        $r = DB::table('match_requests as r')
            ->leftJoin('seniors as s', 's.id', '=', 'r.senior_id')
            ->leftJoin('nursing_patients as np', 'np.id', '=', 'r.nursing_patient_id')
            ->leftJoin('service_addresses as sa', 'sa.id', '=', 'r.service_address_id')
            ->leftJoin('guardians as g', 'g.id', '=', 'r.guardian_id')
            ->leftJoin('users as gu', 'gu.id', '=', 'g.user_id')
            ->select(
                'r.id', 'r.mode', 'r.service_domain', 'r.scheduled_start', 'r.duration_min',
                'r.status', 'r.special_request', 'r.matched_at', 'r.created_at',
                DB::raw('COALESCE(s.name, np.name, sa.label) as recipient_name'),
                DB::raw('gu.name as guardian_name'),
                's.gender as senior_gender', 's.care_grade', 's.special_notes', 's.home_address',
                'sa.label as addr_label', 'sa.address as addr_full', 'sa.entry_note'
            )
            ->where('r.id', $id)->first();

        if (!$r) {
            return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => '매칭 요청을 찾을 수 없습니다.'], 404);
        }

        $match = DB::table('matches as m')
            ->leftJoin('caregivers as cg', 'cg.id', '=', 'm.caregiver_id')
            ->leftJoin('users as cu', 'cu.id', '=', 'cg.user_id')
            ->where('m.request_id', $id)
            ->whereIn('m.status', ['confirmed', 'in_progress', 'completed'])
            ->orderByDesc('m.id')
            ->select('cu.name as caregiver_name', 'm.caregiver_id', 'm.status as match_status', 'm.scheduled_start', 'm.scheduled_end', 'm.estimated_amount')
            ->first();

        $cands = DB::table('match_candidates as mc')
            ->leftJoin('caregivers as cg', 'cg.id', '=', 'mc.caregiver_id')
            ->leftJoin('users as cu', 'cu.id', '=', 'cg.user_id')
            ->where('mc.request_id', $id)
            ->orderBy('mc.rank')
            ->select('mc.id', 'cu.name as caregiver_name', 'mc.caregiver_id', 'mc.source', 'mc.ai_score', 'mc.rank', 'mc.response', 'mc.responded_at')
            ->get();

        return response()->json(['success' => true, 'data' => [
            'id' => $r->id,
            'recipient_name' => $r->recipient_name ?? '(미상)',
            'guardian_name' => $r->guardian_name,
            'service_domain' => $r->service_domain,
            'mode' => $r->mode,
            'scheduled_start' => $r->scheduled_start,
            'duration_min' => (int) $r->duration_min,
            'status' => $r->status,
            'special_request' => $r->special_request,
            'created_at' => $r->created_at,
            'matched_at' => $r->matched_at,
            'senior' => [
                'gender' => $r->senior_gender,
                'care_grade' => $r->care_grade,
                'special_notes' => $r->special_notes,
            ],
            'address' => [
                'label' => $r->addr_label,
                'address' => $r->addr_full ?? $r->home_address,
                'entry_note' => $r->entry_note,
            ],
            'matched_caregiver' => $match ? [
                'name' => $match->caregiver_name,
                'id' => (int) $match->caregiver_id,
                'status' => $match->match_status,
                'scheduled_start' => $match->scheduled_start,
                'scheduled_end' => $match->scheduled_end,
                'estimated_amount' => $match->estimated_amount !== null ? (int) $match->estimated_amount : null,
            ] : null,
            'candidates' => $cands->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->caregiver_name,
                'caregiver_id' => (int) $c->caregiver_id,
                'source' => $c->source ?? 'ai',
                'ai_score' => (float) $c->ai_score,
                'rank' => (int) $c->rank,
                'response' => $c->response,
                'responded_at' => $c->responded_at,
            ]),
        ]]);
    }

    public function matchingRequests(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $candidateCount = DB::table('match_candidates')
            ->select('request_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('request_id');

        $latestMatch = DB::table('matches')
            ->select('request_id', DB::raw('MAX(id) as mid'))
            ->whereIn('status', ['confirmed', 'in_progress', 'completed'])
            ->groupBy('request_id');

        $query = DB::table('match_requests as r')
            ->leftJoin('seniors as s', 's.id', '=', 'r.senior_id')
            ->leftJoin('nursing_patients as np', 'np.id', '=', 'r.nursing_patient_id')
            ->leftJoin('service_addresses as sa', 'sa.id', '=', 'r.service_address_id')
            ->leftJoinSub($candidateCount, 'mc', 'mc.request_id', '=', 'r.id')
            ->leftJoinSub($latestMatch, 'lm', 'lm.request_id', '=', 'r.id')
            ->leftJoin('matches as m', 'm.id', '=', 'lm.mid')
            ->leftJoin('caregivers as cg', 'cg.id', '=', 'm.caregiver_id')
            ->leftJoin('users as cu', 'cu.id', '=', 'cg.user_id')
            ->leftJoin('guardians as g', 'g.id', '=', 'r.guardian_id')
            ->leftJoin('users as gu', 'gu.id', '=', 'g.user_id')
            ->select(
                'r.id', 'r.senior_id',
                DB::raw('COALESCE(s.name, np.name, sa.label) as senior_name'),
                DB::raw('gu.name as guardian_name'),
                'r.mode', 'r.service_domain', 'r.scheduled_start',
                'r.status', 'r.created_at',
                DB::raw('COALESCE(mc.cnt, 0) as candidate_count'),
                DB::raw('cu.name as matched_caregiver_name'),
                DB::raw('m.caregiver_id as matched_caregiver_id')
            );

        if ($request->filled('status')) {
            $query->where('r.status', $request->input('status'));
        }
        if ($request->filled('domain')) {
            $query->where('r.service_domain', $request->input('domain'));
        }

        $paginated = $query->orderByDesc('r.created_at')->paginate($perPage);

        $items = collect($paginated->items())->map(fn ($r) => [
            'id' => $r->id,
            'senior_name' => $r->senior_name ?? '(미상)',
            'guardian_name' => $r->guardian_name,
            'mode' => $r->mode,
            'service_domain' => $r->service_domain,
            'scheduled_start' => $r->scheduled_start,
            'status' => $r->status,
            'candidate_count' => (int) $r->candidate_count,
            'matched_caregiver_name' => $r->matched_caregiver_name,
            'matched_caregiver_id' => $r->matched_caregiver_id ? (int) $r->matched_caregiver_id : null,
            'created_at' => $r->created_at,
        ]);

        $countsQ = DB::table('match_requests as r');
        if ($request->filled('domain')) {
            $countsQ->where('r.service_domain', $request->input('domain'));
        }
        $byStatus = (clone $countsQ)->select('r.status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('r.status')->pluck('cnt', 'status');
        $counts = [
            'all' => (int) array_sum($byStatus->all()),
            'open' => (int) ($byStatus['open'] ?? 0),
            'matching' => (int) ($byStatus['matching'] ?? 0),
            'matched' => (int) ($byStatus['matched'] ?? 0),
            'expired' => (int) ($byStatus['expired'] ?? 0),
            'cancelled' => (int) ($byStatus['cancelled'] ?? 0),
        ];

        return response()->json([
            'success' => true,
            'data' => $items,
            'counts' => $counts,
            'meta' => $this->meta($paginated),
        ]);
    }

    /** POST /v1/admin/matching/requests/{id}/manual-assign — 운영자 수동 매칭 */
    public function manualAssign(Request $request, int $id): JsonResponse
    {
        $request->validate(['caregiver_id' => 'required|integer']);
        $caregiverId = (int) $request->input('caregiver_id');

        $req = DB::table('match_requests')->where('id', $id)->first();
        if (! $req) {
            return response()->json(['success' => false, 'message' => '요청을 찾을 수 없습니다.'], 404);
        }
        $cg = DB::table('caregivers')->where('id', $caregiverId)->where('status', 'active')->first();
        if (! $cg) {
            return response()->json(['success' => false, 'message' => '활성 인력이 아닙니다.'], 422);
        }

        $now = now();
        $start = $req->scheduled_start ? \Illuminate\Support\Carbon::parse($req->scheduled_start) : $now->copy();
        $durationMin = (int) ($req->duration_min ?? 120);
        $baseRate = (float) (DB::table('service_categories')->where('id', $req->category_id)->value('base_rate') ?? 0);
        $days = 1;
        if (($req->mode ?? null) === 'recurring') {
            $rule = json_decode($req->recurrence_rule ?? 'null', true);
            $days = max(1, min((int) ($rule['days'] ?? 1), 30));
        }
        $end = $start->copy()->addDays($days - 1)->addMinutes($durationMin);
        $estimated = round($baseRate * $durationMin / 60) * $days;

        DB::transaction(function () use ($id, $caregiverId, $req, $start, $end, $now, $durationMin, $baseRate, $estimated, $days) {
            // 1) 후보 등록(수동) — 중복 방지
            $exists = DB::table('match_candidates')
                ->where('request_id', $id)->where('caregiver_id', $caregiverId)->exists();
            if (! $exists) {
                DB::table('match_candidates')->insert([
                    'request_id' => $id,
                    'caregiver_id' => $caregiverId,
                    'ai_score' => 1.000,
                    'ai_reasons' => json_encode(['운영자 수동 매칭'], JSON_UNESCAPED_UNICODE),
                    'rank' => 1,
                    'response' => 'accepted',
                    'responded_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('match_candidates')
                    ->where('request_id', $id)->where('caregiver_id', $caregiverId)
                    ->update(['response' => 'accepted', 'responded_at' => $now, 'updated_at' => $now]);
            }

            // 2) 매칭(계약) 생성
            $matchId = DB::table('matches')->insertGetId([
                'request_id' => $id,
                'caregiver_id' => $caregiverId,
                'scheduled_start' => $start,
                'scheduled_end' => $end,
                'hourly_rate' => $baseRate,
                'estimated_amount' => $estimated,
                'is_manual' => 1,
                'status' => 'confirmed',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // 2-1) 일별 케어 세션 생성 (앱 '내 케어 일정' 노출 + 체크인/아웃 단위). acceptByCaregiver 경로와 동일.
            for ($i = 0; $i < $days; $i++) {
                DB::table('care_sessions')->insert([
                    'match_id' => $matchId,
                    'scheduled_start' => $start->copy()->addDays($i),
                    'scheduled_end' => $start->copy()->addDays($i)->addMinutes($durationMin),
                    'duration_min' => $durationMin,
                    'status' => 'scheduled',
                    'review_status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // 3) 요청 상태 갱신
            DB::table('match_requests')->where('id', $id)
                ->update(['status' => 'matched', 'matched_at' => $now, 'updated_at' => $now]);
        });

        return response()->json(['success' => true, 'message' => '수동 매칭이 완료되었습니다.']);
    }

    /* ===================== #19 회원·인력 통합관리 ===================== */

    /** GET /v1/admin/members — 회원 통합 목록 (role 필터) */
    /**
     * GET /v1/admin/members/{id}
     * 회원 상세 (돌봄전문가는 자격정보 포함)
     */
    /**
     * POST /v1/admin/members
     * 관리자 회원 추가(역할별 프로필 포함)
     */
    public function createMember(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'phone' => ['required', 'string', 'regex:/^01[0-9]\d{7,8}$/', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'in:guardian,caregiver,organization,admin'],
            'relation' => ['nullable', 'string', 'max:20'],
            'gender' => ['required_if:role,caregiver', 'in:M,F'],
            'birth_date' => ['required_if:role,caregiver', 'date'],
            'base_address' => ['required_if:role,caregiver', 'string', 'max:255'],
            'service_domains' => ['nullable', 'string', 'max:60'],
            'license_no' => ['nullable', 'string', 'max:30'],
            'license_photo' => ['nullable', 'image', 'max:5120'],
            'biz_no' => ['nullable', 'string', 'max:20'],
            'representative' => ['nullable', 'string', 'max:50'],
            'biz_type' => ['nullable', 'string', 'max:40'],
            'permission_level' => ['nullable', 'in:super,operator,cs,analyst'],
        ]);

        $licenseUrl = null;
        if ($request->hasFile('license_photo')) {
            $path = $request->file('license_photo')->store('licenses', 'public');
            $licenseUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($path);
        }

        $id = DB::transaction(function () use ($data, $licenseUrl) {
            $now = now();
            $uid = DB::table('users')->insertGetId([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'role' => $data['role'],
                'password' => \Illuminate\Support\Facades\Hash::make($data['password']),
                'status' => 'active',
                'phone_verified_at' => $now,
                'email_verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($data['role'] === 'guardian') {
                DB::table('guardians')->insert(['user_id' => $uid, 'relation' => $data['relation'] ?? '미지정', 'created_at' => $now, 'updated_at' => $now]);
            } elseif ($data['role'] === 'caregiver') {
                DB::table('caregivers')->insert(['user_id' => $uid, 'birth_date' => $data['birth_date'], 'gender' => $data['gender'], 'base_address' => $data['base_address'], 'service_domains' => $data['service_domains'] ?? 'senior', 'license_no' => $data['license_no'] ?? null, 'license_image_url' => $licenseUrl, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now]);
            } elseif ($data['role'] === 'organization') {
                DB::table('organizations')->insert(['user_id' => $uid, 'name' => $data['name'], 'biz_no' => $data['biz_no'] ?? null, 'representative' => $data['representative'] ?? null, 'biz_type' => $data['biz_type'] ?? 'other', 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now]);
            } elseif ($data['role'] === 'admin') {
                DB::table('admins')->insert(['user_id' => $uid, 'permission_level' => $data['permission_level'] ?? 'operator', 'department' => null, 'created_at' => $now, 'updated_at' => $now]);
            }
            return $uid;
        });

        return response()->json(['success' => true, 'message' => '회원이 추가되었습니다.', 'data' => ['id' => $id]], 201);
    }

    /**
     * PATCH /v1/admin/members/{id}/status
     * 회원 계정 상태 변경(정지/탈퇴/활성)
     */
    public function updateMemberStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,suspended,withdrawn'],
        ]);

        if ($request->user() && (int) $request->user()->id === $id) {
            return response()->json([
                'success' => false,
                'error_code' => 'SELF_FORBIDDEN',
                'message' => '본인 계정의 상태는 변경할 수 없습니다.',
            ], 422);
        }

        $u = DB::table('users')->whereNull('deleted_at')->where('id', $id)->first();
        if (!$u) {
            return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => '회원을 찾을 수 없습니다.'], 404);
        }

        DB::table('users')->where('id', $id)->update(['status' => $data['status'], 'updated_at' => now()]);

        return response()->json(['success' => true, 'message' => '회원 상태가 변경되었습니다.', 'data' => ['id' => $id, 'status' => $data['status']]]);
    }

    /**
     * PATCH /v1/admin/organizations/{id}
     * 기관 정보 수정(분야 등)
     */
    public function updateOrganization(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'biz_type' => ['nullable', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'max:100'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'representative' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:pending,active,suspended'],
        ]);

        $org = DB::table('organizations')->where('id', $id)->first();
        if (!$org) {
            return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => '기관을 찾을 수 없습니다.'], 404);
        }

        $update = array_filter($data, fn ($v) => $v !== null);
        if (empty($update)) {
            return response()->json(['success' => false, 'error_code' => 'NO_FIELDS', 'message' => '변경할 항목이 없습니다.'], 422);
        }
        $update['updated_at'] = now();
        DB::table('organizations')->where('id', $id)->update($update);

        return response()->json(['success' => true, 'message' => '기관 정보가 수정되었습니다.', 'data' => ['id' => $id]]);
    }

    /**
     * PATCH /v1/admin/caregivers/{id}
     * 돌봄전문가 직군(service_domains) 수정
     */
    public function updateCaregiver(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'service_domains' => ['required', 'string', 'max:60'],
        ]);

        $allowed = ['senior', 'nursing', 'housekeeping', 'living_support', 'postpartum', 'companion', 'care'];
        $codes = array_values(array_unique(array_filter(array_map('trim', explode(',', $data['service_domains'])))));
        if (empty($codes) || array_diff($codes, $allowed)) {
            return response()->json(['success' => false, 'error_code' => 'INVALID_DOMAIN', 'message' => '유효하지 않은 직군입니다.'], 422);
        }

        $cg = DB::table('caregivers')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$cg) {
            return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => '돌봄전문가를 찾을 수 없습니다.'], 404);
        }

        DB::table('caregivers')->where('id', $id)->update(['service_domains' => implode(',', $codes), 'updated_at' => now()]);

        return response()->json(['success' => true, 'message' => '직군이 수정되었습니다.', 'data' => ['id' => $id]]);
    }

    /**
     * PATCH /v1/admin/members/{id}/credentials
     * 슈퍼관리자 전용: 회원 로그인 ID(이메일)·연락처·비밀번호 관리
     */
    public function updateMemberCredentials(Request $request, int $id): JsonResponse
    {
        $level = optional($request->user()->admin)->permission_level;
        if ($level !== 'super') {
            return response()->json(['success' => false, 'error_code' => 'FORBIDDEN', 'message' => '슈퍼관리자만 사용할 수 있습니다.'], 403);
        }

        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:120', 'unique:users,email,' . $id],
            'phone' => ['nullable', 'regex:/^01[0-9]\\d{7,8}$/', 'unique:users,phone,' . $id],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $u = DB::table('users')->whereNull('deleted_at')->where('id', $id)->first();
        if (!$u) {
            return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => '회원을 찾을 수 없습니다.'], 404);
        }

        $update = [];
        if (!empty($data['email'])) {
            $update['email'] = $data['email'];
        }
        if (!empty($data['phone'])) {
            $update['phone'] = $data['phone'];
        }
        if (!empty($data['password'])) {
            $update['password'] = \Illuminate\Support\Facades\Hash::make($data['password']);
        }
        if (empty($update)) {
            return response()->json(['success' => false, 'error_code' => 'NO_FIELDS', 'message' => '변경할 항목이 없습니다.'], 422);
        }
        $update['updated_at'] = now();
        DB::table('users')->where('id', $id)->update($update);

        return response()->json(['success' => true, 'message' => '계정 정보가 수정되었습니다.', 'data' => ['id' => $id]]);
    }

    /**
     * PATCH /v1/admin/matching/requests/{id}/match
     * 매칭 변경: 담당 돌봄전문가 교체 / 일정 시작 변경 (보호자 요청 등). 기존 매칭 + care_sessions 동기화.
     */
    public function updateMatch(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'caregiver_id' => ['nullable', 'integer'],
            'scheduled_start' => ['nullable', 'date'],
        ]);
        if (empty($data['caregiver_id']) && empty($data['scheduled_start'])) {
            return response()->json(['success' => false, 'error_code' => 'NO_FIELDS', 'message' => '변경할 항목이 없습니다.'], 422);
        }

        $req = DB::table('match_requests')->where('id', $id)->first();
        if (!$req) {
            return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => '요청을 찾을 수 없습니다.'], 404);
        }
        $match = DB::table('matches')->where('request_id', $id)->orderByDesc('id')->first();
        if (!$match) {
            return response()->json(['success' => false, 'error_code' => 'NO_MATCH', 'message' => '아직 매칭되지 않은 요청입니다. 수동 매칭으로 먼저 배정하세요.'], 422);
        }

        $newCaregiverId = !empty($data['caregiver_id']) ? (int) $data['caregiver_id'] : (int) $match->caregiver_id;
        if ($newCaregiverId !== (int) $match->caregiver_id) {
            $cg = DB::table('caregivers')->where('id', $newCaregiverId)->where('status', 'active')->first();
            if (!$cg) {
                return response()->json(['success' => false, 'error_code' => 'INVALID_CAREGIVER', 'message' => '활성 돌봄전문가가 아닙니다.'], 422);
            }
        }

        DB::transaction(function () use ($id, $match, $data, $newCaregiverId) {
            $now = now();
            $delta = 0;
            $newStart = $match->scheduled_start ? \Illuminate\Support\Carbon::parse($match->scheduled_start) : $now->copy();
            if (!empty($data['scheduled_start'])) {
                $new = \Illuminate\Support\Carbon::parse($data['scheduled_start']);
                $old = $match->scheduled_start ? \Illuminate\Support\Carbon::parse($match->scheduled_start) : $new->copy();
                $delta = $new->getTimestamp() - $old->getTimestamp();
                $newStart = $new;
            }

            $upd = ['caregiver_id' => $newCaregiverId, 'is_manual' => 1, 'updated_at' => $now];
            if (!empty($data['scheduled_start'])) {
                $upd['scheduled_start'] = $newStart;
                $upd['scheduled_end'] = $match->scheduled_end ? \Illuminate\Support\Carbon::parse($match->scheduled_end)->addSeconds($delta) : null;
            }
            DB::table('matches')->where('id', $match->id)->update($upd);

            if (!empty($data['scheduled_start'])) {
                foreach (DB::table('care_sessions')->where('match_id', $match->id)->get() as $cs) {
                    DB::table('care_sessions')->where('id', $cs->id)->update([
                        'scheduled_start' => $cs->scheduled_start ? \Illuminate\Support\Carbon::parse($cs->scheduled_start)->addSeconds($delta) : $newStart,
                        'scheduled_end' => $cs->scheduled_end ? \Illuminate\Support\Carbon::parse($cs->scheduled_end)->addSeconds($delta) : null,
                        'updated_at' => $now,
                    ]);
                }
                DB::table('match_requests')->where('id', $id)->update(['scheduled_start' => $newStart, 'updated_at' => $now]);
            }

            if ($newCaregiverId !== (int) $match->caregiver_id) {
                $exists = DB::table('match_candidates')->where('request_id', $id)->where('caregiver_id', $newCaregiverId)->exists();
                if (!$exists) {
                    DB::table('match_candidates')->insert([
                        'request_id' => $id, 'caregiver_id' => $newCaregiverId, 'ai_score' => 1.000,
                        'ai_reasons' => json_encode(['운영자 매칭 변경'], JSON_UNESCAPED_UNICODE),
                        'rank' => 1, 'response' => 'accepted', 'responded_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                } else {
                    DB::table('match_candidates')->where('request_id', $id)->where('caregiver_id', $newCaregiverId)
                        ->update(['response' => 'accepted', 'responded_at' => $now, 'updated_at' => $now]);
                }
            }
        });

        return response()->json(['success' => true, 'message' => '매칭이 변경되었습니다.']);
    }

    /**
     * GET /v1/admin/monitoring/alerts — 전체 이상징후 알림(심각도/상태 필터)
     */
    public function monitoringAlerts(Request $request): JsonResponse
    {
        $status = $request->input('status');
        $severity = $request->input('severity');
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 5;

        $applyStatus = function ($q) use ($status) {
            if ($status === 'unresolved') {
                $q->whereIn('status', ['new', 'acknowledged', 'in_progress']);
            } elseif ($status === 'resolved') {
                $q->whereIn('status', ['resolved', 'dismissed']);
            }
            return $q;
        };

        $bySev = $applyStatus(DB::table('anomaly_alerts'))
            ->select('severity', DB::raw('COUNT(*) as c'))->groupBy('severity')->pluck('c', 'severity');

        $listBase = $applyStatus(DB::table('anomaly_alerts as a')->leftJoin('seniors as s', 's.id', '=', 'a.senior_id'));
        if ($severity && $severity !== 'all') {
            $listBase->where('a.severity', $severity);
        }

        $total = (clone $listBase)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));

        $rows = (clone $listBase)
            ->orderByDesc('a.detected_at')
            ->forPage($page, $perPage)
            ->select('a.id', 'a.senior_id', 's.name as senior_name', 's.care_grade', 'a.risk_type',
                'a.risk_score', 'a.severity', 'a.status', 'a.recommendation', 'a.resolution_note', 'a.detected_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rows->map(fn ($a) => [
                'id' => $a->id,
                'senior_id' => $a->senior_id,
                'senior_name' => $a->senior_name ?? '(미상)',
                'care_grade' => $a->care_grade,
                'risk_type' => $a->risk_type,
                'risk_score' => (float) $a->risk_score,
                'severity' => $a->severity,
                'status' => $a->status,
                'recommendation' => $a->recommendation,
                'resolution_note' => $a->resolution_note,
                'detected_at' => $a->detected_at,
                'detected_ago' => $a->detected_at ? \Illuminate\Support\Carbon::parse($a->detected_at)->diffForHumans() : null,
            ]),
            'meta' => [
                'total' => $total,
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'status_total' => (int) array_sum($bySev->all()),
                'by_severity' => [
                    'critical' => (int) ($bySev['critical'] ?? 0),
                    'high' => (int) ($bySev['high'] ?? 0),
                    'mid' => (int) ($bySev['mid'] ?? 0),
                    'low' => (int) ($bySev['low'] ?? 0),
                ],
            ],
        ]);
    }

    public function acknowledgeAlert(Request $request, int $id): JsonResponse
    {
        if (!DB::table('anomaly_alerts')->where('id', $id)->exists()) {
            return response()->json(['success' => false, 'message' => '알림을 찾을 수 없습니다.'], 404);
        }
        $update = ['status' => 'acknowledged', 'updated_at' => now()];
        // 어떤 대응을 기록했는지(긴급 방문 배정/보호자 알림/119 연계 등) 감사용으로 보존
        $note = $request->input('action_note');
        if (is_string($note) && trim($note) !== '') {
            $update['resolution_note'] = mb_substr(trim($note), 0, 255);
        }
        DB::table('anomaly_alerts')->where('id', $id)->update($update);
        return response()->json(['success' => true, 'message' => '확인 처리되었습니다.']);
    }

    public function resolveAlert(Request $request, int $id): JsonResponse
    {
        if (!DB::table('anomaly_alerts')->where('id', $id)->exists()) {
            return response()->json(['success' => false, 'message' => '알림을 찾을 수 없습니다.'], 404);
        }
        DB::table('anomaly_alerts')->where('id', $id)->update([
            'status' => 'resolved',
            'resolution_note' => $request->input('resolution_note', '관리자 처리'),
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['success' => true, 'message' => '해결 처리되었습니다.']);
    }

    public function memberDetail(Request $request, int $id): JsonResponse
    {
        $u = DB::table('users')->whereNull('deleted_at')
            ->select('id', 'name', 'email', 'phone', 'role', 'status', 'created_at')
            ->where('id', $id)->first();

        if (!$u) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_FOUND',
                'message' => '회원을 찾을 수 없습니다.',
            ], 404);
        }

        $detail = [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'role' => $u->role,
            'status' => $u->status,
            'created_at' => $u->created_at,
            'caregiver' => null,
            'guardian' => null,
            'organization' => null,
        ];

        if ($u->role === 'caregiver') {
            $c = DB::table('caregivers')->where('user_id', $id)->whereNull('deleted_at')->first();
            if ($c) {
                $spec = $c->specialties ? json_decode($c->specialties, true) : [];
                $detail['caregiver'] = [
                    'id' => $c->id,
                    'status' => $c->status,
                    'gender' => $c->gender,
                    'birth_date' => $c->birth_date,
                    'license_no' => $c->license_no,
                    'license_image_url' => $c->license_image_url,
                    'license_verified' => $c->license_verified_at !== null,
                    'license_issued_at' => $c->license_issued_at,
                    'specialties' => is_array($spec) ? $spec : [],
                    'service_domains' => $c->service_domains,
                    'career_track' => $c->career_track,
                    'base_address' => $c->base_address,
                    'rating_avg' => (float) $c->rating_avg,
                    'completed_sessions' => (int) $c->completed_sessions,
                    'grade_level' => (int) $c->grade_level,
                    'rejection_reason' => $c->rejection_reason,
                    'created_at' => $c->created_at,
                ];
            }
        }

        if ($u->role === 'guardian') {
            $g = DB::table('guardians')->where('user_id', $id)->first();
            if ($g) {
                $seniors = DB::table('seniors')->where('guardian_id', $g->id)->whereNull('deleted_at')
                    ->select('name', 'care_grade')->get()
                    ->map(fn ($x) => ['name' => $x->name, 'care_grade' => $x->care_grade !== null ? (int) $x->care_grade : null]);
                $patients = DB::table('nursing_patients')->where('guardian_id', $g->id)->whereNull('deleted_at')
                    ->select('name', 'hospital_name')->get()
                    ->map(fn ($x) => ['name' => $x->name, 'hospital_name' => $x->hospital_name]);
                $detail['guardian'] = [
                    'id' => $g->id,
                    'relation' => $g->relation,
                    'contact_address' => $g->contact_address,
                    'seniors' => $seniors->values(),
                    'patients' => $patients->values(),
                ];
            }
        }

        if ($u->role === 'organization') {
            $o = DB::table('organizations')->where('user_id', $id)->first();
            if ($o) {
                $detail['organization'] = [
                    'id' => $o->id,
                    'name' => $o->name,
                    'biz_no' => $o->biz_no,
                    'representative' => $o->representative,
                    'contact_phone' => $o->contact_phone,
                    'address' => $o->address,
                    'biz_type' => $o->biz_type,
                    'status' => $o->status,
                ];
            }
        }

        return response()->json(['success' => true, 'data' => $detail]);
    }

    public function members(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $query = DB::table('users')
            ->leftJoin('caregivers as cg', function ($j) {
                $j->on('cg.user_id', '=', 'users.id')->whereNull('cg.deleted_at');
            })
            ->leftJoin('guardians as g', 'g.user_id', '=', 'users.id')
            ->select('users.id', 'users.name', 'users.email', 'users.phone', 'users.role', 'users.status', 'users.created_at', 'cg.status as caregiver_status', 'cg.service_domains', 'g.intent as guardian_intent')
            ->whereNull('users.deleted_at');

        if ($request->filled('role')) {
            $role = $request->input('role');
            // 가상 역할: housekeeping=가사요청자(role=guardian + intent=housekeeping),
            // guardian=순수 보호자(intent=care 또는 미지정)
            if ($role === 'housekeeping') {
                $query->where('users.role', 'guardian')->where('g.intent', 'housekeeping');
            } elseif ($role === 'guardian') {
                $query->where('users.role', 'guardian')
                    ->where(function ($w) {
                        $w->where('g.intent', '<>', 'housekeeping')->orWhereNull('g.intent');
                    });
            } else {
                $query->where('users.role', $role);
            }
        }
        if ($request->filled('q')) {
            $kw = $request->input('q');
            $query->where(function ($w) use ($kw) {
                $w->where('users.name', 'like', "%{$kw}%")->orWhere('users.email', 'like', "%{$kw}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('users.status', $request->input('status'));
        }

        $sort = $request->input('sort', 'recent');
        if ($sort === 'name') {
            $query->orderBy('users.name');
        } elseif ($sort === 'oldest') {
            $query->orderBy('users.created_at');
        } else {
            $query->orderByDesc('users.created_at');
        }
        $paginated = $query->paginate($perPage);

        $cgStatus = DB::table('caregivers')
            ->whereIn('user_id', collect($paginated->items())->pluck('id'))
            ->pluck('status', 'user_id');

        $items = collect($paginated->items())->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'role' => $u->role,
            // 가사요청자는 role=guardian이지만 intent로 구분. 보호자는 care(미지정 포함).
            'intent' => $u->role === 'guardian' ? ($u->guardian_intent ?? 'care') : null,
            'status' => $u->status,
            'caregiver_status' => $u->role === 'caregiver' ? ($cgStatus[$u->id] ?? null) : null,
            'service_domains' => $u->role === 'caregiver' ? $u->service_domains : null,
            'created_at' => $u->created_at,
        ]);

        $counts = DB::table('users')->whereNull('deleted_at')
            ->select('role', DB::raw('COUNT(*) as cnt'))->groupBy('role')->pluck('cnt', 'role');

        // 보호자(guardian) 내에서 가사요청자(housekeeping) 분리 집계
        $housekeeping = (int) DB::table('users')
            ->join('guardians as g', 'g.user_id', '=', 'users.id')
            ->whereNull('users.deleted_at')
            ->where('users.role', 'guardian')
            ->where('g.intent', 'housekeeping')
            ->count();
        $guardianTotal = (int) ($counts['guardian'] ?? 0);

        return response()->json([
            'success' => true,
            'data' => $items,
            'summary' => [
                // 순수 보호자 = 전체 guardian - 가사요청자 (intent 미지정 레거시는 보호자로 집계)
                'guardian' => $guardianTotal - $housekeeping,
                'housekeeping' => $housekeeping,
                'caregiver' => (int) ($counts['caregiver'] ?? 0),
                'organization' => (int) ($counts['organization'] ?? 0),
                'admin' => (int) ($counts['admin'] ?? 0),
            ],
            'meta' => $this->meta($paginated),
        ]);
    }

    /* ===================== #23 정산 ===================== */

    /** GET /v1/admin/settlements — 전체 정산 + 요약 */
    /**
     * GET /v1/admin/settlements/{id}
     * 정산 상세 (요약 + 항목 내역)
     */
    public function settlementDetail(Request $request, int $id): JsonResponse
    {
        $st = DB::table('settlements as st')
            ->leftJoin('caregivers as cg', 'cg.id', '=', 'st.caregiver_id')
            ->leftJoin('users as cu', 'cu.id', '=', 'cg.user_id')
            ->where('st.id', $id)
            ->select(
                'st.id', 'cu.name as caregiver_name', 'st.period_start', 'st.period_end',
                'st.gross_amount', 'st.withholding_tax_3_3 as withholding_tax', 'st.net_amount',
                'st.status', 'st.hometax_filing_no', 'st.bank_tx_id', 'st.confirmed_at', 'st.paid_at', 'st.created_at'
            )
            ->first();

        if (!$st) {
            return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => '정산을 찾을 수 없습니다.'], 404);
        }

        $items = DB::table('settlement_items as si')
            ->leftJoin('care_sessions as cs', 'cs.id', '=', 'si.session_id')
            ->where('si.settlement_id', $id)
            ->orderBy('cs.scheduled_start')
            ->select('si.id', 'si.session_id', 'cs.scheduled_start', 'si.hours', 'si.hourly_rate', 'si.amount', 'si.surcharge')
            ->get();

        return response()->json(['success' => true, 'data' => [
            'id' => $st->id,
            'caregiver_name' => $st->caregiver_name ?? '(미상)',
            'period_start' => $st->period_start,
            'period_end' => $st->period_end,
            'gross_amount' => (int) $st->gross_amount,
            'withholding_tax' => (int) $st->withholding_tax,
            'net_amount' => (int) $st->net_amount,
            'status' => $st->status,
            'hometax_filing_no' => $st->hometax_filing_no,
            'bank_tx_id' => $st->bank_tx_id,
            'confirmed_at' => $st->confirmed_at,
            'paid_at' => $st->paid_at,
            'created_at' => $st->created_at,
            'items' => $items->map(fn ($i) => [
                'id' => $i->id,
                'session_id' => $i->session_id,
                'scheduled_start' => $i->scheduled_start,
                'hours' => (float) $i->hours,
                'hourly_rate' => (int) $i->hourly_rate,
                'amount' => (int) $i->amount,
                'surcharge' => (int) $i->surcharge,
            ]),
        ]]);
    }

    public function settlements(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 50), 100);

        $query = DB::table('settlements as st')
            ->leftJoin('caregivers as c', 'c.id', '=', 'st.caregiver_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->select(
                'st.id', 'u.name as caregiver_name',
                'st.period_start', 'st.period_end',
                'st.gross_amount', 'st.withholding_tax_3_3', 'st.net_amount',
                'st.status', 'st.hometax_filing_no', 'st.paid_at'
            );

        if ($request->filled('status')) {
            $query->where('st.status', $request->input('status'));
        }

        $paginated = $query->orderByDesc('st.period_start')->paginate($perPage);

        $items = collect($paginated->items())->map(fn ($s) => [
            'id' => $s->id,
            'caregiver_name' => $s->caregiver_name ?? '(미상)',
            'period_start' => $s->period_start,
            'period_end' => $s->period_end,
            'gross_amount' => (int) $s->gross_amount,
            'withholding_tax' => (int) $s->withholding_tax_3_3,
            'net_amount' => (int) $s->net_amount,
            'status' => $s->status,
            'hometax_filing_no' => $s->hometax_filing_no,
            'paid_at' => $s->paid_at,
        ]);

        $agg = DB::table('settlements')->selectRaw(
            'COUNT(DISTINCT caregiver_id) as caregivers,
             COALESCE(SUM(gross_amount),0) as gross,
             COALESCE(SUM(withholding_tax_3_3),0) as tax,
             COALESCE(SUM(net_amount),0) as net'
        )->first();

        return response()->json([
            'success' => true,
            'data' => $items,
            'summary' => [
                'caregivers' => (int) $agg->caregivers,
                'gross_amount' => (int) $agg->gross,
                'withholding_tax' => (int) $agg->tax,
                'net_amount' => (int) $agg->net,
            ],
            'meta' => $this->meta($paginated),
        ]);
    }

    /* ===================== helper ===================== */

    private function meta($paginated): array
    {
        return [
            'total' => $paginated->total(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
        ];
    }
}
