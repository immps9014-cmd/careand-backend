<?php

namespace App\Services\External;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 한국보건의료인국가시험원(국시원) 면허 진위확인 연동.
 * 간호사 면허 진위 확인.
 *
 * 실제 API는 운영 시 국시원과 협약 후 발급받음.
 * 본 클래스는 인터페이스 + 모의 응답을 포함한 골격(MohwService 패턴 계승).
 */
class KuksiwonService
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {
    }

    /**
     * 면허 진위 확인.
     *
     * @param string $licenseNo 면허번호
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
                ->post("{$this->baseUrl}/license/verify", [
                    'license_no' => $licenseNo,
                    'name' => $name,
                    'birth_date' => $birthDate,
                ]);

            if (!$response->successful()) {
                throw new RuntimeException(
                    "국시원 API 오류 ({$response->status()}): {$response->body()}"
                );
            }

            $data = $response->json();

            return [
                'valid' => (bool) ($data['valid'] ?? false),
                'license_type' => $data['license_type'] ?? null,
                'issued_at' => $data['issued_at'] ?? null,
                'status' => $data['status'] ?? 'unknown',
            ];
        } catch (\Throwable $e) {
            Log::error('국시원 면허 진위확인 실패', [
                'license_no' => $licenseNo,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('면허 진위 확인 중 오류가 발생했습니다.');
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
            'license_type' => '간호사',
            'issued_at' => '2018-02-20',
            'status' => 'active',
        ];
    }
}
