<?php

namespace App\Jobs;

use App\Models\AiLogSummary;
use App\Models\VoiceLog;
use App\Services\External\AiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 음성 일지 STT + LLM 요약 비동기 처리.
 * 케어 세션에 업로드된 음성을 ai-service로 보내 전사(STT)하고,
 * 대상자(어르신/간병환자 등, 도메인별 MatchRequest::recipient() 참조) 컨텍스트와 함께
 * 보호자/의료 버전 요약을 생성·저장한다.
 * (이전에는 CareSessionController::processVoiceLogSync 로 동기 처리 — STT가
 *  CPU에서 수 초 블로킹되어 운영 부적합. 큐 워커로 이관.)
 */
class ProcessVoiceLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public int $voiceLogId)
    {
    }

    public function handle(AiService $aiService): void
    {
        $voiceLog = VoiceLog::find($this->voiceLogId);
        if (! $voiceLog) {
            Log::warning("음성 처리: VoiceLog {$this->voiceLogId} 없음");
            return;
        }

        $session = $voiceLog->session()->with('match.request')->first();
        if (! $session) {
            Log::warning("음성 처리: 세션 없음 (VoiceLog {$this->voiceLogId})");
            $voiceLog->update(['status' => 'failed', 'error_message' => '세션 없음']);
            return;
        }

        $voiceLog->update(['status' => 'transcribing']);

        try {
            // 대상자(수혜자) 추상화 — senior 하드코딩 시 nursing/postpartum 등 도메인은
            // recipient가 null이라 아래서 속성 접근 시 예외로 죽었음(recipientFeatures()가
            // 도메인별 분기를 이미 갖고 있어 재사용, MatchRequest.php 참조).
            $request = $session->match->request;
            $features = $request->recipientFeatures();
            if (! $features) {
                Log::warning("음성 처리: 대상자 정보 없음 (VoiceLog {$this->voiceLogId}, domain={$request->service_domain})");
            }
            $diseases = $features['diseases'] ?? [];

            // 1. STT (대상자 질병 연관 어휘로 개인화)
            $sttResult = $aiService->transcribe($voiceLog->audio_url, diseases: $diseases);
            $voiceLog->update([
                'stt_text' => $sttResult['stt_text'],
                'stt_confidence' => $sttResult['confidence'],
                'status' => 'transcribed',
            ]);

            // 2. LLM 요약
            $summary = $aiService->summarizeCareLog(
                sttText: $sttResult['stt_text'],
                seniorContext: [
                    'name' => $request->recipientName() ?? '대상자',
                    'care_grade' => $features['care_grade'] ?? null,
                    'diseases' => $diseases,
                ]
            );

            AiLogSummary::create([
                'session_id' => $session->id,
                'voice_log_id' => $voiceLog->id,
                'guardian_version' => $summary['guardian_version'],
                'medical_version' => $summary['medical_version'],
                'categorized' => $summary['categorized'],
                'confidence' => $summary['confidence'],
                'llm_model' => $summary['model'],
                'generated_at' => now(),
            ]);

            $voiceLog->update(['status' => 'summarized']);

            // TODO: 보호자에게 FCM 푸시 (CARE_SUMMARY_READY)
        } catch (\Throwable $e) {
            Log::error("음성 처리 실패(VoiceLog {$this->voiceLogId}): {$e->getMessage()}");
            $voiceLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
