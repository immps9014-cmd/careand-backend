<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 산모 가정 정보(postpartum_clients.care_profile) SSOT — 요구사항분석 PDF 「이용자 회원가입」 6~10번 (2026-10-05).
 *
 *   postnatal_center    {used:bool, days:int}             조리원 이용 여부(기간)
 *   spouse              {present:bool, at_home:bool}      배우자(재택 여부)
 *   older_children      [{age:int, school:preschool|school}]  큰아이(연령·취학 여부)
 *   other_family        string                            기타 가족
 *   pets                {has:bool, detail:string}         반려동물
 *   cctv                {has:bool, location:string}       CCTV(위치)
 *   wishes              {mother_care, newborn_care, family_care, housework, emotional_support, work_style, focus, special}  희망사항 8종
 *   preferred_caregiver {region, min_career_years, age_range, religion, other}  희망 제공인력
 *
 * 돌봄전문가에게는 summaries() 의 한 줄(조리원·가족·반려동물·CCTV)만 — 희망사항·희망인력 조건은 이용자·관리자만.
 */
class PostpartumCareProfile
{
    public const WISHES = [
        'mother_care' => '산모 케어', 'newborn_care' => '신생아 케어', 'family_care' => '가족 케어', 'housework' => '가사 케어',
        'emotional_support' => '정서적 지지', 'work_style' => '성향·업무 스타일', 'focus' => '서비스 중점 고려사항', 'special' => '특이사항',
    ];

    /** 검증 규칙 — $prefix 는 'care_profile' 처럼 요청 안의 위치 */
    public static function rules(string $prefix = 'care_profile'): array
    {
        $p = $prefix;
        $rules = [
            $p                                    => ['nullable', 'array'],
            "$p.postnatal_center.used"            => ['nullable', 'boolean'],
            "$p.postnatal_center.days"            => ['nullable', 'integer', 'min:0', 'max:60'],
            "$p.spouse.present"                   => ['nullable', 'boolean'],
            "$p.spouse.at_home"                   => ['nullable', 'boolean'],
            "$p.older_children"                   => ['nullable', 'array', 'max:6'],
            "$p.older_children.*.age"             => ['required', 'integer', 'min:0', 'max:19'],
            "$p.older_children.*.school"          => ['nullable', 'in:preschool,school'],
            "$p.other_family"                     => ['nullable', 'string', 'max:100'],
            "$p.pets.has"                         => ['nullable', 'boolean'],
            "$p.pets.detail"                      => ['nullable', 'string', 'max:100'],
            "$p.cctv.has"                         => ['nullable', 'boolean'],
            "$p.cctv.location"                    => ['nullable', 'string', 'max:100'],
            "$p.preferred_caregiver.region"       => ['nullable', 'string', 'max:50'],
            "$p.preferred_caregiver.min_career_years" => ['nullable', 'integer', 'min:0', 'max:40'],
            "$p.preferred_caregiver.age_range"    => ['nullable', 'string', 'max:30'],
            "$p.preferred_caregiver.religion"     => ['nullable', 'string', 'max:30'],
            "$p.preferred_caregiver.other"        => ['nullable', 'string', 'max:200'],
        ];
        foreach (array_keys(self::WISHES) as $k) {
            $rules["$p.wishes.$k"] = ['nullable', 'string', 'max:300'];
        }

        return $rules;
    }

    /** 검증된 배열 → 저장 형태(알 수 없는 키 제거, 빈 값 정리) */
    public static function normalize(?array $in): ?array
    {
        if (!$in) {
            return null;
        }
        $b = fn ($v) => $v === null ? null : (bool) $v;
        $s = fn ($v) => ($v = trim((string) $v)) === '' ? null : $v;
        $out = [
            'postnatal_center' => ['used' => $b(data_get($in, 'postnatal_center.used')), 'days' => data_get($in, 'postnatal_center.used') ? (int) data_get($in, 'postnatal_center.days', 0) ?: null : null],
            'spouse' => ['present' => $b(data_get($in, 'spouse.present')), 'at_home' => data_get($in, 'spouse.present') ? $b(data_get($in, 'spouse.at_home')) : null],
            'older_children' => array_values(array_map(fn ($c) => ['age' => (int) $c['age'], 'school' => $c['school'] ?? ((int) $c['age'] >= 7 ? 'school' : 'preschool')], (array) data_get($in, 'older_children', []))),
            'other_family' => $s(data_get($in, 'other_family')),
            'pets' => ['has' => $b(data_get($in, 'pets.has')), 'detail' => data_get($in, 'pets.has') ? $s(data_get($in, 'pets.detail')) : null],
            'cctv' => ['has' => $b(data_get($in, 'cctv.has')), 'location' => data_get($in, 'cctv.has') ? $s(data_get($in, 'cctv.location')) : null],
            'wishes' => array_filter(array_map(fn ($k) => $s(data_get($in, "wishes.$k")), array_combine(array_keys(self::WISHES), array_keys(self::WISHES)))),
            'preferred_caregiver' => array_filter([
                'region' => $s(data_get($in, 'preferred_caregiver.region')),
                'min_career_years' => data_get($in, 'preferred_caregiver.min_career_years') !== null ? (int) data_get($in, 'preferred_caregiver.min_career_years') : null,
                'age_range' => $s(data_get($in, 'preferred_caregiver.age_range')),
                'religion' => $s(data_get($in, 'preferred_caregiver.religion')),
                'other' => $s(data_get($in, 'preferred_caregiver.other')),
            ], fn ($v) => $v !== null),
        ];

        return $out;
    }

    public static function decode(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }

        return $raw ? json_decode((string) $raw, true) : null;
    }

    /** 돌봄 준비용 한 줄 — 「조리원 14일 후 · 배우자 재택 · 큰아이 1명(미취학) · 반려동물: 고양이 · CCTV: 거실」. 적은 게 없으면 null */
    public static function summary(?array $p): ?string
    {
        if (!$p) {
            return null;
        }
        $parts = [];
        if (data_get($p, 'postnatal_center.used')) {
            $d = (int) data_get($p, 'postnatal_center.days');
            $parts[] = '조리원' . ($d ? " {$d}일" : '') . ' 후';
        }
        if (data_get($p, 'spouse.present')) {
            $parts[] = data_get($p, 'spouse.at_home') ? '배우자 재택' : '배우자 있음';
        }
        $kids = (array) data_get($p, 'older_children', []);
        if ($kids) {
            $pre = count(array_filter($kids, fn ($k) => ($k['school'] ?? '') === 'preschool'));
            $parts[] = '큰아이 ' . count($kids) . '명' . ($pre ? "(미취학 {$pre})" : '');
        }
        if (data_get($p, 'other_family')) {
            $parts[] = '기타 가족 있음';
        }
        if (data_get($p, 'pets.has')) {
            $parts[] = '반려동물' . (data_get($p, 'pets.detail') ? ': ' . data_get($p, 'pets.detail') : '');
        }
        if (data_get($p, 'cctv.has')) {
            $parts[] = 'CCTV' . (data_get($p, 'cctv.location') ? ': ' . data_get($p, 'cctv.location') : ' 있음');
        }

        return $parts ? implode(' · ', $parts) : null;
    }

    /** 산모 id → summary (돌봄전문가 목록용, newbornSummaries 와 같은 모양) */
    public static function summaries(Collection $clientIds): array
    {
        $ids = $clientIds->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return DB::table('postpartum_clients')->whereIn('id', $ids)->whereNotNull('care_profile')
            ->pluck('care_profile', 'id')
            ->map(fn ($raw) => self::summary(self::decode($raw)))
            ->filter()->all();
    }
}
