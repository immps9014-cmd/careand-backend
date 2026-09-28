<?php

namespace App\Services;

use App\Jobs\SendAlimtalkJob;
use App\Models\Notification;
use App\Models\User;
use App\Services\External\FcmService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 알림 통합 서비스
 *
 * - DB 기록 (notifications 테이블) + FCM 푸시 동시 처리
 * - 알림 타입별 템플릿 관리
 * - 카카오 알림톡(실패 시 SMS) — AlimtalkTemplates::BY_TYPE 에 있는 종류만, 관리자 제외, 커밋 뒤 큐 발송(2026-09-28 S4)
 *
 * 사용 예:
 *   $svc->notify($userId, NotificationService::TYPE_MATCH_CONFIRMED, [
 *       'senior_name' => '홍어머님',
 *       'caregiver_name' => '이정희',
 *       'match_id' => 123,
 *   ]);
 */
class NotificationService
{
    // 알림 타입 상수
    public const TYPE_MATCH_REQUEST_ASSIGNED = 'MATCH_REQUEST_ASSIGNED';
    public const TYPE_MATCH_CONFIRMED = 'MATCH_CONFIRMED';
    public const TYPE_CARE_STARTED = 'CARE_STARTED';
    public const TYPE_CARE_COMPLETED = 'CARE_COMPLETED';
    public const TYPE_CARE_SUMMARY_READY = 'CARE_SUMMARY_READY';
    public const TYPE_ANOMALY_HIGH = 'ANOMALY_HIGH';
    public const TYPE_ANOMALY_CRITICAL = 'ANOMALY_CRITICAL';
    public const TYPE_SAFETY_ALERT = 'SAFETY_ALERT';   // 돌봄 기록의 안전 알림 10종(기능 41)
    public const TYPE_PAYMENT_PAID = 'PAYMENT_PAID';
    public const TYPE_PAYMENT_FAILED = 'PAYMENT_FAILED';
    public const TYPE_SETTLEMENT_CONFIRMED = 'SETTLEMENT_CONFIRMED';
    public const TYPE_SETTLEMENT_PAID = 'SETTLEMENT_PAID';
    public const TYPE_MATCH_REQUEST_EXPIRED = 'MATCH_REQUEST_EXPIRED';
    public const TYPE_ORG_CAREGIVER_JOINED = 'ORG_CAREGIVER_JOINED';
    public const TYPE_ORG_CAREGIVER_REMOVED = 'ORG_CAREGIVER_REMOVED';
    public const TYPE_CAREGIVER_APPROVED = 'CAREGIVER_APPROVED';
    public const TYPE_CAREGIVER_REJECTED = 'CAREGIVER_REJECTED';
    public const TYPE_CAREGIVER_APPLIED = 'CAREGIVER_APPLIED';
    public const TYPE_REVIEW_REQUEST = 'REVIEW_REQUEST';   // 케어 종료 → 보호자 후기 요청(기능 7)
    public const TYPE_REVIEW_LOW = 'REVIEW_LOW';
    public const TYPE_CAREGIVER_DOC_REJECTED = 'CAREGIVER_DOC_REJECTED';   // 서류 반려(기능 20)
    public const TYPE_MATCH_OFFER_TIMEOUT = 'MATCH_OFFER_TIMEOUT';         // 지정 후보 무응답 자동 거절 → 보호자(기능 10)
    public const TYPE_MATCH_UNMATCHED_ALERT = 'MATCH_UNMATCHED_ALERT';     // 장시간 미매칭 → 매칭 담당 관리자(기능 18)
    public const TYPE_CARE_REMINDER = 'CARE_REMINDER';                     // 방문 전 리마인더 → 보호자·돌봄전문가(기능 11)           // 2점 이하 후기 → CS 관리자(기능 24)

    public function __construct(private FcmService $fcm)
    {
    }

