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
        $token = JWTAuth::fromUser($user);
        return [$user, $guardian, $token];
    }

    protected function createCaregiverUser(string $status = 'active'): array
    {
        $user = User::factory()->caregiver()->create();
        $caregiver = Caregiver::factory()->create(['user_id' => $user->id, 'status' => $status]);
        $token = JWTAuth::fromUser($user);
        return [$user, $caregiver, $token];
    }

    protected function createAdminUser(): array
    {
        $user = User::factory()->admin()->create();
        Admin::create(['user_id' => $user->id, 'permission_level' => 'super', 'department' => '시스템']);
        $token = JWTAuth::fromUser($user);
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

    protected function authHeaders(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }
}
