<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Match\StoreMatchRequestRequest;
use App\Http\Resources\MatchCandidateResource;
use App\Http\Resources\MatchRequestResource;
use App\Jobs\GenerateMatchCandidatesJob;
use App\Models\CareMatch;
use App\Models\CareSession;
use App\Models\Caregiver;
use App\Models\CaregiverBlock;
use App\Models\MatchCandidate;
use App\Models\MatchRequest;
use App\Services\External\AiService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        // +09:00 등 오프셋 입력을 앱 타임존(UTC)으로 정규화
        // (Eloquent는 Carbon 인스턴스의 자체 tz 벽시계 값을 그대로 기록하므로 미변환 시 9시간 지연 저장)
        $data['scheduled_start'] = \Illuminate\Support\Carbon::parse($data['scheduled_start'])->utc();

        // 도메인에 해당하지 않는 대상자 필드는 비운다 (혼합 전송 방어)
        $domain = $data['service_domain'];
        $data['senior_id'] = $domain === 'senior' ? ($data['senior_id'] ?? null) : null;
        $data['nursing_patient_id'] = $domain === 'nursing' ? ($data['nursing_patient_id'] ?? null) : null;
        $data['service_address_id'] = $domain === 'housekeeping' ? ($data['service_address_id'] ?? null) : null;

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
            ->with(['senior:id,name,care_grade', 'nursingPatient:id,name,hospital_name', 'serviceAddress:id,label,address', 'category:id,name'])
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

            // 정기(recurring) 요청은 recurrence_rule.days 만큼 일 단위 세션 — 간병 교대/상주
            $days = 1;
            if ($request->mode === 'recurring') {
                $days = max(1, min((int) ($request->recurrence_rule['days'] ?? 1), 30));
            }

            // matches 테이블 생성
            $match = CareMatch::create([
                'request_id' => $request->id,
                'caregiver_id' => $candidate->caregiver_id,
                'scheduled_start' => $request->scheduled_start,
                'scheduled_end' => $request->scheduled_start->copy()->addDays($days - 1)->addMinutes($request->duration_min),
                'hourly_rate' => $request->category->base_rate,
                'estimated_amount' => round($request->category->base_rate * $request->duration_min / 60) * $days,
                'status' => 'confirmed',
            ]);

            // 일별 케어 세션 생성 (체크인/아웃 단위)
            for ($i = 0; $i < $days; $i++) {
                CareSession::create([
                    'match_id' => $match->id,
                    'scheduled_start' => $request->scheduled_start->copy()->addDays($i),
                    'scheduled_end' => $request->scheduled_start->copy()->addDays($i)->addMinutes($request->duration_min),
                    'status' => 'scheduled',
                ]);
            }

            return $match;
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

        // 인력 풀 조회 (활성, 자격 검증, 요청 도메인 서비스 가능, 가사는 스킬 보유자만)
        // + 이 보호대상을 기피(차단)한 돌봄전문가는 제외
        $requiredSkill = $matchRequest->requiredSkillTag();
        $recipient = $matchRequest->recipient();
        $buildPool = fn (bool $withSkill) => Caregiver::active()
            ->whereNotNull('license_verified_at')
            ->whereRaw('FIND_IN_SET(?, service_domains)', [$matchRequest->service_domain])
            ->when($withSkill && $requiredSkill, fn ($q) => $q->whereJsonContains('specialties', $requiredSkill))
            ->when($recipient, fn ($q) => $q->whereNotExists(function ($sub) use ($matchRequest, $recipient) {
                $sub->select(DB::raw(1))
                    ->from('caregiver_blocks')
                    ->whereColumn('caregiver_blocks.caregiver_id', 'caregivers.id')
                    ->where('caregiver_blocks.target_type', $matchRequest->service_domain)
                    ->where('caregiver_blocks.target_id', $recipient->id);
            }))
            ->limit(30)
            ->get();

        $caregivers = $buildPool(true);

        // 공급 희박 폴백: 스킬 보유자 0이면 도메인 적격자 전체로 완화 (Job과 동일 정책)
        if ($caregivers->isEmpty() && $requiredSkill) {
            $caregivers = $buildPool(false);
        }

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
            serviceDomain: $matchRequest->service_domain,
            requiredSkills: $requiredSkill ? [$requiredSkill] : [],
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
     * GET /v1/matching/categories?domain=senior|nursing|housekeeping
     * 서비스 카테고리 목록 (요청 생성 폼용)
     */
    public function categories(Request $request): JsonResponse
    {
        $rows = DB::table('service_categories')
            ->select('id', 'name', 'domain', 'base_rate')
            ->where('is_active', 1)
            ->when($request->input('domain'), fn ($q, $d) => $q->where('domain', $d))
            ->orderBy('id')
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /* ===================== 돌봄전문가 주도(pull) 흐름 ===================== */

    /**
     * GET /v1/matching/open-requests
     * 돌봄전문가가 지원 가능한 열린 보호대상 요청 탐색.
     * 필터: status=open ∩ 내 직군 ∩ 미지원 ∩ 기피제외 ∩ (가사)필수스킬. 거리 가까운 순.
     */
    public function openRequests(Request $request): JsonResponse
    {
        $caregiver = $request->user()->caregiver;
        if (!$caregiver) {
            return response()->json(['success' => false, 'error_code' => 'NOT_CAREGIVER', 'message' => '돌봄전문가 회원만 사용 가능합니다.'], 403);
        }
        if ($caregiver->status !== 'active') {
            return response()->json(['success' => false, 'error_code' => 'NOT_ACTIVE', 'message' => '자격 심사 완료 후 이용할 수 있어요.'], 403);
        }

        $domains = array_filter(explode(',', $caregiver->service_domains ?? ''));
        if (empty($domains)) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $blockedSet = array_flip(
            CaregiverBlock::where('caregiver_id', $caregiver->id)
                ->get()
                ->map(fn ($b) => $b->target_type . ':' . $b->target_id)
                ->all()
        );

        $requests = MatchRequest::where('status', 'open')
            ->whereIn('service_domain', $domains)
            ->whereDoesntHave('candidates', fn ($q) => $q->where('caregiver_id', $caregiver->id))
            ->with(['category'])
            ->latest()
            ->limit(100)
            ->get();

        $specialties = $caregiver->specialties ?? [];
        $items = [];
        foreach ($requests as $r) {
            $skill = $r->requiredSkillTag();
            if ($skill && !in_array($skill, $specialties, true)) {
                continue;
            }
            $recipient = $r->recipient();
            if (!$recipient) {
                continue;
            }
            if (isset($blockedSet[$r->service_domain . ':' . $recipient->id])) {
                continue;
            }
            $items[] = $this->openRequestSummary($r, $caregiver);
        }

        usort($items, function ($a, $b) {
            $da = $a['distance_km'] ?? 9999;
            $db = $b['distance_km'] ?? 9999;
            if ($da === $db) {
                return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
            }
            return $da <=> $db;
        });

        return response()->json(['success' => true, 'data' => $items]);
    }

    /**
     * POST /v1/matching/requests/{id}/apply
     * 돌봄전문가 직접 지원 → match_candidates(source=self, pending) 삽입. 보호자가 최종 선택.
     */
    public function apply(Request $request, int $id): JsonResponse
    {
        $caregiver = $request->user()->caregiver;
        if (!$caregiver) {
            return response()->json(['success' => false, 'error_code' => 'NOT_CAREGIVER', 'message' => '돌봄전문가 회원만 사용 가능합니다.'], 403);
        }
        if ($caregiver->status !== 'active') {
            return response()->json(['success' => false, 'error_code' => 'NOT_ACTIVE', 'message' => '자격 심사 완료 후 지원할 수 있어요.'], 403);
        }

        $matchRequest = MatchRequest::where('id', $id)->where('status', 'open')->first();
        if (!$matchRequest) {
            return response()->json(['success' => false, 'error_code' => 'NOT_OPEN', 'message' => '이미 마감되었거나 존재하지 않는 요청입니다.'], 404);
        }

        $domains = array_filter(explode(',', $caregiver->service_domains ?? ''));
        if (!in_array($matchRequest->service_domain, $domains, true)) {
            return response()->json(['success' => false, 'error_code' => 'DOMAIN_MISMATCH', 'message' => '담당 직군이 아닌 요청입니다.'], 422);
        }
        $skill = $matchRequest->requiredSkillTag();
        if ($skill && !in_array($skill, $caregiver->specialties ?? [], true)) {
            return response()->json(['success' => false, 'error_code' => 'SKILL_REQUIRED', 'message' => '해당 가사 작업 자격이 필요합니다.'], 422);
        }

        $recipient = $matchRequest->recipient();
        if ($recipient && CaregiverBlock::where('caregiver_id', $caregiver->id)
            ->where('target_type', $matchRequest->service_domain)
            ->where('target_id', $recipient->id)->exists()) {
            return response()->json(['success' => false, 'error_code' => 'BLOCKED', 'message' => '기피 대상으로 설정한 요청입니다. 먼저 기피를 해제해주세요.'], 422);
        }
        if (MatchCandidate::where('request_id', $id)->where('caregiver_id', $caregiver->id)->exists()) {
            return response()->json(['success' => false, 'error_code' => 'ALREADY_APPLIED', 'message' => '이미 지원했거나 추천된 요청입니다.'], 409);
        }

        $rank = (int) (MatchCandidate::where('request_id', $id)->max('rank') ?? 0) + 1;
        MatchCandidate::create([
            'request_id' => $id,
            'caregiver_id' => $caregiver->id,
            // 직접 지원은 AI 점수 없음 — 컬럼이 NOT NULL이라 0, 구분은 source=self로
            'ai_score' => 0,
            'ai_reasons' => ['돌봄전문가 직접 지원'],
            'rank' => $rank,
            'response' => 'pending',
            'source' => 'self',
        ]);

        try {
            $guardianUserId = $matchRequest->guardian?->user_id;
            if ($guardianUserId) {
                app(NotificationService::class)->notify(
                    (int) $guardianUserId,
                    NotificationService::TYPE_CAREGIVER_APPLIED,
                    [
                        'caregiver_name' => $caregiver->user?->name ?? '돌봄전문가',
                        'recipient_name' => $matchRequest->recipientName() ?? '대상자',
                        'request_id' => $id,
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('지원 알림 실패', ['request_id' => $id, 'error' => $e->getMessage()]);
        }

        return response()->json(['success' => true, 'message' => '지원이 접수되었습니다. 보호자 확인 후 매칭이 확정돼요.']);
    }

    /**
     * POST /v1/matching/blocks  { request_id, reason? }
     * 돌봄전문가가 보호대상을 기피(영구 차단). 검색·자동매칭서 제외 + 진행중 내 후보 거절.
     */
    public function blockTarget(Request $request): JsonResponse
    {
        $caregiver = $request->user()->caregiver;
        if (!$caregiver) {
            return response()->json(['success' => false, 'error_code' => 'NOT_CAREGIVER', 'message' => '돌봄전문가 회원만 사용 가능합니다.'], 403);
        }

        $validated = $request->validate([
            'request_id' => ['required', 'integer', 'exists:match_requests,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $matchRequest = MatchRequest::find($validated['request_id']);
        $recipient = $matchRequest?->recipient();
        if (!$recipient) {
            return response()->json(['success' => false, 'error_code' => 'NO_TARGET', 'message' => '대상을 확인할 수 없습니다.'], 422);
        }

        $block = CaregiverBlock::firstOrCreate(
            [
                'caregiver_id' => $caregiver->id,
                'target_type' => $matchRequest->service_domain,
                'target_id' => $recipient->id,
            ],
            ['reason' => $validated['reason'] ?? null]
        );

        // 같은 대상의 진행중(pending) 내 후보(지원/추천) 거절 처리
        $targetRequestIds = MatchRequest::where('service_domain', $matchRequest->service_domain)
            ->where($this->recipientColumn($matchRequest->service_domain), $recipient->id)
            ->pluck('id');
        MatchCandidate::whereIn('request_id', $targetRequestIds)
            ->where('caregiver_id', $caregiver->id)
            ->where('response', 'pending')
            ->update(['response' => 'rejected', 'responded_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => '기피 대상으로 설정했어요. 이후 검색·매칭에서 제외됩니다.',
            'block_id' => $block->id,
        ]);
    }

    /**
     * DELETE /v1/matching/blocks/{blockId}
     */
    public function unblock(Request $request, int $blockId): JsonResponse
    {
        $caregiver = $request->user()->caregiver;
        if (!$caregiver) {
            return response()->json(['success' => false, 'error_code' => 'NOT_CAREGIVER', 'message' => '돌봄전문가 회원만 사용 가능합니다.'], 403);
        }
        $deleted = CaregiverBlock::where('id', $blockId)->where('caregiver_id', $caregiver->id)->delete();
        if (!$deleted) {
            return response()->json(['success' => false, 'message' => '기피 항목을 찾을 수 없습니다.'], 404);
        }
        return response()->json(['success' => true, 'message' => '기피를 해제했어요.']);
    }

    /**
     * GET /v1/matching/blocks — 내 기피 목록
     */
    public function myBlocks(Request $request): JsonResponse
    {
        $caregiver = $request->user()->caregiver;
        if (!$caregiver) {
            return response()->json(['success' => false, 'error_code' => 'NOT_CAREGIVER', 'message' => '돌봄전문가 회원만 사용 가능합니다.'], 403);
        }
        $data = CaregiverBlock::where('caregiver_id', $caregiver->id)->latest()->get()->map(fn ($b) => [
            'id' => $b->id,
            'target_type' => $b->target_type,
            'target_id' => $b->target_id,
            'target_name' => $this->maskName($this->resolveTargetName($b->target_type, (int) $b->target_id)),
            'reason' => $b->reason,
            'created_at' => optional($b->created_at)->toIso8601String(),
        ]);
        return response()->json(['success' => true, 'data' => $data]);
    }

    /* ----- helpers ----- */

    private function openRequestSummary(MatchRequest $r, Caregiver $caregiver): array
    {
        $recipient = $r->recipient();
        $loc = $r->recipientLocation();
        $distance = null;
        if ($loc && $caregiver->base_lat !== null && $caregiver->base_lng !== null) {
            $distance = round($this->haversineKm((float) $caregiver->base_lat, (float) $caregiver->base_lng, $loc[0], $loc[1]), 1);
        }

        $rawAddr = match ($r->service_domain) {
            'nursing' => $recipient->hospital_address ?? null,
            'housekeeping' => $recipient->address ?? null,
            default => $recipient->home_address ?? null,
        };

        return [
            'request_id' => $r->id,
            'service_domain' => $r->service_domain,
            'category' => $r->category?->name,
            'recipient_name' => $this->maskName($r->recipientName()),
            'recipient_age' => $this->ageFrom($recipient->birth_date ?? null),
            'recipient_gender' => $recipient->gender ?? null,
            'care_grade' => $recipient->care_grade ?? null,
            'region' => $this->regionLabel($rawAddr),
            'distance_km' => $distance,
            'scheduled_start' => optional($r->scheduled_start)->toIso8601String(),
            'duration_min' => $r->duration_min,
            'mode' => $r->mode,
            'special_request' => $r->special_request,
            'created_at' => optional($r->created_at)->toIso8601String(),
        ];
    }

    private function recipientColumn(string $domain): string
    {
        return match ($domain) {
            'nursing' => 'nursing_patient_id',
            'housekeeping' => 'service_address_id',
            default => 'senior_id',
        };
    }

    private function resolveTargetName(string $type, int $id): ?string
    {
        return match ($type) {
            'nursing' => DB::table('nursing_patients')->where('id', $id)->value('name'),
            'housekeeping' => DB::table('service_addresses')->where('id', $id)->value('label'),
            default => DB::table('seniors')->where('id', $id)->value('name'),
        };
    }

    private function maskName(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '대상자';
        }
        $len = mb_strlen($name);
        if ($len <= 1) {
            return $name;
        }
        return mb_substr($name, 0, 1) . str_repeat('○', $len - 1);
    }

    private function regionLabel(?string $address): ?string
    {
        $address = trim((string) $address);
        if ($address === '') {
            return null;
        }
        $parts = preg_split('/\s+/', $address);
        return implode(' ', array_slice($parts, 0, 3));
    }

    private function ageFrom($birthDate): ?int
    {
        if (!$birthDate) {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($birthDate)->age;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $earth * 2 * asin(min(1, sqrt($a)));
    }
}
