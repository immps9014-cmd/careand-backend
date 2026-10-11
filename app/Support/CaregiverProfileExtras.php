<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * 인력 비상연락처·사진·희망사항(2026-10-05) SSOT — 회원 웹·앱(본인), 관리자 자격검증, 이용자 상세(사진만)가 같이 쓴다.
 * 비상연락처는 제3자 개인정보라 본인·관리자에게만, 희망사항은 본인·관리자(매칭 참고)에게만, 사진은 로그인 회원에게 보인다.
 */
class CaregiverProfileExtras
{
    public const TIMES = ['day' => '주간(09~18시)', 'evening' => '저녁', 'night' => '야간', 'live_in' => '입주·숙식'];

    public const RELATIONS = ['배우자', '자녀', '부모', '형제자매', '친척', '지인', '기타'];

    public static function emergencyRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:30'],
            'relation' => ['required', 'string', 'max:20'],
            'phone' => ['required', 'string', 'regex:/^0\d{1,2}-?\d{3,4}-?\d{4}$/'],
        ];
    }

    public static function preferenceRules(): array
    {
        return [
            'days' => ['nullable', 'array'],
            'days.*' => ['integer', 'between:1,7'],
            'times' => ['nullable', 'array'],
            'times.*' => ['string', 'in:' . implode(',', array_keys(self::TIMES))],
            'regions' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public static function normalizePreferences(array $v): array
    {
        $days = array_values(array_unique(array_map('intval', $v['days'] ?? [])));
        sort($days);

        return [
            'days' => $days,
            'times' => array_values(array_intersect(array_keys(self::TIMES), $v['times'] ?? [])),
            'regions' => trim((string) ($v['regions'] ?? '')) ?: null,
            'note' => trim((string) ($v['note'] ?? '')) ?: null,
        ];
    }

    public static function emergency(?string $stored): ?array
    {
        $plain = MedicalCrypto::decrypt($stored);
        $v = $plain ? json_decode($plain, true) : null;

        return is_array($v) ? $v : null;
    }

    public static function encryptEmergency(array $v): string
    {
        $phone = preg_replace('/\D/', '', $v['phone']);

        return MedicalCrypto::encrypt(json_encode(['name' => trim($v['name']), 'relation' => trim($v['relation']), 'phone' => $phone], JSON_UNESCAPED_UNICODE));
    }

    /** 사진 서명 링크(상대 경로, 6시간) — <img> 는 Authorization 헤더를 못 보내서 */
    public static function photoUrl(int $caregiverId, ?string $path, $updatedAt): ?string
    {
        if (!$path) {
            return null;
        }

        return URL::temporarySignedRoute('caregiver.photo', now()->addHours(6),
            ['id' => $caregiverId, 'v' => $updatedAt ? strtotime((string) $updatedAt) : 0], false);
    }

    /** 본인·관리자용 묶음. $admin 이면 비상연락처 원문 */
    public static function forOwnerOrAdmin(int $caregiverId): array
    {
        $r = DB::table('caregivers')->where('id', $caregiverId)->first(['emergency_contact', 'work_preferences', 'photo_path', 'photo_updated_at', 'avatar_key']);
        $pref = $r && $r->work_preferences ? json_decode($r->work_preferences, true) : null;

        return [
            'emergency_contact' => $r ? self::emergency($r->emergency_contact) : null,
            'work_preferences' => $pref,
            'photo_url' => $r ? self::photoUrl($caregiverId, $r->photo_path, $r->photo_updated_at) : null,
            'avatar_key' => $r?->avatar_key,   // 사진 대신 캐릭터면 c1~c8
            'missing' => array_values(array_filter([
                !$r || !$r->emergency_contact ? 'emergency_contact' : null,
                !$r || !$r->photo_path ? 'photo' : null,
            ])),
        ];
    }

    /** 사진 대신 고를 수 있는 캐릭터(2026-10-11) — 그림은 public/caregiver-avatars/{key}.jpg, 원본은 resources/caregiver-avatars */
    public const AVATARS = [
        'c1' => '단발 · 코랄', 'c2' => '숏컷 · 초록', 'c3' => '묶은 머리 · 하늘', 'c4' => '곱슬 · 보라',
        'c5' => '은발 · 베이지', 'c6' => '포니테일 · 민트', 'c7' => '가르마 · 남색', 'c8' => '안경 · 주황',
    ];

    /** 캐릭터를 사진 자리(caregiver-photos/{id}.jpg)에 넣는다 — 사진을 보여 주는 모든 화면이 그대로 캐릭터를 보여 준다. 본인(앱)·관리자 공용 */
    public static function setAvatar(int $caregiverId, string $key): void
    {
        $src = public_path("caregiver-avatars/{$key}.jpg");
        abort_if(!isset(self::AVATARS[$key]) || !is_file($src), 422, '고를 수 없는 캐릭터예요.');
        $path = "caregiver-photos/{$caregiverId}.jpg";
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, (string) file_get_contents($src));
        DB::table('caregivers')->where('id', $caregiverId)->update(['photo_path' => $path, 'photo_updated_at' => now(), 'avatar_key' => $key, 'updated_at' => now()]);
    }

    /** 캐릭터만 지운다(실제 사진이면 건드리지 않음). 지웠으면 true */
    public static function clearAvatar(int $caregiverId): bool
    {
        $r = DB::table('caregivers')->where('id', $caregiverId)->first(['photo_path', 'avatar_key']);
        if (!$r || !$r->avatar_key) {
            return false;
        }
        if ($r->photo_path) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($r->photo_path);
        }
        DB::table('caregivers')->where('id', $caregiverId)->update(['photo_path' => null, 'photo_updated_at' => null, 'avatar_key' => null, 'updated_at' => now()]);

        return true;
    }

    /** 탈퇴 파기 — 사진 파일까지 */
    public static function purge(int $caregiverId): void
    {
        $path = DB::table('caregivers')->where('id', $caregiverId)->value('photo_path');
        if ($path) {
            Storage::disk('local')->delete($path);
        }
        DB::table('caregivers')->where('id', $caregiverId)->update(['emergency_contact' => null, 'work_preferences' => null,
            'photo_path' => null, 'photo_updated_at' => null, 'avatar_key' => null]);
    }
}
