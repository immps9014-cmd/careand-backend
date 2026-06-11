<?php

namespace App\Services\External;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 보건복지부 자격관리시스템 연동
 * 요양보호사 자격증 진위 확인
 *
 * 실제 API는 운영 시 보건복지부와 협약 후 발급받음.
 * 본 클래스는 인터페이스 + 모의 응답을 포함한 골격.
 */
class MohwService
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {
    }

    /**
     * 자격증 진위 확인
     *
     * @param string $licenseNo 요양보호사 자격번호
     * @param string $name 본인 성명
     * @param string $birthDate YYYY-MM-DD
     * @return array{valid: bool, license_type: ?string, issued_at: ?string, status: string}
     */
    public function verifyLicense(string $licenseNo, string $name, string $birthDate): array
    {
        // 로컬/테스트 환경에서는 모의 응답
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
                    "MOHW API 오류 ({$response->status()}): {$response->body()}"
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
            Log::error('MOHW 자격 진위확인 실패', [
                'license_no' => $licenseNo,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('자격 진위 확인 중 오류가 발생했습니다.');
        }
    }

    private function mockResponse(string $licenseNo): array
    {
        // 자격번호 끝자리에 따라 다른 응답 반환 (테스트용)
        $lastDigit = (int) substr($licenseNo, -1);

        if ($lastDigit === 9) {
            return [
                'valid' => false,
                'license_type' => null,
                'issued_at' => null,
                'status' => 'invalid',
            ];
        }

        return [
            'valid' => true,
            'license_type' => '요양보호사 1급',
            'issued_at' => '2020-03-15',
            'status' => 'active',
        ];
    }
}
