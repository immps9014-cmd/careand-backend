<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * #28 C:Writer LLM 일지 자동 생성
 * 케어 세션 종료 시 활동기록을 ai-service로 보내 보호자 톤 일지를 생성·저장한다.
 */
class GenerateCareLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public int $sessionId)
    {
    }

    public function handle(): void
    {
        $session = DB::table('care_sessions as cs')
            ->leftJoin('matches as m', 'm.id', '=', 'cs.match_id')
            ->leftJoin('match_requests as r', 'r.id', '=', 'm.request_id')
            ->leftJoin('seniors as s', 's.id', '=', 'r.senior_id')
            ->where('cs.id', $this->sessionId)
            ->select('cs.id', 'cs.duration_min', 'r.service_domain', 's.name as senior_name')
            ->first();

        if (! $session) {
            Log::warning("일지 생성: 세션 {$this->sessionId} 없음");
            return;
        }

        // 이미 생성됐으면 스킵
        if (DB::table('ai_log_summaries')->where('session_id', $this->sessionId)->exists()) {
            return;
        }

        $activities = DB::table('care_activities')
            ->where('session_id', $this->sessionId)
            ->get(['category', 'memo'])
            ->map(fn ($a) => ['category' => $a->category, 'memo' => $a->memo])
            ->toArray();

        $aiUrl = config('services.ai.base_url', 'http://localhost:8001');

        try {
            $resp = Http::timeout(40)
                ->withToken(config('services.ai.token') ?? '')
                ->post("{$aiUrl}/care-log/generate", [
                    'session_id' => $session->id,
                    'service_domain' => $session->service_domain,
                    'senior_name' => $session->senior_name ?? '어르신',
                    'duration_min' => (int) $session->duration_min,
                    'activities' => $activities,
                ]);

            if (! $resp->successful()) {
                Log::error("일지 생성 실패(세션 {$this->sessionId}): HTTP {$resp->status()}");
                return;
            }

            $d = $resp->json();
            $now = now();
            DB::table('ai_log_summaries')->insert([
                'session_id' => $session->id,
                'guardian_version' => $d['guardian_version'] ?? '',
                'medical_version' => $d['medical_version'] ?? '',
                'categorized' => json_encode($d['categorized'] ?? [], JSON_UNESCAPED_UNICODE),
                'confidence' => $d['confidence'] ?? 0.9,
                'llm_model' => $d['model'] ?? 'stub-claude',
                'generated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Log::info("일지 생성 완료: 세션 {$this->sessionId}");
        } catch (\Throwable $e) {
            Log::error("일지 생성 예외(세션 {$this->sessionId}): {$e->getMessage()}");
        }
    }
}
