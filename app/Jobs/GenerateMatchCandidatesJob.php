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

        // 인력 풀 조회
        $caregivers = Caregiver::active()
            ->whereNotNull('license_verified_at')
            ->limit(50)
            ->get();

        if ($caregivers->isEmpty()) {
            Log::warning("매칭 요청 {$this->matchRequestId}: 활성 인력이 없습니다.");
            $matchRequest->update(['status' => 'expired']);
            return;
        }

        // AI 추천 호출
        $aiResult = $aiService->recommendMatch(
            requestId: $matchRequest->id,
            seniorFeatures: [
                'id' => $matchRequest->senior->id,
                'care_grade' => $matchRequest->senior->care_grade,
                'diseases' => $matchRequest->senior->diseases ?? [],
                'lat' => $matchRequest->senior->home_lat,
                'lng' => $matchRequest->senior->home_lng,
            ],
            caregiverPool: $caregivers->map(fn ($c) => [
                'id' => $c->id,
                'specialties' => $c->specialties ?? [],
                'rating_avg' => $c->rating_avg,
                'lat' => $c->base_lat,
                'lng' => $c->base_lng,
            ])->toArray(),
        );

        if (empty($aiResult['candidates'])) {
            Log::warning("매칭 요청 {$this->matchRequestId}: AI 추천 결과 없음");
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
}
