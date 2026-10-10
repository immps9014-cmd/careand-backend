<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026년 산모·신생아 건강관리 지원유형 기준표(복지부 고시 81행) 적재 — CAREN-REF-01 1단계(2026-10-10).
 * 출처: 강남구보건소·서울시 임신출산정보센터 2026 가격표(두 곳 81행 전부 일치),
 *       청주시 청원보건소 '26년 가격 안내(수정 2025-12-03: C-라-①형 본인부담 1,292→1,291 반영된 값).
 * 고시는 천원 단위. 서비스 가격은 실제 이용 개시일 기준 연도를 따른다.
 * 이미 운영자가 같은 키로 입력한 행이 있으면 건드리지 않는다(insertOrIgnore, 유니크 uq_mnh_support_type).
 */
return new class extends Migration {
    private const YEAR = 2026;

    // [유형 접두, fetus_type, birth_order, 비고, 일수[단축,표준,연장], 서비스가격[3], [가 지원금[3]], [통합[3]], [라[3]]] (천원)
    private function table(): array
    {
        return [
            ['A', '①', 'single', 'first', '단태아 첫째아', [5, 10, 15], [732, 1464, 2196],
                [[659, 1165, 1525], [569, 1002, 1303], [456, 764, 1035]]],
            ['A', '②', 'single', 'second', '단태아 둘째아', [10, 15, 20], [1464, 2196, 2928],
                [[1345, 1794, 2094], [1165, 1525, 1767], [943, 1193, 1440]]],
            ['A', '③', 'single', 'third_plus', '단태아 셋째아 이상', [10, 15, 20], [1464, 2196, 2928],
                [[1374, 1838, 2154], [1195, 1548, 1797], [973, 1236, 1499]]],
            ['B', '①', 'twins', 'any', '쌍태아 또는 중증장애 산모의 단태아 · 인력 1명', [10, 15, 20], [1832, 2748, 3664],
                [[1758, 2357, 2771], [1572, 2050, 2436], [1274, 1605, 1952]]],
            ['B', '②', 'twins', 'any', '쌍태아 또는 중증장애 산모의 단태아 · 인력 2명', [10, 15, 20], [2848, 4272, 5696],
                [[2614, 3478, 4289], [2369, 3165, 3915], [2004, 2698, 3353]]],
            ['C', '①', 'triplets_plus', 'any', '삼태아 또는 중증장애 산모의 쌍태아 · 인력 2명', [15, 25, 40], [5544, 9240, 14784],
                [[5431, 8303, 12088], [4983, 7368, 11039], [4253, 6337, 9540]]],
            ['C', '②', 'triplets_plus', 'any', '삼태아 또는 중증장애 산모의 쌍태아 · 인력 3명', [15, 25, 40], [6408, 10680, 17088],
                [[6278, 9596, 13968], [5759, 8514, 12755], [4914, 7321, 11020]]],
            ['D', '①', 'quadruplets_plus', 'any', '사태아 이상 또는 중증장애 산모의 삼태아 이상 · 인력 2명', [15, 25, 40], [5976, 9960, 15936],
                [[5854, 8952, 13035], [5372, 7946, 11906], [4586, 6836, 10293]]],
            ['D', '②', 'quadruplets_plus', 'any', '사태아 이상 또는 중증장애 산모의 삼태아 이상 · 인력 4명', [15, 25, 40], [8544, 14240, 22784],
                [[8369, 12789, 18604], [7674, 11338, 16978], [6542, 9740, 14655]]],
        ];
    }

    public function up(): void
    {
        $tiers = ['가' => '자격확인(기초·차상위 등)', '통합' => '기준중위소득 150% 이하', '라' => '150% 초과·예외지원'];
        $periods = ['short', 'standard', 'extended'];
        $now = now();
        $rows = [];
        foreach ($this->table() as [$grp, $no, $fetus, $order, $desc, $days, $price, $support]) {
            $ti = 0;
            foreach ($tiers as $tier => $tierDesc) {
                foreach ($periods as $pi => $period) {
                    $total = $price[$pi] * 1000;
                    $gov = $support[$ti][$pi] * 1000;
                    if ($gov > $total) {
                        throw new RuntimeException("{$grp}-{$tier}-{$no}형 {$period}: 지원금이 가격보다 큼");
                    }
                    $rows[] = [
                        'year' => self::YEAR, 'fetus_type' => $fetus, 'birth_order' => $order,
                        'income_tier' => "{$grp}-{$tier}-{$no}형", 'period' => $period, 'days' => $days[$pi],
                        'total_price' => $total, 'gov_support' => $gov, 'self_pay' => $total - $gov,
                        'note' => "{$desc} · {$tierDesc} (2026 복지부 고시)", 'is_active' => true,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
                $ti++;
            }
        }
        DB::table('mnh_support_types')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('mnh_support_types')->where('year', self::YEAR)->where('note', 'like', '%(2026 복지부 고시)')->delete();
    }
};
