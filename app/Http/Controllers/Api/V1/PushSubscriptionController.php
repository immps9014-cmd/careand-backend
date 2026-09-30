<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 웹 푸시 구독 관리 (PWA 1단계, CAREN-PWA-01).
 *   GET    /v1/push/public-key   브라우저 구독에 쓸 VAPID 공개키(비로그인도 가능)
 *   POST   /v1/push/subscriptions  { endpoint, keys: { p256dh, auth } }  — 같은 endpoint 면 소유자·키 갱신
 *   DELETE /v1/push/subscriptions  { endpoint }
 *   POST   /v1/push/test          내 기기로 시험 알림
 */
class PushSubscriptionController extends Controller
{
    public function __construct(private WebPushService $push)
    {
    }

    public function publicKey(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'enabled' => $this->push->enabled(),
            'public_key' => $this->push->publicKey(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:1000', 'starts_with:https://'],
            'keys.p256dh' => ['required', 'string', 'max:120'],
            'keys.auth' => ['required', 'string', 'max:40'],
        ]);

        // 키 길이 검증 — 잘못된 키로는 암호화가 안 되니 저장 전에 거른다
        if (strlen(WebPushService::b64uDecode($data['keys']['p256dh'])) !== 65
            || strlen(WebPushService::b64uDecode($data['keys']['auth'])) !== 16) {
            return response()->json(['success' => false, 'error_code' => 'INVALID_KEYS', 'message' => '알림 구독 정보가 올바르지 않아요. 다시 시도해 주세요.'], 422);
        }

        $hash = hash('sha256', $data['endpoint']);
        // 같은 기기를 다른 계정으로 로그인해 쓰면 마지막 로그인 계정으로 옮긴다(이전 계정 알림이 가지 않게)
        DB::table('push_subscriptions')->updateOrInsert(
            ['endpoint_hash' => $hash],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'failures' => 0,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return response()->json(['success' => true, 'message' => '이 기기에서 알림을 받아요.']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);
        DB::table('push_subscriptions')
            ->where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->where('user_id', $request->user()->id)
            ->delete();
        return response()->json(['success' => true, 'message' => '이 기기의 알림을 껐어요.']);
    }

    public function test(Request $request): JsonResponse
    {
        if (!$this->push->enabled()) {
            return response()->json(['success' => false, 'error_code' => 'PUSH_DISABLED', 'message' => '서버 알림 설정이 아직 준비되지 않았어요.'], 503);
        }
        $sent = $this->push->sendToUser($request->user()->id, [
            'title' => '케어앤 알림 시험',
            'body' => '이 알림이 보이면 준비 완료예요. 매칭·돌봄 소식을 이렇게 알려 드릴게요.',
            'url' => '/app/notifications',
            'tag' => 'push-test',
        ]);
        return $sent > 0
            ? response()->json(['success' => true, 'sent' => $sent, 'message' => '시험 알림을 보냈어요.'])
            : response()->json(['success' => false, 'error_code' => 'PUSH_NOT_DELIVERED', 'message' => '알림을 보내지 못했어요. 알림을 껐다가 다시 켜 주세요.'], 422);
    }
}
