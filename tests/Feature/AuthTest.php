<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

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
}