    /**
     * 알림 발송 (DB 기록 + FCM 푸시 동시)
     */
    public function notify(int $userId, string $type, array $payload = []): ?Notification
    {
        $user = User::find($userId);
        if (!$user) {
            Log::warning('알림 발송 실패: 사용자 없음', ['user_id' => $userId, 'type' => $type]);
            return null;
        }

        $template = $this->getTemplate($type, $payload);
        if (!$template) {
            Log::warning('알림 발송 실패: 미정의 타입', ['type' => $type]);
            return null;
        }

        // DB + FCM 동시 처리 (트랜잭션)
        $notification = DB::transaction(function () use ($user, $type, $template, $payload) {
            $notification = Notification::create([
                'user_id' => $user->id,
                'type' => $type,
                'title' => $template['title'],
                'body' => $template['body'],
                'data' => $payload,
                'sent_at' => now(),
            ]);

            // FCM 푸시 (token이 있는 경우만)
            if ($user->fcm_token) {
                $result = $this->fcm->send(
                    fcmToken: $user->fcm_token,
                    title: $template['title'],
                    body: $template['body'],
                    data: array_merge(['notification_id' => (string) $notification->id, 'type' => $type], $payload),
                );

                if (!$result['success']) {
                    Log::warning('FCM 푸시 실패', [
                        'user_id' => $user->id,
                        'type' => $type,
                        'error' => $result['error'],
                    ]);
                }
            }

            return $notification;
        });

        if ($user->role !== 'admin' && isset(AlimtalkTemplates::BY_TYPE[$type])) {
            try {
                SendAlimtalkJob::dispatch($user->id, $type, $payload, $notification->id)->afterCommit();
            } catch (\Throwable $e) {
                Log::warning('알림톡 큐 등록 실패', ['user_id' => $user->id, 'type' => $type, 'error' => $e->getMessage()]);
            }
        }

        return $notification;
    }

