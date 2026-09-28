<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\AlimtalkTemplates;
use App\Services\External\AligoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 앱 알림과 같은 내용을 카카오 알림톡으로 보낸다(기능 6·32). 실패하면 SMS/LMS 로 대체.
 * NotificationService::notify() 가 DB 커밋 뒤에 큐에 넣는다 — 발송이 느리거나 실패해도 본 처리에 영향 없음.
 * 재시도는 하지 않는다(중복 발송이 누락보다 나쁨). 결과는 message_logs 에 남는다.
 */
class SendAlimtalkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(public int $userId, public string $type, public array $payload, public ?int $notificationId = null)
    {
    }

    public function handle(AligoService $aligo): void
    {
        $user = User::find($this->userId);
        if (!$user || !$user->phone) {
            return;
        }
        $tpl = AlimtalkTemplates::build($this->type, $user, $this->payload);
        if (!$tpl) {
            return;
        }
        $log = ['user_id' => $user->id, 'notification_id' => $this->notificationId, 'template' => $tpl['key']];

        $res = $aligo->sendAlimtalk($user->phone, AlimtalkTemplates::code($tpl['key']), $tpl['subject'],
            $tpl['message'], $tpl['button'], $tpl['fallback'], $log);

        // 알림톡 요청 자체가 거절되면(템플릿 불일치·키 오류 등) 알리고 자동 대체도 안 되므로 직접 SMS/LMS
        if ($res['status'] === 'failed') {
            $sms = $aligo->sendText($user->phone, $tpl['fallback'], '[케어앤] ' . $tpl['subject'], $log);
            if ($sms['status'] === 'failed') {
                Log::warning("알림톡·대체 문자 모두 실패 user={$user->id} type={$this->type}");
            }
        }
    }
}
