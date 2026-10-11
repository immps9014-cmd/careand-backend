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
}
