<?php

namespace App\Console\Commands;

use App\Services\External\FcmV1Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * FCM v1 켜기 전 점검(CAREN-APP-01 D4, 2026-10-07). FCM_V1_ENABLED 와 무관하게 설정값으로 발송기를 만들어 본다.
 *  1) 서비스 계정 파일 → 액세스 토큰 교환까지
 *  2) --user / --token 을 주면 그 기기로 validate_only 발송(기기엔 안 뜸), --send 면 실제 발송
 */
class FcmV1Check extends Command
{
    protected $signature = 'fcm:v1-check
        {--user= : 이 사용자의 users.fcm_token 으로 시험}
        {--token= : 기기 토큰을 직접 지정}
        {--send : validate_only 가 아니라 실제로 기기에 보냄}';
    protected $description = 'FCM HTTP v1 발송기 설정·토큰 교환·시험 발송 점검';

    public function handle(): int
    {
        $cfg = config('services.fcm.v1');
        $this->line('FCM_V1_ENABLED = ' . ($cfg['enabled'] ? 'true (지금 알림이 v1 로 나감)' : 'false (지금 알림은 구형 FcmService)'));
        $this->line('교환 주소      = ' . $cfg['token_url']);

        $send = (bool) $this->option('send');
        $svc = new FcmV1Service(
            credentialsPath: (string) ($cfg['credentials'] ?? ''),
            projectId: $cfg['project_id'] ?: null,
            tokenUrl: (string) $cfg['token_url'],
            dryRun: !$send,
            androidChannel: (string) $cfg['android_channel'],
        );

        try {
            Cache::forget('fcm-v1:access-token');
            $svc->accessToken();
            $this->info('① 액세스 토큰 교환 성공');
        } catch (\Throwable $e) {
            $this->error('① 실패: ' . $e->getMessage());
            return self::FAILURE;
        }

        $token = $this->option('token');
        if (!$token && $this->option('user')) {
            $token = DB::table('users')->where('id', (int) $this->option('user'))->value('fcm_token');
            if (!$token) {
                $this->error('② 이 사용자는 fcm_token 이 없습니다(앱 로그인 후 등록돼야 함).');
                return self::FAILURE;
            }
        }
        if (!$token) {
            $this->line('② 기기 시험은 건너뜀(--user 또는 --token 지정 시 실행)');
            return self::SUCCESS;
        }

        $r = $svc->send($token, '케어앤 알림 시험', $send ? '푸시 알림이 정상적으로 도착했어요.' : '(검증 전용 — 표시되지 않음)',
            ['type' => 'PUSH_TEST']);
        if (!$r['success']) {
            $this->error('② 발송 실패: ' . $r['error'] . ($r['error'] === 'UNREGISTERED' ? ' — 만료 토큰이라 users.fcm_token 을 비웠습니다.' : ''));
            return self::FAILURE;
        }
        $this->info('② ' . ($send ? '실제 발송 성공' : 'validate_only 통과') . ': ' . $r['message_id']);
        return self::SUCCESS;
    }
}
