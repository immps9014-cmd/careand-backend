<?php

namespace App\Support;

use App\Models\MnhContract;
use App\Services\MnhContractService;
use Illuminate\Support\Facades\DB;

/** 바우처 계약 응답 모양 — 회원(본인 계약)·관리자 공용. 관리자 전용 칸은 $admin 일 때만. */
final class MnhContractPresenter
{
    public static function summary(MnhContract $c, MnhContractService $svc, ?object $client = null, ?string $caregiverName = null): array
    {
        $labels = config('mnh');

        return [
            'id' => $c->id,
            'contract_no' => $c->contract_no,
            'status' => $c->status,
            'status_label' => $labels['statuses'][$c->status] ?? $c->status,
            'postpartum_client_id' => $c->postpartum_client_id,
            'client_name' => $client->name ?? null,
            'year' => $c->year,
            'fetus_type' => $c->fetus_type,
            'birth_order' => $c->birth_order,
            'income_tier' => $c->income_tier,
            'period' => $c->period,
            'support_label' => self::supportLabel($c),
            'days' => $c->days,
            'total_price' => $c->total_price,
            'gov_support' => $c->gov_support,
            'self_pay' => $c->self_pay,
            'rates_set' => $c->self_pay !== null,
            'addons' => $c->addons ?: [],
            'provisional' => (bool) $c->provisional,
            'start_change_request' => $c->start_change_request,
            'addon_total' => $c->addon_total,
            'start_date' => $c->start_date?->format('Y-m-d'),
            'end_date' => $svc->endDate($c),
            'weekdays' => $c->weekdays ?: config('mnh.weekdays'),
            'skip_dates' => $c->skip_dates ?: [],
            'daily_start' => $c->daily_start,
            'daily_minutes' => $c->daily_minutes,
            'payment_method' => $c->payment_method,
            'payment_method_label' => $labels['payment_methods'][$c->payment_method] ?? $c->payment_method,
            'prepaid' => $c->prepaid_at !== null,
            'prepaid_amount' => $c->prepaid_amount,
            'prepaid_at' => Kst::iso($c->prepaid_at),
            'prepaid_receipt_no' => $c->prepaid_receipt_no,
            'refund_amount' => $c->refund_amount,
            'refunded_at' => Kst::iso($c->refunded_at),
            'caregiver_id' => $c->caregiver_id,
            'caregiver_name' => $caregiverName,
            'member_note' => $c->member_note,
            'cancel_reason' => $c->cancel_reason,
            'created_at' => Kst::iso($c->created_at),
        ];
    }

    public static function supportLabel(MnhContract $c): ?string
    {
        if (!$c->fetus_type) {
            return null;
        }
        $l = config('mnh');

        return trim(implode(' · ', array_filter([
            $l['fetus_types'][$c->fetus_type] ?? $c->fetus_type,
            $c->birth_order && $c->birth_order !== 'any' ? ($l['birth_orders'][$c->birth_order] ?? $c->birth_order) : null,
            $c->income_tier,
            $c->period ? ($l['periods'][$c->period] ?? $c->period) : null,
        ])));
    }

    /** 상세 — 날짜별 일정(제공일·연기일·세션) + 이력 */
    public static function detail(MnhContract $c, MnhContractService $svc, bool $admin): array
    {
        $client = DB::table('postpartum_clients')->where('id', $c->postpartum_client_id)
            ->first(['id', 'name', 'delivery_date', 'delivery_type', 'address', 'address_detail', 'emergency_contact', 'birth_confirmed', 'expected_delivery_date']);
        $cgName = $c->caregiver_id ? DB::table('caregivers as cg')->join('users as u', 'u.id', '=', 'cg.user_id')
            ->where('cg.id', $c->caregiver_id)->value('u.name') : null;
        $out = self::summary($c, $svc, $client, $cgName);

        $sessions = $svc->sessions($c);
        $live = $sessions->where('status', '!=', 'cancelled')->keyBy('date');
        $days = [];
        foreach ($svc->serviceDates($c) as $i => $d) {
            $s = $live[$d] ?? null;
            $days[] = [
                'date' => $d, 'seq' => $i + 1,
                'status' => $s->status ?? 'planned',
                'session_id' => $s->id ?? null,
                'caregiver_name' => $s->caregiver_name ?? null,
                'actual_start' => Kst::iso($s->actual_start ?? null),
                'actual_end' => Kst::iso($s->actual_end ?? null),
                'journal' => $admin && $s ? ($s->journal_note ?: null) : null,
                'holiday' => Holidays::name($d),   // 공휴일 근무로 지정한 날이면 이름
            ];
        }
        $out['schedule'] = $days;
        $out['postponed'] = array_values($c->skip_dates ?: []);
        $out['holidays'] = $svc->skippedHolidays($c);   // 공휴일이라 빠진 날 [{date, name}]
        $out['holiday_work_dates'] = array_values($c->holiday_work_dates ?: []);
        $out['completed_days'] = collect($days)->where('status', 'completed')->count();
        $out['delivery_date'] = $client->delivery_date ?? null;
        $out['birth_confirmed'] = (bool) ($client->birth_confirmed ?? true);
        $out['expected_delivery_date'] = $client->expected_delivery_date ?? null;
        $out['voucher_warning'] = ($client->birth_confirmed ?? true) ? $svc->voucherExpiryWarning($c, $client->delivery_date ?? null) : null;

        $events = $c->events()->orderBy('id')->get();
        $out['events'] = $events
            ->filter(fn ($e) => $admin || in_array($e->type, ['created', 'assigned', 'swapped', 'postponed', 'restored', 'start_changed', 'prepaid', 'cancelled', 'completed', 'holiday_work', 'holiday_off', 'holiday_changed', 'birth_confirmed', 'start_review'], true))
            ->map(fn ($e) => [
                'id' => $e->id, 'type' => $e->type, 'date' => $e->event_date?->format('Y-m-d'),
                'payload' => $admin ? $e->payload : array_intersect_key($e->payload ?? [], array_flip(['reason', 'new_end', 'name', 'start_date'])),
                'created_at' => Kst::iso($e->created_at),
            ])->values();

        if ($admin) {
            $out['admin_note'] = $c->admin_note;
            $out['address'] = trim(($client->address ?? '') . ' ' . ($client->address_detail ?? ''));
            $out['match_request_id'] = $c->match_request_id;
            $out['user'] = DB::table('users')->where('id', $c->user_id)->first(['id', 'name']);
            $out['client_emergency_contact'] = CaregiverProfileExtras::emergency($client->emergency_contact ?? null);   // 산모 비상연락처(2026-10-05)
            $out['care_profile_summary'] = PostpartumCareProfile::summaries(collect([$c->postpartum_client_id]))[$c->postpartum_client_id] ?? null;
            $out['cancelled_sessions'] = $sessions->where('status', 'cancelled')->map(fn ($s) => [
                'date' => $s->date, 'reason' => $s->cancel_reason, 'caregiver_name' => $s->caregiver_name,
            ])->values();
        } else {
            // 이용자에게 담당 자격 요약(요구사항 「인적사항 및 자격사항 전달」) — 공개 서류만
            $out['caregiver_documents'] = $c->caregiver_id
                ? app(\App\Services\CaregiverDocumentService::class)->publicSummary((int) $c->caregiver_id) : [];
        }

        return $out;
    }
}
