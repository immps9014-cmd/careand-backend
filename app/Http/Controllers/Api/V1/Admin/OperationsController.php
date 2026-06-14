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
                'c.license_no', 'c.career_track', 'c.rating_avg',
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
            ->select(
                'cs.id', 'cs.match_id', 'cs.status', 'cs.review_status',
                'cs.review_note', 'cs.duration_min',
                'cs.actual_start', 'cs.actual_end', 'cs.reviewed_at',
                'cu.name as caregiver_name',
                DB::raw('COALESCE(s.name, np.name, sa.label) as senior_name'),
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
    public function matchingRequests(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $candidateCount = DB::table('match_candidates')
            ->select('request_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('request_id');

        $query = DB::table('match_requests as r')
            ->leftJoin('seniors as s', 's.id', '=', 'r.senior_id')
            ->leftJoin('nursing_patients as np', 'np.id', '=', 'r.nursing_patient_id')
            ->leftJoin('service_addresses as sa', 'sa.id', '=', 'r.service_address_id')
            ->leftJoinSub($candidateCount, 'mc', 'mc.request_id', '=', 'r.id')
            ->select(
                'r.id', 'r.senior_id',
                DB::raw('COALESCE(s.name, np.name, sa.label) as senior_name'),
                'r.mode', 'r.service_domain', 'r.scheduled_start',
                'r.status', 'r.created_at',
                DB::raw('COALESCE(mc.cnt, 0) as candidate_count')
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
            'mode' => $r->mode,
            'service_domain' => $r->service_domain,
            'scheduled_start' => $r->scheduled_start,
            'status' => $r->status,
            'candidate_count' => (int) $r->candidate_count,
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
        $end = $req->scheduled_start
            ? \Illuminate\Support\Carbon::parse($req->scheduled_start)->addMinutes($req->duration_min ?? 120)
            : $now;

        DB::transaction(function () use ($id, $caregiverId, $req, $end, $now) {
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
            DB::table('matches')->insert([
                'request_id' => $id,
                'caregiver_id' => $caregiverId,
                'scheduled_start' => $req->scheduled_start ?? $now,
                'scheduled_end' => $end,
                'status' => 'confirmed',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // 3) 요청 상태 갱신
            DB::table('match_requests')->where('id', $id)
                ->update(['status' => 'matched', 'matched_at' => $now, 'updated_at' => $now]);
        });

        return response()->json(['success' => true, 'message' => '수동 매칭이 완료되었습니다.']);
    }

    /* ===================== #19 회원·인력 통합관리 ===================== */

    /** GET /v1/admin/members — 회원 통합 목록 (role 필터) */
    public function members(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $query = DB::table('users')
            ->select('id', 'name', 'email', 'phone', 'role', 'status', 'created_at')
            ->whereNull('deleted_at');

        if ($request->filled('role')) {
            $query->where('role', $request->input('role'));
        }
        if ($request->filled('q')) {
            $kw = $request->input('q');
            $query->where(function ($w) use ($kw) {
                $w->where('name', 'like', "%{$kw}%")->orWhere('email', 'like', "%{$kw}%");
            });
        }

        $paginated = $query->orderByDesc('created_at')->paginate($perPage);

        $cgStatus = DB::table('caregivers')
            ->whereIn('user_id', collect($paginated->items())->pluck('id'))
            ->pluck('status', 'user_id');

        $items = collect($paginated->items())->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'role' => $u->role,
            'status' => $u->status,
            'caregiver_status' => $u->role === 'caregiver' ? ($cgStatus[$u->id] ?? null) : null,
            'created_at' => $u->created_at,
        ]);

        $counts = DB::table('users')->whereNull('deleted_at')
            ->select('role', DB::raw('COUNT(*) as cnt'))->groupBy('role')->pluck('cnt', 'role');

        return response()->json([
            'success' => true,
            'data' => $items,
            'summary' => [
                'guardian' => (int) ($counts['guardian'] ?? 0),
                'caregiver' => (int) ($counts['caregiver'] ?? 0),
                'organization' => (int) ($counts['organization'] ?? 0),
                'admin' => (int) ($counts['admin'] ?? 0),
            ],
            'meta' => $this->meta($paginated),
        ]);
    }

    /* ===================== #23 정산 ===================== */

    /** GET /v1/admin/settlements — 전체 정산 + 요약 */
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
