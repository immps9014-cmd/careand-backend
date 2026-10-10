<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\MnhContractException;
use App\Http\Controllers\Controller;
use App\Models\MnhContract;
use App\Models\MnhAddonItem;
use App\Models\MnhIncomeCriterion;
use App\Models\MnhSupportType;
use App\Services\MnhContractService;
use App\Support\MnhContractPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 회원 — 산모신생아 바우처 기간형 계약(CAREN-MNH-01 2단계, 2026-10-05).
 * 이용자가 유형·기간을 고르면 본인부담금을 안내하고 계약을 신청한다. 담당 배정·선납 확인은 제공기관(케어앤 운영팀)이 한다.
 */
class MnhContractController extends Controller
{
    public function __construct(private MnhContractService $svc)
    {
    }

    /** GET /v1/mnh/options?year= — 선택지 + 그 해 기준표(고시값, 공개 정보) */
    public function options(Request $request): JsonResponse
    {
        $cfg = config('mnh');

        return response()->json(['success' => true, 'data' => self::guidePayload($this->year($request)) + [
            'payment_methods' => $cfg['payment_methods'],
            'min_days' => $cfg['min_days'],
            'max_days' => $cfg['max_days'],
            'weekdays' => $cfg['weekdays'],
            'daily_start' => $cfg['daily_start'],
            'daily_minutes' => $cfg['daily_minutes'],
        ]]);
    }

