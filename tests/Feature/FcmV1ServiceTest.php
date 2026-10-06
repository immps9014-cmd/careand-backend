<?php

namespace Tests\Feature;

use App\Services\External\FcmService;
use App\Services\External\FcmV1Service;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\AuthHelpers;
use Tests\TestCase;

/** FCM HTTP v1 발송기(CAREN-APP-01 D4) — 구글 서버는 Http::fake, 서비스 계정 키는 시험마다 새로 만든다. */
class FcmV1ServiceTest extends TestCase
{
    use RefreshDatabase, AuthHelpers;

    private string $credPath;
    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $this->publicKey = openssl_pkey_get_details($key)['key'];
        $this->credPath = tempnam(sys_get_temp_dir(), 'fcmcred');
        file_put_contents($this->credPath, json_encode([
            'type' => 'service_account',
            'project_id' => 'caren-test',
            'client_email' => 'push@caren-test.iam.gserviceaccount.com',
            'private_key' => $pem,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));
        Cache::flush();
    }

    protected function tearDown(): void
    {
        @unlink($this->credPath);
        parent::tearDown();
    }

    private function service(bool $dryRun = false): FcmV1Service
    {
        return new FcmV1Service(credentialsPath: $this->credPath, dryRun: $dryRun);
    }

    private function fakeGoogle(array $sendResponses = []): void
    {
        $send = Http::sequence();
        foreach ($sendResponses ?: [[['name' => 'projects/caren-test/messages/1'], 200]] as [$body, $status]) {
            $send->push($body, $status);
        }
        Http::fake([
            'www.googleapis.com/oauth2/v4/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
            'fcm.googleapis.com/*' => $send,
        ]);
    }

    public function test_sends_v1_message_with_signed_jwt_and_string_data(): void
    {
        $this->fakeGoogle();

        $r = $this->service()->send('device-token-1', '매칭 확정', '본문', ['request_id' => 52, 'urgent' => true, 'skip' => null, 'list' => [1, 2]]);

        $this->assertTrue($r['success']);
        $this->assertSame('projects/caren-test/messages/1', $r['message_id']);

        Http::assertSent(function (Request $req) {
            if (!str_contains($req->url(), 'oauth2/v4/token')) {
                return false;
            }
            [$h, $c, $s] = explode('.', $req['assertion']);
            $claims = json_decode(base64_decode(strtr($c, '-_', '+/')), true);
            $sig = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
            return $claims['aud'] === 'https://oauth2.googleapis.com/token'
                && $claims['scope'] === 'https://www.googleapis.com/auth/firebase.messaging'
                && openssl_verify("{$h}.{$c}", $sig, $this->publicKey, OPENSSL_ALGO_SHA256) === 1;
        });
        Http::assertSent(function (Request $req) {
            return $req->url() === 'https://fcm.googleapis.com/v1/projects/caren-test/messages:send'
                && $req->hasHeader('Authorization', 'Bearer ya29.test')
                && $req['message']['token'] === 'device-token-1'
                && $req['message']['data'] === ['request_id' => '52', 'urgent' => 'true', 'list' => '[1,2]']
                && $req['message']['android']['notification']['channel_id'] === 'caren_default'
                && !isset($req['validate_only']);
        });
    }

    public function test_dry_run_uses_validate_only(): void
    {
        $this->fakeGoogle();
        $this->service(dryRun: true)->send('t', '제목', '본문');
        Http::assertSent(fn (Request $req) => str_contains($req->url(), 'messages:send') && $req['validate_only'] === true);
    }

    public function test_access_token_is_cached_between_sends(): void
    {
        $this->fakeGoogle([[['name' => 'a'], 200], [['name' => 'b'], 200]]);
        $svc = $this->service();
        $svc->send('t1', '제목', '본문');
        $svc->send('t2', '제목', '본문');
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'oauth2')));
    }

    public function test_unregistered_token_is_cleared(): void
    {
        [$user] = $this->createGuardianUser();
        $user->update(['fcm_token' => 'stale-token']);
        $this->fakeGoogle([[['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [
            ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED'],
        ]]], 404]]);

        $r = $this->service()->send('stale-token', '제목', '본문');

        $this->assertFalse($r['success']);
        $this->assertSame('UNREGISTERED', $r['error']);
        $this->assertNull($user->fresh()->fcm_token);
    }

    public function test_missing_credentials_fails_without_throwing(): void
    {
        Http::fake();
        $r = (new FcmV1Service(credentialsPath: '/nonexistent.json'))->send('t', '제목', '본문');
        $this->assertFalse($r['success']);
        Http::assertNothingSent();
    }

    public function test_container_switches_on_flag_and_notification_service_uses_it(): void
    {
        $this->assertNotInstanceOf(FcmV1Service::class, app(FcmService::class));

        config(['services.fcm.v1.enabled' => true, 'services.fcm.v1.credentials' => $this->credPath]);
        $this->app->forgetInstance(FcmService::class);
        $this->app->forgetInstance(NotificationService::class);
        $this->assertInstanceOf(FcmV1Service::class, app(FcmService::class));

        $this->fakeGoogle();
        [$user] = $this->createGuardianUser();
        $user->update(['fcm_token' => 'app-device']);
        $n = app(NotificationService::class)->notify($user->id, NotificationService::TYPE_CARE_REMINDER, ['session_id' => 7]);

        $this->assertNotNull($n);
        Http::assertSent(fn (Request $req) => str_contains($req->url(), 'messages:send')
            && $req['message']['token'] === 'app-device'
            && $req['message']['data']['notification_id'] === (string) $n->id
            && $req['message']['data']['session_id'] === '7');
    }
}
