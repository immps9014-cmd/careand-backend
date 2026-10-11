<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase, \Tests\AuthHelpers;

    public function test_otp_send_returns_success(): void
    {
        $response = $this->postJson('/api/v1/auth/otp/send', [
            'phone' => '01012345678',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_otp_send_rejects_invalid_phone(): void
    {
        $response = $this->postJson('/api/v1/auth/otp/send', [
            'phone' => '12345',
        ]);

        $response->assertStatus(422);
    }

    public function test_otp_verify_with_correct_code(): void
    {
        $phone = '01012345678';
        Cache::put("otp:code:{$phone}", '123456', 180);

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => $phone,
            'code' => '123456',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'phone_verify_token']);
    }

    public function test_otp_verify_with_wrong_code(): void
    {
        $phone = '01012345678';
        Cache::put("otp:code:{$phone}", '123456', 180);

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => $phone,
            'code' => '000000',
        ]);

        $response->assertStatus(422)
            ->assertJson(['error_code' => 'OTP_INVALID']);
    }

    public function test_signup_creates_guardian_user(): void
    {
        $phone = '01099998888';

        // OTP 인증을 통과한 상태로 토큰 발급
        $otpService = app(OtpService::class);
        $verifyToken = $otpService->issueVerifyToken($phone);

        $response = $this->postJson('/api/v1/auth/signup', [
            'email' => 'test@example.com',
            'phone' => $phone,
            'phone_verify_token' => $verifyToken,
            'name' => '테스트 보호자',
            'password' => 'Test1234!',
            'password_confirmation' => 'Test1234!',
            'role' => 'guardian',
            'relation' => '자녀',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'user' => ['id', 'email', 'name', 'role'],
                'access_token',
                'refresh_token',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'role' => 'guardian',
        ]);

        $this->assertDatabaseHas('guardians', [
            'user_id' => User::where('email', 'test@example.com')->first()->id,
        ]);
    }

    public function test_signup_keeps_postpartum_mode_only_with_postpartum(): void
    {
        $otp = app(OtpService::class);
        $base = ['password' => 'Test1234!', 'password_confirmation' => 'Test1234!', 'role' => 'guardian', 'agree_terms' => true, 'agree_privacy' => true];

        $this->postJson('/api/v1/auth/signup', $base + [
            'email' => 'pp_voucher', 'phone' => '01099997001', 'phone_verify_token' => $otp->issueVerifyToken('01099997001'), 'name' => '바우처 산모',
            'services' => ['postpartum'], 'intent' => 'postpartum', 'postpartum_mode' => 'voucher',
        ])->assertStatus(201);
        $prefs = User::where('email', 'pp_voucher')->first()->guardian->preferences;
        $this->assertSame(['services' => ['postpartum'], 'postpartum_mode' => 'voucher'], $prefs);

        // 산모신생아를 안 골랐으면 방식은 버린다
        $this->postJson('/api/v1/auth/signup', $base + [
            'email' => 'pp_none', 'phone' => '01099997002', 'phone_verify_token' => $otp->issueVerifyToken('01099997002'), 'name' => '아이 보호자',
            'services' => ['childcare'], 'intent' => 'childcare', 'postpartum_mode' => 'hourly',
        ])->assertStatus(201);
        $this->assertSame(['services' => ['childcare']], User::where('email', 'pp_none')->first()->guardian->preferences);

        // 생년월일(선택)도 가입 때 저장
        $this->postJson('/api/v1/auth/signup', $base + [
            'email' => 'pp_birth', 'phone' => '01099997004', 'phone_verify_token' => $otp->issueVerifyToken('01099997004'), 'name' => '생일 산모',
            'services' => ['postpartum'], 'intent' => 'postpartum', 'birth_date' => '1994-07-08',
        ])->assertStatus(201)->assertJsonPath('user.birth_date', '1994-07-08');
        $this->postJson('/api/v1/auth/signup', $base + [
            'email' => 'pp_birth2', 'phone' => '01099997005', 'phone_verify_token' => $otp->issueVerifyToken('01099997005'), 'name' => '미래',
            'services' => ['senior'], 'relation' => '자녀', 'birth_date' => now()->addDay()->format('Y-m-d'),
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/signup', $base + [
            'email' => 'pp_bad', 'phone' => '01099997003', 'phone_verify_token' => $otp->issueVerifyToken('01099997003'), 'name' => '잘못된 값',
            'services' => ['postpartum'], 'intent' => 'postpartum', 'postpartum_mode' => 'monthly',
        ])->assertStatus(422);
    }

    /** 회원 생년월일(선택, 2026-10-11) — 내 정보에서 저장·지우기, 응답 user.birth_date */
    public function test_update_me_birth_date(): void
    {
        [$u, , $tok] = $this->createGuardianUser();
        $h = ['Authorization' => "Bearer {$tok}"];
        $this->withHeaders($h)->patchJson('/api/v1/auth/me', ['birth_date' => '1993-04-05'])->assertOk()->assertJsonPath('user.birth_date', '1993-04-05');
        $this->assertSame('1993-04-05', $u->fresh()->birth_date->format('Y-m-d'));
        $this->withHeaders($h)->getJson('/api/v1/auth/me')->assertJsonPath('user.birth_date', '1993-04-05');
        $this->withHeaders($h)->patchJson('/api/v1/auth/me', ['birth_date' => now()->addDay()->format('Y-m-d')])->assertStatus(422);
        $this->withHeaders($h)->patchJson('/api/v1/auth/me', ['birth_date' => null])->assertOk()->assertJsonPath('user.birth_date', null);
    }

    public function test_login_with_correct_credentials(): void
    {
        User::factory()->create([
            'email' => 'login@test.com',
            'password' => 'Pass1234!',
            'phone_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'login@test.com',
            'password' => 'Pass1234!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'access_token', 'refresh_token']);
    }

    public function test_login_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'login@test.com',
            'password' => 'Pass1234!',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'login@test.com',
            'password' => 'WrongPass!',
        ]);

        $response->assertStatus(401)
            ->assertJson(['error_code' => 'INVALID_CREDENTIALS']);
    }

    public function test_authenticated_user_can_access_me(): void
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJsonPath('user.id', $user->id);
    }
    /** 정지된 계정은 이미 받은 토큰·갱신으로도 못 쓴다(2026-10-07) */
    public function test_suspended_user_token_and_refresh_are_rejected(): void
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/auth/me')->assertOk();

        $user->update(['status' => 'suspended']);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/auth/me')
            ->assertStatus(403)->assertJsonPath('error_code', 'USER_SUSPENDED');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/auth/refresh')
            ->assertStatus(403)->assertJsonPath('error_code', 'USER_SUSPENDED');

        // 다시 활성화하면 새 로그인 토큰으로 정상
        $user->update(['status' => 'active']);
        $this->app['auth']->forgetGuards();
        $fresh = auth('api')->login($user->fresh());
        $this->withHeader('Authorization', "Bearer {$fresh}")->getJson('/api/v1/auth/me')->assertOk();
    }

    /** 갱신마다 새 리프레시 토큰(회전) — 연달아 갱신해도 로그인 유지, 쓴 토큰은 재사용 불가(2026-10-10) */
    public function test_refresh_rotates_refresh_token(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $rt = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()->json('refresh_token');

        $this->fresh();
        $rt2 = $this->withHeader('Authorization', "Bearer {$rt}")->postJson('/api/v1/auth/refresh')
            ->assertOk()->assertJsonStructure(['access_token', 'refresh_token'])->json('refresh_token');
        $this->assertNotSame($rt, $rt2);

        $this->fresh();
        $this->withHeader('Authorization', "Bearer {$rt2}")->postJson('/api/v1/auth/refresh')->assertOk();

        $this->fresh();
        $this->withHeader('Authorization', "Bearer {$rt}")->postJson('/api/v1/auth/refresh')->assertStatus(401);
    }

    /** 실제 HTTP 처럼 요청마다 새 상태 — JWT 싱글턴이 앞 요청 토큰을 들고 있지 않게 */
    private function fresh(): void
    {
        $this->app['auth']->forgetGuards();
        foreach (['tymon.jwt', 'tymon.jwt.auth', 'tymon.jwt.parser'] as $k) {
            $this->app->forgetInstance($k);
        }
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        $this->flushHeaders();
    }
}
