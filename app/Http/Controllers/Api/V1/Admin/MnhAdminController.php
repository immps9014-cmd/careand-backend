<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\MnhContractException;
use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Models\MnhContract;
use App\Models\MnhSupportType;
use App\Services\MnhContractService;
use App\Support\Holidays;
use App\Support\MnhContractPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * 관리자 — 산모신생아 바우처(CAREN-MNH-01 2단계, 2026-10-05). 제공기관 = 케어앤 운영사.
 * 지원유형 기준표(고시값 입력) · 계약 목록/달력/상세 · 선납 기록 · 담당 배정/교체 · 연기 · 특이사항.
 */
class MnhAdminController extends Controller
{
    public function __construct(private MnhContractService $svc)
    {
    }

    /* ───────────── 지원유형 기준표 ───────────── */

    /** GET /v1/admin/mnh/support-types?year= */
    public function supportTypes(Request $request): JsonResponse
    {
        $year = (int) ($request->query('year') ?: Carbon::now('Asia/Seoul')->year);
        $rows = MnhSupportType::where('year', $year)
            ->orderByRaw("FIELD(fetus_type,'single','twins','triplets_plus','quadruplets_plus')")->orderByRaw("FIELD(birth_order,'first','second','third_plus','any')")->orderBy('income_tier')->orderByRaw("FIELD(period,'short','standard','extended')")->get();
        $years = MnhSupportType::distinct()->orderByDesc('year')->pluck('year');
        $used = MnhContract::whereIn('support_type_id', $rows->pluck('id'))->groupBy('support_type_id')
            ->selectRaw('support_type_id, count(*) n')->pluck('n', 'support_type_id');

        return response()->json(['success' => true, 'data' => [
            'year' => $year,
            'years' => $years,
            'rows' => $rows->map(fn ($r) => $r->toArray() + ['contracts' => (int) ($used[$r->id] ?? 0)])->values(),
            'labels' => config('mnh'),
        ]]);
    }

    /** POST /v1/admin/mnh/support-types */
    public function storeSupportType(Request $request): JsonResponse
    {
        $data = $this->validateSupportType($request);
        $dup = MnhSupportType::where('year', $data['year'])->where('fetus_type', $data['fetus_type'])
            ->where('birth_order', $data['birth_order'])->where('income_tier', $data['income_tier'])->where('period', $data['period'])->exists();
        if ($dup) {
            return $this->fail('DUPLICATE', '같은 연도·태아·순위·유형·기간 행이 이미 있어요.');
        }
        $row = MnhSupportType::create($data);

        return response()->json(['success' => true, 'data' => $row], 201);
    }

    /** PATCH /v1/admin/mnh/support-types/{id} — 이미 맺은 계약 금액은 바뀌지 않는다(계약에 복사돼 있음) */
    public function updateSupportType(Request $request, int $id): JsonResponse
    {
        $row = MnhSupportType::findOrFail($id);
        $data = $this->validateSupportType($request, $row);
        $row->update($data);

        return response()->json(['success' => true, 'data' => $row->fresh()]);
    }

    /** DELETE /v1/admin/mnh/support-types/{id} — 계약에 쓰인 행은 지우지 않고 사용 중지 */
    public function deleteSupportType(int $id): JsonResponse
    {
        $row = MnhSupportType::findOrFail($id);
        if (MnhContract::where('support_type_id', $id)->exists()) {
            $row->update(['is_active' => false]);

            return response()->json(['success' => true, 'message' => '계약에 쓰인 행이라 삭제 대신 사용 중지했어요.']);
        }
        $row->delete();

        return response()->json(['success' => true, 'message' => '삭제했어요.']);
    }

