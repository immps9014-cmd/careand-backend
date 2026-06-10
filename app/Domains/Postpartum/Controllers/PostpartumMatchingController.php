<?php

namespace App\Domains\Postpartum\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Postpartum\Models\PostpartumClient;
use App\Domains\Postpartum\Resources\PostpartumMatchRequestResource;
use App\Domains\Postpartum\Services\SbaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 산후 매칭 컨트롤러 (D2)
 *
 * D1 시니어 매칭과 동일한 알고리즘을 재사용하되 service_domain=postpartum.
 * AI는 산모신생아 건강관리사 자격 + 바우처 등급 + 거리 등을 고려하여
 * 1순위 인력 추천.
 */
class PostpartumMatchingController extends Controller
{
    public function __construct(private SbaService $sba)
    {
    }

    /**
     * POST /api/v1/postpartum/match-requests
     *
     * 산모 매칭 요청 생성
     * - 바우처 잔여 일수 검증
     * - AI 매칭 서비스 호출 (FastAPI /matching)
     * - match_candidates 자동 채움
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'postpartum_client_id'   => 'required|integer|exists:postpartum_clients,id',
            'scheduled_start'        => 'required|date|after:now',
            'scheduled_end'          => 'required|date|after:scheduled_start',
            'weekly_hours'           => 'sometimes|integer|min:1|max:80',
            'special_requirements'   => 'sometimes|array',
            'special_requirements.*' => 'string',
        ]);

        $client = PostpartumClient::findOrFail($validated['postpartum_client_id']);
        $this->authorize('matchRequest', $client);

        // 바우처 잔여 검증
        if (!$client->isVoucherEligible()) {
            return response()->json([
                'success' => false,
                'code'    => 'VOUCHER_INELIGIBLE',
                'message' => '바우처 자격이 없거나 만료되었습니다.',
            ], 402);
        }

        // 트랜잭션으로 매칭 요청 생성 + AI 추천
        $matchRequest = DB::transaction(function () use ($client, $validated) {
            $mr = DB::table('match_requests')->insertGetId([
                'service_domain'        => 'postpartum',
                'postpartum_client_id'  => $client->id,
                'service_category_id'   => null,
                'mode'                  => 'visit',
                'scheduled_start'       => $validated['scheduled_start'],
                'scheduled_end'         => $validated['scheduled_end'],
                'requirements'          => json_encode([
                    'weekly_hours'  => $validated['weekly_hours'] ?? 40,
                    'special'       => $validated['special_requirements'] ?? [],
                    'delivery_type' => $client->delivery_type,
                    'is_first_baby' => $client->is_first_baby,
                    'breastfeeding' => $client->breastfeeding_intent,
                ]),
                'status'                => 'recruiting',
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);

            $this->triggerAiMatching($mr, $client);
            return $mr;
        });

        return response()->json([
            'success' => true,
            'code'    => 'OK',
            'message' => '매칭 요청이 생성되었습니다. AI 후보 추천이 진행됩니다.',
            'data'    => ['match_request_id' => $matchRequest],
        ], 201);
    }

    /**
     * GET /api/v1/postpartum/match-requests/{id}
     *
     * 매칭 요청 상세 + AI 후보 목록
     */
    public function show(int $id): JsonResponse
    {
        $request = DB::table('match_requests')
            ->where('id', $id)
            ->where('service_domain', 'postpartum')
            ->first();

        abort_unless($request, 404, 'Match request not found');

        $client = PostpartumClient::findOrFail($request->postpartum_client_id);
        $this->authorize('view', $client);

        $candidates = DB::table('match_candidates as mc')
            ->join('caregivers as cg', 'mc.caregiver_id', '=', 'cg.id')
            ->join('users as u', 'cg.user_id', '=', 'u.id')
            ->where('mc.match_request_id', $id)
            ->whereRaw("FIND_IN_SET('postpartum', cg.service_domains)")
            ->select(
                'mc.id', 'mc.score', 'mc.reasons',
                'cg.id as caregiver_id',
                'cg.career_track', 'cg.rating_avg', 'cg.completed_sessions',
                'cg.certifications', 'u.name as caregiver_name'
            )
            ->orderByDesc('mc.score')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'code'    => 'OK',
            'data'    => [
                'request'    => $request,
                'candidates' => $candidates,
            ],
        ]);
    }

    /**
     * AI 매칭 서비스 호출 (FastAPI /matching/postpartum)
     *
     * 비동기로 호출하되 응답 실패해도 매칭 요청 자체는 유지.
     * AI 서비스가 match_candidates 테이블에 직접 INSERT.
     */
    private function triggerAiMatching(int $matchRequestId, PostpartumClient $client): void
    {
        try {
            $aiUrl = config('services.ai.base_url', 'http://ai-service:8000');
            Http::timeout(15)
                ->withToken(config('services.ai.token') ?? '')
                ->post("{$aiUrl}/matching/postpartum", [
                'match_request_id'    => $matchRequestId,
                'postpartum_client_id'=> $client->id,
                'delivery_type'       => $client->delivery_type,
                'is_first_baby'       => $client->is_first_baby,
                'is_multiple_birth'   => $client->is_multiple_birth,
                'breastfeeding_intent'=> $client->breastfeeding_intent,
                'voucher_grade'       => $client->voucher_grade,
                'region_code'         => $client->region_code,
                'branch_id'           => $client->branch_id,
                'special_conditions'  => $client->postpartum_conditions,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AI matching trigger failed', [
                'match_request_id' => $matchRequestId,
                'error'            => $e->getMessage(),
            ]);
        }
    }
}
