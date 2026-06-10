<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChatbotMessageResource;
use App\Models\ChatbotMessage;
use App\Models\ChatbotSession;
use App\Services\External\AiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatbotController extends Controller
{
    public function __construct(private AiService $aiService)
    {
    }

    /**
     * GET /v1/chatbot/sessions
     */
    public function sessions(Request $request): JsonResponse
    {
        $guardian = $request->user()->guardian;
        if (!$guardian) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_GUARDIAN',
                'message' => '보호자 회원만 사용 가능합니다.',
            ], 403);
        }

        $sessions = ChatbotSession::where('guardian_id', $guardian->id)
            ->withCount('messages')
            ->orderByDesc('started_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $sessions->map(fn ($s) => [
                'id' => $s->id,
                'topic' => $s->topic,
                'message_count' => $s->messages_count,
                'started_at' => $s->started_at->toIso8601String(),
                'ended_at' => $s->ended_at?->toIso8601String(),
            ]),
            'meta' => [
                'total' => $sessions->total(),
                'current_page' => $sessions->currentPage(),
            ],
        ]);
    }

    /**
     * POST /v1/chatbot/sessions
     * 새 세션 시작
     */
    public function startSession(Request $request): JsonResponse
    {
        $guardian = $request->user()->guardian;
        if (!$guardian) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_GUARDIAN',
                'message' => '보호자 회원만 사용 가능합니다.',
            ], 403);
        }

        $session = ChatbotSession::create([
            'guardian_id' => $guardian->id,
            'topic' => $request->input('topic'),
            'started_at' => now(),
        ]);

        // 환영 메시지
        $welcome = ChatbotMessage::create([
            'session_id' => $session->id,
            'role' => 'assistant',
            'content' => "안녕하세요 {$request->user()->name} 님 👋\n어르신 케어 관련 무엇이든 편하게 물어보세요.",
        ]);

        return response()->json([
            'success' => true,
            'session_id' => $session->id,
            'welcome_message' => new ChatbotMessageResource($welcome),
        ], 201);
    }

    /**
     * GET /v1/chatbot/sessions/{id}/messages
     */
    public function messages(Request $request, int $sessionId): JsonResponse
    {
        $session = ChatbotSession::findOrFail($sessionId);

        if ($session->guardian_id !== $request->user()->guardian?->id) {
            return response()->json([
                'success' => false,
                'error_code' => 'FORBIDDEN',
                'message' => '본인의 채팅 세션만 조회 가능합니다.',
            ], 403);
        }

        $messages = ChatbotMessage::where('session_id', $sessionId)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => ChatbotMessageResource::collection($messages),
        ]);
    }

    /**
     * POST /v1/chatbot/sessions/{id}/ask
     * 질문 입력 → AI 답변 (RAG)
     */
    public function ask(Request $request, int $sessionId): JsonResponse
    {
        $session = ChatbotSession::findOrFail($sessionId);

        if ($session->guardian_id !== $request->user()->guardian?->id) {
            return response()->json([
                'success' => false,
                'error_code' => 'FORBIDDEN',
                'message' => '본인의 채팅 세션만 사용 가능합니다.',
            ], 403);
        }

        $validated = $request->validate([
            'question' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $result = DB::transaction(function () use ($session, $validated) {
                // 1. 사용자 메시지 저장
                $userMessage = ChatbotMessage::create([
                    'session_id' => $session->id,
                    'role' => 'user',
                    'content' => $validated['question'],
                ]);

                // 2. 컨텍스트 (최근 5개 메시지)
                $recentMessages = ChatbotMessage::where('session_id', $session->id)
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get()
                    ->reverse()
                    ->values()
                    ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
                    ->toArray();

                // 3. AI 호출 (RAG)
                $aiResult = $this->aiService->chatbotAnswer(
                    question: $validated['question'],
                    context: [
                        'recent_messages' => $recentMessages,
                        'guardian_id' => $session->guardian_id,
                    ]
                );

                // 4. AI 답변 저장
                $assistantMessage = ChatbotMessage::create([
                    'session_id' => $session->id,
                    'role' => 'assistant',
                    'content' => $aiResult['answer'] ?? '',
                    'sources' => $aiResult['sources'] ?? [],
                    'metadata' => [
                        'model' => $aiResult['model'] ?? 'unknown',
                    ],
                ]);

                return [$userMessage, $assistantMessage];
            });

            [, $assistantMessage] = $result;

            return response()->json([
                'success' => true,
                'data' => new ChatbotMessageResource($assistantMessage),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error_code' => 'AI_ERROR',
                'message' => '답변 생성 중 오류가 발생했습니다: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /v1/chatbot/sessions/{id}/end
     */
    public function endSession(Request $request, int $sessionId): JsonResponse
    {
        $session = ChatbotSession::findOrFail($sessionId);

        if ($session->guardian_id !== $request->user()->guardian?->id) {
            return response()->json([
                'success' => false,
                'error_code' => 'FORBIDDEN',
                'message' => '본인의 채팅 세션만 사용 가능합니다.',
            ], 403);
        }

        if ($session->ended_at) {
            return response()->json([
                'success' => true,
                'message' => '이미 종료된 세션입니다.',
            ]);
        }

        $session->update(['ended_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => '채팅 세션이 종료되었습니다.',
        ]);
    }
}