    /** POST /v1/admin/mnh/support-types/copy {from, to} — 전년도 행을 새 연도로 복사(금액은 고시 확인 후 고칠 것) */
    public function copySupportTypes(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'integer'], 'to' => ['required', 'integer', 'different:from']]);
        if (MnhSupportType::where('year', $data['to'])->exists()) {
            return $this->fail('TARGET_NOT_EMPTY', $data['to'] . '년 행이 이미 있어요. 비어 있는 연도로만 복사할 수 있어요.');
        }
        $n = 0;
        foreach (MnhSupportType::where('year', $data['from'])->get() as $r) {
            MnhSupportType::create(array_merge($r->only(['fetus_type', 'birth_order', 'income_tier', 'period', 'days', 'total_price', 'gov_support', 'self_pay', 'is_active']),
                ['year' => $data['to'], 'note' => '복사본 — 고시 확인 필요']));
            $n++;
        }

        return response()->json(['success' => true, 'message' => "{$n}개 행을 복사했어요. 고시 금액으로 고쳐 주세요."]);
    }

    private function validateSupportType(Request $request, ?MnhSupportType $row = null): array
    {
        $cfg = config('mnh');
        $req = $row ? 'sometimes' : 'required';
        $data = $request->validate([
            'year' => [$req, 'integer', 'between:2020,2100'],
            'fetus_type' => [$req, Rule::in(array_keys($cfg['fetus_types']))],
            'birth_order' => [$req, Rule::in(array_keys($cfg['birth_orders']))],
            'income_tier' => [$req, 'string', 'max:30'],
            'period' => [$req, Rule::in(array_keys($cfg['periods']))],
            'days' => [$req, 'integer', 'between:' . $cfg['min_days'] . ',' . $cfg['max_days']],
            'total_price' => [$req, 'integer', 'min:0'],
            'gov_support' => [$req, 'integer', 'min:0'],
            'self_pay' => [$req, 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:200'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $merged = array_merge($row?->toArray() ?? [], $data);
        if ((int) $merged['gov_support'] + (int) $merged['self_pay'] !== (int) $merged['total_price']) {
            abort(response()->json(['success' => false, 'error_code' => 'SUM_MISMATCH',
                'message' => '정부지원금 + 본인부담금이 서비스 가격과 같아야 해요.'], 422));
        }

        return $data;
    }

    /* ───────────── 계약 ───────────── */

    /** GET /v1/admin/mnh/contracts?status=&q= */
    public function contracts(Request $request): JsonResponse
    {
        $q = MnhContract::query()->orderByRaw("FIELD(status,'applied','confirmed','active','completed','cancelled')")->orderBy('start_date');
        if ($s = $request->query('status')) {
            $q->where('status', $s);
        }
        if ($kw = trim((string) $request->query('q'))) {
            $ids = DB::table('postpartum_clients')->where('name', 'like', "%{$kw}%")->pluck('id');
            $q->where(fn ($w) => $w->where('contract_no', 'like', "%{$kw}%")->orWhereIn('postpartum_client_id', $ids));
        }
        $rows = $q->limit(300)->get();

        return response()->json(['success' => true, 'data' => $this->summaries($rows),
            'counts' => MnhContract::groupBy('status')->selectRaw('status, count(*) n')->pluck('n', 'status')]);
    }

    /** GET /v1/admin/mnh/calendar?month=YYYY-MM — 그 달에 걸친 계약별 날짜 칸 */
    public function calendar(Request $request): JsonResponse
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? $request->query('month') : Carbon::now('Asia/Seoul')->format('Y-m');
        $first = Carbon::parse($month . '-01', 'Asia/Seoul');
        $last = $first->copy()->endOfMonth();
        // 개시일이 그 달 말 이전이고 아직 열려 있거나 그 달 이후 끝나는 계약(종료일은 계산값이라 넉넉히 개시일 90일 전까지)
        $rows = MnhContract::where('status', '!=', 'cancelled')
            ->where('start_date', '<=', $last->toDateString())
            ->where('start_date', '>=', $first->copy()->subDays(90)->toDateString())
            ->orderBy('start_date')->get();

        $out = [];
        foreach ($rows as $c) {
            $detail = MnhContractPresenter::detail($c, $this->svc, true);
            if (($detail['end_date'] ?? $detail['start_date']) < $first->toDateString()) {
                continue;
            }
            $events = collect($detail['events'])->filter(fn ($e) => $e['date'] && in_array($e['type'], ['note', 'postponed', 'swapped'], true)
                && $e['date'] >= $first->toDateString() && $e['date'] <= $last->toDateString())->values();
            $out[] = [
                'id' => $c->id, 'contract_no' => $c->contract_no, 'status' => $c->status, 'status_label' => $detail['status_label'],
                'client_name' => $detail['client_name'], 'caregiver_name' => $detail['caregiver_name'],
                'start_date' => $detail['start_date'], 'end_date' => $detail['end_date'], 'days' => $c->days,
                'prepaid' => $detail['prepaid'],
                'cells' => collect($detail['schedule'])->filter(fn ($d) => $d['date'] >= $first->toDateString() && $d['date'] <= $last->toDateString())
                    ->map(fn ($d) => ['date' => $d['date'], 'seq' => $d['seq'], 'status' => $d['status'], 'caregiver_name' => $d['caregiver_name']])->values(),
                'postponed' => array_values(array_filter($detail['postponed'], fn ($d) => $d >= $first->toDateString() && $d <= $last->toDateString())),
                'events' => $events,
            ];
        }

        $holidays = Holiday::whereBetween('date', [$first->toDateString(), $last->toDateString()])->orderBy('date')
            ->get(['date', 'name'])->map(fn ($h) => ['date' => $h->date->format('Y-m-d'), 'name' => $h->name])->values();

        return response()->json(['success' => true, 'data' => ['month' => $month, 'holidays' => $holidays, 'contracts' => $out]]);
    }

    /** GET /v1/admin/mnh/contracts/{id} */
    public function show(int $id): JsonResponse
    {
        return response()->json(['success' => true, 'data' => MnhContractPresenter::detail(MnhContract::findOrFail($id), $this->svc, true)]);
    }

    /** PATCH /v1/admin/mnh/contracts/{id} — 지원유형·금액·결제수단·메모, 일정(개시일·일수·요일·시간) */
    public function update(Request $request, int $id): JsonResponse
    {
        $c = MnhContract::findOrFail($id);
        $cfg = config('mnh');
        $data = $request->validate([
            'support_type_id' => ['nullable', 'integer', 'exists:mnh_support_types,id'],
            'total_price' => ['nullable', 'integer', 'min:0'],
            'gov_support' => ['nullable', 'integer', 'min:0'],
            'self_pay' => ['nullable', 'integer', 'min:0'],
            'payment_method' => ['sometimes', Rule::in(array_keys($cfg['payment_methods']))],
            'admin_note' => ['nullable', 'string', 'max:2000'],
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'days' => ['sometimes', 'integer', 'between:' . $cfg['min_days'] . ',' . $cfg['max_days']],
            'weekdays' => ['sometimes', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'daily_start' => ['sometimes', 'date_format:H:i'],
            'daily_minutes' => ['sometimes', 'integer', 'between:60,720'],
            'force' => ['nullable', 'boolean'],
        ]);
        $actor = $request->user()->id;

        try {
            $plain = [];
            if (array_key_exists('support_type_id', $data) && $data['support_type_id']) {
                $t = MnhSupportType::find($data['support_type_id']);
                $plain += ['support_type_id' => $t->id, 'year' => $t->year, 'fetus_type' => $t->fetus_type, 'birth_order' => $t->birth_order,
                    'income_tier' => $t->income_tier, 'period' => $t->period, 'total_price' => $t->total_price,
                    'gov_support' => $t->gov_support, 'self_pay' => $t->self_pay];
                if ($t->days !== $c->days) {
                    $data['days'] = $t->days;
                }
            } elseif (array_key_exists('self_pay', $data)) {
                // 기준표 밖 수기 금액(고시 예외·지자체 추가지원 등)
                $plain += array_intersect_key($data, array_flip(['total_price', 'gov_support', 'self_pay']));
            }
            foreach (['payment_method', 'admin_note'] as $k) {
                if (array_key_exists($k, $data)) {
                    $plain[$k] = $data[$k];
                }
            }
            if ($plain) {
                $c->update($plain);
                if (isset($plain['self_pay'])) {
                    $this->svc->log($c, 'support_set', null, ['support_type_id' => $c->support_type_id, 'self_pay' => $c->self_pay], $actor);
                }
            }
            $sched = array_intersect_key($data, array_flip(['start_date', 'days', 'weekdays', 'daily_start', 'daily_minutes']));
            if (isset($sched['weekdays'])) {
                $sched['weekdays'] = array_values(array_unique(array_map('intval', $sched['weekdays'])));
                sort($sched['weekdays']);
            }
            // 바뀐 값만
            $sched = array_filter($sched, fn ($v, $k) => $k === 'start_date' ? $v !== $c->start_date?->format('Y-m-d') : $v != $c->{$k}, ARRAY_FILTER_USE_BOTH);
            if ($sched) {
                $this->svc->reschedule($c, $sched, (bool) ($data['force'] ?? false), $actor);
            }
        } catch (MnhContractException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->status, $e->extra);
        }

        return response()->json(['success' => true, 'message' => '저장했어요.', 'data' => MnhContractPresenter::detail($c->fresh(), $this->svc, true)]);
    }

    /** POST /v1/admin/mnh/contracts/{id}/prepaid — 본인부담금 선납 기록 */
    public function prepaid(Request $request, int $id): JsonResponse
    {
        $c = MnhContract::findOrFail($id);
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:0'],
            'method' => ['required', Rule::in(array_keys(config('mnh.payment_methods')))],
            'receipt_no' => ['nullable', 'string', 'max:50'],
            'paid_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        if (in_array($c->status, ['completed', 'cancelled'], true)) {
            return $this->fail('CLOSED', '종료·취소된 계약이에요.');
        }
        $paidAt = !empty($data['paid_on']) ? Carbon::parse($data['paid_on'] . ' 12:00', 'Asia/Seoul')->utc() : now();
        $c->update(['prepaid_amount' => $data['amount'], 'payment_method' => $data['method'], 'prepaid_receipt_no' => $data['receipt_no'] ?? null,
            'prepaid_at' => $paidAt, 'prepaid_by' => $request->user()->id]);
        $this->svc->log($c, 'prepaid', $paidAt->copy()->setTimezone('Asia/Seoul')->toDateString(),
            ['amount' => $data['amount'], 'method' => $data['method'], 'receipt_no' => $data['receipt_no'] ?? null], $request->user()->id);
        // 3단계: 본인부담금 영수증 발행(이미 있으면 그대로 — 금액을 고쳤으면 서류에서 「다시 발행」)
        app(\App\Services\MnhDocumentService::class)->autoIssue($c->fresh(), 'prepaid', $request->user()->id);
        $warn = $c->self_pay !== null && (int) $data['amount'] !== (int) $c->self_pay
            ? sprintf(' 본인부담금 %s원과 금액이 달라요.', number_format($c->self_pay)) : '';

        return response()->json(['success' => true, 'message' => '선납을 기록했어요.' . $warn,
            'data' => MnhContractPresenter::detail($c->fresh(), $this->svc, true)]);
    }

    /** DELETE /v1/admin/mnh/contracts/{id}/prepaid — 잘못 기록한 선납 취소(출근 전만) */
    public function clearPrepaid(Request $request, int $id): JsonResponse
    {
        $c = MnhContract::findOrFail($id);
        if ($c->status === 'active' || $c->status === 'completed') {
            return $this->fail('ALREADY_STARTED', '서비스가 시작된 계약의 선납 기록은 지울 수 없어요.');
        }
        $c->update(['prepaid_amount' => null, 'prepaid_at' => null, 'prepaid_receipt_no' => null, 'prepaid_by' => null]);
        $this->svc->log($c, 'note', null, ['text' => '선납 기록 취소'], $request->user()->id);

        return response()->json(['success' => true, 'message' => '선납 기록을 지웠어요.']);
    }

    /** POST /v1/admin/mnh/contracts/{id}/assign {caregiver_id, force} */
    public function assign(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['caregiver_id' => ['required', 'integer'], 'force' => ['nullable', 'boolean']]);

        return $this->run($id, fn ($c) => $this->svc->assign($c, (int) $data['caregiver_id'], (bool) ($data['force'] ?? false), $request->user()->id), '담당을 배정했어요.');
    }

    /** POST /v1/admin/mnh/contracts/{id}/swap {caregiver_id, from_date, reason, force} */
    public function swap(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'caregiver_id' => ['required', 'integer'], 'from_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:300'], 'force' => ['nullable', 'boolean'],
        ]);

        return $this->run($id, fn ($c) => $this->svc->swap($c, (int) $data['caregiver_id'], $data['from_date'], $data['reason'],
            (bool) ($data['force'] ?? false), $request->user()->id), '담당을 교체했어요.');
    }

    /** POST /v1/admin/mnh/contracts/{id}/postpone {date, reason, force} */
    public function postpone(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:300'], 'force' => ['nullable', 'boolean']]);

        return $this->run($id, fn ($c) => $this->svc->postpone($c, $data['date'], $data['reason'], (bool) ($data['force'] ?? false), $request->user()->id), '연기했어요. 끝에 하루가 붙었어요.');
    }

    /** POST /v1/admin/mnh/contracts/{id}/restore {date, force} */
    public function restore(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'force' => ['nullable', 'boolean']]);

        return $this->run($id, fn ($c) => $this->svc->restore($c, $data['date'], (bool) ($data['force'] ?? false), $request->user()->id), '연기를 되돌렸어요.');
    }

    /** POST /v1/admin/mnh/contracts/{id}/holiday-work {date, work, force} — 공휴일 근무 지정(work=true)·해제 */
    public function holidayWork(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'work' => ['required', 'boolean'], 'force' => ['nullable', 'boolean']]);

        return $this->run($id, fn ($c) => $this->svc->setHolidayWork($c, $data['date'], (bool) $data['work'], (bool) ($data['force'] ?? false), $request->user()->id),
            $data['work'] ? '공휴일 근무로 지정했어요. 끝에서 하루가 줄었어요.' : '공휴일 휴무로 되돌렸어요. 끝에 하루가 붙었어요.');
    }

    /* ───────────── 공휴일 표 ───────────── */

    /** GET /v1/admin/mnh/holidays?year= */
    public function holidays(Request $request): JsonResponse
    {
        $year = (int) ($request->query('year') ?: Carbon::now('Asia/Seoul')->year);
        $rows = Holiday::whereYear('date', $year)->orderBy('date')->get()
            ->map(fn ($h) => ['id' => $h->id, 'date' => $h->date->format('Y-m-d'), 'name' => $h->name, 'source' => $h->source]);
        $years = Holiday::selectRaw('DISTINCT YEAR(date) y')->orderBy('y')->pluck('y');

        return response()->json(['success' => true, 'data' => ['year' => $year, 'years' => $years, 'today' => Carbon::now('Asia/Seoul')->toDateString(), 'rows' => $rows]]);
    }

    /** POST /v1/admin/mnh/holidays {date, name} — 임시공휴일·다음 해 공휴일. 지난 날짜는 이미 제공한 기록과 어긋나므로 받지 않는다 */
    public function storeHoliday(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'name' => ['required', 'string', 'max:50']]);
        if ($data['date'] <= Carbon::now('Asia/Seoul')->toDateString()) {
            return $this->fail('PAST_DATE', '내일 이후 날짜만 넣을 수 있어요.');
        }
        if (Holiday::where('date', $data['date'])->exists()) {
            return $this->fail('DUPLICATE', '이미 공휴일로 등록된 날이에요.');
        }
        Holiday::create($data + ['source' => 'admin']);
        Holidays::flush();
        $r = $this->svc->resyncForHoliday($data['date'], $request->user()->id);

        return response()->json(['success' => true, 'message' => self::holidayMessage('공휴일을 넣었어요.', $r), 'result' => $r]);
    }

    /** DELETE /v1/admin/mnh/holidays/{id} */
    public function deleteHoliday(Request $request, int $id): JsonResponse
    {
        $h = Holiday::findOrFail($id);
        $date = $h->date->format('Y-m-d');
        if ($date <= Carbon::now('Asia/Seoul')->toDateString()) {
            return $this->fail('PAST_DATE', '오늘이나 지난 공휴일은 지울 수 없어요.');
        }
        $h->delete();
        Holidays::flush();
        $r = $this->svc->resyncForHoliday($date, $request->user()->id);

        return response()->json(['success' => true, 'message' => self::holidayMessage('공휴일을 지웠어요.', $r), 'result' => $r]);
    }

    private static function holidayMessage(string $head, array $r): string
    {
        $msg = $head;
        if ($r['synced']) {
            $msg .= ' 계약 ' . count($r['synced']) . '건의 일정을 다시 맞췄어요.';
        }
        if ($r['conflicts']) {
            $msg .= ' 담당 일정이 겹쳐 ' . count($r['conflicts']) . '건은 그대로예요 — 계약 상세에서 확인하세요.';
        }

        return $msg;
    }

    /** POST /v1/admin/mnh/contracts/{id}/notes {date?, text} — 달력 특이사항(행사·연락 등) */
    public function note(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'text' => ['required', 'string', 'max:500']]);
        $c = MnhContract::findOrFail($id);
        $this->svc->log($c, 'note', $data['date'] ?? null, ['text' => $data['text']], $request->user()->id);

        return response()->json(['success' => true, 'message' => '특이사항을 남겼어요.', 'data' => MnhContractPresenter::detail($c, $this->svc, true)]);
    }

    /** POST /v1/admin/mnh/contracts/{id}/cancel {reason} */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'refund_amount' => ['nullable', 'integer', 'min:0']]);
        $refund = isset($data['refund_amount']) ? (int) $data['refund_amount'] : null;

        return $this->run($id, fn ($c) => $this->svc->cancel($c, $data['reason'], $request->user()->id, $refund), '계약을 취소했어요. 지난 기록은 남아 있어요.');
    }

    /** GET /v1/admin/mnh/caregivers?all=1 — 배정 후보(기본은 산모신생아 직군, all=1 이면 활동 중 전체) */
    public function caregivers(Request $request): JsonResponse
    {
        $q = DB::table('caregivers as cg')->join('users as u', 'u.id', '=', 'cg.user_id')
            ->where('cg.status', 'active')->whereNull('cg.deleted_at');
        if (!$request->boolean('all')) {
            $q->whereRaw("FIND_IN_SET('postpartum', cg.service_domains)");
        }
        $rows = $q->orderBy('u.name')->limit(300)->get(['cg.id', 'u.name', 'cg.service_domains', 'cg.base_address', 'cg.rating_avg', 'cg.completed_sessions']);

        return response()->json(['success' => true, 'data' => $rows->map(fn ($r) => [
            'id' => $r->id, 'name' => $r->name, 'postpartum' => in_array('postpartum', explode(',', (string) $r->service_domains), true),
            'region' => $r->base_address ? implode(' ', array_slice(preg_split('/\s+/', $r->base_address), 0, 2)) : null,
            'rating' => $r->rating_avg !== null ? round((float) $r->rating_avg, 1) : null, 'completed_sessions' => (int) $r->completed_sessions,
        ])]);
    }

    /* ───────────── 공통 ───────────── */

    private function run(int $id, \Closure $fn, string $ok): JsonResponse
    {
        $c = MnhContract::findOrFail($id);
        try {
            $r = $fn($c);
        } catch (MnhContractException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->status, $e->extra);
        }

        return response()->json(['success' => true, 'message' => $ok, 'result' => is_array($r) ? $r : null,
            'data' => MnhContractPresenter::detail($c->fresh(), $this->svc, true)]);
    }

    private function summaries($rows): array
    {
        $clients = DB::table('postpartum_clients')->whereIn('id', $rows->pluck('postpartum_client_id'))->get(['id', 'name'])->keyBy('id');
        $cg = DB::table('caregivers as cg')->join('users as u', 'u.id', '=', 'cg.user_id')
            ->whereIn('cg.id', $rows->pluck('caregiver_id')->filter())->pluck('u.name', 'cg.id');
        $done = DB::table('care_sessions as cs')->join('matches as m', 'm.id', '=', 'cs.match_id')
            ->whereIn('m.request_id', $rows->pluck('match_request_id')->filter())->where('cs.status', 'completed')
            ->groupBy('m.request_id')->selectRaw('m.request_id, count(*) n')->pluck('n', 'm.request_id');

        return $rows->map(fn ($c) => MnhContractPresenter::summary($c, $this->svc, $clients[$c->postpartum_client_id] ?? null, $cg[$c->caregiver_id] ?? null)
            + ['completed_days' => (int) ($done[$c->match_request_id] ?? 0)])->values()->all();
    }

    private function fail(string $code, string $message, int $status = 422, array $extra = []): JsonResponse
    {
        return response()->json(['success' => false, 'error_code' => $code, 'message' => $message] + $extra, $status);
    }
}
