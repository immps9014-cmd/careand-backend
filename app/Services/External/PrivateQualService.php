<?php

namespace App\Services\External;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 민간자격정보서비스(한국직업능력연구원, www.pqi.or.kr) 자격 진위확인 연동.
 * 산후관리사·간병사 등 등록 민간자격 진위 확인.
 *
 * 실제 API는 운영 시 한국직업능력연구원과 협약 후 발급받음.
 * 본 클래스는 인터페이스 + 모의 응답을 포함한 골격(MohwService 패턴 계승).
 */
class PrivateQualService
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {
    }

    /**
     * 민간자격 진위 확인.
     *
     * @param string $licenseNo 자격증 번호(자격발급번호)
     * @param string $name 본인 성명
     * @param string $birthDate YYYY-MM-DD
     * @return array{valid: bool, license_type: ?string, issued_at: ?string, status: string}
     */
    public function verifyLicense(string $licenseNo, string $name, string $birthDate): array
    {
        if (config('services.external.stub')) {
            return $this->mockResponse($licenseNo);
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Authorization' => "Bearer {$this->apiKey}"])
                ->post("{$this->baseUrl}/qualification/verify", [
                    'cert_no' => $licenseNo,
                    'name' => $name,
                    'birth_date' => $birthDate,
                ]);

            if (!$response->successful()) {
                throw new RuntimeException(
                    "민간자격정보서비스 API 오류 ({$response->status()}): {$response->body()}"
                );
            }

            $data = $response->json();

            return [
                'valid' => (bool) ($data['valid'] ?? false),
                'license_type' => $data['qual_name'] ?? null,
                'issued_at' => $data['issued_at'] ?? null,
                'status' => $data['status'] ?? 'unknown',
            ];
        } catch (\Throwable $e) {
            Log::error('민간자격 진위확인 실패', [
                'license_no' => $licenseNo,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('자격 진위 확인 중 오류가 발생했습니다.');
        }
    }

    private function mockResponse(string $licenseNo): array
    {
        // 자격번호 끝자리 9 = 진위확인 실패 (테스트용)
        if ((int) substr($licenseNo, -1) === 9) {
            return ['valid' => false, 'license_type' => null, 'issued_at' => null, 'status' => 'invalid'];
        }

        return [
            'valid' => true,
            'license_type' => '민간등록자격',
            'issued_at' => '2021-06-10',
            'status' => 'active',
        ];
    }
}
