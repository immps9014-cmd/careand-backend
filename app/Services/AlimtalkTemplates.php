<?php

namespace App\Services;

use App\Models\User;

/**
 * 알림 종류 → 카카오 알림톡 템플릿 문안 (사업계획서 기능 6·32, 2026-09-28 구현계획 S4)
 *
 * 문안 원본: /root/caren/doc/외부연동/케어앤_알림톡_템플릿_초안_r1.0 (PMS SPEC-011).
 * 카카오는 승인된 문안과 글자까지 같아야 보내 준다 → 심사에서 문구가 바뀌면 여기도 같이 고칠 것.
 * 심사 통과 후 알리고가 준 템플릿 코드는 .env ALIMTALK_TEMPLATES="CAREN_MATCH_OK=TA_1234,..." 로 연결한다.
 * 개인정보: 대상자 이름은 성만 남기고 가린다(김○○님). 질환·주소·연락처는 넣지 않는다.
 */
class AlimtalkTemplates
{
    private const APP = 'https://caren.aiclaude.kr/app';

    /** 알림 종류 → 템플릿 키. 여기 없는 종류(기관 소속, 반려, 관리자용 등)는 앱 알림만 */
    public const BY_TYPE = [
        NotificationService::TYPE_MATCH_REQUEST_ASSIGNED => 'CAREN_MATCH_REQ',
        NotificationService::TYPE_MATCH_CONFIRMED => 'CAREN_MATCH_OK',
        NotificationService::TYPE_MATCH_REQUEST_EXPIRED => 'CAREN_MATCH_EXP',
        NotificationService::TYPE_CARE_STARTED => 'CAREN_CARE_START',
        NotificationService::TYPE_CARE_COMPLETED => 'CAREN_CARE_END',
        NotificationService::TYPE_CARE_SUMMARY_READY => 'CAREN_LOG_READY',
        NotificationService::TYPE_SAFETY_ALERT => 'CAREN_SAFETY',
        NotificationService::TYPE_PAYMENT_PAID => 'CAREN_PAY_OK',
        NotificationService::TYPE_SETTLEMENT_CONFIRMED => 'CAREN_SETTLE_OK',
        NotificationService::TYPE_SETTLEMENT_PAID => 'CAREN_SETTLE_PAID',
        NotificationService::TYPE_CAREGIVER_APPROVED => 'CAREN_CG_APPROVED',
        NotificationService::TYPE_CARE_REMINDER => 'CAREN_REMIND_24H',
    ];

    /** 심사 통과 후 받은 템플릿 코드 — 없으면 '' (스텁 기록만) */
    public static function code(string $key): string
    {
        foreach (explode(',', (string) config('services.alimtalk.templates', '')) as $pair) {
            [$k, $v] = array_pad(array_map('trim', explode('=', $pair, 2)), 2, '');
            if ($k === $key) {
                return $v;
            }
        }
        return '';
    }

