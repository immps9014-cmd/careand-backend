<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Postpartum\Models\EpdsAssessment;
use App\Domains\Postpartum\Services\EpdsCalculatorService;
use App\Http\Controllers\Controller;
use App\Support\Kst;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * 관리자 — 산후우울(에딘버러) 검사 결과(2026-10-05). RBAC 영역 'epds'(조회·조치 기록 모두 운영 3등급).
 * 목록은 판정·총점·하위척도만 보이고, 문항별 응답은 산모 상세에서 열람 사유(X-Access-Reason, 5자 이상)를 받아야 보인다
 * — 정신건강 응답은 민감정보라 감사로그(AuditPersonalDataAccess)에 사유가 남게 한다.
 * 「조치 필요」 = 상담 권고·즉시 도움 필요 판정인데 운영팀 후속 조치(followup_status)가 아직 없는 검사.
 */
class EpdsAdminController extends Controller
{
    public const FOLLOWUPS = [
        'contacted' => '연락함',
        'linked' => '상담·마음돌봄 연결',
        'referred' => '의료기관 안내',
        'closed' => '추가 조치 없음',
    ];

    private const ATTENTION = ['high', 'critical'];

    /** GET /v1/admin/epds?status=open|done|all&risk=&days=&q= */
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'done', 'all'])],
            'risk' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical', 'attention'])],
            'days' => ['nullable', 'integer', Rule::in([30, 90, 365])],
            'q' => ['nullable', 'string', 'max:50'],
        ]);
        $days = (int) ($v['days'] ?? 90);
        $since = Carbon::now('Asia/Seoul')->subDays($days)->toDateString();

        $base = fn () => DB::table('epds_assessments as e')
            ->join('postpartum_clients as pc', 'pc.id', '=', 'e.postpartum_client_id')
            ->whereNull('pc.deleted_at')
            ->where('e.assessment_date', '>=', $since);

        $q = $base()
            ->when(($v['risk'] ?? null) === 'attention', fn ($q) => $q->whereIn('e.risk_level', self::ATTENTION))
            ->when(isset($v['risk']) && $v['risk'] !== 'attention', fn ($q) => $q->where('e.risk_level', $v['risk']))
            ->when(($v['status'] ?? 'all') === 'open', fn ($q) => $q->whereIn('e.risk_level', self::ATTENTION)->whereNull('e.followup_status'))
            ->when(($v['status'] ?? 'all') === 'done', fn ($q) => $q->whereNotNull('e.followup_status'))
            ->when(!empty($v['q']), fn ($q) => $q->where('pc.name', 'like', '%' . $v['q'] . '%'))
            ->orderByRaw("e.risk_level IN ('high','critical') AND e.followup_status IS NULL DESC")   // 조치 필요를 맨 위로
            ->orderByDesc('e.assessment_date')->orderByDesc('e.id')
            ->limit(200);

        $rows = EpdsAssessment::hydrate($q->get(['e.*'])->map(fn ($r) => (array) $r)->all());
        $names = DB::table('postpartum_clients')->whereIn('id', $rows->pluck('postpartum_client_id'))->pluck('name', 'id');
        $counts = DB::table('epds_assessments')->whereIn('postpartum_client_id', $rows->pluck('postpartum_client_id'))
            ->groupBy('postpartum_client_id')->selectRaw('postpartum_client_id, COUNT(*) n')->pluck('n', 'postpartum_client_id');

        $out = $rows->map(function (EpdsAssessment $a) use ($names, $counts) {
            $prev = EpdsAssessment::where('postpartum_client_id', $a->postpartum_client_id)
                ->where('assessment_date', '<', $a->assessment_date)->orderByDesc('assessment_date')->value('total_score');

            return self::row($a) + [
                'client_name' => $names[$a->postpartum_client_id] ?? '(삭제된 산모)',
                'prev_total' => $prev !== null ? (int) $prev : null,
                'tests' => (int) ($counts[$a->postpartum_client_id] ?? 1),
            ];
        })->values();

        $all = $base();
        $summary = [
            'open' => (clone $all)->whereIn('e.risk_level', self::ATTENTION)->whereNull('e.followup_status')->count(),
            'open_self_harm' => (clone $all)->whereIn('e.risk_level', self::ATTENTION)->whereNull('e.followup_status')->where('e.q10_score', '>=', 1)->count(),
            'tests' => (clone $all)->count(),
            'clients' => (clone $all)->distinct()->count('e.postpartum_client_id'),
            'by_risk' => (clone $all)->groupBy('e.risk_level')->selectRaw('e.risk_level, COUNT(*) n')->pluck('n', 'risk_level'),
        ];

        return response()->json(['success' => true, 'data' => [
            'days' => $days, 'summary' => $summary, 'rows' => $out, 'followups' => self::FOLLOWUPS,
        ]]);
    }

    /** GET /v1/admin/epds/clients/{clientId} — 산모별 이력·문항 응답. 열람 사유 필수 */
    public function client(Request $request, int $clientId): JsonResponse
    {
        $reason = trim(rawurldecode((string) $request->header('X-Access-Reason', '')));
        if (mb_strlen($reason) < 5) {
            return response()->json(['success' => false, 'error_code' => 'REASON_REQUIRED',
                'message' => '문항별 응답은 민감정보라 열람 사유(5자 이상)가 필요해요.'], 422);
        }
        $c = DB::table('postpartum_clients')->where('id', $clientId)->whereNull('deleted_at')
            ->first(['id', 'user_id', 'name', 'birth_date', 'delivery_date', 'delivery_type']);
        abort_if(!$c, 404);
        $user = DB::table('users')->where('id', $c->user_id)->first(['id', 'name', 'phone']);
        $contract = DB::table('mnh_contracts as m')
            ->leftJoin('caregivers as cg', 'cg.id', '=', 'm.caregiver_id')->leftJoin('users as u', 'u.id', '=', 'cg.user_id')
            ->where('m.postpartum_client_id', $clientId)->whereIn('m.status', ['applied', 'confirmed', 'active'])
            ->orderByDesc('m.id')->first(['m.id', 'm.contract_no', 'm.status', 'u.name as caregiver_name']);

        $questions = collect(config('epds.questions'));
        $staff = [];
        $history = EpdsAssessment::where('postpartum_client_id', $clientId)->orderByDesc('assessment_date')->limit(30)->get()
            ->map(function (EpdsAssessment $a) use ($questions, &$staff) {
                $answers = $questions->map(function ($qq) use ($a) {
                    $score = (int) $a->{'q' . $qq['no'] . '_score'};
                    $label = collect($qq['options'])->first(fn ($o) => (int) $o[1] === $score)[0] ?? null;

                    return ['no' => $qq['no'], 'text' => $qq['text'], 'score' => $score, 'answer' => $label];
                })->values();
                if ($a->followed_up_by && !isset($staff[$a->followed_up_by])) {
                    $staff[$a->followed_up_by] = DB::table('users')->where('id', $a->followed_up_by)->value('name');
                }

                return self::row($a) + [
                    'answers' => $answers,
                    'followed_up_by_name' => $a->followed_up_by ? ($staff[$a->followed_up_by] ?? null) : null,
                ];
            })->values();

        return response()->json(['success' => true, 'data' => [
            'client' => [
                'id' => $c->id, 'name' => $c->name, 'birth_date' => $c->birth_date, 'delivery_date' => $c->delivery_date,
                'delivery_type' => $c->delivery_type,
                'guardian' => $user ? ['id' => $user->id, 'name' => $user->name, 'phone' => $user->phone] : null,
            ],
            'contract' => $contract,
            'history' => $history,
            'period' => config('epds.period'),
            'crisis_contacts' => config('epds.crisis_contacts'),
            'followups' => self::FOLLOWUPS,
        ]]);
    }

    /** POST /v1/admin/epds/{id}/followup {status, note} — 후속 조치 기록(덮어쓰기, 이력은 감사로그) */
    public function followup(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(self::FOLLOWUPS))],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $a = EpdsAssessment::findOrFail($id);
        $a->update(['followup_status' => $data['status'], 'followup_note' => $data['note'] ?? null,
            'followed_up_at' => now(), 'followed_up_by' => $request->user()->id]);

        return response()->json(['success' => true, 'message' => '조치를 기록했어요.', 'data' => self::row($a->fresh())]);
    }

    /** 목록·상세 공통 — 판정·하위척도(EpdsCalculatorService::report) + 조치 */
    private static function row(EpdsAssessment $a): array
    {
        $r = EpdsCalculatorService::report($a);

        return [
            'id' => $a->id,
            'client_id' => $a->postpartum_client_id,
            'date' => $r['date'],
            'total' => $r['total'],
            'risk_level' => $r['risk_level'],
            'risk_label' => $r['risk_label'],
            'self_harm' => $r['self_harm'],
            'subscales' => $r['subscales'],
            'recommend_mental_care' => $r['recommend_mental_care'],
            'needs_action' => in_array($a->risk_level, self::ATTENTION, true) && !$a->followup_status,
            'followup_status' => $a->followup_status,
            'followup_label' => $a->followup_status ? (self::FOLLOWUPS[$a->followup_status] ?? $a->followup_status) : null,
            'followup_note' => $a->followup_note,
            'followed_up_at' => Kst::iso($a->followed_up_at),
            'submitted_at' => Kst::iso($a->created_at),
        ];
    }
}
