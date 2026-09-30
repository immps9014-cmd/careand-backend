<?php

namespace App\Jobs;

use App\Services\WebPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 알림 한 건을 그 사용자의 모든 웹 푸시 구독으로 보낸다(PWA 1단계).
 * 요청 처리 중 외부 푸시 서버를 기다리지 않도록 큐로 뺀다. 실패해도 앱 안 알림(DB)은 이미 남아 있다.
 */
class SendWebPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(public int $userId, public array $message)
    {
    }

    public function handle(WebPushService $push): void
    {
        $push->sendToUser($this->userId, $this->message);
    }
}
