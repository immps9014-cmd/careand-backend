<?php

namespace Tests;

use App\Models\Admin;
use App\Models\Caregiver;
use App\Models\Guardian;
use App\Models\Senior;
use App\Models\ServiceCategory;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

trait AuthHelpers
{
    protected function createGuardianUser(): array
    {
        $user = User::factory()->guardian()->create();
        $guardian = Guardian::factory()->create(['user_id' => $user->id]);
        $token = $this->issueToken($user);
        return [$user, $guardian, $token];
    }

    protected function createCaregiverUser(string $status = 'active'): array
    {
        $user = User::factory()->caregiver()->create();
        $caregiver = Caregiver::factory()->create(['user_id' => $user->id, 'status' => $status]);
        $token = $this->issueToken($user);
        return [$user, $caregiver, $token];
    }

    protected function createAdminUser(string $level = 'super'): array
    {
        $user = User::factory()->admin()->create();
        Admin::create(['user_id' => $user->id, 'permission_level' => $level, 'department' => '시스템']);
        // S2 부터 관리자 API 는 2단계 인증을 마친 토큰(mfa)만 받는다
        $token = $this->issueToken($user, ['mfa' => true]);
        return [$user, $token];
    }

    protected function createSeniorFor(Guardian $guardian): Senior
    {
        return Senior::factory()->create(['guardian_id' => $guardian->id]);
    }

    protected function ensureCategories(): void
    {
        if (ServiceCategory::count() === 0) {
            ServiceCategory::create(['code' => 'VISIT_CARE', 'name' => '방문요양', 'base_rate' => 18000, 'is_active' => true]);
        }
    }

    /** JWT 발급 — 페이로드 팩토리가 앞 발급의 클레임(mfa 등)을 들고 있으므로 매번 비운다(S6) */
    protected function issueToken(User $user, array $claims = []): string
    {
        JWTAuth::factory()->emptyClaims();
        return JWTAuth::customClaims($claims)->fromUser($user);
    }

    protected function authHeaders(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }
}
