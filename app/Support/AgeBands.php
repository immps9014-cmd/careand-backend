<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;

/**
 * 회원 나이대 — users.birth_date 기준 만 나이, 오늘 KST(2026-10-11).
 * 관리자 회원 목록 필터(age_band)와 대시보드 분포가 같은 구간을 쓴다.
 */
final class AgeBands
{
    /** 키 => [라벨, 최소 만 나이, 최대 만 나이] — 순서가 곧 화면 순서 */
    public const BANDS = [
        'under20' => ['20세 미만', 0, 19],
        '20s' => ['20대', 20, 29],
        '30s' => ['30대', 30, 39],
        '40s' => ['40대', 40, 49],
        '50s' => ['50대', 50, 59],
        '60s' => ['60대', 60, 69],
        '70plus' => ['70세 이상', 70, 200],
    ];

    /** 만 lo~hi 세 ⇔ 생년월일이 (오늘-(hi+1)년, 오늘-lo년] 안 */
    public static function apply(Builder $q, string $band, string $col = 'users.birth_date'): void
    {
        if ($band === 'unknown') {
            $q->whereNull($col);
            return;
        }
        if (!isset(self::BANDS[$band])) {
            return;
        }
        [, $lo, $hi] = self::BANDS[$band];
        $today = now('Asia/Seoul')->startOfDay();
        $q->where($col, '>', $today->copy()->subYears($hi + 1)->toDateString())
            ->where($col, '<=', $today->copy()->subYears($lo)->toDateString());
    }
}
