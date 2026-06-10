<?php

namespace App\Services\External;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging (FCM) 푸시 알림
 *
 * 운영 시 v1 API로 마이그레이션 권장 (Legacy HTTP API 대비 보안 강화).
 * 본 클래스는 Legacy API 기준 (간단/안정, 기존 MES 코드와 호환).
 */
class FcmService
{
    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send';

    public function __construct(
        private readonly string $serverKey,
    ) {
    }

    /**
     * 단일 디바이스 푸시
     *
     * @return array{success: bool, message_id: ?string, error: ?string}
     */
    public function send(string $fcmToken, string $title, string $body, array $data = []): array
    {
        if (app()->environment('local', 'testing')) {
            Log::info("[FCM-DEV] {$title} → {$fcmToken}", [
                'body' => $body,
                'data' => $data,
            ]);
            return ['success' => true, 'message_id' => 'DEV_' . uniqid(), 'error' => null];
        }

        if (empty($this->serverKey)) {
            return ['success' => false, 'message_id' => null, 'error' => 'FCM server key not configured'];
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => "key={$this->serverKey}",
                    'Content-Type' => 'application/json',
                ])
                ->post(self::ENDPOINT, [
                    'to' => $fcmToken,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                        'sound' => 'default',
                    ],
                    'data' => array_merge($data, [
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    ]),
                    'priority' => 'high',
                ]);

            $result = $response->json();
            $success = ($result['success'] ?? 0) > 0;

            return [
                'success' => $success,
                'message_id' => $result['results'][0]['message_id'] ?? null,
                'error' => $success ? null : ($result['results'][0]['error'] ?? 'Unknown error'),
            ];
        } catch (\Throwable $e) {
            Log::error('FCM 발송 실패', [
                'token' => substr($fcmToken, 0, 20) . '...',
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * 다중 디바이스 푸시 (최대 1000개)
     */
    public function sendMulticast(array $fcmTokens, string $title, string $body, array $data = []): array
    {
        if (empty($fcmTokens)) {
            return ['success' => true, 'sent' => 0, 'failed' => 0];
        }

        if (app()->environment('local', 'testing')) {
            Log::info("[FCM-DEV-MULTICAST] {$title} → " . count($fcmTokens) . "명");
            return ['success' => true, 'sent' => count($fcmTokens), 'failed' => 0];
        }

        // FCM 제한: 1000개 / 회 → 청크 분할
        $chunks = array_chunk($fcmTokens, 1000);
        $totalSent = 0;
        $totalFailed = 0;

        foreach ($chunks as $chunk) {
            try {
                $response = Http::timeout(15)
                    ->withHeaders([
                        'Authorization' => "key={$this->serverKey}",
                        'Content-Type' => 'application/json',
                    ])
                    ->post(self::ENDPOINT, [
                        'registration_ids' => $chunk,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                            'sound' => 'default',
                        ],
                        'data' => $data,
                        'priority' => 'high',
                    ]);

                $result = $response->json();
                $totalSent += $result['success'] ?? 0;
                $totalFailed += $result['failure'] ?? 0;
            } catch (\Throwable $e) {
                $totalFailed += count($chunk);
                Log::error('FCM 멀티캐스트 실패', ['error' => $e->getMessage()]);
            }
        }

        return [
            'success' => $totalSent > 0,
            'sent' => $totalSent,
            'failed' => $totalFailed,
        ];
    }
}
