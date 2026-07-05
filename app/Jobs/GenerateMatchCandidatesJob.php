<?php

namespace App\Jobs;

use App\Models\Caregiver;
use App\Models\MatchCandidate;
use App\Models\MatchRequest;
use App\Services\External\AiService;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateMatchCandidatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public int $matchRequestId)
    {
    }

    public function handle(AiService $aiService): void
    {
        $matchRequest = MatchRequest::with('senior')->find($this->matchRequestId);
        if (!$matchRequest || $matchRequest->status !== 'open') {
            Log::info("매칭 요청 {$this->matchRequestId}: 이미 처리되었거나 존재하지 않음");
            return;
        }

        $features = $matchRequest->recipientFeatures();
        if (!$features) {
            Log::error("매칭 요청 {$this->matchRequestId}: 대상자 정보 없음 (domain={$matchRequest->service_domain})");
            return;
        }

        // 인력 풀 조회 — 요청 도메인을 서비스할 수 있는 인력만 (가사는 스킬 보유자만)
        // + 이 보호대상을 기피(차단)한 돌봄전문가는 제외
        $requiredSkill = $matchRequest->requiredSkillTag();
        $recipient = $matchRequest->recipient();

        // 직접 지정(찜한 전문가 등) — 적격이면 AI 결과와 무관하게 최상단 직접 후보로 먼저 보장.
        $preferredId = $matchRequest->requirements['preferred_caregiver_id'] ?? null;
        $hasDirect = $preferredId ? $this->createDirectCandidate($matchRequest, (int) $preferredId) : false;

        // 동성 매칭 하드 조건(방문목욕 등): 반대 성별은 풀에서 제외. 폴백으로도 완화하지 않음.
        // 대상자 성별을 알 수 없으면 안전하게 동성 강제를 적용할 수 없으므로 경고만 남기고 미적용.
        $requiredGender = null;
        if ($matchRequest->requiresSameGender()) {
            $requiredGender = $recipient->gender ?? null;
            if (!$requiredGender) {
                Log::warning("매칭 요청 {$this->matchRequestId}: 동성 매칭 필요하나 대상자 성별 미상 → 동성 강제 미적용");
            }
        }

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
            ->limit(50)
            ->get();

        $caregivers = $buildPool(true);

        // 공급 희박 폴백: 스킬(가사 hk_*) 보유 인력이 0이면 도메인 적격자 전체로 완화한다.
        // "도메인 인력은 있는데 스킬 미태깅이라 0건 → 침묵 만료"를 방지 (min_score 완화와 동일 철학).
        // ※ 동성 매칭($requiredGender)은 하드 조건이라 이 폴백에서도 유지된다($buildPool 내부에 포함).
        if ($caregivers->isEmpty() && $requiredSkill) {
            Log::info("매칭 요청 {$this->matchRequestId}: 스킬({$requiredSkill}) 보유 인력 0 → 도메인 적격자로 완화 재조회");
            $caregivers = $buildPool(false);
        }

        if ($caregivers->isEmpty()) {
            Log::warning("매칭 요청 {$this->matchRequestId}: 활성 인력이 없습니다.");
            // 직접 지정 후보가 있으면 만료시키지 않는다(직접 후보만으로 진행).
            if ($hasDirect) {
                app(\App\Services\Pricing\BiddingService::class)->applyAutoBids($matchRequest);
            } else {
                $matchRequest->update(['status' => 'expired']);
            }
            return;
        }

        // 연속성(재돌봄) 신호: 이 대상자를 과거에 수락(accepted)으로 맡았던 횟수를 caregiver별 집계.
        // 가족·환자는 익숙한 인력을 선호 → 동일 대상 재요청 시 기존 담당자를 상위로.
        $priorMatches = $this->priorMatchCounts($matchRequest, $recipient, $caregivers->pluck('id'));

        // 선호 성별(보호자 지정, M/F) — 매칭에서 소프트 가산 신호
        $preferredGender = $matchRequest->requirements['preferred_gender'] ?? null;

        // AI 추천 호출
        $pool = $caregivers->map(fn ($c) => [
            'id' => $c->id,
            'specialties' => $c->specialties ?? [],
            'gender' => $c->gender,
            'rating_avg' => (float) $c->rating_avg,
            'rating_count' => (int) $c->rating_count,
            'completed_sessions' => (int) $c->completed_sessions,
            'lat' => $c->base_lat ? (float) $c->base_lat : null,
            'lng' => $c->base_lng ? (float) $c->base_lng : null,
            'prior_matches' => (int) ($priorMatches[$c->id] ?? 0),
        ])->toArray();

        $aiResult = $aiService->recommendMatch(
            requestId: $matchRequest->id,
            seniorFeatures: $features,
            caregiverPool: $pool,
            serviceDomain: $matchRequest->service_domain,
            requiredSkills: $requiredSkill ? [$requiredSkill] : [],
            preferredGender: $preferredGender,
        );

        // 공급 희박 폴백: 적격 인력 풀은 있는데 점수 임계(min_score)로 후보가 0건이면,
        // 임계를 0으로 완화해 재시도한다. "공급이 있는데 0건"을 방지(좌표 미설정·신규 인력 등).
        if (empty($aiResult['candidates'])) {
            Log::info("매칭 요청 {$this->matchRequestId}: 임계 후보 없음 → 임계 완화 재시도 (pool=" . count($pool) . ")");
            $aiResult = $aiService->recommendMatch(
                requestId: $matchRequest->id,
                seniorFeatures: $features,
                caregiverPool: $pool,
                serviceDomain: $matchRequest->service_domain,
                requiredSkills: $requiredSkill ? [$requiredSkill] : [],
                minScore: 0.0,
                preferredGender: $preferredGender,
            );
        }

        if (empty($aiResult['candidates'])) {
            Log::warning("매칭 요청 {$this->matchRequestId}: AI 추천 결과 없음 (완화 후에도)");
            if ($hasDirect) {
                app(\App\Services\Pricing\BiddingService::class)->applyAutoBids($matchRequest);
                // AI 후보가 0이어도 직접 지정 전문가는 후보로 존재 → 알림 발송(누락 방지).
                if ($preferredId) {
                    $this->notifyInvitedCaregivers($matchRequest, $recipient, [(int) $preferredId]);
                }
            }
            return;
        }

        // 후보로 지정된 돌봄전문가 목록(알림 대상). 직접 지정 전문가도 포함.
        $notifiedCaregiverIds = $hasDirect && $preferredId ? [(int) $preferredId] : [];

        DB::transaction(function () use ($matchRequest, $aiResult, $preferredId, &$notifiedCaregiverIds) {
            foreach ($aiResult['candidates'] as $cand) {
                // 직접 지정 전문가는 이미 direct 후보로 생성됨 → 중복 방지
                if ($preferredId && (int) $cand['caregiver_id'] === (int) $preferredId) {
                    continue;
                }
                MatchCandidate::create([
                    'request_id' => $matchRequest->id,
                    'caregiver_id' => $cand['caregiver_id'],
                    'ai_score' => $cand['score'],
                    'ai_reasons' => $cand['reasons'],
                    'rank' => $cand['rank'],
                    'response' => 'pending',
                    'bid_status' => 'invited',
                ]);
                $notifiedCaregiverIds[] = (int) $cand['caregiver_id'];
            }
        });

        // 자동입찰 설정 돌봄전문가는 즉시 입찰 채움(입찰 공백 방지)
        app(\App\Services\Pricing\BiddingService::class)->applyAutoBids($matchRequest);

        // 후보로 지정된 돌봄전문가에게 '새 매칭 요청' 알림(DB+FCM). 커밋 후 발송하며,
        // 알림 실패가 후보 생성을 되돌리지 않도록 방어적으로 처리.
        $this->notifyInvitedCaregivers($matchRequest, $recipient, array_unique($notifiedCaregiverIds));

        // TODO: 보호자에게 FCM 푸시 (CANDIDATES_READY)
        Log::info("매칭 요청 {$this->matchRequestId}: " . count($aiResult['candidates']) . "명 후보 산출 완료");
    }

    /**
     * 직접 지정 전문가를 최상단(rank=0, source=direct) 후보로 보장한다.
     * 적격(활성·자격검증·요청 도메인 서비스)일 때만. 부적격이면 무시(AI 후보만 진행).
     */
    private function createDirectCandidate(MatchRequest $req, int $caregiverId): bool
    {
        $eligible = Caregiver::active()
            ->whereNotNull('license_verified_at')
            ->whereRaw('FIND_IN_SET(?, service_domains)', [$req->service_domain])
            ->whereKey($caregiverId)
            ->exists();
        if (!$eligible) {
            Log::info("매칭 요청 {$req->id}: 직접 지정 전문가 {$caregiverId} 부적격 → 무시");
            return false;
        }

        MatchCandidate::firstOrCreate(
            ['request_id' => $req->id, 'caregiver_id' => $caregiverId],
            [
                'ai_score' => 1.0,
                'ai_reasons' => ['보호자 직접 지정'],
                'rank' => 0,
                'response' => 'pending',
                'bid_status' => 'invited',
                'source' => 'direct',
            ]
        );

        return true;
    }

    /**
     * 후보로 지정된 돌봄전문가들에게 '새 매칭 요청' 알림을 발송한다.
     * caregiver_id → user_id 매핑 후 NotificationService(DB 기록 + FCM)로 개별 발송.
     * 개별 실패는 로깅만 하고 나머지 발송을 계속한다(후보 생성은 이미 커밋됨).
     *
     * @param  array<int,int>  $caregiverIds
     */
    private function notifyInvitedCaregivers(MatchRequest $matchRequest, $recipient, array $caregiverIds): void
    {
        if (empty($caregiverIds)) {
            return;
        }

        $userIds = Caregiver::whereIn('id', $caregiverIds)
            ->pluck('user_id', 'id');

        // 저장값은 UTC 인스턴트. 표시는 KST로 변환(코드베이스 관례: setTimezone('Asia/Seoul')).
        $scheduledKst = $matchRequest->scheduled_start
            ? $matchRequest->scheduled_start->copy()->setTimezone('Asia/Seoul')->format('n월 j일 H:i')
            : '';
        // 도메인별 서비스명(알림 문구용). 미정의 도메인은 일반 '케어'로 폴백.
        $serviceLabels = [
            'senior' => '시니어돌봄',
            'nursing' => '병원간병',
            'living_support' => '생활지원',
            'postpartum' => '산후관리',
            'childcare' => '아이돌봄',
            'mental_care' => '마음돌봄',
        ];
        // 대상자 표기: senior/nursing 등은 name, 가사(living_support)는 주소 label.
        $recipientName = $recipient->name ?? $recipient->label ?? '대상자';
        $payload = [
            'recipient_name' => $recipientName,
            'senior_name' => $recipientName, // 하위호환
            'service_label' => $serviceLabels[$matchRequest->service_domain] ?? '케어',
            'scheduled_at' => $scheduledKst,
            'request_id' => $matchRequest->id,
            'service_domain' => $matchRequest->service_domain,
        ];

        $notifier = app(NotificationService::class);
        $sent = 0;
        foreach ($caregiverIds as $caregiverId) {
            $userId = $userIds[$caregiverId] ?? null;
            if (!$userId) {
                Log::warning("매칭 요청 {$matchRequest->id}: 돌봄전문가 {$caregiverId} user_id 없음 → 알림 생략");
                continue;
            }
            try {
                $notifier->notify((int) $userId, NotificationService::TYPE_MATCH_REQUEST_ASSIGNED, $payload);
                $sent++;
            } catch (\Throwable $e) {
                Log::warning("매칭 요청 {$matchRequest->id}: 돌봄전문가 {$caregiverId} 알림 실패 — {$e->getMessage()}");
            }
        }
        Log::info("매칭 요청 {$matchRequest->id}: 돌봄전문가 {$sent}명에게 매칭 요청 알림 발송");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("매칭 후보 생성 실패: request_id={$this->matchRequestId}, error={$exception->getMessage()}");
    }

    /**
     * 대상자(recipient)를 과거에 수락(accepted)으로 맡았던 횟수를 caregiver_id별로 집계.
     * 현재 요청은 제외. 풀에 속한 caregiver만 조회.
     *
     * @param  \Illuminate\Support\Collection<int,int>  $caregiverIds
     * @return array<int,int>  caregiver_id => 과거 매칭 횟수
     */
    private function priorMatchCounts(MatchRequest $matchRequest, $recipient, $caregiverIds): array
    {
        if (!$recipient || $caregiverIds->isEmpty()) {
            return [];
        }

        $recipientCol = match ($matchRequest->service_domain) {
            'nursing' => 'nursing_patient_id',
            'living_support' => 'service_address_id',
            'postpartum' => 'postpartum_client_id',
            'childcare' => 'childcare_child_id',
            'mental_care' => 'mental_care_client_id',
            default => 'senior_id',
        };

        $historyRequestIds = MatchRequest::where('service_domain', $matchRequest->service_domain)
            ->where($recipientCol, $recipient->id)
            ->where('id', '!=', $matchRequest->id)
            ->pluck('id');

        if ($historyRequestIds->isEmpty()) {
            return [];
        }

        return MatchCandidate::whereIn('request_id', $historyRequestIds)
            ->whereIn('caregiver_id', $caregiverIds)
            ->where('response', 'accepted')
            ->selectRaw('caregiver_id, COUNT(*) as c')
            ->groupBy('caregiver_id')
            ->pluck('c', 'caregiver_id')
            ->all();
    }
}
