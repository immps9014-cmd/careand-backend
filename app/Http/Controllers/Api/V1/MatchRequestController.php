<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Match\StoreMatchRequestRequest;
use App\Http\Resources\MatchCandidateResource;
use App\Http\Resources\MatchRequestResource;
use App\Jobs\GenerateMatchCandidatesJob;
use App\Models\CareMatch;
use App\Models\Caregiver;
use App\Models\MatchCandidate;
use App\Models\MatchRequest;
use App\Services\External\AiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MatchRequestController extends Controller
{
    public function __construct(private AiService $aiService)
    {
    }

    /**
     * POST /v1/matching/requests
     * 보호자 → AI 매칭 요청
     */
    public function store(StoreMatchRequestRequest $request): JsonResponse
    {
        $guardian = $request->user()->guardian;
        if (!$guardian) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_GUARDIAN',
                'message' => '보호자 회원만 매칭 요청 가능합니다.',
            ], 403);
        }

        $data = $request->validated();
        $data['guardian_id'] = $guardian->id;
        $data['status'] = 'open';

        $matchRequest = MatchRequest::create($data);

        // 비동기 큐 작업으로 AI 후보 산출 (즉시 반환, 후속 polling)
        // 실제 환경에서는 dispatch, 로컬에서는 동기로 즉시 처리
        if (app()->environment('local', 'testing')) {
            $this->generateCandidatesSync($matchRequest);
        } else {
            GenerateMatchCandidatesJob::dispatch($matchRequest->id);
        }

        return response()->json([
            'success' => true,
            'message' => 'AI 매칭이 요청되었습니다. 30초 내 결과를 확인해주세요.',
            'data' => new MatchRequestResource($matchRequest->fresh()),
            'polling_url' => route('api.v1.matching.candidates', ['id' => $matchRequest->id]),
        ], 201);
    }

    /**
     * GET /v1/matching/requests
     * 내 매칭 요청 목록
     */
    public function index(Request $request): JsonResponse
    {
        $guardian = $request->user()->guardian;
        if (!$guardian) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_GUARDIAN',
                'message' => '보호자 전용 API 입니다.',
            ], 403);
        }

        $requests = MatchRequest::where('guardian_id', $guardian->id)
            ->with(['senior:id,name,care_grade', 'category:id,name'])
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => MatchRequestResource::collection($requests),
            'meta' => [
                'total' => $requests->total(),
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
            ],
        ]);
    }

    /**
     * GET /v1/matching/requests/{id}/candidates
     * AI 추천 후보 목록 (보호자가 폴링)
     */
    public function candidates(Request $request, int $id): JsonResponse
    {
        $matchRequest = MatchRequest::findOrFail($id);
        $this->authorize('view', $matchRequest);

        $candidates = MatchCandidate::where('request_id', $id)
            ->with(['caregiver.user:id,name', 'caregiver.organization:id,name'])
            ->orderBy('rank')
            ->get();

        return response()->json([
            'success' => true,
            'request_status' => $matchRequest->status,
            'data' => MatchCandidateResource::collection($candidates),
            'message' => $candidates->isEmpty()
                ? 'AI가 추천 후보를 산출 중입니다. 잠시 후 다시 확인해주세요.'
                : null,
        ]);
    }

    /**
     * POST /v1/matching/requests/{id}/select
     * 보호자가 후보 1명 선택 → 인력에게 푸시
     */
    public function selectCandidate(Request $request, int $id): JsonResponse
    {
        $matchRequest = MatchRequest::findOrFail($id);
        $this->authorize('update', $matchRequest);

        $validated = $request->validate([
            'candidate_id' => ['required', 'exists:match_candidates,id'],
        ]);

        $candidate = MatchCandidate::where('id', $validated['candidate_id'])
            ->where('request_id', $id)
            ->firstOrFail();

        if ($candidate->response !== 'pending') {
            return response()->json([
                'success' => false,
                'error_code' => 'INVALID_CANDIDATE_STATUS',
                'message' => '이미 처리된 후보입니다.',
            ], 422);
        }

        $matchRequest->update(['status' => 'matching']);

        // TODO: 인력에게 FCM 푸시 알림 (MATCH_REQUEST_ASSIGNED)
        // app(NotificationService::class)->notifyCaregiver($candidate->caregiver_id, 'MATCH_REQUEST_ASSIGNED', [...]);

        return response()->json([
            'success' => true,
            'message' => '인력에게 매칭 요청이 전송되었습니다. 응답 대기 중입니다.',
            'candidate' => new MatchCandidateResource($candidate),
        ]);
    }

    /**
     * POST /v1/matching/candidates/{candidateId}/accept
     * 인력이 매칭 수락 → matches 테이블 INSERT
     */
    public function acceptByCaregiver(Request $request, int $candidateId): JsonResponse
    {
        $caregiver = $request->user()->caregiver;
        if (!$caregiver) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_CAREGIVER',
                'message' => '인력 회원만 사용 가능합니다.',
            ], 403);
        }

        $candidate = MatchCandidate::where('id', $candidateId)
            ->where('caregiver_id', $caregiver->id)
            ->where('response', 'pending')
            ->firstOrFail();

        $match = DB::transaction(function () use ($candidate) {
            $candidate->update([
                'response' => 'accepted',
                'responded_at' => now(),
            ]);

            $request = $candidate->request;
            $request->update([
                'status' => 'matched',
                'matched_at' => now(),
            ]);

            // 다른 후보들 자동 expired 처리
            MatchCandidate::where('request_id', $request->id)
                ->where('id', '!=', $candidate->id)
                ->where('response', 'pending')
                ->update(['response' => 'expired', 'responded_at' => now()]);

            // matches 테이블 생성
            return CareMatch::create([
                'request_id' => $request->id,
                'caregiver_id' => $candidate->caregiver_id,
                'scheduled_start' => $request->scheduled_start,
                'scheduled_end' => $request->scheduled_start->copy()->addMinutes($request->duration_min),
                'hourly_rate' => $request->category->base_rate,
                'estimated_amount' => round($request->category->base_rate * $request->duration_min / 60),
                'status' => 'confirmed',
            ]);
        });

        // TODO: 양측에 FCM 푸시 (MATCH_CONFIRMED)

        return response()->json([
            'success' => true,
            'message' => '매칭이 확정되었습니다.',
            'match' => [
                'id' => $match->id,
                'scheduled_start' => $match->scheduled_start->toIso8601String(),
                'estimated_amount' => $match->estimated_amount,
            ],
        ]);
    }

    /**
     * POST /v1/matching/candidates/{candidateId}/reject
     */
    public function rejectByCaregiver(Request $request, int $candidateId): JsonResponse
    {
        $caregiver = $request->user()->caregiver;
        if (!$caregiver) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_CAREGIVER',
                'message' => '인력 회원만 사용 가능합니다.',
            ], 403);
        }

        $candidate = MatchCandidate::where('id', $candidateId)
            ->where('caregiver_id', $caregiver->id)
            ->where('response', 'pending')
            ->firstOrFail();

        $candidate->update([
            'response' => 'rejected',
            'responded_at' => now(),
        ]);

        // TODO: 차순위 후보에게 자동 알림

        return response()->json([
            'success' => true,
            'message' => '매칭을 거절했습니다.',
        ]);
    }

    /**
     * 동기 모드(로컬/테스트) AI 후보 생성
     */
    private function generateCandidatesSync(MatchRequest $matchRequest): void
    {
        $matchRequest->load('senior');

        $features = $matchRequest->recipientFeatures();
        if (!$features) {
            return;
        }

        // 인력 풀 조회 (활성, 자격 검증, 요청 도메인 서비스 가능)
        $caregivers = Caregiver::active()
            ->whereNotNull('license_verified_at')
            ->whereRaw('FIND_IN_SET(?, service_domains)', [$matchRequest->service_domain])
            ->limit(30)
            ->get();

        if ($caregivers->isEmpty()) {
            return;
        }

        $aiResult = $this->aiService->recommendMatch(
            requestId: $matchRequest->id,
            seniorFeatures: $features,
            caregiverPool: $caregivers->map(fn ($c) => [
                'id' => $c->id,
                'specialties' => $c->specialties ?? [],
                'rating_avg' => (float) $c->rating_avg,
                'completed_sessions' => (int) $c->completed_sessions,
                'lat' => $c->base_lat ? (float) $c->base_lat : null,
                'lng' => $c->base_lng ? (float) $c->base_lng : null,
            ])->toArray(),
        );

        DB::transaction(function () use ($matchRequest, $aiResult) {
            foreach ($aiResult['candidates'] as $cand) {
                MatchCandidate::create([
                    'request_id' => $matchRequest->id,
                    'caregiver_id' => $cand['caregiver_id'],
                    'ai_score' => $cand['score'],
                    'ai_reasons' => $cand['reasons'],
                    'rank' => $cand['rank'],
                    'response' => 'pending',
                ]);
            }
        });
    }

    /**
     * GET /v1/matching/categories
     * 서비스 카테고리 목록 (요청 생성 폼용)
     */
    public function categories(): JsonResponse
    {
        $rows = DB::table('service_categories')
            ->select('id', 'name')
            ->where('is_active', 1)
            ->orderBy('id')
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }
}
