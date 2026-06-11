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
 * 어르신 컨텍스트와 함께 보호자/의료 버전 요약을 생성·저장한다.
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

        $session = $voiceLog->session()->with('match.request.senior')->first();
        if (! $session) {
            Log::warning("음성 처리: 세션 없음 (VoiceLog {$this->voiceLogId})");
            $voiceLog->update(['status' => 'failed', 'error_message' => '세션 없음']);
            return;
        }

        $voiceLog->update(['status' => 'transcribing']);

        try {
            // 1. STT
            $sttResult = $aiService->transcribe($voiceLog->audio_url);
            $voiceLog->update([
                'stt_text' => $sttResult['stt_text'],
                'stt_confidence' => $sttResult['confidence'],
                'status' => 'transcribed',
            ]);

            // 2. LLM 요약
            $senior = $session->match->request->senior;

            $summary = $aiService->summarizeCareLog(
                sttText: $sttResult['stt_text'],
                seniorContext: [
                    'name' => $senior->name,
                    'care_grade' => $senior->care_grade,
                    'diseases' => $senior->diseases ?? [],
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
