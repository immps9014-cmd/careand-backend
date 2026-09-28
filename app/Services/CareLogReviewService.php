<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 위험 기반 AI 일지 검수 — 사업계획서 기능 22·29·41 (2026-09-28, 구현계획 S3).
 * 일지가 만들어질 때마다 AI 서비스의 검증 결과(verification)로 갈래를 정한다.
 *   · 안전 알림 있음 → 보호자·운영자에게 즉시 알림 + 검수 대기(사유 기록)
 *   · 사실성 위험·민감정보 → 검수 대기(사유 기록). 이미 승인된 세션이면 다시 검수 대기로(보호자 화면에서 내림)
 *   · 위험 없음 → 자동 승인, 보호자에게 즉시 전송(KPI 3 「케어일지 작성시간」 대기 제거)
 *   · 검증 결과 없음(AI 오류 등) → 지금처럼 검수 대기(보수적)
 */
class CareLogReviewService
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function route(int $sessionId, ?array $verification): void
    {
        try {
            $session = DB::table('care_sessions')->where('id', $sessionId)->first(['id', 'review_status', 'review_note']);
            if (!$session || $verification === null) {
                return;
            }
            $ctx = $this->context($sessionId);
            $alerts = $verification['alerts'] ?? [];
            $reasons = $verification['reasons'] ?? [];

            if ($alerts && $ctx) {
                $labels = implode(', ', array_unique(array_column($alerts, 'label')));
                $critical = collect($alerts)->contains(fn ($a) => ($a['severity'] ?? '') === 'critical');
                $payload = ['senior_name' => $ctx->recipient_name ?? '어르신', 'alert_labels' => $labels, 'session_id' => $sessionId, 'critical' => $critical];
                if ($ctx->guardian_user_id) {
                    $this->notifications->notify((int) $ctx->guardian_user_id, NotificationService::TYPE_SAFETY_ALERT, $payload);
                }
                $admins = DB::table('users')->where('role', 'admin')->where('status', 'active')->whereNull('deleted_at')->pluck('id')->all();
                $this->notifications->notifyBulk($admins, NotificationService::TYPE_SAFETY_ALERT, $payload);
            }

            if (!empty($verification['needs_review'])) {
                $note = '검수 필요 — ' . implode(' / ', $reasons);
                DB::table('care_sessions')->where('id', $sessionId)->update([
                    'review_status' => 'pending',
                    'review_note' => mb_substr($note, 0, 1000),
                    'updated_at' => now(),
                ]);
                return;
            }

            if ($session->review_status === 'pending') {
                DB::table('care_sessions')->where('id', $sessionId)->update([
                    'review_status' => 'approved',
                    'review_note' => '자동 승인 — 사실성 검증·안전 알림 이상 없음',
                    'reviewed_by' => null,
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);
                // KPI 3 종료 시각 = 보호자에게 전송된 시각(최초 승인)
                DB::table('care_sessions')->where('id', $sessionId)->whereNull('log_sent_at')->update(['log_sent_at' => now()]);
                if ($ctx?->guardian_user_id) {
                    $this->notifications->notify((int) $ctx->guardian_user_id, NotificationService::TYPE_CARE_SUMMARY_READY,
                        ['senior_name' => $ctx->recipient_name ?? '어르신', 'session_id' => $sessionId]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('일지 검수 분기 실패(세션 ' . $sessionId . '): ' . $e->getMessage());
        }
    }

    private function context(int $sessionId): ?object
    {
        return DB::table('care_sessions as cs')
            ->join('matches as m', 'm.id', '=', 'cs.match_id')
            ->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->join('guardians as g', 'g.id', '=', 'r.guardian_id')
            ->leftJoin('seniors as s', 's.id', '=', 'r.senior_id')
            ->leftJoin('nursing_patients as np', 'np.id', '=', 'r.nursing_patient_id')
            ->leftJoin('service_addresses as sa', 'sa.id', '=', 'r.service_address_id')
            ->leftJoin('postpartum_clients as pp', 'pp.id', '=', 'r.postpartum_client_id')
            ->leftJoin('children as ch', 'ch.id', '=', 'r.childcare_child_id')
            ->leftJoin('mental_care_clients as mcc', 'mcc.id', '=', 'r.mental_care_client_id')
            ->where('cs.id', $sessionId)
            ->selectRaw('g.user_id as guardian_user_id, COALESCE(s.name, np.name, pp.name, ch.name, mcc.name, sa.label) as recipient_name')
            ->first();
    }
}
