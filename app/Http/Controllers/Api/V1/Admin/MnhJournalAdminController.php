<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\Kst;
use App\Support\MnhClientJournal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * 관리자 — 산모 이용일지 기관 확인(2026-10-05). RBAC 영역 'mnh-journals'(조회·확인 모두 운영 3등급).
 * 「확인 필요」 = 아직 기관이 확인하지 않은 기록, 주의(flag) 기록을 맨 위로.
 */
class MnhJournalAdminController extends Controller
{
    /** GET /v1/admin/mnh-journals?status=open|done|all&flagged=1&client_id=&days=7|30|90&q= */
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'done', 'all'])],
            'flagged' => ['nullable', 'boolean'],
            'client_id' => ['nullable', 'integer'],
            'days' => ['nullable', 'integer', Rule::in([7, 30, 90])],
            'q' => ['nullable', 'string', 'max:50'],
        ]);
        $since = Carbon::now(Kst::TZ)->startOfDay()->subDays((int) ($v['days'] ?? 30) - 1)->utc();

        $base = fn () => DB::table('mnh_client_journals as j')
            ->join('postpartum_clients as pc', 'pc.id', '=', 'j.postpartum_client_id')
            ->whereNull('pc.deleted_at')
            ->where('j.logged_at', '>=', $since)
            ->when(!empty($v['client_id']), fn ($q) => $q->where('j.postpartum_client_id', $v['client_id']))
            ->when(!empty($v['q']), fn ($q) => $q->where('pc.name', 'like', '%' . $v['q'] . '%'));

        $rows = $base()
            ->when(($v['status'] ?? 'open') === 'open', fn ($q) => $q->whereNull('j.checked_at'))
            ->when(($v['status'] ?? 'open') === 'done', fn ($q) => $q->whereNotNull('j.checked_at'))
            ->when(!empty($v['flagged']), fn ($q) => $q->whereNotNull('j.flag'))
            ->orderByRaw('j.flag IS NOT NULL AND j.checked_at IS NULL DESC')
            ->orderByDesc('j.logged_at')->orderByDesc('j.id')
            ->limit(300)->get(['j.*', 'pc.name as client_name']);

        $babies = DB::table('newborns')->whereIn('id', $rows->pluck('newborn_id')->filter()->unique())->pluck('name', 'id')->all();
        $staff = DB::table('users')->whereIn('id', $rows->pluck('checked_by')->filter()->unique())->pluck('name', 'id')->all();
        $contracts = DB::table('mnh_contracts')->whereIn('postpartum_client_id', $rows->pluck('postpartum_client_id')->unique())
            ->whereIn('status', ['applied', 'confirmed', 'active', 'completed'])->orderBy('id')
            ->get(['id', 'contract_no', 'postpartum_client_id'])->keyBy('postpartum_client_id');   // 산모별 최근 계약

        $summary = $base()->selectRaw('SUM(j.checked_at IS NULL) as open_count, SUM(j.checked_at IS NULL AND j.flag IS NOT NULL) as flagged_open, COUNT(*) as total')->first();

        return response()->json(['success' => true, 'data' => [
            'summary' => [
                'open' => (int) ($summary->open_count ?? 0),
                'flagged_open' => (int) ($summary->flagged_open ?? 0),
                'total' => (int) ($summary->total ?? 0),
            ],
            'entries' => $rows->map(fn ($r) => MnhClientJournal::present($r, $babies, $staff) + [
                'postpartum_client_id' => (int) $r->postpartum_client_id,
                'client_name' => $r->client_name,
                'contract' => isset($contracts[$r->postpartum_client_id])
                    ? ['id' => (int) $contracts[$r->postpartum_client_id]->id, 'contract_no' => $contracts[$r->postpartum_client_id]->contract_no] : null,
            ])->values(),
            'options' => MnhClientJournal::options(),
        ]]);
    }

    /** POST /v1/admin/mnh-journals/check {ids:[], note?} — 기관 확인(이미 확인한 건은 그대로) */
    public function check(Request $request): JsonResponse
    {
        $v = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $n = DB::table('mnh_client_journals')->whereIn('id', $v['ids'])->whereNull('checked_at')->update([
            'checked_at' => now(),
            'checked_by' => $request->user()->id,
            'check_note' => trim((string) ($v['note'] ?? '')) ?: null,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => $n ? "{$n}건을 확인했어요." : '이미 확인된 기록이에요.', 'data' => ['checked' => $n]]);
    }
}
