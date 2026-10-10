<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\MnhContractException;
use App\Http\Controllers\Controller;
use App\Models\MnhContract;
use App\Services\MnhContractService;
use App\Services\NotificationService;
use App\Support\MnhContractPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 출산 전 예비예약 → 출산 후 확정(CAREN-REF-01 2단계, 2026-10-10). 회원(산모 본인·가족) 쪽.
 *
 *  1) POST /v1/matching/postpartum-clients/{id}/confirm-birth — 실제 출산일(·아기) 등록. 예비 계약마다 같은 간격만큼 옮긴 개시일을 제안한다.
 *  2) POST /v1/mnh/contracts/{id}/confirm-start — 개시일 확정. 출산일·개시일이 2주(REVIEW_GAP_DAYS) 이상 바뀌었거나
 *     담당 일정이 겹치면 바로 바꾸지 않고 기관 확인 요청(start_change_request)으로 남긴다.
 */
class MnhBirthController extends Controller
{
    public const REVIEW_GAP_DAYS = 14;

    public function __construct(private MnhContractService $svc)
    {
    }

    public function confirmBirth(Request $request, int $id): JsonResponse
    {
        $client = DB::table('postpartum_clients')->where('id', $id)->where('user_id', $request->user()->id)->whereNull('deleted_at')->first();
        if (!$client) {
            return $this->fail('NOT_FOUND', '산모 정보를 찾을 수 없어요.', 404);
        }
        $today = Carbon::now('Asia/Seoul')->toDateString();
        $data = $request->validate([
            'delivery_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $today, 'after_or_equal:' . Carbon::now('Asia/Seoul')->subDays(120)->toDateString()],
            'delivery_type' => ['nullable', 'in:natural,cesarean,vbac'],
            'newborns' => ['nullable', 'array', 'max:5'],
            'newborns.*.name' => ['required', 'string', 'max:50'],
            'newborns.*.gender' => ['required', 'in:M,F'],
            'newborns.*.birth_weight_g' => ['required', 'integer', 'min:500', 'max:7000'],
        ], [
            'delivery_date.before_or_equal' => '출산일은 오늘이나 그 이전 날짜여야 해요.',
            'delivery_date.after_or_equal' => '출산일을 다시 확인해 주세요.',
        ]);

        $expected = $client->expected_delivery_date ?: $client->delivery_date;
        DB::transaction(function () use ($client, $data, $expected) {
            DB::table('postpartum_clients')->where('id', $client->id)->update(array_filter([
                'delivery_date' => $data['delivery_date'],
                'delivery_type' => $data['delivery_type'] ?? null,
                'expected_delivery_date' => $expected,
                'birth_confirmed' => true,
                'birth_confirmed_at' => now(),
                'is_multiple_birth' => count($data['newborns'] ?? []) > 1 ? 1 : null,
                'updated_at' => now(),
            ], fn ($v) => $v !== null));
            $order = (int) DB::table('newborns')->where('postpartum_client_id', $client->id)->max('birth_order');
            foreach ($data['newborns'] ?? [] as $b) {
                DB::table('newborns')->insert([
                    'postpartum_client_id' => $client->id, 'name' => $b['name'], 'gender' => $b['gender'],
                    'birth_datetime' => $data['delivery_date'] . ' 00:00:00', 'birth_weight_g' => $b['birth_weight_g'],
                    'birth_order' => min(++$order, 5), 'is_alive' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $shift = $expected ? (int) Carbon::parse($expected)->diffInDays(Carbon::parse($data['delivery_date']), false) : 0;
        $tomorrow = Carbon::now('Asia/Seoul')->addDay()->toDateString();
        $contracts = MnhContract::where('postpartum_client_id', $client->id)->where('provisional', true)
            ->whereIn('status', ['applied', 'confirmed', 'active'])->get()
            ->map(function ($c) use ($shift, $tomorrow) {
                $suggest = $c->start_date->copy()->addDays($shift)->toDateString();

                return ['id' => $c->id, 'contract_no' => $c->contract_no, 'start_date' => $c->start_date->format('Y-m-d'),
                    'suggested_start' => max($suggest, $tomorrow), 'days' => $c->days];
            })->values();

        return response()->json(['success' => true, 'message' => '출산일을 등록했어요. 축하드려요!', 'data' => [
            'delivery_date' => $data['delivery_date'],
            'expected_delivery_date' => $expected,
            'birth_gap_days' => abs($shift),
            'provisional_contracts' => $contracts,
        ]]);
    }

    public function confirmStart(Request $request, int $id): JsonResponse
    {
        $c = MnhContract::where('id', $id)->where('user_id', $request->user()->id)->firstOrFail();
        $tomorrow = Carbon::now('Asia/Seoul')->addDay()->toDateString();
        $data = $request->validate(['start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:' . $tomorrow]],
            ['start_date.after_or_equal' => '개시일은 내일 이후로 골라 주세요.']);
        if (!$c->provisional) {
            return $this->fail('NOT_PROVISIONAL', '이미 확정된 계약이에요. 일정 변경은 운영팀에 요청해 주세요.');
        }
        if (!in_array($c->status, ['applied', 'confirmed', 'active'], true)) {
            return $this->fail('CLOSED', '진행 중인 계약이 아니에요.');
        }
        $client = DB::table('postpartum_clients')->where('id', $c->postpartum_client_id)->first(['birth_confirmed', 'delivery_date', 'expected_delivery_date']);
        if (!$client?->birth_confirmed) {
            return $this->fail('BIRTH_NOT_CONFIRMED', '출산일을 먼저 등록해 주세요.');
        }

        $birthGap = $client->expected_delivery_date ? abs((int) Carbon::parse($client->expected_delivery_date)->diffInDays(Carbon::parse($client->delivery_date), false)) : 0;
        $startGap = abs((int) $c->start_date->diffInDays(Carbon::parse($data['start_date']), false));
        $reason = null;
        if ($birthGap >= self::REVIEW_GAP_DAYS) {
            $reason = sprintf('출산일이 예정일과 %d일 차이', $birthGap);
        } elseif ($startGap >= self::REVIEW_GAP_DAYS) {
            $reason = sprintf('개시일이 %d일 바뀜', $startGap);
        }

        if (!$reason) {
            try {
                $this->svc->confirmStart($c, $data['start_date'], false, false, $request->user()->id);
                $c->refresh();

                return response()->json(['success' => true, 'message' => '일정을 확정했어요.',
                    'data' => MnhContractPresenter::detail($c, $this->svc, false)]);
            } catch (MnhContractException $e) {
                if (!in_array($e->errorCode, ['CONFLICT', 'CAREGIVER_CONFLICT', 'SCHEDULE_CONFLICT'], true) && $e->status !== 409) {
                    return $this->fail($e->errorCode, $e->getMessage(), $e->status);
                }
                $reason = '담당 관리사 일정 확인 필요';
            }
        }

        $c->update(['start_change_request' => ['start_date' => $data['start_date'], 'reason' => $reason,
            'birth_gap' => $birthGap, 'start_gap' => $startGap, 'requested_at' => now('Asia/Seoul')->toIso8601String()]]);
        $this->svc->log($c, 'start_review', $data['start_date'], ['reason' => $reason, 'start_date' => $data['start_date']], $request->user()->id);
        $notifier = app(NotificationService::class);
        foreach ($notifier->adminsFor('mnh') as $adminUserId) {
            $notifier->notifySafely($adminUserId, NotificationService::TYPE_MNH_CONTRACT, [
                'contract_id' => $c->id, 'event' => 'start_review', 'contract_no' => $c->contract_no,
                'start_date' => $data['start_date'], 'reason' => $reason,
            ]);
        }

        return response()->json(['success' => true, 'review' => true,
            'message' => sprintf('%s라 운영팀이 관리사 일정을 확인한 뒤 확정해요. 확정되면 알려 드릴게요.', $reason),
            'data' => MnhContractPresenter::detail($c->fresh(), $this->svc, false)]);
    }

    private function fail(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json(['success' => false, 'error_code' => $code, 'message' => $message], $status);
    }
}
