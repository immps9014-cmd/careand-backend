<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CareSession;
use App\Services\AlimtalkTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 케어일지 가족 공유 (기능 5, 2026-09-29)
 * 보호자가 승인된 일지로 읽기 전용 링크를 만든다. 링크로 보는 일지는 대상자 이름을 가리고(김○○),
 * 사진·의료진용 요약·연락처는 넣지 않는다. 7일 뒤 만료, 보호자가 언제든 해제.
 */
class CareLogShareController extends Controller
{
    private const TTL_DAYS = 7;

    /** POST /v1/care-sessions/{id}/share */
    public function create(Request $request, int $id): JsonResponse
    {
        $session = CareSession::with('match.request.guardian')->findOrFail($id);
        $guardian = $request->user()->guardian;
        if (!$guardian || $session->match?->request?->guardian_id !== $guardian->id) {
            return response()->json(['success' => false, 'message' => '본인 돌봄의 일지만 공유할 수 있어요.'], 403);
        }
        if ($session->review_status !== 'approved') {
            return response()->json(['success' => false, 'error_code' => 'NOT_APPROVED', 'message' => '보호자에게 전달된 일지만 공유할 수 있어요.'], 422);
        }
        $token = Str::random(40);
        DB::table('care_log_shares')->insert([
            'session_id' => $id, 'created_by' => $request->user()->id, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::TTL_DAYS), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['success' => true, 'data' => [
            'url' => 'https://caren.aiclaude.kr/app/shared/' . $token,
            'expires_at' => now()->addDays(self::TTL_DAYS)->toIso8601String(),
        ]], 201);
    }

    /** GET /v1/care-sessions/{id}/shares — 내가 만든 공유 링크(활성) */
    public function index(Request $request, int $id): JsonResponse
    {
        $rows = DB::table('care_log_shares')->where('session_id', $id)->where('created_by', $request->user()->id)
            ->whereNull('revoked_at')->where('expires_at', '>', now())->orderByDesc('id')
            ->get(['id', 'expires_at', 'view_count', 'last_viewed_at', 'created_at']);
        return response()->json(['success' => true, 'data' => $rows]);
    }

    /** DELETE /v1/care-log-shares/{shareId} */
    public function revoke(Request $request, int $shareId): JsonResponse
    {
        $n = DB::table('care_log_shares')->where('id', $shareId)->where('created_by', $request->user()->id)
            ->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        return response()->json(['success' => (bool) $n, 'message' => $n ? '공유 링크를 껐어요.' : '이미 꺼졌거나 없는 링크예요.'], $n ? 200 : 404);
    }

    /** GET /v1/public/care-logs/{token} — 로그인 없이(요청 제한) */
    public function show(string $token): JsonResponse
    {
        $share = DB::table('care_log_shares')->where('token_hash', hash('sha256', $token))->first();
        if (!$share || $share->revoked_at || Carbon::parse($share->expires_at)->isPast()) {
            return response()->json(['success' => false, 'message' => '만료되었거나 공유가 해제된 링크예요.'], 404);
        }
        $row = DB::table('care_sessions as cs')
            ->join('matches as m', 'm.id', '=', 'cs.match_id')
            ->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->leftJoin('seniors as s', 's.id', '=', 'r.senior_id')
            ->leftJoin('nursing_patients as np', 'np.id', '=', 'r.nursing_patient_id')
            ->leftJoin('postpartum_clients as pp', 'pp.id', '=', 'r.postpartum_client_id')
            ->leftJoin('children as ch', 'ch.id', '=', 'r.childcare_child_id')
            ->leftJoin('mental_care_clients as mcc', 'mcc.id', '=', 'r.mental_care_client_id')
            ->leftJoin('service_addresses as sa', 'sa.id', '=', 'r.service_address_id')
            ->where('cs.id', $share->session_id)->where('cs.review_status', 'approved')
            ->first(['cs.actual_start', 'cs.duration_min', 'r.service_domain',
                DB::raw('COALESCE(s.name, np.name, pp.name, ch.name, mcc.name, sa.label) as recipient_name')]);
        $sum = DB::table('ai_log_summaries')->where('session_id', $share->session_id)->orderByDesc('id')->first(['guardian_version', 'categorized']);
        if (!$row || !$sum) {
            return response()->json(['success' => false, 'message' => '일지를 볼 수 없어요.'], 404);
        }
        DB::table('care_log_shares')->where('id', $share->id)->update(['view_count' => DB::raw('view_count + 1'), 'last_viewed_at' => now()]);

        // 본문에 들어 있는 대상자 이름도 가린다
        $name = (string) $row->recipient_name;
        $masked = AlimtalkTemplates::maskName($name);
        $text = $name !== '' ? str_replace($name, $masked, (string) $sum->guardian_version) : (string) $sum->guardian_version;

        return response()->json(['success' => true, 'data' => [
            'recipient' => $masked,
            'service' => \App\Support\ServiceDomains::label((string) $row->service_domain),
            'date' => $row->actual_start ? Carbon::parse($row->actual_start, 'UTC')->setTimezone('Asia/Seoul')->format('Y-m-d') : null,
            'duration_min' => $row->duration_min !== null ? (int) $row->duration_min : null,
            'guardian_version' => $text,
            'categorized' => json_decode((string) $sum->categorized, true) ?: (object) [],
            'expires_at' => Carbon::parse($share->expires_at)->toIso8601String(),
        ]]);
    }
}
