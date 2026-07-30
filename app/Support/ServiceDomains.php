<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * 서비스 도메인 레지스트리 접근 헬퍼 (SSOT).
 * config/service_domains.php 를 읽어 라벨/가시성/활성 카테고리를 제공한다.
 * 도메인 라벨/카테고리 라벨의 인라인 중복을 이 한 곳으로 일원화.
 *
 * @see config/service_domains.php
 * @see /root/CAREAND-DOMAIN-INTEGRATION.md
 */
class ServiceDomains
{
    /**
     * 돌봄전문가 전문분야(specialty 코드) → 표시 라벨.
     * 카테고리 코드(소문자)와 매핑. 카테고리 추가 시 여기 한 곳만 보강.
     */
    private const SPECIALTY_LABELS = [
        'nursing_hospital' => '병원 간병',
        'hk_cleaning'      => '가사 청소',
        'hk_repair'        => '가사 수리',
        'hk_organizing'    => '정리수납',
        'ls_companion'     => '동행',
    ];

    /** @return array<string,array> 전체 도메인 메타 (order 정렬) */
    public static function all(): array
    {
        $domains = config('service_domains', []);
        uasort($domains, fn ($a, $b) => ($a['order'] ?? 999) <=> ($b['order'] ?? 999));

        return $domains;
    }

    /** @return array<string,mixed>|null 단일 도메인 메타 */
    public static function meta(string $token): ?array
    {
        return config("service_domains.$token");
    }

    /** 도메인 표시 라벨 (없으면 '돌봄') */
    public static function label(string $token): string
    {
        return config("service_domains.$token.domain_label", '돌봄');
    }

    /** 전문분야 코드 → 라벨 (없으면 원본 코드 반환) */
    public static function specialtyLabel(string $code): string
    {
        return self::SPECIALTY_LABELS[strtolower($code)] ?? $code;
    }

    /** @return array<string,mixed> 도메인 자격 정책 (qualification 메타, 기본값 머지) */
    public static function qualification(string $token): array
    {
        return array_merge([
            'license_required' => false,
            'license_label'    => '자격번호(선택)',
            'verify'           => 'none',
            'accepted_types'   => [],
        ], config("service_domains.$token.qualification", []));
    }

    /** 해당 도메인이 자격번호 필수인지 */
    public static function requiresLicense(string $token): bool
    {
        return (bool) self::qualification($token)['license_required'];
    }

    /** 진위확인 경로: 'mohw' | 'manual' | 'none' */
    public static function verifyMode(string $token): string
    {
        return self::qualification($token)['verify'];
    }

    /** @return array<int,string> 허용 자격증 종류(라벨). 빈 배열이면 종류 제한 없음 */
    public static function acceptedTypes(string $token): array
    {
        return self::qualification($token)['accepted_types'] ?? [];
    }

    /** @return array<string,mixed> 도메인 관계 메타 (hasSubject 테이블/FK, requestedBy 역할). 문서화 목적 */
    public static function relations(string $token): array
    {
        return array_merge([
            'hasSubject'  => null,
            'requestedBy' => [],
        ], config("service_domains.$token.relations", []));
    }

    /** hasSubject 관계의 대상 테이블/FK (없으면 null) */
    public static function subject(string $token): ?array
    {
        return self::relations($token)['hasSubject'];
    }

    /**
     * 역할(role)에게 노출 가능한 활성 도메인 + 각 도메인의 활성 카테고리.
     * 활성 카테고리가 없는 도메인은 자동 제외(잠복).
     *
     * @return array<int,array{token:string,label:string,desc:string,icon:string,picker:?string,categories:\Illuminate\Support\Collection}>
     */
    public static function activeForRole(?string $role): array
    {
        $catsByDomain = DB::table('service_categories')
            ->select('id', 'code', 'name', 'domain', 'base_rate')
            ->where('is_active', 1)
            ->orderBy('id')
            ->get()
            ->groupBy('domain');

        $out = [];
        foreach (self::all() as $token => $m) {
            if (!($m['is_active'] ?? false)) {
                continue;
            }
            if ($role !== null && in_array($role, $m['hidden_for_roles'] ?? [], true)) {
                continue;
            }

            $domainCats = $catsByDomain->get($token, collect())->values();
            if ($domainCats->isEmpty()) {
                continue; // 활성 카테고리 없는 도메인은 노출하지 않음
            }

            $out[] = [
                'token'         => $token,
                'label'         => $m['label'],
                'desc'          => $m['desc'] ?? '',
                'icon'          => $m['icon'] ?? 'heart-pulse',
                'picker'        => $m['picker']['type'] ?? null,
                'categories'    => $domainCats,
                // 돌봄전문가(공급자) 등록 FE가 도메인별 자격 요건/허용 자격종류를 렌더하는 데 사용.
                'qualification' => self::qualification($token),
            ];
        }

        return $out;
    }
}
