<?php

namespace Tests\Feature;

use App\Models\ChatbotMessage;
use App\Models\ChatbotSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\AuthHelpers;
use Tests\TestCase;

class ChatbotTest extends TestCase
{
    use RefreshDatabase, AuthHelpers;

    public function test_guardian_can_start_session_with_welcome_message(): void
    {
        [, , $token] = $this->createGuardianUser();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/chatbot/sessions', ['topic' => '장기요양 문의']);

        $response->assertStatus(201)->assertJsonStructure(['session_id', 'welcome_message']);
        $this->assertDatabaseCount('chatbot_sessions', 1);
        $this->assertDatabaseCount('chatbot_messages', 1);
    }

    public function test_caregiver_cannot_use_chatbot(): void
    {
        [, , $token] = $this->createCaregiverUser();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/chatbot/sessions');

        $response->assertStatus(403)->assertJsonPath('error_code', 'NOT_GUARDIAN');
    }

    public function test_ask_creates_user_and_assistant_messages(): void
    {
        [, $guardian, $token] = $this->createGuardianUser();
        $session = ChatbotSession::create(['guardian_id' => $guardian->id, 'started_at' => now()]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/v1/chatbot/sessions/{$session->id}/ask", ['question' => '본인부담률은?']);

        $response->assertStatus(200)->assertJsonPath('success', true);
        $this->assertEquals(2, ChatbotMessage::where('session_id', $session->id)->count());
        $this->assertEquals('assistant', ChatbotMessage::where('session_id', $session->id)->latest('id')->first()->role);
    }

    public function test_cannot_access_other_guardian_session(): void
    {
        [, , $token] = $this->createGuardianUser();
        [, $otherGuardian] = $this->createGuardianUser();
        $foreignSession = ChatbotSession::create(['guardian_id' => $otherGuardian->id, 'started_at' => now()]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/v1/chatbot/sessions/{$foreignSession->id}/messages");

        $response->assertStatus(403);
    }

    public function test_end_session_sets_ended_at(): void
    {
        [, $guardian, $token] = $this->createGuardianUser();
        $session = ChatbotSession::create(['guardian_id' => $guardian->id, 'started_at' => now()]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/v1/chatbot/sessions/{$session->id}/end");

        $response->assertStatus(200);
        $this->assertNotNull($session->fresh()->ended_at);
    }
}
