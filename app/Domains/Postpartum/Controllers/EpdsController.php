<?php

namespace App\Domains\Postpartum\Controllers;

use App\Domains\Postpartum\Models\PostpartumClient;
use App\Domains\Postpartum\Models\EpdsAssessment;
use App\Domains\Postpartum\Requests\EpdsSubmitRequest;
use App\Domains\Postpartum\Services\EpdsCalculatorService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * EPDS 산후우울 자가진단
 */
class EpdsController extends Controller
{
    public function __construct(
        private readonly EpdsCalculatorService $epdsService
    ) {}

    /**
     * EPDS 응시 (10문항 0~3점)
     * POST /postpartum/epds/assessments
     */
    public function submit(EpdsSubmitRequest $request): JsonResponse
    {
        $client = PostpartumClient::findOrFail($request->integer('postpartum_client_id'));
        $this->authorize('update', $client);

        // 1산모 1일 1평가 (uk_epds_client_date) — DB 제약 위반 전에 422로 안내
        $already = \App\Domains\Postpartum\Models\EpdsAssessment::where('postpartum_client_id', $client->id)
            ->whereDate('assessment_date', now()->toDateString())
            ->exists();
        if ($already) {
            return response()->json([
                'success' => false,
                'error_code' => 'EPDS_ALREADY_TODAY',
                'message' => '오늘은 이미 EPDS 평가를 완료했습니다. 내일 다시 응시해주세요.',
            ], 422);
        }

        $scores = $request->only([
            'q1_score', 'q2_score', 'q3_score', 'q4_score', 'q5_score',
            'q6_score', 'q7_score', 'q8_score', 'q9_score', 'q10_score',
        ]);

        $llmSentiment = $request->filled('llm_sentiment_text')
            ? $this->epdsService->analyzeSentimentFromText($request->input('llm_sentiment_text'))
            : null;

        $assessment = $this->epdsService->submit($client, $scores, $llmSentiment);

        return response()->json([
            'success'   => true,
            'code'      => 'CREATED',
            'message'   => 'EPDS 진단 완료',
            'data'      => $assessment,
            'timestamp' => now()->toIso8601String(),
        ], 201);
    }

    /**
     * 산모 EPDS 진단 이력
     * GET /postpartum/epds/clients/{id}/history
     */
    public function history(PostpartumClient $client): JsonResponse
    {
        $this->authorize('view', $client);

        $history = EpdsAssessment::where('postpartum_client_id', $client->id)
            ->orderByDesc('assessment_date')
            ->limit(50)
            ->get();

        return response()->json([
            'success'   => true,
            'code'      => 'OK',
            'data'      => $history,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
