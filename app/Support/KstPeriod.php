<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * 한국 시각(KST) 기간 → DB(UTC) 비교용 경계. 모든 시각은 KST 로 계산한다(2026-09-29 규칙).
 * today·this_week(월~)·last_week·this_month·last_month·custom(from,to YYYY-MM-DD, 끝 날 포함).
 */
final class KstPeriod
{
    /** @return array{0: Carbon, 1: Carbon, 2: string, 3: string} [UTC 시작(이상), UTC 끝(미만), KST 시작일, KST 끝일(포함)] */
    public static function range(string $period, ?string $from = null, ?string $to = null): array
    {
        $today = Carbon::now('Asia/Seoul')->startOfDay();
        [$s, $e] = match ($period) {
            'today' => [$today->copy(), $today->copy()->addDay()],
            'last_week' => [$today->copy()->startOfWeek()->subWeek(), $today->copy()->startOfWeek()],
            'this_month' => [$today->copy()->startOfMonth(), $today->copy()->startOfMonth()->addMonth()],
            'last_month' => [$today->copy()->startOfMonth()->subMonth(), $today->copy()->startOfMonth()],
            'custom' => [
                self::day($from) ?? $today->copy()->startOfWeek(),
                (self::day($to) ?? $today->copy())->addDay(),
            ],
            default => [$today->copy()->startOfWeek(), $today->copy()->startOfWeek()->addWeek()], // this_week
        };
        if ($e->lte($s)) {
            $e = $s->copy()->addDay();
        }

        return [$s->copy()->utc(), $e->copy()->utc(), $s->toDateString(), $e->copy()->subDay()->toDateString()];
    }

    private static function day(?string $ymd): ?Carbon
    {
        return $ymd && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) ? Carbon::parse($ymd, 'Asia/Seoul')->startOfDay() : null;
    }
}
