<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 매칭 시간 규칙 감시 — 매분(Kernel). 기능 10·11·18(2026-09-28, 구현계획 S5). 기준값은 config/matching_rules.php
 *  ① 지정 후보 무응답: offer_expires_at 지난 pending 후보 → expired(자동 거절), 요청을 open 으로 되돌리고 보호자 알림
 *  ② 장시간 미매칭: 요청 후 N시간 지난 open/matching 요청 → 매칭 담당 관리자에게 1회 알림
 *  ③ 방문 전 리마인더: N시간 안에 시작하는 예정 세션 → 보호자·돌봄전문가에게 1회 알림(알림톡 CAREN_REMIND_24H)
 *  ④ 지각: 시작 N분 뒤에도 출근 기록 없음 → 돌봄전문가·보호자 1회(2026-10-05)
 *  ⑤ 노쇼 의심: 시작 M분 뒤에도 출근 기록 없음 → 케어 진행 담당 관리자·보호자 1회. 상태는 운영팀이 확인 후 처리
 * 각 단계는 조건부 UPDATE 로 먼저 표시한 행만 알림 → 겹쳐 돌아도 두 번 보내지 않는다.
 */
class MatchingWatch extends Command
{
    protected $signature = 'matching:watch {--dry-run : 변경·알림 없이 대상 수만}';
    protected $description = '매칭 응답 시한·장시간 미매칭·방문 전 리마인더 처리';

