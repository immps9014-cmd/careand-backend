<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Caregiver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\AuthHelpers;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class AdminTest extends TestCase
{
    use RefreshDatabase, AuthHelpers;

    public function test_kpi_returns_aggregated_counts(): void
    {
        [, $token] = $this->createAdminUser();
        $this->createGuardianUser();
        $this->createCaregiverUser();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/v1/admin/dashboard/kpi');

        $response->assertStatus(200)->assertJsonStructure([
            'success',
            'data' => ['matches_in_progress', 'matches_today', 'revenue_today', 'high_alerts_unresolved', 'pending_caregivers'],
        ]);
    }

    public function test_non_admin_cannot_access_admin_dashboard(): void
    {
        [, , $token] = $this->createGuardianUser();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/v1/admin/dashboard/kpi');

        $response->assertStatus(403);
    }

    public function test_pending_caregiver_count_in_kpi(): void
    {
        [, $token] = $this->createAdminUser();
        // 3명 pending 인력 생성
        for ($i = 0; $i < 3; $i++) {
            $u = User::factory()->caregiver()->create();
            Caregiver::factory()->pending()->create(['user_id' => $u->id]);
        }

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/v1/admin/dashboard/kpi');

        $response->assertJsonPath('data.pending_caregivers', 3);
    }

    public function test_ai_models_list_returns_summary(): void
    {
        [, $token] = $this->createAdminUser();
        AiModel::create(['model_name' => 'matching', 'version' => 'v1', 'status' => 'active', 'accuracy' => 0.9, 'avg_latency_ms' => 100]);
        AiModel::create(['model_name' => 'matching', 'version' => 'v2', 'status' => 'shadow', 'accuracy' => 0.92, 'avg_latency_ms' => 110]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/v1/admin/ai-models');

        $response->assertStatus(200)
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.active', 1)
            ->assertJsonPath('summary.shadow', 1);
    }

    public function test_recent_alerts_endpoint_returns_data(): void
    {
        [, $token] = $this->createAdminUser();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/v1/admin/dashboard/recent-alerts');

        $response->assertStatus(200)->assertJsonPath('success', true);
    }
}
