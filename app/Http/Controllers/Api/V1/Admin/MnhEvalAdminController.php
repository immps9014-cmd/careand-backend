<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\MnhContractException;
use App\Http\Controllers\Controller;
use App\Models\MnhContract;
use App\Services\MnhEvaluationService;
use App\Support\Kst;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 관리자 — 기관 평가·양방향 평가 조회·종합평가 육각형(CAREN-MNH-01 4단계, 2026-10-05).
 */
class MnhEvalAdminController extends Controller
{
    public function __construct(private MnhEvaluationService $evals)
    {
    }

    /** GET /v1/admin/mnh/hexagons?months= — 산모신생아 돌봄전문가 전체 육각형 + 팀 평균 */
    public function hexagons(Request $request): JsonResponse
    {
        $since = $this->since($request);
        $cgs = DB::table('caregivers as cg')->join('users as u', 'u.id', '=', 'cg.user_id')
            ->whereNull('cg.deleted_at')->whereIn('cg.status', ['active', 'leave', 'suspended'])
            ->whereRaw("FIND_IN_SET('postpartum', cg.service_domains)")->orderBy('u.name')->get(['cg.id', 'u.name', 'cg.status']);
        $hex = $this->evals->hexagons($cgs->pluck('id')->all(), $since);

        return response()->json(['success' => true, 'data' => [
            'caregivers' => $cgs->map(fn ($c) => ['caregiver_id' => $c->id, 'name' => $c->name, 'status' => $c->status] + $hex[$c->id])->values(),
            'team_average' => MnhEvaluationService::teamAverage($hex),
            'axes' => collect(config('mnh_eval.axes'))->map(fn ($a, $k) => ['key' => $k, 'label' => $a['label'], 'sources' => $a['sources']])->values(),
            'min_samples' => (int) config('mnh_eval.min_samples'),
            'since' => $since?->toDateString(),
        ]]);
    }

    /** GET /v1/admin/mnh/caregivers/{id}/evaluation — 한 사람 육각형 + 최근 평가(기관·이용자 후기) */
    public function caregiver(Request $request, int $id): JsonResponse
    {
        $since = $this->since($request);
        $name = DB::table('caregivers as cg')->join('users as u', 'u.id', '=', 'cg.user_id')->where('cg.id', $id)->value('u.name');
        abort_unless($name !== null, 404);
        $org = DB::table('mnh_evaluations as e')->leftJoin('users as u', 'u.id', '=', 'e.evaluator_user_id')
            ->leftJoin('mnh_contracts as c', 'c.id', '=', 'e.contract_id')
            ->where('e.kind', 'org_to_caregiver')->where('e.caregiver_id', $id)->orderByDesc('e.id')->limit(30)
            ->get(['e.*', 'u.name as evaluator_name', 'c.contract_no'])
            ->map(fn ($e) => $this->evals->present($e) + ['evaluator_name' => $e->evaluator_name, 'contract_no' => $e->contract_no]);
        $reviewLabels = config('review_criteria.postpartum');
        $reviews = DB::table('reviews as rv')->join('matches as m', 'm.id', '=', 'rv.match_id')
            ->join('match_requests as r', 'r.id', '=', 'm.request_id')->leftJoin('mnh_contracts as c', 'c.id', '=', 'r.mnh_contract_id')
            ->where('m.caregiver_id', $id)->where('rv.reviewer_role', 'guardian')->where('r.service_domain', 'postpartum')
            ->orderByDesc('rv.id')->limit(30)->get(['rv.id', 'rv.rating', 'rv.scores', 'rv.comment', 'rv.created_at', 'c.contract_no'])
            ->map(function ($r) use ($reviewLabels) {
                $s = json_decode((string) $r->scores, true) ?: [];

                return ['id' => $r->id, 'rating' => (int) $r->rating, 'comment' => $r->comment, 'contract_no' => $r->contract_no,
                    'items' => collect($reviewLabels)->map(fn ($l, $k) => ['key' => $k, 'label' => $l, 'score' => $s[$k] ?? null])->values(),
                    'created_at' => Kst::iso($r->created_at)];
            });
        // 이 관리사가 맡은 바우처 계약(기관 평가 대상 고르기용)
        $contracts = DB::table('matches as m')->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->join('mnh_contracts as c', 'c.id', '=', 'r.mnh_contract_id')->leftJoin('postpartum_clients as pc', 'pc.id', '=', 'c.postpartum_client_id')
            ->where('m.caregiver_id', $id)->where('m.status', '!=', 'cancelled')->whereIn('c.status', ['confirmed', 'active', 'completed'])
            ->distinct()->orderByDesc('c.start_date')->get(['c.id', 'c.contract_no', 'c.status', 'pc.name as client_name']);

        return response()->json(['success' => true, 'data' => ['caregiver_id' => $id, 'name' => $name]
            + $this->evals->hexagons([$id], $since)[$id] + [
                'org_evaluations' => $org, 'reviews' => $reviews, 'contracts' => $contracts,
                'org_items' => collect(config('mnh_eval.org_to_caregiver'))->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            ]]);
    }

    /** POST /v1/admin/mnh/evaluations {caregiver_id, contract_id?, scores, comment, timing} — 기관 평가 */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'caregiver_id' => ['required', 'integer', 'exists:caregivers,id'], 'contract_id' => ['nullable', 'integer'],
            'scores' => ['required', 'array'], 'comment' => ['nullable', 'string', 'max:2000'], 'timing' => ['required', 'in:interim,final'],
        ]);
        $c = !empty($data['contract_id']) ? MnhContract::findOrFail($data['contract_id']) : null;
        if (!$c && $data['timing'] === 'final') {
            return $this->fail('CONTRACT_REQUIRED', '종료 평가는 계약을 골라야 해요.');
        }
        try {
            $e = $this->evals->submit('org_to_caregiver', $c, (int) $data['caregiver_id'], (int) $request->user()->id,
                $data['scores'], $data['comment'] ?? null, $data['timing']);
        } catch (MnhContractException $ex) {
            return $this->fail($ex->errorCode, $ex->getMessage(), $ex->status);
        }

        return response()->json(['success' => true, 'message' => '기관 평가를 저장했어요.', 'data' => $e]);
    }

    /** GET /v1/admin/mnh/contracts/{id}/evaluations — 계약의 양방향 평가 + 담당(이었던) 관리사 */
    public function forContract(int $id): JsonResponse
    {
        $c = MnhContract::findOrFail($id);
        $cgIds = $this->evals->contractCaregivers($c);
        $names = DB::table('caregivers as cg')->join('users as u', 'u.id', '=', 'cg.user_id')->whereIn('cg.id', $cgIds)->pluck('u.name', 'cg.id');

        return response()->json(['success' => true, 'data' => [
            'evaluations' => $this->evals->forContract($c),
            'caregivers' => collect($cgIds)->map(fn ($i) => ['caregiver_id' => $i, 'name' => $names[$i] ?? null])->values(),
            'org_items' => collect(config('mnh_eval.org_to_caregiver'))->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
        ]]);
    }

    private function since(Request $request): ?Carbon
    {
        $m = (int) $request->query('months', 0);

        return $m > 0 ? now()->subMonths(min($m, 60)) : null;
    }

    private function fail(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json(['success' => false, 'error_code' => $code, 'message' => $message], $status);
    }
}
