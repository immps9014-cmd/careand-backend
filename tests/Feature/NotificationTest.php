<?php

namespace Tests\Feature;

use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\AuthHelpers;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase, AuthHelpers;

    public function test_index_returns_users_notifications(): void
    {
        [$user, , $token] = $this->createGuardianUser();
        Notification::create(['user_id' => $user->id, 'type' => 'MATCH_CONFIRMED', 'title' => '매칭 확정', 'body' => '인력 수락', 'is_read' => false, 'sent_at' => now()]);
        Notification::create(['user_id' => $user->id, 'type' => 'CARE_COMPLETED', 'title' => '케어 완료', 'body' => '오늘', 'is_read' => true, 'read_at' => now(), 'sent_at' => now()]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/v1/notifications');

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.unread_count', 1);
    }

    public function test_user_cannot_see_others_notifications(): void
    {
        [$user1, , $token1] = $this->createGuardianUser();
        [$user2] = $this->createGuardianUser();
        Notification::create(['user_id' => $user2->id, 'type' => 'X', 'title' => 'foreign', 'body' => '...', 'sent_at' => now()]);

        $response = $this->withHeaders($this->authHeaders($token1))
            ->getJson('/api/v1/notifications');

        $response->assertJsonPath('meta.total', 0);
    }

    public function test_mark_as_read(): void
    {
        [$user, , $token] = $this->createGuardianUser();
        $n = Notification::create(['user_id' => $user->id, 'type' => 'X', 'title' => 't', 'body' => 'b', 'is_read' => false, 'sent_at' => now()]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/v1/notifications/{$n->id}/read");

        $response->assertStatus(200);
        $this->assertTrue($n->fresh()->is_read);
        $this->assertNotNull($n->fresh()->read_at);
    }

    public function test_mark_all_as_read(): void
    {
        [$user, , $token] = $this->createGuardianUser();
        for ($i = 0; $i < 3; $i++) {
            Notification::create(['user_id' => $user->id, 'type' => 'X', 'title' => 't', 'body' => 'b', 'is_read' => false, 'sent_at' => now()]);
        }

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/notifications/read-all');

        $response->assertStatus(200);
        $this->assertEquals(0, Notification::where('user_id', $user->id)->where('is_read', false)->count());
    }

    public function test_update_fcm_token(): void
    {
        [$user, , $token] = $this->createGuardianUser();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/notifications/fcm-token', ['fcm_token' => 'fake-fcm-token-' . str_repeat('x', 100)]);

        $response->assertStatus(200);
        $this->assertNotNull($user->fresh()->fcm_token);
    }
}
