<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SettlementResource;
use App\Models\CareSession;
use App\Models\Settlement;
use App\Models\SettlementItem;
use App\Services\External\HometaxService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SettlementController extends Controller
{
    private const WITHHOLDING_TAX_RATE = 0.033; // 3.3% (사업소득세 3% + 지방세 0.3%)

    public function __construct(private HometaxService $hometaxService)
    {
    }

    /**
     * GET /v1/settlements
     * 인력 본인의 정산 이력
     */
    public function index(Request $request): JsonResponse
    {
        $caregiver = $request->user()->caregiver;
        if (!$caregiver) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_CAREGIVER',
                'message' => '인력 회원만 조회 가능합니다.',
            ], 403);
        }

        $settlements = Settlement::where('caregiver_id', $caregiver->id)
            ->with(['items.session.match.request.senior:id,name'])
            ->orderByDesc('period_start')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => SettlementResource::collection($settlements),
            'meta' => [
                'total' => $settlements->total(),
                'current_page' => $settlements->currentPage(),
                'last_page' => $settlements->lastPage(),
            ],
        ]);
    }

    /**
     * GET /v1/settlements/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $settlement = Settlement::with(['items.session.match.request.senior:id,name'])
            ->findOrFail($id);

        $this->authorize('view', $settlement);

        return response()->json([
            'success' => true,
            'data' => new SettlementResource($settlement),
        ]);
    }

    /**
     * POST /v1/settlements/preview
     * 이번 주 정산 미리보기 (인력 앱)
     */
    public function preview(Request $request): JsonResponse
    {
        $caregiver = $request->user()->caregiver;
        if (!$caregiver) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_CAREGIVER',
                'message' => '인력 회원만 사용 가능합니다.',
            ], 403);
        }

        // 이번 주 (월~일)
        $periodStart = now()->startOfWeek();
        $periodEnd = now()->endOfWeek();

        $sessions = CareSession::whereHas('match', fn ($q) => $q->where('caregiver_id', $caregiver->id))
            ->where('status', 'completed')
            ->whereBetween('actual_end', [$periodStart, $periodEnd])
            ->with('match')
            ->get();

        $gross = 0;
        $items = [];
        foreach ($sessions as $session) {
            $hours = $session->duration_min / 60;
            $rate = $session->match->hourly_rate;
            $amount = round($hours * $rate);
            $gross += $amount;
            $items[] = [
                'session_id' => $session->id,
                'date' => $session->actual_end->toDateString(),
                'hours' => round($hours, 2),
                'hourly_rate' => $rate,
                'amount' => $amount,
            ];
        }

        $tax = (int) round($gross * self::WITHHOLDING_TAX_RATE);
        $net = $gross - $tax;

        return response()->json([
            'success' => true,
            'data' => [
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'session_count' => count($items),
                'total_hours' => round(array_sum(array_column($items, 'hours')), 2),
                'gross_amount' => $gross,
                'withholding_tax' => $tax,
                'net_amount' => $net,
                'items' => $items,
                'expected_paid_at' => $periodEnd->copy()->next(Carbon::MONDAY)->toDateString(),
            ],
        ]);
    }

    /**
     * POST /v1/admin/settlements/run
     * 관리자: 주간 정산 일괄 실행 (매주 월요일 자동)
     */
    public function runWeekly(Request $request): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json([
                'success' => false,
                'error_code' => 'FORBIDDEN',
                'message' => '관리자 전용 API 입니다.',
            ], 403);
        }

        $validated = $request->validate([
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
        ]);

        $periodStart = Carbon::parse($validated['period_start'])->startOfDay();
        $periodEnd = Carbon::parse($validated['period_end'])->endOfDay();

        // 이미 정산된 인력 제외
        $alreadySettled = Settlement::where('period_start', $periodStart->toDateString())
            ->pluck('caregiver_id')
            ->toArray();

        // 해당 기간 완료 세션이 있는 인력 ID 추출
        $caregiverIds = CareSession::where('status', 'completed')
            ->whereBetween('actual_end', [$periodStart, $periodEnd])
            ->whereHas('match')
            ->with('match:id,caregiver_id')
            ->get()
            ->pluck('match.caregiver_id')
            ->unique()
            ->diff($alreadySettled)
            ->values();

        $created = 0;
        $totalGross = 0;
        $totalTax = 0;
        $totalNet = 0;

        foreach ($caregiverIds as $caregiverId) {
            $settlement = $this->createSettlementForCaregiver($caregiverId, $periodStart, $periodEnd);
            if ($settlement) {
                $created++;
                $totalGross += $settlement->gross_amount;
                $totalTax += $settlement->withholding_tax_3_3;
                $totalNet += $settlement->net_amount;
            }
        }

        return response()->json([
            'success' => true,
            'message' => "{$created}명 정산서가 생성되었습니다.",
            'summary' => [
                'caregivers_settled' => $created,
                'total_gross' => $totalGross,
                'total_tax' => $totalTax,
                'total_net' => $totalNet,
            ],
        ]);
    }

    /**
     * POST /v1/admin/settlements/file-tax
     * 관리자: 홈택스 일괄 신고 (정산 후)
     */
    public function fileTax(Request $request): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $validated = $request->validate([
            'period_start' => ['required', 'date_format:Y-m-d'],
        ]);

        $settlements = Settlement::where('period_start', $validated['period_start'])
            ->where('status', 'confirmed')
            ->whereNull('hometax_filing_no')
            ->with('caregiver.user')
            ->get();

        if ($settlements->isEmpty()) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOTHING_TO_FILE',
                'message' => '신고할 정산이 없습니다.',
            ], 422);
        }

        $items = $settlements->map(fn ($s) => [
            'name' => $s->caregiver->user->name,
            'rrn' => '미설정', // 실제: 별도 암호화된 주민번호 컬럼 필요
            'gross_amount' => (int) $s->gross_amount,
            'tax_amount' => (int) $s->withholding_tax_3_3,
        ])->toArray();

        $period = Carbon::parse($validated['period_start'])->format('Y-m');
        $result = $this->hometaxService->fileWithholdingTax($items, $period);

        if (!$result['success']) {
            Log::error('홈택스 신고 실패', ['error' => $result['error']]);
            return response()->json([
                'success' => false,
                'error_code' => 'HOMETAX_FAILED',
                'message' => $result['error'],
            ], 500);
        }

        // 모든 정산서에 신고번호 기록
        foreach ($settlements as $settlement) {
            $settlement->update(['hometax_filing_no' => $result['filing_no']]);
        }

        return response()->json([
            'success' => true,
            'message' => '홈택스 신고가 완료되었습니다.',
            'filing_no' => $result['filing_no'],
            'settlement_count' => $settlements->count(),
        ]);
    }

    /**
     * POST /v1/admin/settlements/{id}/confirm
     * 정산서 확정 (송금 트리거)
     */
    public function confirm(Request $request, int $id): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $settlement = Settlement::findOrFail($id);

        if ($settlement->status !== 'draft') {
            return response()->json([
                'success' => false,
                'error_code' => 'INVALID_STATUS',
                'message' => 'draft 상태의 정산서만 확정 가능합니다.',
            ], 422);
        }

        $settlement->update([
            'status' => 'confirmed',
            'confirmed_by' => $request->user()->id,
            'confirmed_at' => now(),
        ]);

        // TODO: 인력에게 FCM 푸시 (SETTLEMENT_CONFIRMED)
        // TODO: 송금 큐 등록

        return response()->json([
            'success' => true,
            'message' => '정산서가 확정되었습니다.',
            'data' => new SettlementResource($settlement->fresh()),
        ]);
    }

    /**
     * 인력 1명에 대한 주간 정산서 생성
     */
    private function createSettlementForCaregiver(int $caregiverId, Carbon $start, Carbon $end): ?Settlement
    {
        $sessions = CareSession::whereHas('match', fn ($q) => $q->where('caregiver_id', $caregiverId))
            ->where('status', 'completed')
            ->whereBetween('actual_end', [$start, $end])
            ->with('match')
            ->get();

        if ($sessions->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($caregiverId, $start, $end, $sessions) {
            $gross = 0;
            $itemsData = [];

            foreach ($sessions as $session) {
                $hours = $session->duration_min / 60;
                $rate = $session->match->hourly_rate;
                $amount = round($hours * $rate);

                // 야간 할증 (22시~6시)
                $surcharge = 0;
                if ($session->actual_start->hour >= 22 || $session->actual_end->hour < 6) {
                    $surcharge = (int) round($amount * 0.3); // 30% 할증
                }

                $gross += $amount + $surcharge;
                $itemsData[] = [
                    'session_id' => $session->id,
                    'hours' => round($hours, 2),
                    'hourly_rate' => $rate,
                    'amount' => $amount,
                    'surcharge' => $surcharge,
                ];
            }

            $tax = (int) round($gross * self::WITHHOLDING_TAX_RATE);
            $net = $gross - $tax;

            $settlement = Settlement::create([
                'caregiver_id' => $caregiverId,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'gross_amount' => $gross,
                'withholding_tax_3_3' => $tax,
                'net_amount' => $net,
                'status' => 'draft',
            ]);

            foreach ($itemsData as $item) {
                SettlementItem::create(array_merge(['settlement_id' => $settlement->id], $item));
            }

            return $settlement;
        });
    }
}
