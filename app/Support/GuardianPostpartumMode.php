<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * 보호자의 산모신생아 이용 방식(guardians.preferences.postpartum_mode = voucher|hourly) — 2026-10-11.
 * 가입 때 고르고(AuthController::signup), 관리자 상세(Admin\OperationsController)·본인 내 정보(GuardianController)에서 바꾼다.
 * null 이면 지운다. preferences 의 다른 값(services 등)은 그대로 두고, 남는 게 없으면 NULL.
 */
class GuardianPostpartumMode
{
    public const MODES = ['voucher', 'hourly'];

    public static function set(int $guardianId, ?string $mode): void
    {
        $prefs = json_decode((string) DB::table('guardians')->where('id', $guardianId)->value('preferences'), true) ?: [];
        if ($mode === null) {
            unset($prefs['postpartum_mode']);
        } else {
            $prefs['postpartum_mode'] = $mode;
        }
        DB::table('guardians')->where('id', $guardianId)->update([
            'preferences' => $prefs ? json_encode($prefs, JSON_UNESCAPED_UNICODE) : null,
            'updated_at' => now(),
        ]);
    }

    /**
     * 돌봄전문가 화면용 바우처 표시 — 요청 id → 'contract'(바우처 계약으로 생긴 방문) | 'eligible'(계약은 없지만 보호자가 가입 때 정부 바우처를 고름).
     * 해당 없으면 키가 없다. 받은 제안·열린 요청·일정 목록이 같이 쓴다(2026-10-11).
     */
    public static function voucherFlags(iterable $requestIds): array
    {
        $ids = collect($requestIds)->filter()->map(fn ($v) => (int) $v)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $out = [];
        $rows = DB::table('match_requests as r')
            ->leftJoin('guardians as g', 'g.id', '=', 'r.guardian_id')
            ->whereIn('r.id', $ids)
            ->get(['r.id', 'r.mnh_contract_id', DB::raw("JSON_UNQUOTE(JSON_EXTRACT(g.preferences, '$.postpartum_mode')) as pp_mode")]);
        foreach ($rows as $r) {
            if ($r->mnh_contract_id) {
                $out[$r->id] = 'contract';
            } elseif ($r->pp_mode === 'voucher') {
                $out[$r->id] = 'eligible';
            }
        }

        return $out;
    }
}
