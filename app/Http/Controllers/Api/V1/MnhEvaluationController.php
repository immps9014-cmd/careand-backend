<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\MnhContractException;
use App\Http\Controllers\Controller;
use App\Models\MnhContract;
use App\Services\MnhContractService;
use App\Services\MnhEvaluationService;
use App\Support\Kst;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 회원(돌봄전문가) — 이용자 평가와 내 종합평가(CAREN-MNH-01 4단계, 2026-10-05).
 * 관리사가 남긴 이용자 평가는 이용자에게 보이지 않는다(운영팀만).
 */
class MnhEvaluationController extends Controller
{
    public function __construct(private MnhEvaluationService $evals, private MnhContractService $contracts)
    {
    }

    private function caregiverId(Request $request): ?int
    {
        $id = DB::table('caregivers')->where('user_id', $request->user()->id)->value('id');

        return $id ? (int) $id : null;
    }

    /** GET /v1/mnh/client-evaluations — 내가 맡은(맡았던) 바우처 계약과 평가 상태 */
    public function index(Request $request): JsonResponse
    {
        $cg = $this->caregiverId($request);
        if (!$cg) {
            return $this->fail('NOT_CAREGIVER', '돌봄전문가만 쓸 수 있어요.', 403);
        }
        $contractIds = DB::table('matches as m')->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->where('m.caregiver_id', $cg)->where('m.status', '!=', 'cancelled')->whereNotNull('r.mnh_contract_id')
            ->distinct()->pluck('r.mnh_contract_id');
        $rows = MnhContract::whereIn('id', $contractIds)->whereIn('status', ['confirmed', 'active', 'completed'])->orderByDesc('start_date')->get();
        $mine = DB::table('mnh_evaluations')->where('kind', 'caregiver_to_client')->where('caregiver_id', $cg)
            ->whereIn('contract_id', $rows->pluck('id'))->get()->groupBy('contract_id');
        $clients = DB::table('postpartum_clients')->whereIn('id', $rows->pluck('postpartum_client_id'))->pluck('name', 'id');

        return response()->json(['success' => true, 'data' => $rows->map(fn ($c) => [
            'contract_id' => $c->id, 'contract_no' => $c->contract_no, 'status' => $c->status,
            'client_name' => $clients[$c->postpartum_client_id] ?? null,
            'start_date' => $c->start_date->format('Y-m-d'), 'end_date' => $this->contracts->endDate($c),
            'interim_count' => ($mine[$c->id] ?? collect())->where('timing', 'interim')->count(),
            'final_done' => ($mine[$c->id] ?? collect())->contains('timing', 'final'),
        ])->values()]);
    }

    /** GET /v1/mnh/contracts/{id}/client-evaluation — 평가 화면(항목·내가 남긴 평가) */
    public function form(Request $request, int $id): JsonResponse
    {
        [$c, $cg] = $this->contractFor($request, $id);
        $mine = DB::table('mnh_evaluations')->where('kind', 'caregiver_to_client')->where('contract_id', $c->id)
            ->where('caregiver_id', $cg)->orderByDesc('id')->get()->map(fn ($e) => $this->evals->present($e));
        $live = $this->contracts->sessions($c)->where('caregiver_id', $cg)->where('status', '!=', 'cancelled');

        return response()->json(['success' => true, 'data' => [
            'contract_id' => $c->id, 'contract_no' => $c->contract_no, 'status' => $c->status,
            'client_name' => DB::table('postpartum_clients')->where('id', $c->postpartum_client_id)->value('name'),
            'items' => collect(config('mnh_eval.caregiver_to_client'))->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'can_final' => $c->status === 'completed' || ($live->isNotEmpty() && $live->every(fn ($s) => $s->status === 'completed')),
            'mine' => $mine,
        ]]);
    }

    /** POST /v1/mnh/contracts/{id}/client-evaluations {scores, comment, timing} */
    public function store(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'scores' => ['required', 'array'], 'comment' => ['nullable', 'string', 'max:2000'],
            'timing' => ['required', 'in:interim,final'],
        ]);
        [$c, $cg] = $this->contractFor($request, $id);
        try {
            $e = $this->evals->submit('caregiver_to_client', $c, $cg, (int) $request->user()->id, $data['scores'], $data['comment'] ?? null, $data['timing']);
        } catch (MnhContractException $ex) {
            return $this->fail($ex->errorCode, $ex->getMessage(), $ex->status);
        }

        return response()->json(['success' => true, 'message' => '평가를 남겼어요. 운영팀만 볼 수 있어요.', 'data' => $e]);
    }

    /** GET /v1/mnh/my-hexagon — 내 종합평가(축 점수·근거 수만, 기관 평가 의견은 빼고) */
    public function myHexagon(Request $request): JsonResponse
    {
        $cg = $this->caregiverId($request);
        if (!$cg) {
            return $this->fail('NOT_CAREGIVER', '돌봄전문가만 쓸 수 있어요.', 403);
        }
        $mine = $this->evals->hexagons([$cg])[$cg];
        $team = $this->teamIds();

        return response()->json(['success' => true, 'data' => $mine + [
            'team_average' => MnhEvaluationService::teamAverage($this->evals->hexagons($team)),
            'min_samples' => (int) config('mnh_eval.min_samples'),
            'as_of' => Kst::iso(now()),
        ]]);
    }

    /** 산모신생아 직군 돌봄전문가 */
    private function teamIds(): array
    {
        return DB::table('caregivers')->whereNull('deleted_at')->where('status', 'active')
            ->whereRaw("FIND_IN_SET('postpartum', service_domains)")->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    private function contractFor(Request $request, int $id): array
    {
        $cg = $this->caregiverId($request);
        $c = MnhContract::find($id);
        abort_unless($cg && $c && in_array($cg, $this->evals->contractCaregivers($c), true), 404);

        return [$c, $cg];
    }

    private function fail(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json(['success' => false, 'error_code' => $code, 'message' => $message], $status);
    }
}