    /**
     * @return array{key:string, subject:string, message:string, button:?array, fallback:string}|null
     */
    public static function build(string $type, User $user, array $p): ?array
    {
        $key = self::BY_TYPE[$type] ?? null;
        if (!$key) {
            return null;
        }
        $me = $user->name ?: '회원';
        $target = self::maskName($p['recipient_name'] ?? $p['senior_name'] ?? $p['target_name'] ?? null);
        $cg = $p['caregiver_name'] ?? '돌봄전문가';
        $when = $p['scheduled_at'] ?? '';

        [$subject, $body, $button] = match ($key) {
            'CAREN_MATCH_REQ' => ['새 돌봄 요청',
                "[케어앤] 새 돌봄 요청\n{$me} 선생님, 새 돌봄 요청이 도착했어요.\n"
                . '■ 서비스: ' . ($p['service_label'] ?? '돌봄') . "\n■ 일시: {$when}\n"
                . '■ 지역: ' . ($p['region'] ?? '앱에서 확인') . "\n■ 예상 보수: " . ($p['pay_label'] ?? '앱에서 확인') . "\n"
                . '5분 안에 수락 여부를 알려 주세요. 응답이 없으면 다음 후보에게 넘어갑니다.',
                ['요청 확인하기', '/home']],
            'CAREN_MATCH_OK' => ['매칭 확정',
                "[케어앤] 매칭 확정\n{$me}님, 요청하신 돌봄이 확정되었어요.\n"
                . "■ 대상: {$target}님\n■ 돌봄전문가: {$cg}\n■ 첫 방문: {$when}\n"
                . '결제를 마치면 방문 일정이 확정됩니다.',
                ['결제하기', '/payments/' . ($p['match_id'] ?? '')]],
            'CAREN_MATCH_EXP' => ['매칭 미성사 안내',
                "[케어앤] 매칭 미성사 안내\n{$me}님, {$when} 돌봄 요청이 예정 시각까지 매칭되지 않아 종료되었어요. 청구된 금액은 없습니다.\n"
                . '일정을 바꿔 다시 요청하시거나 고객센터로 문의해 주세요.',
                ['다시 요청하기', '/request/new']],
            'CAREN_CARE_START' => ['돌봄 시작',
                "[케어앤] 돌봄 시작\n{$cg} 돌봄전문가가 " . ($p['time'] ?? '') . "에 도착해 {$target}님 돌봄을 시작했어요.",
                ['실시간 확인', '/home']],
            'CAREN_CARE_END' => ['돌봄 완료',
                "[케어앤] 돌봄 완료\n{$target}님 오늘 돌봄이 " . ($p['time'] ?? '') . '에 끝났어요(' . (int) ($p['duration_min'] ?? 0) . '분). 케어일지는 정리되는 대로 보내 드릴게요.',
                null],
            'CAREN_LOG_READY' => ['케어일지 도착',
                "[케어앤] 케어일지 도착\n{$target}님의 " . ($p['date'] ?? now('Asia/Seoul')->format('n월 j일')) . ' 케어일지가 도착했어요. 식사·활동·특이사항을 확인해 보세요.',
                ['일지 보기', '/logs/' . ($p['session_id'] ?? '')]],
            'CAREN_SAFETY' => ['안전 알림',
                "[케어앤] 안전 알림\n{$target}님 오늘 돌봄 기록에 「" . ($p['alert_labels'] ?? '주의') . "」 관련 내용이 있어요. 상태를 확인해 주세요.\n"
                . '응급 상황이면 119 에 먼저 연락해 주세요.',
                ['기록 확인하기', '/logs/' . ($p['session_id'] ?? '')]],
            'CAREN_PAY_OK' => ['결제 완료',
                "[케어앤] 결제 완료\n{$me}님, " . ($p['service_label'] ?? '돌봄') . " 결제가 완료되었어요.\n"
                . '■ 결제 금액: ' . number_format((int) ($p['amount'] ?? 0)) . "원\n"
                . '■ 결제 수단: ' . ($p['method_label'] ?? '카드') . "\n■ 결제 일시: " . ($p['paid_at'] ?? ''),
                ['결제 내역', '/payments']],
            'CAREN_SETTLE_OK' => ['정산 확정',
                "[케어앤] 정산 확정\n{$me} 선생님, 이번 주 정산이 확정되었어요.\n"
                . '■ 지급 예정액: ' . number_format((int) ($p['net_amount'] ?? 0)) . "원(원천징수 3.3% 공제 후)\n"
                . '■ 지급 예정일: ' . ($p['pay_date'] ?? '명세서에서 확인') . "\n"
                . '명세서를 확인하고 이상이 있으면 24시간 안에 알려 주세요.',
                ['명세서 보기', '/settlements']],
            'CAREN_SETTLE_PAID' => ['정산금 입금',
                "[케어앤] 정산금 입금\n{$me} 선생님, " . number_format((int) ($p['net_amount'] ?? 0)) . '원이 등록 계좌로 입금되었어요. 수고 많으셨습니다.',
                null],
            'CAREN_REMIND_24H' => ['내일 방문 안내',
                "[케어앤] 내일 방문 안내\n{$me}님, 내일 돌봄 방문이 예정되어 있어요.\n"
                . "■ 일시: {$when}\n■ 대상: {$target}님\n■ 돌봄전문가: {$cg}\n"
                . '일정 변경이 필요하면 미리 알려 주세요.',
                ['일정 보기', '/schedule']],
            'CAREN_CG_APPROVED' => ['자격 심사 완료',
                "[케어앤] 자격 심사 완료\n{$me} 선생님, 자격 심사가 완료되었어요. 이제 돌봄 요청을 받을 수 있어요.",
                ['시작하기', '/home']],
        };

        $btn = $button ? ['name' => $button[0], 'url' => self::APP . $button[1]] : null;

        return [
            'key' => $key,
            'subject' => $subject,
            'message' => $body,
            'button' => $btn,
            // 대체 SMS/LMS 는 버튼이 없으니 링크를 본문 끝에 붙인다
            'fallback' => $body . ($btn ? "\n{$btn['url']}" : ''),
        ];
    }

    /** 김영희 → 김○○, 우리집(주소 별칭) → 우○○. 비어 있으면 '대상자' */
    public static function maskName(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '대상자';
        }
        return mb_strlen($name) <= 1 ? $name : mb_substr($name, 0, 1) . '○○';
    }
}
