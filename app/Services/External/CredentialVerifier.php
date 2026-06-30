<?php

namespace App\Services\External;

use Illuminate\Support\Facades\Log;

/**
 * 돌봄전문가 자격증 진위조회 디스패처.
 *
 * 자격증 종류(license_type)에 따라 적절한 발급 기관 API로 라우팅한다.
 * 도메인이 아닌 자격종류로 분기하므로, 다중 도메인 등록·요양보호사 외 자격의
 * 오탐(MohwService로 상담/간병 자격을 조회하던 문제)을 구조적으로 방지한다.
 *
 *  - 요양보호사·간호조무사 → 보건복지부(MohwService)
 *  - 간호사               → 한국보건의료인국가시험원(KuksiwonService)
 *  - 산후관리사·간병사     → 민간자격정보서비스(PrivateQualService)
 *  - 그 외(상담심리사 등)  → 자동조회 미지원 → null(관리자 수동 검증)
 *
 * @see config/service_domains.php  qualification.accepted_types
 * @see /root/CAREAND-DOMAIN-INTEGRATION.md
 */
class CredentialVerifier
{
    /** 자격증 종류 → 진위조회 기관 키 */
    private const TYPE_AUTHORITY = [
        '요양보호사'           => 'mohw',
        '간호조무사'           => 'mohw',
        '간호사'               => 'kuksiwon',
        '산후관리사'           => 'pqi',
        '간병사'               => 'pqi',
        // 상담 계열(상담심리사·임상심리사·정신건강임상심리사 등)은 자동 진위조회 API 미지원 → 수동
    ];

    /** 기관 키 → 표시 라벨 */
    private const AUTHORITY_LABEL = [
        'mohw'     => '보건복지부',
        'kuksiwon' => '한국보건의료인국가시험원',
        'pqi'      => '민간자격정보서비스',
    ];

    public function __construct(
        private readonly MohwService $mohw,
        private readonly KuksiwonService $kuksiwon,
        private readonly PrivateQualService $pqi,
    ) {
    }

    /** 자격종류 → 진위조회 기관 키 (없으면 null = 자동조회 미지원) */
    public function authorityFor(?string $licenseType): ?string
    {
        return $licenseType ? (self::TYPE_AUTHORITY[$licenseType] ?? null) : null;
    }

    /** 해당 자격종류가 자동 진위조회 가능한지 */
    public function canAutoVerify(?string $licenseType): bool
    {
        return $this->authorityFor($licenseType) !== null;
    }

    /**
     * 자격증 종류에 맞는 외부 기관으로 진위조회.
     *
     * @return array{valid:bool, status:string, authority:string, authority_label:string, license_type:?string, issued_at:?string}|null
     *         null = 자동조회 미지원 자격(관리자 수동 검증 대상)
     */
    public function verify(?string $licenseType, string $licenseNo, string $name, string $birthDate): ?array
    {
        $authority = $this->authorityFor($licenseType);
        if ($authority === null) {
            return null;
        }

        $service = match ($authority) {
            'mohw'     => $this->mohw,
            'kuksiwon' => $this->kuksiwon,
            'pqi'      => $this->pqi,
        };

        $result = $service->verifyLicense($licenseNo, $name, $birthDate);
        $result['authority'] = $authority;
        $result['authority_label'] = self::AUTHORITY_LABEL[$authority];

        Log::info('자격 진위조회', [
            'license_type' => $licenseType,
            'authority' => $authority,
            'valid' => $result['valid'] ?? null,
        ]);

        return $result;
    }
}