    public function handle(NotificationService $svc): int
    {
        $dry = (bool) $this->option('dry-run');
        $counts = ['offer_timeout' => 0, 'unmatched_alert' => 0, 'reminders' => 0, 'late' => 0, 'noshow' => 0];

        // ① 지정 후보 무응답
        $offers = DB::table('match_candidates as mc')
            ->join('match_requests as r', 'r.id', '=', 'mc.request_id')
            ->join('guardians as g', 'g.id', '=', 'r.guardian_id')
            ->join('caregivers as c', 'c.id', '=', 'mc.caregiver_id')
            ->join('users as cu', 'cu.id', '=', 'c.user_id')
            ->where('mc.response', 'pending')->whereNotNull('mc.offer_expires_at')->where('mc.offer_expires_at', '<', now())
            ->get(['mc.id', 'mc.request_id', 'r.status as request_status', 'g.user_id as guardian_user_id', 'cu.name as caregiver_name']);
        foreach ($offers as $o) {
            if ($dry) { $counts['offer_timeout']++; continue; }
            $n = DB::table('match_candidates')->where('id', $o->id)->where('response', 'pending')
                ->update(['response' => 'expired', 'responded_at' => now(), 'updated_at' => now()]);
            if (!$n) {
                continue;   // 그 사이 수락됨
            }
            $counts['offer_timeout']++;
            DB::table('match_requests')->where('id', $o->request_id)->where('status', 'matching')->update(['status' => 'open', 'updated_at' => now()]);
            if ($o->request_status === 'matching') {
                $svc->notifySafely((int) $o->guardian_user_id, NotificationService::TYPE_MATCH_OFFER_TIMEOUT, [
                    'request_id' => (int) $o->request_id, 'caregiver_name' => $o->caregiver_name,
                    'offer_minutes' => (int) config('matching_rules.offer_timeout_min', 5),
                ]);
            }
        }

        // ② 장시간 미매칭
        $hours = (int) config('matching_rules.unmatched_alert_hours', 6);
        $stale = DB::table('match_requests')->whereIn('status', ['open', 'matching'])->whereNull('unmatched_alerted_at')
            ->where('created_at', '<', now()->subHours($hours))->where('scheduled_start', '>', now())
            ->get(['id', 'service_domain', 'scheduled_start']);
        $admins = $stale->isNotEmpty() ? $svc->adminsFor('matching') : [];
        foreach ($stale as $r) {
            if ($dry) { $counts['unmatched_alert']++; continue; }
            if (!DB::table('match_requests')->where('id', $r->id)->whereNull('unmatched_alerted_at')->update(['unmatched_alerted_at' => now()])) {
                continue;
            }
            $counts['unmatched_alert']++;
            foreach ($admins as $a) {
                $svc->notifySafely($a, NotificationService::TYPE_MATCH_UNMATCHED_ALERT, [
                    'request_id' => (int) $r->id, 'hours' => $hours,
                    'service_label' => \App\Support\ServiceDomains::label((string) $r->service_domain),
                    'scheduled_at' => Carbon::parse($r->scheduled_start, 'UTC')->setTimezone('Asia/Seoul')->format('n월 j일 H:i'),
                ]);
            }
        }

        // ③ 방문 전 리마인더 — 1시간 안에 시작하는 건은 늦은 리마인더라 보내지 않음
        $before = (int) config('matching_rules.remind_before_hours', 24);
        $sessions = DB::table('care_sessions')->where('status', 'scheduled')->whereNull('reminder_sent_at')
            ->whereBetween('scheduled_start', [now()->addHour(), now()->addHours($before)])
            ->get(['id', 'match_id', 'scheduled_start']);
        foreach ($sessions as $s) {
            if ($dry) { $counts['reminders']++; continue; }
            if (!DB::table('care_sessions')->where('id', $s->id)->whereNull('reminder_sent_at')->update(['reminder_sent_at' => now()])) {
                continue;
            }
            $ctx = $svc->matchContext((int) $s->match_id);
            if (!$ctx) {
                continue;
            }
            $counts['reminders']++;
            $payload = [
                'session_id' => (int) $s->id, 'match_id' => (int) $s->match_id,
                'scheduled_at' => Carbon::parse($s->scheduled_start, 'UTC')->setTimezone('Asia/Seoul')->format('n월 j일 H:i'),
                'recipient_name' => $ctx->recipient_name, 'caregiver_name' => $ctx->caregiver_name,
            ];
            // 미결제면 보호자에겐 방문 안내 대신 결제 안내 — 결제 전엔 출근이 막힌다(CareSessionController::checkin)
            $paid = \App\Support\MatchPaid::is((int) $s->match_id);
            $svc->notifySafely((int) $ctx->guardian_user_id, $paid ? NotificationService::TYPE_CARE_REMINDER : NotificationService::TYPE_PAYMENT_DUE, $payload);
            $svc->notifySafely((int) $ctx->caregiver_user_id, NotificationService::TYPE_CARE_REMINDER, $payload);
        }

        // ④⑤ 지각·노쇼 의심 — 하루 넘게 지난 건은 보지 않는다(옛 데이터 일괄 알림 방지)
        foreach (['late' => ['late_alert_minutes', 15, 'late_alerted_at'], 'noshow' => ['noshow_alert_minutes', 60, 'noshow_alerted_at']] as $k => [$cfg, $def, $col]) {
            $min = (int) config("matching_rules.$cfg", $def);
            $rows = DB::table('care_sessions')->where('status', 'scheduled')->whereNull('actual_start')->whereNull($col)
                ->where('scheduled_start', '<', now()->subMinutes($min))->where('scheduled_start', '>', now()->subDay())
                ->get(['id', 'match_id', 'scheduled_start']);
            $admins = $k === 'noshow' && $rows->isNotEmpty() ? $svc->adminsFor('care-sessions') : [];
            foreach ($rows as $s) {
                if (!DB::table('matches')->where('id', $s->match_id)->whereIn('status', ['confirmed', 'in_progress'])->exists()) {
                    continue;   // 취소된 매칭의 남은 일정
                }
                if ($dry) { $counts[$k]++; continue; }
                $upd = [$col => now()];
                if ($k === 'noshow') {
                    $upd['late_alerted_at'] = DB::raw('COALESCE(late_alerted_at, NOW())');   // 노쇼를 먼저 잡으면 지각 알림은 건너뛴다
                }
                if (!DB::table('care_sessions')->where('id', $s->id)->whereNull($col)->update($upd)) {
                    continue;
                }
                $ctx = $svc->matchContext((int) $s->match_id);
                if (!$ctx) {
                    continue;
                }
                $counts[$k]++;
                $payload = [
                    'session_id' => (int) $s->id, 'match_id' => (int) $s->match_id, 'minutes' => $min,
                    'scheduled_at' => Carbon::parse($s->scheduled_start, 'UTC')->setTimezone('Asia/Seoul')->format('n월 j일 H:i'),
                    'recipient_name' => $ctx->recipient_name, 'caregiver_name' => $ctx->caregiver_name,
                    'request_id' => (int) DB::table('matches')->where('id', $s->match_id)->value('request_id'),
                ];
                if ($k === 'late') {
                    $svc->notifySafely((int) $ctx->caregiver_user_id, NotificationService::TYPE_CARE_LATE, $payload + ['for' => 'caregiver']);
                    $svc->notifySafely((int) $ctx->guardian_user_id, NotificationService::TYPE_CARE_LATE, $payload + ['for' => 'guardian']);
                } else {
                    foreach ($admins as $a) {
                        $svc->notifySafely($a, NotificationService::TYPE_CARE_NOSHOW, $payload + ['for' => 'admin']);
                    }
                    $svc->notifySafely((int) $ctx->guardian_user_id, NotificationService::TYPE_CARE_NOSHOW, $payload + ['for' => 'guardian']);
                }
            }
        }

        if ($dry || array_sum($counts) > 0) {
            $this->line(($dry ? '[dry-run] ' : '') . json_encode($counts));
        }
        return self::SUCCESS;
    }
}
