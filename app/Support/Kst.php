<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * 한국시각(KST) 변환 — 케어앤의 모든 시각은 한국시각으로 계산·표시한다(2026-09-29 운영 규칙).
 * 앱 시간대·DB 저장은 UTC 라서, DB::table() 로 읽은 원문 시각을 그대로 API 로 넘기면
 * 브라우저가 시간대 없는 문자열을 현지(한국)시각으로 읽어 9시간 이르게 보인다.
 */
final class Kst
{
    public const TZ = 'Asia/Seoul';

    /** DB(UTC) 시각 → 한국시각 ISO 8601("2026-09-30T10:00:00+09:00"). 빈 값은 null. */
    public static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $c = $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse((string) $value, 'UTC');

        return $c->setTimezone(self::TZ)->toIso8601String();
    }

    /** 사용자 입력 시각 → UTC Carbon. 시간대 없는 값("2026-09-30 10:00")은 한국시각으로 해석한다. */
    public static function parseInput(string $value): Carbon
    {
        return Carbon::parse($value, self::TZ)->utc();
    }
}
