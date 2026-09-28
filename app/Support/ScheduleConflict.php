<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 돌봄전문가 일정 충돌 감지 — 기능 11·21(2026-09-28, 구현계획 S5)
 * 같은 돌봄전문가의 예정·진행 중 세션과 시간이 겹치면 충돌. 경계가 맞닿는 것(끝=시작)은 충돌 아님.
 */
class ScheduleConflict
{
    /**
     * @param array<int, array{0: Carbon, 1: Carbon}> $intervals [시작, 끝] 목록
     * @return object|null 첫 충돌 세션 {session_id, match_id, request_id, scheduled_start, scheduled_end}
     */
    public static function find(int $caregiverId, array $intervals, ?int $ignoreRequestId = null): ?object
    {
        if (!$intervals) {
            return null;
        }
        $q = DB::table('care_sessions as cs')
            ->join('matches as m', 'm.id', '=', 'cs.match_id')
            ->where('m.caregiver_id', $caregiverId)
            ->whereIn('cs.status', ['scheduled', 'in_progress'])
            ->whereNotIn('m.status', ['cancelled'])
            ->where(function ($w) use ($intervals) {
                foreach ($intervals as [$s, $e]) {
                    $w->orWhere(fn ($x) => $x->where('cs.scheduled_start', '<', $e)->where('cs.scheduled_end', '>', $s));
                }
            });
        if ($ignoreRequestId) {
            $q->where('m.request_id', '!=', $ignoreRequestId);
        }
        return $q->orderBy('cs.scheduled_start')
            ->first(['cs.id as session_id', 'm.id as match_id', 'm.request_id', 'cs.scheduled_start', 'cs.scheduled_end']);
    }

    /** 충돌 안내 문구(KST) */
    public static function message(object $c): string
    {
        $s = Carbon::parse($c->scheduled_start, 'UTC')->setTimezone('Asia/Seoul');
        $e = Carbon::parse($c->scheduled_end, 'UTC')->setTimezone('Asia/Seoul');
        return sprintf('해당 돌봄전문가는 %s~%s 에 이미 일정이 있어요.', $s->format('n월 j일 H:i'), $e->format('H:i'));
    }
}
