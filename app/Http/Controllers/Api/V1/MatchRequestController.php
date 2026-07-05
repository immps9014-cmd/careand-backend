<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\MatchAlreadyTakenException;
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
use App\Models\Payment;
use App\Models\Senior;
use App\Domains\Nursing\Models\NursingPatient;
use App\Domains\Housekeeping\Models\ServiceAddress;
use App\Services\External\AiService;
use App\Services\Pricing\BiddingService;
use App\Services\Pricing\PricingService;
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
        $data['service_address_id'] = $domain === 'living_support' ? ($data['service_address_id'] ?? null) : null;
        $data['postpartum_client_id'] = $domain === 'postpartum' ? ($data['postpartum_client_id'] ?? null) : null;
        $data['childcare_child_id'] = $domain === 'childcare' ? ($data['childcare_child_id'] ?? null) : null;
        $data['mental_care_client_id'] = $domain === 'mental_care' ? ($data['mental_care_client_id'] ?? null) : null;

        $matchRequest = MatchRequest::create($data);

        // 적정 간병비 스냅샷 산출(best-effort) — 실패해도 요청 생성은 막지 않는다.
        try {
            $matchRequest->price_estimate = app(PricingService::class)->estimate($matchRequest);
            $matchRequest->save();
        } catch (\Throwable $e) {
            Log::warning("적정가 산출 실패 request_id={$matchRequest->id}: {$e->getMessage()}");
        }

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
     * GET /v1/matching/pricing/estimate
     * 요청 생성 전 적정 간병비 미리보기(미저장). 입력 변경 시 폼에서 실시간 호출.
     */
    public function pricingEstimate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_domain' => ['nullable', 'in:senior,nursing,living_support,postpartum,childcare,mental_care'],
            'category_id' => ['required', 'exists:service_categories,id'],
            'mode' => ['nullable', 'in:normal,emergency,recurring'],
            'scheduled_start' => ['nullable', 'date'],
            'duration_min' => ['nullable', 'integer', 'between:1,1440'],
            'senior_id' => ['nullable', 'integer'],
            'nursing_patient_id' => ['nullable', 'integer'],
            'service_address_id' => ['nullable', 'integer'],
            'requirements' => ['nullable', 'array'],
        ]);

        // 대상자 참조는 본인 소유만 반영(난이도 가산 정보 노출 방지). 미소유/미상이면 가산 없이 산출.
        $guardianId = optional($request->user()->guardian)->id;
        $seniorId = $this->ownedId(Senior::class, $validated['senior_id'] ?? null, $guardianId);
        $nursingId = $this->ownedId(NursingPatient::class, $validated['nursing_patient_id'] ?? null, $guardianId);
        $addressId = $this->ownedId(ServiceAddress::class, $validated['service_address_id'] ?? null, $guardianId);

        // 캐스트가 wall-clock을 UTC로 라벨링하므로 store()와 동일하게 UTC 인스턴트로 정규화.
        // (미정규화 시 +09:00 오프셋이 무시돼 야간/주간 판정이 뒤집힌다)
        $scheduledStart = !empty($validated['scheduled_start'])
            ? \Illuminate\Support\Carbon::parse($validated['scheduled_start'])->utc()
            : null;

        $preview = new MatchRequest([
            'service_domain' => $validated['service_domain'] ?? 'senior',
            'category_id' => $validated['category_id'],
            'mode' => $validated['mode'] ?? 'normal',
            'scheduled_start' => $scheduledStart,
            'duration_min' => $validated['duration_min'] ?? 60,
            'senior_id' => $seniorId,
            'nursing_patient_id' => $nursingId,
            'service_address_id' => $addressId,
            'requirements' => $validated['requirements'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => app(PricingService::class)->estimate($preview),
        ]);
    }

    /** 주어진 대상자 ID가 해당 보호자 소유면 그대로, 아니면 null 반환. */
    private function ownedId(string $modelClass, $id, $guardianId): ?int
    {
        if (!$id || !$guardianId) {
            return null;
        }
        return $modelClass::where('id', $id)->where('guardian_id', $guardianId)->exists()
            ? (int) $id
            : null;
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
            ->with([
                'senior:id,name,care_grade',
                'nursingPatient:id,name,hospital_name',
                'serviceAddress:id,label,address',
                'category:id,name',
                // 확정 매칭의 케어자·케어 일정·결제 상태(매칭완료 카드에 노출)
                'match' => fn ($q) => $q->with(['caregiver.user:id,name', 'payment:id,match_id,status']),
            ])
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

        // 가성비 재랭킹: 입찰이 들어온 미확정 요청은 AI가 입찰가 대비 가성비를 반영해
        // 추천순을 보정한다(소프트). AI 실패 시 기존 rank 순서 유지(graceful).
        $candidates = $this->applyValueRank($matchRequest, $candidates);

        // 매칭 확정(인력 수락) 시 결제 진입에 필요한 match_id + 결제 상태를 노출한다.
        // 보호자 프론트가 확정 매칭을 결제(/payments/{matchId})로 연결할 수 있게 함.
        $matchId = null;
        $paymentStatus = null;
        $matchStatus = null;
        if ($matchRequest->status === 'matched') {
            $match = CareMatch::where('request_id', $id)->first();
            if ($match) {
                $matchId = (int) $match->id;
                $matchStatus = $match->status; // 케어 진행: confirmed|in_progress|completed
                // 결제 레코드가 없으면 미결제(null)
                $paymentStatus = Payment::where('match_id', $match->id)->value('status');
            }
        }

        return response()->json([
            'success' => true,
            'request_status' => $matchRequest->status,
            'price_estimate' => $matchRequest->price_estimate,
            'match_id' => $matchId,
            'match_status' => $matchStatus,
            'payment_status' => $paymentStatus,
            'data' => MatchCandidateResource::collection($candidates),
            'message' => $candidates->isEmpty()
                ? 'AI가 추천 후보를 산출 중입니다. 잠시 후 다시 확인해주세요.'
                : null,
        ]);
    }

    /**
     * 입찰가 대비 가성비를 AI 점수에 소프트 가산해 후보를 재정렬한다.
     * 미확정 + 입찰 1건 이상 + 적정가(suggested) 존재 시에만 동작. 실패하면 원본 유지.
     *
     * @param  \Illuminate\Support\Collection<int, MatchCandidate>  $candidates
     * @return \Illuminate\Support\Collection<int, MatchCandidate>
     */
    private function applyValueRank(MatchRequest $matchRequest, $candidates)
    {
        $suggested = (float) ($matchRequest->price_estimate['suggested'] ?? 0);
        $hasBids = $candidates->contains(fn ($c) => $c->bid_hourly !== null);
        if ($matchRequest->status === 'matched' || $suggested <= 0 || !$hasBids) {
            return $candidates;
        }

        try {
            $result = $this->aiService->valueRank($suggested, $candidates->map(fn ($c) => [
                'candidate_id' => $c->id,
                'ai_score' => (float) $c->ai_score,
                'bid_hourly' => $c->bid_hourly !== null ? (float) $c->bid_hourly : null,
            ])->all());

            $byId = collect($result['ranked'] ?? [])->keyBy('candidate_id');
            foreach ($candidates as $c) {
                $row = $byId->get($c->id);
                $c->value_score = $row['value_score'] ?? null;
                $c->value_reason = $row['reason'] ?? null;
            }

            // value_score 내림차순(미산정은 기존 rank 보존하도록 폴백 키)
            return $candidates
                ->sortByDesc(fn ($c) => $c->value_score ?? (1.0 - $c->rank / 100))
                ->values();
        } catch (\Throwable $e) {
            Log::warning("가성비 재랭킹 실패 request_id={$matchRequest->id}: {$e->getMessage()}");
            return $candidates;
        }
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

        // 역경매: 입찰가 있는 후보 선택은 즉시 확정(입찰=확약). 입찰 없으면 기존 2단계(인력 수락 대기).
        $auctionEnabled = (bool) config('services.pricing.auction_enabled', true);
        if ($auctionEnabled && $candidate->bid_hourly !== null) {
            try {
                $match = $this->confirmMatch($candidate, (float) $candidate->bid_hourly);
            } catch (MatchAlreadyTakenException $e) {
                return response()->json([
                    'success' => false,
                    'error_code' => 'MATCH_ALREADY_TAKEN',
                    'message' => $e->getMessage(),
                ], 409);
            }

            return response()->json([
                'success' => true,
                'message' => '입찰가로 매칭이 확정되었습니다.',
                'candidate' => new MatchCandidateResource($candidate->fresh()),
                'match' => [
                    'id' => $match->id,
                    'scheduled_start' => $match->scheduled_start->toIso8601String(),
                    'hourly_rate' => (float) $match->hourly_rate,
                    'estimated_amount' => $match->estimated_amount,
                ],
            ]);
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
            ->first();

        if (!$candidate) {
            return response()->json([
                'success' => false,
                'error_code' => 'CANDIDATE_NOT_FOUND',
                'message' => '해당 매칭 제안을 찾을 수 없습니다.',
            ], 404);
        }

        // 이미 처리된 제안(다른 전문가 선정으로 만료 등) — 선착순 탈락 안내.
        if ($candidate->response !== 'pending') {
            return response()->json([
                'success' => false,
                'error_code' => 'MATCH_ALREADY_TAKEN',
                'message' => '이미 다른 돌봄전문가에게 매칭되었거나 마감된 요청입니다.',
            ], 409);
        }

        // 합의 시급: 입찰가 우선 → 적정가 산출(suggested) → 카테고리 정액 폴백
        try {
            $match = $this->confirmMatch($candidate, $this->agreedHourlyRate($candidate));
        } catch (MatchAlreadyTakenException $e) {
            // 동시 수락 경합에서 밀림 — 선착순으로 다른 전문가가 이미 확정.
            return response()->json([
                'success' => false,
                'error_code' => 'MATCH_ALREADY_TAKEN',
                'message' => $e->getMessage(),
            ], 409);
        }

        // TODO: 양측에 FCM 푸시 (MATCH_CONFIRMED)

        return response()->json([
            'success' => true,
            'message' => '매칭이 확정되었습니다.',
            'match' => [
                'id' => $match->id,
                'scheduled_start' => $match->scheduled_start->toIso8601String(),
                'hourly_rate' => (float) $match->hourly_rate,
                'estimated_amount' => $match->estimated_amount,
            ],
        ]);
    }

    /**
     * 후보 수락/선택을 확정 매칭(matches)으로 전환한다.
     * 후보 accepted, 요청 matched, 잔여 후보 expired, matches + 일별 세션 생성.
     */
    private function confirmMatch(MatchCandidate $candidate, float $hourlyRate): CareMatch
    {
        return DB::transaction(function () use ($candidate, $hourlyRate) {
            // 선착순 보장: 요청 행을 먼저 잠근다(FOR UPDATE). 동시에 여러 돌봄전문가가
            // 수락/선택하면 두 번째 트랜잭션은 여기서 대기 → 첫 트랜잭션 커밋 후
            // status='matched'를 읽고 아래 가드에서 탈락한다(이중 배정 방지).
            $request = MatchRequest::whereKey($candidate->request_id)->lockForUpdate()->firstOrFail();

            if (in_array($request->status, ['matched', 'cancelled', 'expired'], true)) {
                throw new MatchAlreadyTakenException();
            }

            // 후보 행도 잠그고 여전히 pending인지 재확인(잠금 획득 후 최신 상태 기준).
            $locked = MatchCandidate::whereKey($candidate->id)->lockForUpdate()->first();
            if (!$locked || $locked->response !== 'pending') {
                throw new MatchAlreadyTakenException();
            }

            $locked->update([
                'response' => 'accepted',
                'responded_at' => now(),
            ]);
            $candidate = $locked;

            $request->update([
                'status' => 'matched',
                'matched_at' => now(),
            ]);

            // 다른 후보들 자동 expired 처리
            MatchCandidate::where('request_id', $request->id)
                ->where('id', '!=', $candidate->id)
                ->where('response', 'pending')
                ->update(['response' => 'expired', 'responded_at' => now()]);

            // 정기(recurring) 요청의 세션 시작 일시 목록 — 연속일 또는 요일 반복
            $starts = $this->sessionStarts($request);
            $sessionCount = count($starts);
            $lastStart = end($starts);

            // matches 테이블 생성 (합의 시급 반영)
            $match = CareMatch::create([
                'request_id' => $request->id,
                'caregiver_id' => $candidate->caregiver_id,
                'scheduled_start' => $request->scheduled_start,
                'scheduled_end' => $lastStart->copy()->addMinutes($request->duration_min),
                'hourly_rate' => $hourlyRate,
                'estimated_amount' => round($hourlyRate * $request->duration_min / 60) * $sessionCount,
                'status' => 'confirmed',
            ]);

            // 회차별 케어 세션 생성 (체크인/아웃 단위)
            foreach ($starts as $sessionStart) {
                CareSession::create([
                    'match_id' => $match->id,
                    'scheduled_start' => $sessionStart->copy(),
                    'scheduled_end' => $sessionStart->copy()->addMinutes($request->duration_min),
                    'status' => 'scheduled',
                ]);
            }

            return $match;
        });
    }

    /**
     * 정기 요청의 회차별 세션 시작 일시 목록을 계산한다.
     * - mode!=recurring → 단일 회차([scheduled_start]).
     * - recurrence_rule.weekdays(ISO 1=월..7=일) 존재 → 시작일부터 weeks 주 동안 해당 요일마다 세션.
     * - 아니면 recurrence_rule.days(연속 일수) 만큼 연속 세션(기존 동작).
     * 회차 상한 60으로 안전 제한. 요일 판정은 KST 기준(저장값은 UTC 인스턴트 유지).
     *
     * @return array<int, \Illuminate\Support\Carbon>
     */
    private function sessionStarts(MatchRequest $request): array
    {
        $start = $request->scheduled_start->copy();
        if ($request->mode !== 'recurring' || !is_array($request->recurrence_rule)) {
            return [$start];
        }
        $rule = $request->recurrence_rule;

        $weekdays = array_values(array_filter(
            array_unique(array_map('intval', (array) ($rule['weekdays'] ?? []))),
            fn ($d) => $d >= 1 && $d <= 7,
        ));

        if (!empty($weekdays)) {
            $weeks = max(1, min((int) ($rule['weeks'] ?? 1), 12));
            $windowEnd = $start->copy()->addDays($weeks * 7 - 1);
            $dates = [];
            $cursor = $start->copy();
            while ($cursor->lte($windowEnd) && count($dates) < 60) {
                if (in_array($cursor->copy()->setTimezone('Asia/Seoul')->isoWeekday(), $weekdays, true)) {
                    $dates[] = $cursor->copy();
                }
                $cursor->addDay();
            }

            return empty($dates) ? [$start] : $dates;
        }

        // 연속 일수 (기존 동작)
        $days = max(1, min((int) ($rule['days'] ?? 1), 30));
        $dates = [];
        for ($i = 0; $i < $days; $i++) {
            $dates[] = $start->copy()->addDays($i);
        }

        return $dates;
    }

    /** 후보의 합의 시급: 입찰가 → 적정가 suggested → 카테고리 base_rate 순 폴백. */
    private function agreedHourlyRate(MatchCandidate $candidate): float
    {
        if ($candidate->bid_hourly !== null) {
            return (float) $candidate->bid_hourly;
        }
        $request = $candidate->request;
        $suggested = $request->price_estimate['suggested'] ?? null;
        return (float) ($suggested ?? $request->category->base_rate);
    }

    /**
     * POST /v1/matching/candidates/{candidateId}/bid
     * 돌봄전문가가 입찰가(시급)를 제시/수정. 입찰=확약 — 보호자가 선택하면 즉시 확정된다.
     */
    public function submitBid(Request $request, int $candidateId): JsonResponse
    {
        $caregiver = $request->user()->caregiver;
        if (!$caregiver) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_CAREGIVER',
                'message' => '인력 회원만 사용 가능합니다.',
            ], 403);
        }

        $validated = $request->validate([
            'bid_hourly' => ['required', 'numeric', 'min:1'],
            'bid_note' => ['nullable', 'string', 'max:255'],
        ]);

        $candidate = MatchCandidate::where('id', $candidateId)
            ->where('caregiver_id', $caregiver->id)
            ->where('response', 'pending')
            ->whereIn('bid_status', ['invited', 'bid'])
            ->firstOrFail();

        $estimate = $candidate->request->price_estimate;
        $bidding = app(BiddingService::class);
        $bid = (float) $validated['bid_hourly'];

        // 하드 하한: 법정 최저시급 미만 입찰 차단
        $minHourly = $bidding->minHourly($estimate);
        if ($bid < $minHourly) {
            return response()->json([
                'success' => false,
                'error_code' => 'BID_BELOW_MINIMUM',
                'message' => '최저시급(' . number_format($minHourly) . '원) 미만으로는 입찰할 수 없습니다.',
            ], 422);
        }

        $candidate->update([
            'bid_hourly' => $bid,
            'bid_note' => $validated['bid_note'] ?? null,
            'bid_status' => 'bid',
            'bid_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => '입찰이 등록되었습니다.',
            // 권장 가격대 밖이면 경고(차단 아님) — 프론트에서 확인 유도
            'warn_out_of_band' => $bidding->isOutOfBand($bid, $estimate),
            'candidate' => new MatchCandidateResource($candidate->fresh()),
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

        // 동성 매칭 하드 조건(방문목욕 등) — Job과 동일 정책. 반대 성별 제외, 폴백서도 유지.
        $requiredGender = $matchRequest->requiresSameGender() ? ($recipient->gender ?? null) : null;

        $buildPool = fn (bool $withSkill) => Caregiver::active()
            ->whereNotNull('license_verified_at')
            ->whereRaw('FIND_IN_SET(?, service_domains)', [$matchRequest->service_domain])
            ->when($withSkill && $requiredSkill, fn ($q) => $q->whereJsonContains('specialties', $requiredSkill))
            ->when($requiredGender, fn ($q) => $q->where('gender', $requiredGender))
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

        // 연속성(재돌봄) 신호 — Job과 동일 정책 (대상자 과거 수락 매칭 횟수)
        $priorMatches = [];
        if ($recipient) {
            $historyRequestIds = MatchRequest::where('service_domain', $matchRequest->service_domain)
                ->where($this->recipientColumn($matchRequest->service_domain), $recipient->id)
                ->where('id', '!=', $matchRequest->id)
                ->pluck('id');
            if ($historyRequestIds->isNotEmpty()) {
                $priorMatches = MatchCandidate::whereIn('request_id', $historyRequestIds)
                    ->whereIn('caregiver_id', $caregivers->pluck('id'))
                    ->where('response', 'accepted')
                    ->selectRaw('caregiver_id, COUNT(*) as c')
                    ->groupBy('caregiver_id')
                    ->pluck('c', 'caregiver_id')
                    ->all();
            }
        }

        $aiResult = $this->aiService->recommendMatch(
            requestId: $matchRequest->id,
            seniorFeatures: $features,
            caregiverPool: $caregivers->map(fn ($c) => [
                'id' => $c->id,
                'specialties' => $c->specialties ?? [],
                'gender' => $c->gender,
                'rating_avg' => (float) $c->rating_avg,
                'rating_count' => (int) $c->rating_count,
                'completed_sessions' => (int) $c->completed_sessions,
                'lat' => $c->base_lat ? (float) $c->base_lat : null,
                'lng' => $c->base_lng ? (float) $c->base_lng : null,
                'prior_matches' => (int) ($priorMatches[$c->id] ?? 0),
            ])->toArray(),
            serviceDomain: $matchRequest->service_domain,
            requiredSkills: $requiredSkill ? [$requiredSkill] : [],
            preferredGender: $matchRequest->requirements['preferred_gender'] ?? null,
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
                    'bid_status' => 'invited',
                ]);
            }
        });

        app(BiddingService::class)->applyAutoBids($matchRequest);
    }

    /**
     * GET /v1/matching/categories?domain=senior|nursing|living_support
     * 서비스 카테고리 목록 (요청 생성 폼용)
     */
    public function categories(Request $request): JsonResponse
    {
        $rows = DB::table('service_categories')
            ->select('id', 'code', 'name', 'domain', 'base_rate')
            ->where('is_active', 1)
            ->when($request->input('domain'), fn ($q, $d) => $q->where('domain', $d))
            ->orderBy('id')
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * GET /v1/matching/service-domains
     * 서비스 도메인 레지스트리(SSOT) — 요청자 역할에게 노출 가능한 활성 도메인 + 활성 카테고리.
     * FE는 이 응답으로 도메인 카드/대상선택기/카테고리를 동적 렌더한다.
     * @see \App\Support\ServiceDomains
     */
    public function serviceDomains(Request $request): JsonResponse
    {
        $role = $request->user()?->role;

        return response()->json([
            'success' => true,
            'data'    => \App\Support\ServiceDomains::activeForRole($role),
        ]);
    }

    /**
     * GET /v1/matching/postpartum-clients
     * 통합 요청 폼의 산모 선택기용 — 본인(user_id) 소유 산모 목록.
     * (산후 staff 서브시스템과 분리된 소비자용 스코프 — 타인 산모 노출 방지)
     */
    public function postpartumClients(Request $request): JsonResponse
    {
        $rows = DB::table('postpartum_clients')
            ->select('id', 'name', 'delivery_date', 'delivery_type', 'status')
            ->where('user_id', $request->user()->id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * POST /v1/matching/postpartum-clients
     * 산모 간이 등록 — 통합 요청 폼용. 매칭에 필요한 최소 필드만. user_id=본인.
     * (고급 필드/바우처는 산후 전용 서브시스템에서 관리)
     */
    public function storePostpartumClient(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:50'],
            'phone'         => ['required', 'string', 'max:20'],
            'birth_date'    => ['required', 'date', 'before:today'],
            'address'       => ['required', 'string', 'max:500'],
            'region_code'   => ['required', 'string', 'max:20'],
            'delivery_date' => ['required', 'date'],
            'delivery_type' => ['required', 'in:natural,cesarean,vbac'],
            'is_first_baby' => ['nullable', 'boolean'],
        ]);

        $id = DB::table('postpartum_clients')->insertGetId([
            'user_id'         => $request->user()->id,
            'name'            => $data['name'],
            'name_encrypted'  => encrypt($data['name']),
            'phone_encrypted' => encrypt($data['phone']),
            'birth_date'      => $data['birth_date'],
            'address'         => $data['address'],
            'region_code'     => $data['region_code'],
            'delivery_date'   => $data['delivery_date'],
            'delivery_type'   => $data['delivery_type'],
            'is_first_baby'   => (int) ($data['is_first_baby'] ?? 1),
            'status'          => 'active',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return response()->json(['success' => true, 'data' => ['id' => $id, 'name' => $data['name']]], 201);
    }

    /**
     * GET /v1/matching/children
     * 통합 요청 폼의 아동 선택기용 — 본인(보호자) 소유 아동 목록.
     */
    public function children(Request $request): JsonResponse
    {
        $guardian = $request->user()->guardian;
        if (!$guardian) {
            return response()->json(['success' => false, 'error_code' => 'NOT_GUARDIAN', 'message' => '보호자 회원만 사용 가능합니다.'], 403);
        }

        $rows = \App\Models\Child::where('guardian_id', $guardian->id)
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'birth_date', 'gender']);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * POST /v1/matching/children
     * 아동 등록 — 통합 요청 폼용. 주소→좌표 자동 보정(매칭 거리 랭킹).
     */
    public function storeChild(Request $request): JsonResponse
    {
        $guardian = $request->user()->guardian;
        if (!$guardian) {
            return response()->json(['success' => false, 'error_code' => 'NOT_GUARDIAN', 'message' => '보호자 회원만 사용 가능합니다.'], 403);
        }

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:50'],
            'birth_date'    => ['required', 'date', 'before:today'],
            'gender'        => ['required', 'in:M,F'],
            'home_address'  => ['required', 'string', 'max:255'],
            'home_lat'      => ['nullable', 'numeric', 'between:-90,90'],
            'home_lng'      => ['nullable', 'numeric', 'between:-180,180'],
            'special_notes' => ['nullable', 'string', 'max:500'],
        ]);
        $data['guardian_id'] = $guardian->id;

        if (empty($data['home_lat']) && empty($data['home_lng'])) {
            if ($coords = app(\App\Services\GeocodingService::class)->geocode($data['home_address'])) {
                $data['home_lat'] = $coords['lat'];
                $data['home_lng'] = $coords['lng'];
            }
        }

        $child = \App\Models\Child::create($data);

        return response()->json(['success' => true, 'data' => ['id' => $child->id, 'name' => $child->name]], 201);
    }

    /**
     * GET /v1/matching/mental-care-clients
     * 통합 요청 폼의 마음돌봄 대상 선택기 — 본인(보호자) 소유 대상 목록.
     */
    public function mentalCareClients(Request $request): JsonResponse
    {
        $guardian = $request->user()->guardian;
        if (!$guardian) {
            return response()->json(['success' => false, 'error_code' => 'NOT_GUARDIAN', 'message' => '보호자 회원만 사용 가능합니다.'], 403);
        }

        $rows = \App\Models\MentalCareClient::where('guardian_id', $guardian->id)
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'relation', 'gender']);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * POST /v1/matching/mental-care-clients
     * 마음돌봄 대상 등록 — 통합 요청 폼용. 주소→좌표 자동 보정(매칭 거리 랭킹).
     */
    public function storeMentalCareClient(Request $request): JsonResponse
    {
        $guardian = $request->user()->guardian;
        if (!$guardian) {
            return response()->json(['success' => false, 'error_code' => 'NOT_GUARDIAN', 'message' => '보호자 회원만 사용 가능합니다.'], 403);
        }

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:50'],
            'relation'      => ['nullable', 'string', 'max:20'],
            'birth_date'    => ['nullable', 'date', 'before:today'],
            'gender'        => ['nullable', 'in:M,F'],
            'home_address'  => ['required', 'string', 'max:255'],
            'home_lat'      => ['nullable', 'numeric', 'between:-90,90'],
            'home_lng'      => ['nullable', 'numeric', 'between:-180,180'],
            'special_notes' => ['nullable', 'string', 'max:500'],
        ]);
        $data['guardian_id'] = $guardian->id;

        if (empty($data['home_lat']) && empty($data['home_lng'])) {
            if ($coords = app(\App\Services\GeocodingService::class)->geocode($data['home_address'])) {
                $data['home_lat'] = $coords['lat'];
                $data['home_lng'] = $coords['lng'];
            }
        }

        $client = \App\Models\MentalCareClient::create($data);

        return response()->json(['success' => true, 'data' => ['id' => $client->id, 'name' => $client->name]], 201);
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
            'living_support', 'postpartum' => $recipient->address ?? null,
            default => $recipient->home_address ?? null, // senior·childcare
        };

        // 동행(LS_COMPANION) 동선 요약 — 방문 장소·이동수단·복귀 방식·경유지 수만 노출.
        // 만남/복귀 정확 주소·경유지 주소는 매칭 확정 전 미노출(프라이버시).
        $route = data_get($r->requirements, 'companion_route');
        $companionRoute = is_array($route) ? [
            'destination' => $route['destination'] ?? null,
            'return_to_origin' => (bool) ($route['return_to_origin'] ?? true),
            'waypoint_count' => is_array($route['waypoints'] ?? null) ? count($route['waypoints']) : 0,
            'transport' => in_array($route['transport'] ?? null, ['taxi', 'transit'], true) ? $route['transport'] : null,
        ] : null;

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
            'companion_route' => $companionRoute,
            'created_at' => optional($r->created_at)->toIso8601String(),
        ];
    }

    private function recipientColumn(string $domain): string
    {
        return match ($domain) {
            'nursing' => 'nursing_patient_id',
            'living_support' => 'service_address_id',
            'postpartum' => 'postpartum_client_id',
            'childcare' => 'childcare_child_id',
            'mental_care' => 'mental_care_client_id',
            default => 'senior_id',
        };
    }

    private function resolveTargetName(string $type, int $id): ?string
    {
        return match ($type) {
            'nursing' => DB::table('nursing_patients')->where('id', $id)->value('name'),
            'living_support' => DB::table('service_addresses')->where('id', $id)->value('label'),
            'postpartum' => DB::table('postpartum_clients')->where('id', $id)->value('name'),
            'childcare' => DB::table('children')->where('id', $id)->value('name'),
            'mental_care' => DB::table('mental_care_clients')->where('id', $id)->value('name'),
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