    /**
     * 매칭 한 건의 알림 문구용 정보 — 보호자·돌봄전문가 user_id, 대상자 이름(도메인 무관), 서비스명, 예정 일시(KST).
     * 매칭 확정·출퇴근·결제 알림이 같이 쓴다(2026-09-28 S4 — 이전엔 이 종류들이 정의만 되고 발송되지 않았음).
     */
    public function matchContext(int $matchId): ?object
    {
        $row = DB::table('matches as m')
            ->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->join('guardians as g', 'g.id', '=', 'r.guardian_id')
            ->join('caregivers as c', 'c.id', '=', 'm.caregiver_id')
            ->join('users as cu', 'cu.id', '=', 'c.user_id')
            ->leftJoin('seniors as s', 's.id', '=', 'r.senior_id')
            ->leftJoin('nursing_patients as np', 'np.id', '=', 'r.nursing_patient_id')
            ->leftJoin('service_addresses as sa', 'sa.id', '=', 'r.service_address_id')
            ->leftJoin('postpartum_clients as pp', 'pp.id', '=', 'r.postpartum_client_id')
            ->leftJoin('children as ch', 'ch.id', '=', 'r.childcare_child_id')
            ->leftJoin('mental_care_clients as mcc', 'mcc.id', '=', 'r.mental_care_client_id')
            ->where('m.id', $matchId)
            ->selectRaw('m.id as match_id, m.scheduled_start, r.service_domain, g.user_id as guardian_user_id,
                c.user_id as caregiver_user_id, cu.name as caregiver_name,
                COALESCE(s.name, np.name, pp.name, ch.name, mcc.name, sa.label) as recipient_name')
            ->first();
        if ($row) {
            $row->service_label = \App\Support\ServiceDomains::label((string) $row->service_domain);
            $row->scheduled_at = $row->scheduled_start
                ? \Illuminate\Support\Carbon::parse($row->scheduled_start, 'UTC')->setTimezone('Asia/Seoul')->format('n월 j일 H:i')
                : '';
        }
        return $row;
    }

    /** 해당 관리자 영역(config/admin_rbac.php)을 볼 수 있는 활성 관리자 user_id 목록 */
    public function adminsFor(string $area): array
    {
        $levels = config("admin_rbac.areas.$area.read", config('admin_rbac.default.read'));
        return DB::table('users as u')->join('admins as a', 'a.user_id', '=', 'u.id')
            ->where('u.role', 'admin')->where('u.status', 'active')->whereNull('u.deleted_at')
            ->whereIn('a.permission_level', $levels)->pluck('u.id')->map(fn ($i) => (int) $i)->all();
    }

    /** 알림 실패가 본 처리(매칭·출퇴근·결제)를 깨지 않도록 감싼 발송 */
    public function notifySafely(?int $userId, string $type, array $payload = []): void
    {
        if (!$userId) {
            return;
        }
        try {
            $this->notify($userId, $type, $payload);
        } catch (\Throwable $e) {
            Log::warning('알림 발송 예외', ['user_id' => $userId, 'type' => $type, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 다중 사용자 일괄 발송
     */
    public function notifyBulk(array $userIds, string $type, array $payload = []): int
    {
        $sent = 0;
        foreach ($userIds as $userId) {
            if ($this->notify($userId, $type, $payload)) {
                $sent++;
            }
        }
        return $sent;
    }

    /**
     * 알림 템플릿 (한국어)
     */
    private function getTemplate(string $type, array $payload): ?array
    {
        return match ($type) {
            self::TYPE_MATCH_REQUEST_ASSIGNED => isset($payload['offer_minutes']) ? [
                // 보호자가 이 돌봄전문가를 지정 — 응답 시한 있음(기능 10)
                'title' => '보호자가 선생님을 지정했어요',
                'body' => sprintf(
                    '%s %s 요청이에요. %d분 안에 수락해 주세요. 응답이 없으면 자동으로 넘어가요.',
                    $payload['service_label'] ?? '돌봄',
                    $payload['scheduled_at'] ?? '',
                    (int) $payload['offer_minutes']
                ),
            ] : [
                'title' => '새 매칭 요청',
                'body' => sprintf(
                    '%s %s 요청이 도착했어요. (%s)',
                    // 도메인 무관 대상자명. 구 페이로드(senior_name)도 하위호환.
                    $payload['recipient_name'] ?? $payload['senior_name'] ?? '대상자',
                    $payload['service_label'] ?? '케어',
                    $payload['scheduled_at'] ?? ''
                ),
            ],
            self::TYPE_MATCH_REQUEST_EXPIRED => [
                'title' => '매칭 미성사 안내',
                'body' => sprintf(
                    '%s 케어 요청(%s)이 매칭되지 않아 만료되었어요. 다시 요청해 주세요.',
                    $payload['target_name'] ?? '어르신',
                    $payload['scheduled_at'] ?? ''
                ),
            ],
            self::TYPE_MATCH_CONFIRMED => [
                'title' => '매칭 확정',
                'body' => sprintf(
                    '%s 인력이 케어를 수락했어요.',
                    $payload['caregiver_name'] ?? '인력'
                ),
            ],
            self::TYPE_CARE_STARTED => [
                'title' => '케어 시작',
                'body' => sprintf(
                    '%s 인력이 %s 어르신 케어를 시작했어요.',
                    $payload['caregiver_name'] ?? '인력',
                    $payload['senior_name'] ?? '어르신'
                ),
            ],
            self::TYPE_CARE_COMPLETED => [
                'title' => '케어 완료',
                'body' => sprintf(
                    '%s 어르신 케어가 완료되었어요. (%d분)',
                    $payload['senior_name'] ?? '어르신',
                    $payload['duration_min'] ?? 0
                ),
            ],
            self::TYPE_CARE_SUMMARY_READY => [
                'title' => '케어 일지 도착',
                'body' => sprintf(
                    '%s 어르신의 오늘 케어 일지가 정리되었어요.',
                    $payload['senior_name'] ?? '어르신'
                ),
            ],
            self::TYPE_ANOMALY_HIGH => [
                'title' => 'AI 이상징후 감지',
                'body' => sprintf(
                    '⚠ %s 어르신 %s 위험 %d점 — 확인이 필요해요.',
                    $payload['senior_name'] ?? '어르신',
                    $payload['risk_type_ko'] ?? '건강',
                    (int) ($payload['risk_score'] ?? 0)
                ),
            ],
            self::TYPE_SAFETY_ALERT => [
                'title' => ($payload['critical'] ?? false) ? '🚨 안전 알림' : '안전 알림',
                'body' => sprintf(
                    '%s 돌봄 기록에 「%s」 관련 내용이 있어요. 상태를 확인해 주세요.',
                    $payload['senior_name'] ?? '어르신',
                    $payload['alert_labels'] ?? '주의'
                ),
            ],
            self::TYPE_ANOMALY_CRITICAL => [
                'title' => '🚨 긴급 건강 알림',
                'body' => sprintf(
                    '%s 어르신 응급 상황 가능성 — 즉시 의료진 상담을 권장합니다.',
                    $payload['senior_name'] ?? '어르신'
                ),
            ],
            self::TYPE_PAYMENT_PAID => [
                'title' => '결제 완료',
                'body' => sprintf('%s원 결제가 완료되었어요.', number_format($payload['amount'] ?? 0)),
            ],
            self::TYPE_PAYMENT_FAILED => [
                'title' => '결제 실패',
                'body' => sprintf('결제에 실패했어요: %s', $payload['reason'] ?? '카드를 확인해주세요'),
            ],
            self::TYPE_SETTLEMENT_CONFIRMED => [
                'title' => '정산서 확정',
                'body' => sprintf(
                    '이번 주 정산 %s원이 확정되었어요.%s',
                    number_format($payload['net_amount'] ?? 0),
                    isset($payload['days_until_paid']) ? sprintf(' (D-%d)', $payload['days_until_paid']) : ''
                ),
            ],
            self::TYPE_SETTLEMENT_PAID => [
                'title' => '정산 입금 완료',
                'body' => sprintf(
                    '%s원이 입금되었어요. 수고하셨습니다.',
                    number_format($payload['net_amount'] ?? 0)
                ),
            ],
            self::TYPE_ORG_CAREGIVER_JOINED => [
                'title' => '기관 소속 연결',
                'body' => sprintf('%s 기관에 소속 간병인으로 연결되었어요.', $payload['org_name'] ?? '기관'),
            ],
            self::TYPE_ORG_CAREGIVER_REMOVED => [
                'title' => '기관 소속 해제',
                'body' => sprintf('%s 기관 소속이 해제되었어요.', $payload['org_name'] ?? '기관'),
            ],
            self::TYPE_CAREGIVER_APPROVED => [
                'title' => '자격 심사 완료',
                'body' => sprintf(
                    '%s님, 자격 심사가 완료되었어요. 이제 케어 활동을 시작할 수 있어요!',
                    $payload['caregiver_name'] ?? '돌봄전문가'
                ),
            ],
            self::TYPE_CAREGIVER_REJECTED => [
                'title' => '자격 심사 결과 안내',
                'body' => sprintf(
                    '자격 심사가 반려되었어요. 사유: %s',
                    $payload['reason'] ?? '자격 정보를 다시 확인해주세요.'
                ),
            ],
            self::TYPE_CAREGIVER_APPLIED => [
                'title' => '돌봄전문가 지원 도착',
                'body' => sprintf(
                    '%s 돌봄전문가가 %s 케어에 지원했어요. 후보를 확인해보세요.',
                    $payload['caregiver_name'] ?? '돌봄전문가',
                    $payload['recipient_name'] ?? '대상자'
                ),
            ],
            self::TYPE_MATCH_OFFER_TIMEOUT => [
                'title' => '다른 돌봄전문가를 선택해 주세요',
                'body' => sprintf(
                    '%s 돌봄전문가가 %d분 안에 응답하지 않아 요청이 넘어왔어요. 후보 목록에서 다른 분을 선택해 주세요.',
                    $payload['caregiver_name'] ?? '지정한',
                    (int) ($payload['offer_minutes'] ?? 5)
                ),
            ],
            self::TYPE_MATCH_UNMATCHED_ALERT => [
                'title' => '⚠ 장시간 미매칭 요청',
                'body' => sprintf(
                    '요청 #%d(%s, %s)이 %d시간째 매칭되지 않았어요. 수동 매칭을 검토해 주세요.',
                    (int) ($payload['request_id'] ?? 0),
                    $payload['service_label'] ?? '돌봄',
                    $payload['scheduled_at'] ?? '',
                    (int) ($payload['hours'] ?? 6)
                ),
            ],
            self::TYPE_CARE_REMINDER => [
                'title' => '내일 방문 안내',
                'body' => sprintf(
                    '%s %s 돌봄 방문이 예정되어 있어요. (돌봄전문가 %s)',
                    $payload['scheduled_at'] ?? '',
                    $payload['recipient_name'] ?? '대상자',
                    $payload['caregiver_name'] ?? ''
                ),
            ],
            self::TYPE_CAREGIVER_DOC_REJECTED => [
                'title' => '서류 보완 요청',
                'body' => sprintf('제출하신 %s를 다시 올려 주세요. 사유: %s', $payload['doc_label'] ?? '서류', $payload['reason'] ?? '확인 불가'),
            ],
            self::TYPE_REVIEW_REQUEST => [
                'title' => '케어는 어떠셨나요?',
                'body' => sprintf(
                    '%s 돌봄전문가와의 케어가 끝났어요. 만족도를 남겨 주시면 서비스 개선에 큰 도움이 돼요.',
                    $payload['caregiver_name'] ?? '돌봄전문가'
                ),
            ],
            self::TYPE_REVIEW_LOW => [
                'title' => '⚠ 낮은 평점 후기',
                'body' => sprintf(
                    '%s 돌봄전문가에게 %d점 후기가 등록됐어요. CS 확인·답변이 필요해요.',
                    $payload['caregiver_name'] ?? '돌봄전문가',
                    (int) ($payload['rating'] ?? 0)
                ),
            ],
            default => null,
        };
    }
}
