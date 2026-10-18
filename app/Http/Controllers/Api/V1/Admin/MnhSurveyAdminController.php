<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\MnhDocumentService;
use App\Support\Kst;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 관리자 — 바우처 모니터링 설문(서비스 만족도 모니터링 B형) 집계(서류 보관함 2단계, 2026-10-18).
 * 기간(발행일 기준, 한국 날짜)·담당 관리사로 거른 설문의 응답률, 문항별 평균·분포, 일반 문항 건수, 관리사별 평균,
 * 서술 의견, 아직 답하지 않은 설문(다시 알림 단계 포함)을 돌려준다. 문항 정의는 config mnh_docs satisfaction fields 그대로.
 * 점수 문항 = 선택지에 "1"~"5" 가 있는 select(「불편한 점이 없었다」 같은 숫자 아닌 답은 평균에서 빼고 따로 센다).
 */
class MnhSurveyAdminController extends Controller
{
    private const TZ = 'Asia/Seoul';

    /** GET /v1/admin/mnh/satisfaction?from=&to=&caregiver_id= */
    public function summary(Request $request): JsonResponse
    {
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'caregiver_id' => ['nullable', 'integer'],
        ]);
        $rows = self::rows($f);
        $fields = self::fields();
        $signed = $rows->where('status', 'signed');
        $scoreKeys = collect($fields)->filter(fn ($x) => $x['score'])->keys();
        $evalKeys = $scoreKeys->filter(fn ($k) => str_starts_with($k, 'q_'))->values();   // 서비스 평가 10문항(관리사별 평균에 씀)

        $questions = $scoreKeys->map(function ($k) use ($fields, $signed) {
            $vals = $signed->map(fn ($r) => $r->form[$k] ?? null)->filter(fn ($v) => $v !== null && $v !== '');
            $nums = $vals->filter(fn ($v) => ctype_digit((string) $v))->map(fn ($v) => (int) $v);

            return ['key' => $k, 'label' => $fields[$k]['label'], 'section' => $fields[$k]['section'],
                'avg' => $nums->count() ? round($nums->avg(), 2) : null, 'n' => $nums->count(),
                'dist' => collect(range(1, 5))->mapWithKeys(fn ($i) => [$i => $nums->filter(fn ($v) => $v === $i)->count()]),
                'other' => collect($fields[$k]['options'])->except(['1', '2', '3', '4', '5'])
                    ->map(fn ($label, $code) => ['label' => $label, 'n' => $vals->filter(fn ($v) => (string) $v === (string) $code)->count()])->values()];
        })->values();

        $general = collect($fields)->filter(fn ($x) => !$x['score'] && $x['type'] === 'select')->map(fn ($x, $k) => [
            'key' => $k, 'label' => $x['label'],
            'counts' => collect($x['options'])->map(fn ($label, $code) => ['label' => $label,
                'n' => $signed->filter(fn ($r) => (string) ($r->form[$k] ?? '') === (string) $code)->count()])->values(),
        ])->values();

        $byCaregiver = $signed->groupBy('caregiver_id')->map(function (Collection $g, $cgId) use ($evalKeys) {
            $avgOf = function ($keys) use ($g) {
                $nums = $g->flatMap(fn ($r) => collect($keys)->map(fn ($k) => $r->form[$k] ?? null))->filter(fn ($v) => ctype_digit((string) $v));

                return $nums->count() ? round($nums->avg(), 2) : null;
            };

            return ['caregiver_id' => $cgId ? (int) $cgId : null, 'name' => $g->first()->caregiver_name, 'n' => $g->count(),
                'eval_avg' => $avgOf($evalKeys), 'overall_avg' => $avgOf(['q_overall']), 'recommend_avg' => $avgOf(['q_recommend'])];
        })->sortByDesc('n')->values();

        $textKeys = collect($fields)->filter(fn ($x) => $x['type'] === 'textarea')->keys();
        $comments = $signed->sortByDesc('signed_at')->flatMap(fn ($r) => $textKeys->map(fn ($k) => trim((string) ($r->form[$k] ?? '')) === '' ? null : [
            'document_id' => $r->id, 'contract_id' => $r->contract_id, 'contract_no' => $r->contract_no, 'client_name' => $r->client_name,
            'caregiver_name' => $r->caregiver_name, 'question' => $fields[$k]['label'], 'text' => trim((string) $r->form[$k]),
            'signed_at' => Kst::iso($r->signed_at),
        ])->filter())->take(100)->values();

        $pendingRows = $rows->where('status', 'issued');
        $steps = DB::table('mnh_reminders')->where('kind', 'survey')->whereIn('ref_id', $pendingRows->pluck('id'))
            ->groupBy('ref_id')->selectRaw('ref_id, MAX(step) s')->pluck('s', 'ref_id');
        $today = Carbon::now(self::TZ)->startOfDay();
        $pending = $pendingRows->sortBy('created_at')->map(fn ($r) => [
            'document_id' => $r->id, 'contract_id' => $r->contract_id, 'contract_no' => $r->contract_no, 'client_id' => $r->client_id,
            'client_name' => $r->client_name, 'caregiver_name' => $r->caregiver_name, 'issued_at' => Kst::iso($r->created_at),
            'days' => (int) Carbon::parse($r->created_at, 'UTC')->setTimezone(self::TZ)->startOfDay()->diffInDays($today),
            'reminded' => (int) ($steps[$r->id] ?? 0),
        ])->values();

        $overall = $questions->firstWhere('key', 'q_overall');

        return response()->json(['success' => true, 'data' => [
            'issued' => $rows->count(), 'signed' => $signed->count(),
            'response_rate' => $rows->count() ? round($signed->count() / $rows->count() * 100, 1) : null,
            'overall_avg' => $overall['avg'] ?? null,
            'eval_avg' => (function () use ($signed, $evalKeys) {
                $nums = $signed->flatMap(fn ($r) => $evalKeys->map(fn ($k) => $r->form[$k] ?? null))->filter(fn ($v) => ctype_digit((string) $v));

                return $nums->count() ? round($nums->avg(), 2) : null;
            })(),
            'questions' => $questions, 'general' => $general, 'by_caregiver' => $byCaregiver,
            'comments' => $comments, 'pending' => $pending,
        ]]);
    }

    /**
     * 기간·관리사로 거른 모니터링 설문(취소 판 제외). 엑셀 다운로드(ExportController::mnhSatisfaction)도 같은 조회를 쓴다.
     * @return Collection<int, object> form 은 배열로 풀어 둔다
     */
    public static function rows(array $f): Collection
    {
        $q = DB::table('mnh_documents as d')
            ->join('mnh_contracts as c', 'c.id', '=', 'd.contract_id')
            ->leftJoin('postpartum_clients as pc', 'pc.id', '=', 'c.postpartum_client_id')
            ->leftJoin('caregivers as cg', 'cg.id', '=', DB::raw('COALESCE(d.caregiver_id, c.caregiver_id)'))
            ->leftJoin('users as u', 'u.id', '=', 'cg.user_id')
            ->where('d.doc_type', 'satisfaction')->where('d.status', '!=', 'void');
        if (!empty($f['from'])) {
            $q->where('d.created_at', '>=', Carbon::parse($f['from'], self::TZ)->startOfDay()->utc());
        }
        if (!empty($f['to'])) {
            $q->where('d.created_at', '<', Carbon::parse($f['to'], self::TZ)->addDay()->startOfDay()->utc());
        }
        if (!empty($f['caregiver_id'])) {
            $q->where('cg.id', (int) $f['caregiver_id']);
        }

        return $q->orderBy('d.id')->get(['d.id', 'd.status', 'd.form_data', 'd.created_at', 'd.signed_at', 'c.id as contract_id', 'c.contract_no',
            'c.postpartum_client_id as client_id', 'pc.name as client_name', 'cg.id as caregiver_id', 'u.name as caregiver_name'])
            ->map(function ($r) {
                $r->form = json_decode((string) $r->form_data, true) ?: [];

                return $r;
            });
    }

    /** 설문 칸 정의 — key => [label, type, section(가장 가까운 앞 소제목), options, score] */
    public static function fields(): array
    {
        $out = [];
        $section = null;
        foreach (MnhDocumentService::type('satisfaction')['fields'] ?? [] as $x) {
            $section = $x['section'] ?? $section;
            if (!in_array($x['type'] ?? '', ['select', 'textarea'], true)) {
                continue;
            }
            $opts = (array) ($x['options'] ?? []);
            $out[$x['key']] = ['label' => $x['label'], 'type' => $x['type'], 'section' => $section, 'options' => $opts,
                'score' => $x['type'] === 'select' && count(array_intersect(['1', '2', '3', '4', '5'], array_map('strval', array_keys($opts)))) === 5];
        }

        return $out;
    }
}
