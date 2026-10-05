<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * 공휴일 조회(KST Y-m-d → 이름). 표가 작아 통째로 읽고, 큐 워커처럼 오래 사는 프로세스도
 * 관리자 수정이 곧 보이도록 60초만 기억한다.
 */
class Holidays
{
    private static ?array $map = null;

    private static int $loadedAt = 0;

    /** @return array<string,string> */
    public static function map(): array
    {
        if (self::$map === null || time() - self::$loadedAt > 60) {
            self::$map = DB::table('holidays')->orderBy('date')->pluck('name', 'date')
                ->mapWithKeys(fn ($n, $d) => [substr((string) $d, 0, 10) => $n])->all();
            self::$loadedAt = time();
        }

        return self::$map;
    }

    public static function name(string $date): ?string
    {
        return self::map()[$date] ?? null;
    }

    public static function flush(): void
    {
        self::$map = null;
    }
}