    /** GET /v1/public/mnh/guide?year= — 로그인 없이 보는 바우처 안내·본인부담 계산기용(고시값 + 케어앤 추가요금, 개인정보 없음) */
    public function guide(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => self::guidePayload($this->year($request))]);
    }

    private function year(Request $request): int
    {
        $y = (int) $request->query('year');

        return $y >= 2020 && $y <= 2100 ? $y : Carbon::now('Asia/Seoul')->year;
    }

    /** 안내·계산·신청이 모두 같은 기준표를 읽는다(CAREN-REF-01 원칙 1). */
    public static function guidePayload(int $year): array
    {
        $rows = MnhSupportType::where('year', $year)->where('is_active', true)
            ->orderByRaw("FIELD(fetus_type,'single','twins','triplets_plus','quadruplets_plus')")->orderByRaw("FIELD(birth_order,'first','second','third_plus','any')")->orderByRaw("RIGHT(income_tier, 2)")->orderByRaw("FIELD(SUBSTRING_INDEX(SUBSTRING_INDEX(income_tier,'-',2),'-',-1),'가','통합','라')")->orderBy('income_tier')->orderByRaw("FIELD(period,'short','standard','extended')")
            ->get(['id', 'fetus_type', 'birth_order', 'income_tier', 'period', 'days', 'total_price', 'gov_support', 'self_pay', 'note']);
        $cfg = config('mnh');

        return [
            'year' => $year,
            'rates_ready' => $rows->isNotEmpty(),
            'support_types' => $rows,
            'income_tiers' => $rows->pluck('income_tier')->unique()->values(),
            'income_criteria' => MnhIncomeCriterion::where('year', $year)->orderBy('household_size')
                ->get(['household_size', 'income_limit', 'premium_employee', 'premium_regional', 'premium_mixed']),
            'addons' => MnhAddonItem::where('is_active', true)->orderBy('sort')->orderBy('id')
                ->get(['id', 'kind', 'name', 'unit_label', 'price', 'max_qty', 'note']),
            'fetus_types' => $cfg['fetus_types'],
            'birth_orders' => $cfg['birth_orders'],
            'periods' => $cfg['periods'],
            'addon_kinds' => $cfg['addon_kinds'],
            'general' => self::generalRates(),
        ];
    }

    /**
     * 바우처 없이 이용할 때의 하루 예상 금액(케어앤 일반 요금) — 적정 돌봄비와 같은 산출(PricingService)로
     * 다음 평일 하루 8시간(야간 케어는 22시부터) 기준, 지역 가산 없이 전국 기준. 계산기 「안 써요」 탭용.
     */
    public static function generalRates(): array
    {
        $day = Carbon::now('Asia/Seoul')->addDay();
        while ($day->isWeekend() || \App\Support\Holidays::name($day->toDateString())) {
            $day->addDay();
        }
        $hours = (int) (config('mnh.daily_minutes') / 60);
        $svc = app(\App\Services\Pricing\PricingService::class);

        return \App\Models\ServiceCategory::where('domain', 'postpartum')->where('is_active', true)->orderBy('id')->get()
            ->map(function ($c) use ($day, $hours, $svc) {
                $night = $c->code === 'PP_NIGHT';
                $start = $day->copy()->setTime($night ? 22 : 9, 0)->utc();
                $e = $svc->estimate(new \App\Models\MatchRequest([
                    'service_domain' => 'postpartum', 'category_id' => $c->id, 'mode' => 'normal',
                    'scheduled_start' => $start, 'duration_min' => $hours * 60,
                ]));

                return ['category_id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'night' => $night,
                    'hourly' => (int) $e['suggested'], 'floor' => (int) $e['floor'], 'ceil' => (int) $e['ceil'],
                    'day_hours' => $hours, 'day_price' => (int) $e['suggested'] * $hours];
            })->values()->all();
    }

    /** GET /v1/mnh/contracts */
    public function index(Request $request): JsonResponse
    {
        $rows = MnhContract::where('user_id', $request->user()->id)->orderByDesc('id')->get();
        $clients = DB::table('postpartum_clients')->whereIn('id', $rows->pluck('postpartum_client_id'))->get(['id', 'name'])->keyBy('id');
        $cgNames = DB::table('caregivers as cg')->join('users as u', 'u.id', '=', 'cg.user_id')
            ->whereIn('cg.id', $rows->pluck('caregiver_id')->filter())->pluck('u.name', 'cg.id');

        return response()->json(['success' => true, 'data' => $rows->map(fn ($c) => MnhContractPresenter::summary(
            $c, $this->svc, $clients[$c->postpartum_client_id] ?? null, $cgNames[$c->caregiver_id] ?? null))->values()]);
    }

    /** GET /v1/mnh/contracts/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $c = MnhContract::where('id', $id)->where('user_id', $request->user()->id)->firstOrFail();

        return response()->json(['success' => true, 'data' => MnhContractPresenter::detail($c, $this->svc, false)]);
    }

    /** POST /v1/mnh/contracts — 계약 신청 */
    public function store(Request $request): JsonResponse
    {
        $cfg = config('mnh');
        $data = $request->validate([
            'postpartum_client_id' => ['required', 'integer'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'fetus_type' => ['required', 'in:' . implode(',', array_keys($cfg['fetus_types']))],
            'birth_order' => ['required', 'in:' . implode(',', array_keys($cfg['birth_orders']))],
            'income_tier' => ['nullable', 'string', 'max:30'],
            'period' => ['nullable', 'in:' . implode(',', array_keys($cfg['periods']))],
            'days' => ['nullable', 'integer', 'between:' . $cfg['min_days'] . ',' . $cfg['max_days']],
            'payment_method' => ['required', 'in:' . implode(',', array_keys($cfg['payment_methods']))],
            'member_note' => ['nullable', 'string', 'max:1000'],
            'addons' => ['nullable', 'array', 'max:20'],
            'addons.*.id' => ['required', 'integer'],
            'addons.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
        ]);
        $user = $request->user();
        $client = DB::table('postpartum_clients')->where('id', $data['postpartum_client_id'])->where('user_id', $user->id)
            ->whereNull('deleted_at')->first();
        if (!$client) {
            return $this->fail('NOT_FOUND', '산모 정보를 찾을 수 없어요.', 404);
        }
        $today = Carbon::now('Asia/Seoul')->startOfDay();
        if (Carbon::parse($data['start_date'], 'Asia/Seoul')->lt($today->copy()->addDays($cfg['min_lead_days']))) {
            return $this->fail('START_TOO_SOON', '서비스 개시일은 내일 이후로 골라 주세요.');
        }
        $open = MnhContract::where('postpartum_client_id', $client->id)->whereIn('status', ['applied', 'confirmed', 'active'])->exists();
        if ($open) {
            return $this->fail('ALREADY_OPEN', '이 산모로 진행 중인 바우처 계약이 이미 있어요.');
        }

        $year = (int) substr($data['start_date'], 0, 4);
        $type = null;
        if (!empty($data['income_tier']) && !empty($data['period'])) {
            $type = $this->svc->findSupportType($year, $data['fetus_type'], $data['birth_order'], $data['income_tier'], $data['period']);
            if (!$type) {
                return $this->fail('NO_SUPPORT_TYPE', '고른 조건에 맞는 지원 유형이 기준표에 없어요. 다시 골라 주세요.');
            }
        }
        $days = $type?->days ?? ($data['days'] ?? null);
        if (!$days) {
            return $this->fail('DAYS_REQUIRED', '이용 일수를 골라 주세요.');
        }

        // 추가요금·대여 — 지금 판매 중인 항목만, 가격은 서버 값으로 스냅샷
        $addons = [];
        $picked = collect($data['addons'] ?? [])->groupBy('id')->map(fn ($g) => (int) $g->sum('qty'));
        if ($picked->isNotEmpty()) {
            $items = MnhAddonItem::where('is_active', true)->whereIn('id', $picked->keys())->get()->keyBy('id');
            foreach ($picked as $id => $qty) {
                $it = $items[$id] ?? null;
                if (!$it) {
                    return $this->fail('ADDON_UNAVAILABLE', '고른 추가 항목 중 지금 신청할 수 없는 것이 있어요. 다시 골라 주세요.');
                }
                if ($qty > $it->max_qty) {
                    return $this->fail('ADDON_QTY', sprintf('%s은(는) 최대 %d%s까지 고를 수 있어요.', $it->name, $it->max_qty, $it->unit_label));
                }
                $addons[] = ['id' => $it->id, 'kind' => $it->kind, 'name' => $it->name, 'unit_label' => $it->unit_label,
                    'price' => $it->price, 'qty' => $qty, 'amount' => $it->price * $qty];
            }
        }

        $c = DB::transaction(function () use ($data, $user, $client, $type, $days, $year, $cfg, $addons) {
            $c = MnhContract::create([
                'contract_no' => 'tmp-' . uniqid(),
                'postpartum_client_id' => $client->id,
                'user_id' => $user->id,
                'support_type_id' => $type?->id,
                'year' => $year,
                'fetus_type' => $data['fetus_type'],
                'birth_order' => $data['birth_order'],
                'income_tier' => $type?->income_tier ?? ($data['income_tier'] ?? null),
                'period' => $type?->period ?? ($data['period'] ?? null),
                'days' => $days,
                'total_price' => $type?->total_price,
                'gov_support' => $type?->gov_support,
                'self_pay' => $type?->self_pay,
                'start_date' => $data['start_date'],
                'weekdays' => $cfg['weekdays'],
                'daily_start' => $cfg['daily_start'],
                'daily_minutes' => $cfg['daily_minutes'],
                'payment_method' => $data['payment_method'],
                'member_note' => $data['member_note'] ?? null,
                'addons' => $addons ?: null,
                'addon_total' => $addons ? array_sum(array_column($addons, 'amount')) : null,
                'provisional' => !$client->birth_confirmed,   // 출산 전 신청 = 예비 계약(출산일 등록 후 확정)
                'status' => 'applied',
            ]);
            $c->update(['contract_no' => sprintf('MNH-%d-%04d', $year, $c->id)]);
            $this->svc->log($c, 'created', $data['start_date'], ['self_pay' => $c->self_pay, 'addon_total' => $c->addon_total], $user->id);

            return $c;
        });

        // 신청 접수 → 산모신생아 담당 관리자(배정·선납 안내 필요)
        $notifier = app(\App\Services\NotificationService::class);
        foreach ($notifier->adminsFor('mnh') as $adminUserId) {
            $notifier->notifySafely($adminUserId, \App\Services\NotificationService::TYPE_MNH_CONTRACT, [
                'contract_id' => $c->id, 'event' => 'applied', 'contract_no' => $c->contract_no, 'start_date' => $data['start_date'],
            ]);
        }

        return response()->json(['success' => true, 'message' => '바우처 계약 신청이 접수됐어요. 운영팀이 본인부담금 납부와 담당 배정을 안내해 드려요.',
            'data' => MnhContractPresenter::detail($c->fresh(), $this->svc, false)], 201);
    }

    /** POST /v1/mnh/contracts/{id}/cancel — 배정 전 신청만 회원이 직접 취소 */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $c = MnhContract::where('id', $id)->where('user_id', $request->user()->id)->firstOrFail();
        if ($c->status !== 'applied' || $c->match_request_id) {
            return $this->fail('CONTACT_OPS', '담당 배정 뒤에는 운영팀을 통해 취소할 수 있어요.');
        }
        if ($c->prepaid_at) {
            // 낸 본인부담금을 돌려받아야 하므로 운영팀이 환불액과 함께 취소한다
            return $this->fail('CONTACT_OPS', '본인부담금 납부가 확인된 신청은 운영팀을 통해 취소할 수 있어요.');
        }
        try {
            $this->svc->cancel($c, (string) ($request->input('reason') ?: '이용자 취소'), $request->user()->id);
        } catch (MnhContractException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->status);
        }

        return response()->json(['success' => true, 'message' => '신청을 취소했어요.']);
    }

    private function fail(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json(['success' => false, 'error_code' => $code, 'message' => $message], $status);
    }
}
