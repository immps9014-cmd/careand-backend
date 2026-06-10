<?php

namespace App\Domains\Postpartum\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Postpartum\Models\PostpartumChatbotSession;
use App\Domains\Postpartum\Models\PostpartumChatbotMessage;
use App\Domains\Postpartum\Models\PostpartumClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 산후 RAG 챗봇 컨트롤러
 *
 * 모유수유, 신생아 케어, 산모 회복 가이드를 RAG (ChromaDB + KoSimCSE) 검색 후
 * LLM으로 응답 생성. 시니어 챗봇과 인프라 공유, 코퍼스만 분리.
 */
class PostpartumChatbotController extends Controller
{
    /**
     * POST /api/v1/postpartum/chatbot/sessions
     *
     * 새 챗봇 세션 시작 (산모당 동시 세션 1개 제한)
     */
    public function startSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'postpartum_client_id' => 'required|integer|exists:postpartum_clients,id',
        ]);

        $client = PostpartumClient::findOrFail($validated['postpartum_client_id']);
        $this->authorize('chat', $client);

        // 기존 활성 세션이 있으면 그것을 반환 (24시간 이내)
        $existing = PostpartumChatbotSession::where('postpartum_client_id', $client->id)
            ->whereNull('ended_at')
            ->where('started_at', '>=', now()->subDay())
            ->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'code'    => 'OK',
                'message' => '기존 활성 세션이 있어 재사용합니다.',
                'data'    => $existing,
            ]);
        }

        $session = PostpartumChatbotSession::create([
            'postpartum_client_id' => $client->id,
            'started_at'           => now(),
            'message_count'        => 0,
        ]);

        return response()->json([
            'success' => true,
            'code'    => 'OK',
            'message' => '챗봇 세션이 시작되었습니다.',
            'data'    => $session,
        ], 201);
    }

    /**
     * POST /api/v1/postpartum/chatbot/sessions/{id}/messages
     *
     * 메시지 전송 → RAG 검색 → LLM 응답 생성
     */
    public function sendMessage(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required|string|min:1|max:1000',
        ]);

        $session = PostpartumChatbotSession::findOrFail($id);
        $this->authorize('chat', $session->postpartumClient);

        // 사용자 메시지 저장
        $userMsg = PostpartumChatbotMessage::create([
            'session_id' => $session->id,
            'role'       => 'user',
            'content'    => $validated['content'],
        ]);

        // AI 챗봇 호출 (FastAPI /chatbot/postpartum)
        $assistantMsg = $this->callRagService($session, $validated['content']);

        // 세션 메시지 카운트 갱신
        $session->increment('message_count', 2);

        return response()->json([
            'success' => true,
            'code'    => 'OK',
            'data'    => [
                'user_message'      => $userMsg,
                'assistant_message' => $assistantMsg,
            ],
        ], 201);
    }

    /**
     * AI 챗봇 서비스 호출
     *
     * RAG 실패 시 일반적인 안내 메시지로 폴백.
     */
    private function callRagService(
        PostpartumChatbotSession $session,
        string $userMessage
    ): PostpartumChatbotMessage {
        $client = $session->postpartumClient;

        try {
            $aiUrl = config('services.ai.base_url', 'http://ai-service:8000');

            // 최근 5개 메시지를 컨텍스트로 전달
            $history = PostpartumChatbotMessage::where('session_id', $session->id)
                ->orderByDesc('id')
                ->limit(5)
                ->get(['role', 'content'])
                ->reverse()
                ->values()
                ->toArray();

            $response = Http::timeout(20)
                ->withToken(config('services.ai.token') ?? '')
                ->post("{$aiUrl}/chatbot/postpartum", [
                'session_id'           => $session->id,
                'user_message'         => $userMessage,
                'history'              => $history,
                'context' => [
                    'days_since_delivery' => $client?->daysSinceDelivery() ?? 0,
                    'breastfeeding'       => $client?->breastfeeding_intent,
                    'is_first_baby'       => $client?->is_first_baby ?? true,
                    'delivery_type'       => $client?->delivery_type,
                ],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return PostpartumChatbotMessage::create([
                    'session_id' => $session->id,
                    'role'       => 'assistant',
                    'content'    => $data['answer'] ?? '죄송합니다. 응답을 생성하지 못했습니다.',
                    'sources'    => $data['sources'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Postpartum chatbot AI call failed', [
                'session_id' => $session->id,
                'error'      => $e->getMessage(),
            ]);
        }

        // 폴백 메시지
        return PostpartumChatbotMessage::create([
            'session_id' => $session->id,
            'role'       => 'assistant',
            'content'    => '죄송합니다. 일시적으로 답변을 드리기 어렵습니다. '
                          . '응급 상황이라면 즉시 119로 연락하시거나 가까운 산부인과·소아과를 찾아주세요.',
            'sources'    => null,
        ]);
    }
}
