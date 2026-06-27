<?php

namespace App\Jobs;

use App\Models\Caregiver;
use App\Models\MatchCandidate;
use App\Models\MatchRequest;
use App\Services\External\AiService;
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
            $matchRequest->update(['status' => 'expired']);
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
            return;
        }

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

        // TODO: 보호자에게 FCM 푸시 (CANDIDATES_READY)
        Log::info("매칭 요청 {$this->matchRequestId}: " . count($aiResult['candidates']) . "명 후보 산출 완료");
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
            'housekeeping' => 'service_address_id',
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
