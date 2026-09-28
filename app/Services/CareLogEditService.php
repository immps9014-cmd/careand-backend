<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * 일지 본문 수정 — 기능 14(돌봄전문가)·22(운영자) (2026-09-29, S5 잔여)
 * 첫 수정 때 AI 원본을 보관한다. 돌봄전문가가 고친 문장은 원문 대조를 거치지 않았으므로 운영자 검수로 넘긴다.
 */
class CareLogEditService
{
    /** @return array{ok:bool, code?:string, message:string} */
    public function edit(int $sessionId, int $userId, string $role, ?string $guardian, ?string $medical, ?string $reason): array
    {
        $summary = DB::table('ai_log_summaries')->where('session_id', $sessionId)->orderByDesc('id')->first();
        if (!$summary) {
            return ['ok' => false, 'code' => 'SUMMARY_NOT_READY', 'message' => '아직 만들어진 일지가 없어요.'];
        }
        $changed = ($guardian !== null && $guardian !== $summary->guardian_version)
            || ($medical !== null && $medical !== $summary->medical_version);
        if (!$changed) {
            return ['ok' => true, 'message' => '바뀐 내용이 없어요.'];
        }
        DB::table('ai_log_summaries')->where('id', $summary->id)->update([
            'guardian_original' => $summary->guardian_original ?? $summary->guardian_version,
            'medical_original' => $summary->medical_original ?? $summary->medical_version,
            'guardian_version' => $guardian ?? $summary->guardian_version,
            'medical_version' => $medical ?? $summary->medical_version,
            'edited_by' => $userId,
            'edited_role' => $role,
            'edit_reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            'edited_at' => now(),
            'updated_at' => now(),
        ]);
        if ($role === 'caregiver') {
            // 돌봄전문가 수정본은 검수 대기로 — 보호자에게 가기 전 운영자가 본다
            DB::table('care_sessions')->where('id', $sessionId)->update([
                'review_status' => 'pending',
                'review_note' => mb_substr('돌봄전문가 수정 — 검수 필요' . ($reason ? " ({$reason})" : ''), 0, 1000),
                'updated_at' => now(),
            ]);
        }
        return ['ok' => true, 'message' => $role === 'caregiver' ? '수정했어요. 운영팀 확인 후 보호자에게 전달돼요.' : '본문을 수정했어요.'];
    }
}
