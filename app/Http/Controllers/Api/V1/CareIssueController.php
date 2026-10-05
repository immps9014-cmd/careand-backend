<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use App\Support\Kst;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * 보호자의 돌봄전문가 교체 요청·신고(2026-10-05, CAREN-TODO-01 「회원 화면」).
 * 매칭 단위로 남기고 CS 담당자에게 알림. 실제 교체(남은 일정 재배정)는 운영팀이 매칭 관리에서 처리한다.
 * 같은 매칭에 처리 전(open·in_progress) 건이 있으면 새로 받지 않는다(중복 접수 방지).
 */
class CareIssueController extends Controller
{
    public const KINDS = ['replace' => '돌봄전문가 교체 요청', 'report' => '신고'];

    public const CATEGORIES = [
        'late' => '지각',
        'no_show' => '방문하지 않음',
        'attitude' => '불친절·태도',
        'skill' => '돌봄 방식·숙련도',
        'safety' => '안전 문제',
        'hygiene' => '위생',
        'privacy' => '사생활 침해',
        'other' => '기타',
    ];

    public const STATUSES = ['open' => '접수', 'in_progress' => '처리 중', 'resolved' => '처리 완료', 'rejected' => '반려'];

    /** GET /v1/matching/requests/{id}/issues — 이 요청에 내가 남긴 교체 요청·신고 */
    public function index(Request $request, int $id): JsonResponse
    {
        $own = $this->ownRequest($request, $id);
        if (!$own) {
            return response()->json(['success' => false, 'message' => '요청을 찾을 수 없어요.'], 404);
        }
        $rows = DB::table('care_issue_reports')->where('request_id', $id)->orderByDesc('id')->get();

        return response()->json(['success' => true, 'data' => [
            'issues' => $rows->map(fn ($r) => self::present($r))->values(),
            'kinds' => self::KINDS,
            'categories' => self::CATEGORIES,
            'can_report' => $this->liveMatch($id) !== null,
        ]]);
    }

    /** POST /v1/matching/requests/{id}/issues {kind, category, detail, care_session_id?} */
    public function store(Request $request, int $id): JsonResponse
    {
        if (!$this->ownRequest($request, $id)) {
            return response()->json(['success' => false, 'message' => '요청을 찾을 수 없어요.'], 404);
        }
        $v = $request->validate([
            'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'category' => ['required', Rule::in(array_keys(self::CATEGORIES))],
            'detail' => ['required', 'string', 'min:5', 'max:2000'],
            'care_session_id' => ['nullable', 'integer'],
        ], ['detail.min' => '어떤 일이 있었는지 5자 이상 적어 주세요.', 'detail.required' => '어떤 일이 있었는지 적어 주세요.']);

        $match = $this->liveMatch($id);
        if (!$match) {
            return response()->json(['success' => false, 'error_code' => 'NO_MATCH', 'message' => '매칭된 돌봄전문가가 있을 때 남길 수 있어요.'], 422);
        }
        $sessionId = null;
        if (!empty($v['care_session_id'])) {
            $sessionId = DB::table('care_sessions')->where('id', $v['care_session_id'])->where('match_id', $match->id)->value('id');
            if (!$sessionId) {
                return response()->json(['success' => false, 'message' => '방문 일정을 찾을 수 없어요.'], 422);
            }
        }
        $open = DB::table('care_issue_reports')->where('match_id', $match->id)->where('kind', $v['kind'])
            ->whereIn('status', ['open', 'in_progress'])->exists();
        if ($open) {
            return response()->json(['success' => false, 'error_code' => 'ALREADY_OPEN',
                'message' => '이미 접수된 ' . self::KINDS[$v['kind']] . '이 처리 중이에요. 답변을 기다려 주세요.'], 422);
        }

        $iid = DB::table('care_issue_reports')->insertGetId([
            'match_id' => $match->id, 'request_id' => $id, 'caregiver_id' => $match->caregiver_id,
            'reporter_user_id' => $request->user()->id, 'care_session_id' => $sessionId,
            'kind' => $v['kind'], 'category' => $v['category'], 'detail' => trim($v['detail']),
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $svc = app(NotificationService::class);
        $cgName = DB::table('caregivers as c')->join('users as u', 'u.id', '=', 'c.user_id')->where('c.id', $match->caregiver_id)->value('u.name');
        $payload = ['issue_id' => $iid, 'request_id' => $id, 'kind' => $v['kind'], 'kind_label' => self::KINDS[$v['kind']],
            'category_label' => self::CATEGORIES[$v['category']], 'caregiver_name' => $cgName];
        foreach ($svc->adminsFor('cs') as $a) {
            $svc->notifySafely($a, NotificationService::TYPE_CARE_ISSUE_REPORTED, $payload);
        }

        return response()->json(['success' => true,
            'message' => $v['kind'] === 'replace' ? '교체 요청을 받았어요. 운영팀이 확인하고 연락드릴게요.' : '신고를 받았어요. 운영팀이 확인하고 답변드릴게요.',
            'data' => self::present(DB::table('care_issue_reports')->where('id', $iid)->first())], 201);
    }

    public static function present(object $r, bool $admin = false): array
    {
        $out = [
            'id' => (int) $r->id,
            'kind' => $r->kind, 'kind_label' => self::KINDS[$r->kind] ?? $r->kind,
            'category' => $r->category, 'category_label' => self::CATEGORIES[$r->category] ?? $r->category,
            'detail' => $r->detail,
            'status' => $r->status, 'status_label' => self::STATUSES[$r->status] ?? $r->status,
            'admin_reply' => $r->admin_reply,
            'handled_at' => Kst::iso($r->handled_at),
            'created_at' => Kst::iso($r->created_at),
            'care_session_id' => $r->care_session_id ? (int) $r->care_session_id : null,
        ];
        if ($admin) {
            $out += ['match_id' => (int) $r->match_id, 'request_id' => (int) $r->request_id, 'caregiver_id' => (int) $r->caregiver_id];
        }

        return $out;
    }

    private function ownRequest(Request $request, int $id): bool
    {
        return DB::table('match_requests as r')->join('guardians as g', 'g.id', '=', 'r.guardian_id')
            ->where('r.id', $id)->where('g.user_id', $request->user()->id)->exists();
    }

    /** 살아 있는(확정·진행·완료) 매칭 — 완료 뒤에도 신고는 받는다 */
    private function liveMatch(int $requestId): ?object
    {
        return DB::table('matches')->where('request_id', $requestId)
            ->whereIn('status', ['confirmed', 'in_progress', 'completed'])->orderByDesc('id')->first(['id', 'caregiver_id', 'status']);
    }
}
