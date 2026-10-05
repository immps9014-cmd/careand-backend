<?php

namespace App\Services;

use App\Exceptions\MnhContractException;
use App\Models\MnhContract;
use App\Models\MnhContractEvent;
use App\Models\MnhSupportType;
use App\Support\ScheduleConflict;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 산모신생아 바우처 기간형 계약(CAREN-MNH-01 2단계, 2026-10-05).
 *
 * 계약 = 제공일 N일(평일 기본, 건너뛸 날 제외). 담당 배정 때 정기 요청(match_requests.mnh_contract_id) + 매칭 + 일별 세션을
 * 만들고, 이후 출근·근무일지·정산은 기존 세션 경로를 그대로 쓴다.
 * 일정이 바뀌면(개시일 변경·연기·되돌리기) syncSessions() 가 「제공일 목록」에 세션을 맞춘다:
 *   남는 예정 세션은 빈 날로 옮기고(행 재사용), 모자라면 현재 담당 매칭에 새로 만들고, 그래도 남으면 취소.
 * 인력 교체는 지정일 이후 예정 세션을 새 담당의 매칭으로 옮긴다 — 지난 기록은 원래 담당에 남는다.
 * 모든 날짜는 한국 날짜(KST)로 다룬다.
 */
class MnhContractService
{
    private const TZ = 'Asia/Seoul';

    public function __construct(private NotificationService $notifier)
    {
    }

    /* ───────────── 기준표·견적 ───────────── */

    /** 신청 조건에 맞는 기준표 행 — 출산순위 무관(any) 행도 후보 */
    public function findSupportType(int $year, string $fetus, string $order, string $tier, string $period): ?MnhSupportType
    {
        return MnhSupportType::where('year', $year)->where('is_active', true)
            ->where('fetus_type', $fetus)->where('income_tier', $tier)->where('period', $period)
            ->whereIn('birth_order', [$order, 'any'])
            ->orderByRaw("birth_order = 'any'")   // 정확히 맞는 순위 먼저
            ->first();
    }

    /* ───────────── 제공일 계산 ───────────── */

    /** 계약의 제공일 목록(KST Y-m-d, 오름차순, days 개) */
    public function serviceDates(MnhContract $c): array
    {
        $weekdays = array_map('intval', $c->weekdays ?: config('mnh.weekdays'));
        $skip = array_flip($c->skip_dates ?: []);
        $cursor = Carbon::parse($c->start_date->format('Y-m-d'), self::TZ);
        $dates = [];
        for ($guard = 0; count($dates) < $c->days && $guard < 400; $guard++, $cursor->addDay()) {
            $d = $cursor->toDateString();
            if (in_array($cursor->isoWeekday(), $weekdays, true) && !isset($skip[$d])) {
                $dates[] = $d;
            }
        }

        return $dates;
    }

    public function endDate(MnhContract $c): ?string
    {
        $dates = $this->serviceDates($c);

        return $dates ? end($dates) : null;
    }

    /** KST 날짜 → [UTC 시작, UTC 끝] */
    private function slot(MnhContract $c, string $date): array
    {
        $start = Carbon::parse($date . ' ' . ($c->daily_start ?: '09:00'), self::TZ)->utc();

        return [$start, $start->copy()->addMinutes((int) $c->daily_minutes)];
    }

    private static function kstDate($utc): string
    {
        return Carbon::parse($utc, 'UTC')->setTimezone(self::TZ)->toDateString();
    }

    private static function todayKst(): string
    {
        return Carbon::now(self::TZ)->toDateString();
    }

    /** 이 계약의 세션 전부(취소 포함) + 담당 */
    public function sessions(MnhContract $c): Collection
    {
        if (!$c->match_request_id) {
            return collect();
        }

        return DB::table('care_sessions as cs')
            ->join('matches as m', 'm.id', '=', 'cs.match_id')
            ->leftJoin('caregivers as cg', 'cg.id', '=', 'm.caregiver_id')
            ->leftJoin('users as u', 'u.id', '=', 'cg.user_id')
            ->where('m.request_id', $c->match_request_id)
            ->orderBy('cs.scheduled_start')
            ->get(['cs.id', 'cs.match_id', 'cs.status', 'cs.scheduled_start', 'cs.scheduled_end', 'cs.actual_start',
                'cs.actual_end', 'cs.cancel_reason', 'cs.journal_note', 'cs.journal_chips', 'm.caregiver_id', 'u.name as caregiver_name'])
            ->map(function ($s) {
                $s->date = self::kstDate($s->scheduled_start);

                return $s;
            });
    }

    /* ───────────── 일정 동기화 ───────────── */

    /**
     * 세션을 제공일 목록에 맞춘다. $force=false 면 담당 일정 충돌 시 거절.
     * @return array{moved:int, created:int, cancelled:int}
     */
    public function syncSessions(MnhContract $c, bool $force = false, string $cancelReason = '바우처 일정 변경'): array
    {
        if (!$c->match_request_id) {
            return ['moved' => 0, 'created' => 0, 'cancelled' => 0];
        }
        $desired = $this->serviceDates($c);
        $live = $this->sessions($c)->where('status', '!=', 'cancelled');
        $byDate = $live->groupBy('date');

        $missing = array_values(array_filter($desired, fn ($d) => !isset($byDate[$d])));
        // 옮길 수 있는 세션 = 제공일이 아닌 날의 예정 세션(+같은 날 중복분). 진행·완료 세션은 기록이라 건드리지 않는다.
        $orphans = collect();
        foreach ($byDate as $date => $rows) {
            $keep = in_array($date, $desired, true) ? 1 : 0;
            $rows = $rows->sortBy(fn ($s) => $s->status === 'scheduled' ? 1 : 0)->values();   // 진행·완료를 먼저 남긴다
            foreach ($rows->slice($keep) as $s) {
                if ($s->status === 'scheduled') {
                    $orphans->push($s);
                }
            }
        }
        $orphans = $orphans->sortBy('scheduled_start')->values();

        $currentMatchId = $this->currentMatchId($c);
        $now = now();
        $moved = $created = $cancelled = 0;
        $plan = [];
        foreach ($missing as $i => $date) {
            $plan[] = ['date' => $date, 'session' => $orphans[$i] ?? null];
        }
        $leftover = $orphans->slice(count($missing));

        // 충돌 검사: 새로 생기거나 옮겨지는 날의 담당 일정
        if (!$force) {
            $byCaregiver = [];
            foreach ($plan as $p) {
                $cg = $p['session'] ? (int) $p['session']->caregiver_id : (int) $c->caregiver_id;
                $byCaregiver[$cg][] = $this->slot($c, $p['date']);
            }
            foreach ($byCaregiver as $cg => $intervals) {
                if ($cg && ($conflict = ScheduleConflict::find($cg, $intervals, (int) $c->match_request_id))) {
                    throw new MnhContractException('SCHEDULE_CONFLICT', ScheduleConflict::message($conflict) . ' 그래도 진행하려면 강제 적용을 선택하세요.', 409, ['conflict' => $conflict]);
                }
            }
        }

        foreach ($plan as $p) {
            [$s, $e] = $this->slot($c, $p['date']);
            if ($p['session']) {
                DB::table('care_sessions')->where('id', $p['session']->id)
                    ->update(['scheduled_start' => $s, 'scheduled_end' => $e, 'updated_at' => $now]);
                $moved++;
            } elseif ($currentMatchId) {
                DB::table('care_sessions')->insert([
                    'match_id' => $currentMatchId, 'scheduled_start' => $s, 'scheduled_end' => $e,
                    'duration_min' => (int) $c->daily_minutes, 'status' => 'scheduled', 'review_status' => 'pending',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $created++;
            }
        }
        foreach ($leftover as $s) {
            DB::table('care_sessions')->where('id', $s->id)
                ->update(['status' => 'cancelled', 'cancel_reason' => $cancelReason, 'updated_at' => $now]);
            $cancelled++;
        }
        $this->refreshMatches($c);

        return compact('moved', 'created', 'cancelled');
    }

    /** 현재 담당의 매칭(가장 최근, 취소 아님) */
    private function currentMatchId(MnhContract $c): ?int
    {
        $q = DB::table('matches')->where('request_id', $c->match_request_id)->where('status', '!=', 'cancelled');
        if ($c->caregiver_id) {
            $q->where('caregiver_id', $c->caregiver_id);
        }
        $id = $q->orderByDesc('id')->value('id');

        return $id ? (int) $id : null;
    }

    /** 매칭별 기간·예상금액·상태를 세션 기준으로 다시 맞춘다 */
    private function refreshMatches(MnhContract $c): void
    {
        $now = now();
        foreach (DB::table('matches')->where('request_id', $c->match_request_id)->get() as $m) {
            $rows = DB::table('care_sessions')->where('match_id', $m->id)->where('status', '!=', 'cancelled')
                ->orderBy('scheduled_start')->get(['status', 'scheduled_start', 'scheduled_end']);
            if ($rows->isEmpty()) {
                DB::table('matches')->where('id', $m->id)->update(['status' => 'cancelled', 'updated_at' => $now]);
                continue;
            }
            $statuses = $rows->pluck('status')->unique();
            $status = $statuses->every(fn ($s) => $s === 'completed') ? 'completed'
                : ($statuses->contains(fn ($s) => in_array($s, ['in_progress', 'completed'], true)) ? 'in_progress' : 'confirmed');
            DB::table('matches')->where('id', $m->id)->update([
                'scheduled_start' => $rows->first()->scheduled_start,
                'scheduled_end' => $rows->last()->scheduled_end,
                'estimated_amount' => round((float) $m->hourly_rate * $c->daily_minutes / 60) * $rows->count(),
                'status' => $status,
                'updated_at' => $now,
            ]);
        }
    }

    /* ───────────── 운영 동작 ───────────── */

    public function log(MnhContract $c, string $type, ?string $date = null, array $payload = [], ?int $actor = null): void
    {
        MnhContractEvent::create([
            'contract_id' => $c->id, 'type' => $type, 'event_date' => $date,
            'payload' => $payload ?: null, 'actor_user_id' => $actor, 'created_at' => now(),
        ]);
    }

    /** 담당 배정 — 정기 요청·매칭·일별 세션 생성 */
    public function assign(MnhContract $c, int $caregiverId, bool $force, ?int $actor): void
    {
        if (!in_array($c->status, ['applied', 'confirmed'], true) || $c->match_request_id) {
            throw new MnhContractException('ALREADY_ASSIGNED', '이미 담당이 배정된 계약이에요. 교체는 「인력 교체」로 하세요.');
        }
        $this->requireActiveCaregiver($caregiverId);
        $guardianId = DB::table('guardians')->where('user_id', $c->user_id)->value('id');
        if (!$guardianId) {
            throw new MnhContractException('NO_GUARDIAN', '신청 회원의 이용자 정보가 없어 배정할 수 없어요.');
        }
        $dates = $this->serviceDates($c);
        if (!$force && ($conflict = ScheduleConflict::find($caregiverId, array_map(fn ($d) => $this->slot($c, $d), $dates)))) {
            throw new MnhContractException('SCHEDULE_CONFLICT', ScheduleConflict::message($conflict) . ' 그래도 배정하려면 강제 배정을 선택하세요.', 409, ['conflict' => $conflict]);
        }

        DB::transaction(function () use ($c, $caregiverId, $guardianId, $dates, $actor) {
            $now = now();
            [$first] = $this->slot($c, $dates[0]);
            $categoryId = DB::table('service_categories')->where('code', 'PP_CARE')->value('id');
            $hours = max(1, $c->days * $c->daily_minutes / 60);
            $rate = $c->total_price ? round($c->total_price / $hours) : (float) DB::table('service_categories')->where('id', $categoryId)->value('base_rate');

            $requestId = DB::table('match_requests')->insertGetId([
                'guardian_id' => $guardianId,
                'postpartum_client_id' => $c->postpartum_client_id,
                'service_domain' => 'postpartum',
                'category_id' => $categoryId,
                'mode' => 'recurring',
                'scheduled_start' => $first,
                'duration_min' => $c->daily_minutes,
                'recurrence_rule' => json_encode(['days' => $c->days, 'mnh_contract_id' => $c->id]),
                'special_request' => '산모신생아 바우처 ' . $c->contract_no,
                'requirements' => json_encode(['mnh_contract_id' => $c->id]),
                'status' => 'matched',
                'matched_at' => $now,
                'mnh_contract_id' => $c->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->acceptCandidate($requestId, $caregiverId, '바우처 계약 배정');
            DB::table('matches')->insert([
                'request_id' => $requestId, 'caregiver_id' => $caregiverId,
                'scheduled_start' => $first, 'scheduled_end' => $first,
                'hourly_rate' => $rate, 'estimated_amount' => 0, 'is_manual' => 0,
                'status' => 'confirmed', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $c->update(['match_request_id' => $requestId, 'caregiver_id' => $caregiverId, 'status' => 'confirmed']);
            $this->syncSessions($c, true);
            $this->log($c, 'assigned', $dates[0], ['caregiver_id' => $caregiverId], $actor);
        });

        $this->notifyAssigned($c, $caregiverId, $dates[0]);
        // 3단계: 개시 전 서명 서류(이용계약서·개인정보 동의·준수사항) 발행
        app(MnhDocumentService::class)->autoIssue($c->fresh(), 'assigned', $actor);
    }

    /** 인력 교체 — $fromDate(KST) 이후 예정 세션을 새 담당 매칭으로 옮긴다 */
    public function swap(MnhContract $c, int $caregiverId, string $fromDate, string $reason, bool $force, ?int $actor): array
    {
        if (!$c->match_request_id || !in_array($c->status, ['confirmed', 'active'], true)) {
            throw new MnhContractException('NOT_ASSIGNED', '담당이 배정된 진행 중 계약만 교체할 수 있어요.');
        }
        if ((int) $c->caregiver_id === $caregiverId) {
            throw new MnhContractException('SAME_CAREGIVER', '지금 담당과 같은 돌봄전문가예요.');
        }
        if ($fromDate < self::todayKst()) {
            throw new MnhContractException('PAST_DATE', '교체 시작일은 오늘 이후여야 해요.');
        }
        $this->requireActiveCaregiver($caregiverId);
        $moving = $this->sessions($c)->where('status', 'scheduled')->filter(fn ($s) => $s->date >= $fromDate)->values();
        if ($moving->isEmpty()) {
            throw new MnhContractException('NOTHING_TO_MOVE', '그날 이후 남은 예정 일정이 없어요.');
        }
        if (!$force) {
            $intervals = $moving->map(fn ($s) => [Carbon::parse($s->scheduled_start, 'UTC'), Carbon::parse($s->scheduled_end, 'UTC')])->all();
            if ($conflict = ScheduleConflict::find($caregiverId, $intervals, (int) $c->match_request_id)) {
                throw new MnhContractException('SCHEDULE_CONFLICT', ScheduleConflict::message($conflict) . ' 그래도 교체하려면 강제 적용을 선택하세요.', 409, ['conflict' => $conflict]);
            }
        }
        $oldCaregiverId = (int) $c->caregiver_id;

        DB::transaction(function () use ($c, $caregiverId, $moving, $oldCaregiverId, $fromDate, $reason, $actor) {
            $now = now();
            $old = DB::table('matches')->where('id', $moving->first()->match_id)->first();
            $this->acceptCandidate((int) $c->match_request_id, $caregiverId, '바우처 인력 교체');
            $newMatchId = DB::table('matches')->insertGetId([
                'request_id' => $c->match_request_id, 'caregiver_id' => $caregiverId,
                'scheduled_start' => $moving->first()->scheduled_start, 'scheduled_end' => $moving->last()->scheduled_end,
                'hourly_rate' => $old->hourly_rate, 'estimated_amount' => 0, 'is_manual' => 0,
                'status' => 'confirmed', 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('care_sessions')->whereIn('id', $moving->pluck('id'))->update(['match_id' => $newMatchId, 'updated_at' => $now]);
            $c->update(['caregiver_id' => $caregiverId]);
            $this->refreshMatches($c);
            $this->log($c, 'swapped', $fromDate, ['from_caregiver_id' => $oldCaregiverId, 'to_caregiver_id' => $caregiverId,
                'sessions' => $moving->count(), 'reason' => $reason], $actor);
        });

        $this->notifySwap($c, $oldCaregiverId, $caregiverId, $fromDate);

        return ['moved' => $moving->count()];
    }

    /** 연기 — 그날을 제공일에서 빼고 끝에 하루 덧붙인다 */
    public function postpone(MnhContract $c, string $date, string $reason, bool $force, ?int $actor): array
    {
        $this->requireOpen($c);
        if ($date < self::todayKst()) {
            throw new MnhContractException('PAST_DATE', '지난 날짜는 연기할 수 없어요.');
        }
        if (!in_array($date, $this->serviceDates($c), true)) {
            throw new MnhContractException('NOT_SERVICE_DAY', '그날은 제공일이 아니에요.');
        }
        $started = $this->sessions($c)->first(fn ($s) => $s->date === $date && in_array($s->status, ['in_progress', 'completed'], true));
        if ($started) {
            throw new MnhContractException('ALREADY_STARTED', '이미 출근한 날은 연기할 수 없어요.');
        }

        return DB::transaction(function () use ($c, $date, $reason, $force, $actor) {
            $c->update(['skip_dates' => array_values(array_unique(array_merge($c->skip_dates ?: [], [$date])))]);
            $r = $this->syncSessions($c, $force, '연기');
            $this->log($c, 'postponed', $date, ['reason' => $reason, 'new_end' => $this->endDate($c)], $actor);

            return $r + ['end_date' => $this->endDate($c)];
        });
    }

    /** 연기 되돌리기 */
    public function restore(MnhContract $c, string $date, bool $force, ?int $actor): array
    {
        $this->requireOpen($c);
        if (!in_array($date, $c->skip_dates ?: [], true)) {
            throw new MnhContractException('NOT_SKIPPED', '연기된 날이 아니에요.');
        }

        return DB::transaction(function () use ($c, $date, $force, $actor) {
            $c->update(['skip_dates' => array_values(array_diff($c->skip_dates ?: [], [$date]))]);
            $r = $this->syncSessions($c, $force, '연기 되돌림');
            $this->log($c, 'restored', $date, ['new_end' => $this->endDate($c)], $actor);

            return $r + ['end_date' => $this->endDate($c)];
        });
    }

    /** 개시일·일수·제공 요일·시간 변경(시작 전에만) */
    public function reschedule(MnhContract $c, array $fields, bool $force, ?int $actor): array
    {
        $this->requireOpen($c);
        if ($this->sessions($c)->contains(fn ($s) => in_array($s->status, ['in_progress', 'completed'], true))) {
            if (isset($fields['start_date']) || isset($fields['daily_start']) || isset($fields['daily_minutes'])) {
                throw new MnhContractException('ALREADY_STARTED', '서비스가 시작된 뒤에는 개시일·시간을 바꿀 수 없어요. 연기나 일수 조정을 쓰세요.');
            }
        }

        return DB::transaction(function () use ($c, $fields, $force, $actor) {
            $before = $c->only(array_keys($fields));
            $c->update($fields);
            // 시간이 바뀌면 남은 예정 세션 시각도 같이 — sync 는 날짜만 보므로 여기서 맞춘다
            if (isset($fields['daily_start']) || isset($fields['daily_minutes'])) {
                foreach ($this->sessions($c)->where('status', 'scheduled') as $s) {
                    [$st, $en] = $this->slot($c, $s->date);
                    DB::table('care_sessions')->where('id', $s->id)->update(['scheduled_start' => $st, 'scheduled_end' => $en,
                        'duration_min' => (int) $c->daily_minutes, 'updated_at' => now()]);
                }
                if ($c->match_request_id) {
                    DB::table('match_requests')->where('id', $c->match_request_id)->update(['duration_min' => $c->daily_minutes]);
                }
            }
            $r = $this->syncSessions($c, $force);
            $this->log($c, 'start_changed', $c->start_date->format('Y-m-d'), ['before' => $before, 'after' => $fields], $actor);

            return $r + ['end_date' => $this->endDate($c)];
        });
    }

    public function cancel(MnhContract $c, string $reason, ?int $actor): void
    {
        if (in_array($c->status, ['completed', 'cancelled'], true)) {
            throw new MnhContractException('CLOSED', '이미 종료·취소된 계약이에요.');
        }
        DB::transaction(function () use ($c, $reason, $actor) {
            $now = now();
            if ($c->match_request_id) {
                $ids = $this->sessions($c)->where('status', 'scheduled')->pluck('id');
                DB::table('care_sessions')->whereIn('id', $ids)->update(['status' => 'cancelled', 'cancel_reason' => '계약 취소', 'updated_at' => $now]);
                $this->refreshMatches($c);
                $hasDone = $this->sessions($c)->contains(fn ($s) => $s->status === 'completed');
                DB::table('match_requests')->where('id', $c->match_request_id)
                    ->update(['status' => $hasDone ? 'matched' : 'cancelled', 'updated_at' => $now]);
            }
            $c->update(['status' => 'cancelled', 'cancel_reason' => $reason]);
            $this->log($c, 'cancelled', null, ['reason' => $reason], $actor);
        });
    }

    /** 출근·퇴근 뒤 계약 상태 — CareSessionController 가 부른다 */
    public static function onSessionChange(int $matchId): void
    {
        $contractId = DB::table('matches as m')->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->where('m.id', $matchId)->value('r.mnh_contract_id');
        if (!$contractId || !($c = MnhContract::find($contractId))) {
            return;
        }
        $svc = app(self::class);
        $live = $svc->sessions($c)->where('status', '!=', 'cancelled');
        if ($c->status === 'confirmed' && $live->contains(fn ($s) => $s->status !== 'scheduled')) {
            $c->update(['status' => 'active']);
        }
        if (in_array($c->status, ['confirmed', 'active'], true) && $live->isNotEmpty() && $live->every(fn ($s) => $s->status === 'completed')) {
            $c->update(['status' => 'completed']);
            $svc->log($c, 'completed', self::todayKst());
            app(MnhDocumentService::class)->autoIssue($c, 'completed');   // 만족도 모니터링
        }
    }

    /** 바우처 계약 세션의 출근을 기관에 알린다(요구사항 「출근 알림(기관 확인) 매일」) */
    public static function notifyCheckin(int $sessionId): void
    {
        $row = DB::table('care_sessions as cs')->join('matches as m', 'm.id', '=', 'cs.match_id')
            ->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->join('mnh_contracts as c', 'c.id', '=', 'r.mnh_contract_id')
            ->leftJoin('caregivers as cg', 'cg.id', '=', 'm.caregiver_id')->leftJoin('users as u', 'u.id', '=', 'cg.user_id')
            ->leftJoin('postpartum_clients as pc', 'pc.id', '=', 'c.postpartum_client_id')
            ->where('cs.id', $sessionId)
            ->first(['c.id as contract_id', 'c.contract_no', 'u.name as caregiver_name', 'pc.name as client_name', 'cs.actual_start']);
        if (!$row) {
            return;
        }
        $svc = app(NotificationService::class);
        $name = (string) $row->client_name;
        $payload = [
            'contract_id' => (int) $row->contract_id, 'contract_no' => $row->contract_no,
            'caregiver_name' => $row->caregiver_name, 'client_name' => $name !== '' ? mb_substr($name, 0, 1) . '○○' : '산모',
            'at' => $row->actual_start ? Carbon::parse($row->actual_start, 'UTC')->setTimezone(self::TZ)->format('H:i') : null,
        ];
        foreach ($svc->adminsFor('mnh') as $adminUserId) {
            $svc->notifySafely($adminUserId, NotificationService::TYPE_MNH_CHECKIN, $payload);
        }
    }

    /* ───────────── 내부 도우미 ───────────── */

    private function requireOpen(MnhContract $c): void
    {
        if (in_array($c->status, ['completed', 'cancelled'], true)) {
            throw new MnhContractException('CLOSED', '종료·취소된 계약은 바꿀 수 없어요.');
        }
    }

    private function requireActiveCaregiver(int $caregiverId): void
    {
        if (!DB::table('caregivers')->where('id', $caregiverId)->where('status', 'active')->exists()) {
            throw new MnhContractException('INVALID_CAREGIVER', '활동 중인 돌봄전문가가 아니에요.');
        }
    }

    private function acceptCandidate(int $requestId, int $caregiverId, string $reason): void
    {
        $now = now();
        $exists = DB::table('match_candidates')->where('request_id', $requestId)->where('caregiver_id', $caregiverId)->exists();
        if ($exists) {
            DB::table('match_candidates')->where('request_id', $requestId)->where('caregiver_id', $caregiverId)
                ->update(['response' => 'accepted', 'responded_at' => $now, 'updated_at' => $now]);

            return;
        }
        DB::table('match_candidates')->insert([
            'request_id' => $requestId, 'caregiver_id' => $caregiverId, 'ai_score' => 1.000,
            'ai_reasons' => json_encode([$reason], JSON_UNESCAPED_UNICODE), 'rank' => 1,
            'response' => 'accepted', 'responded_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function caregiverUser(int $caregiverId): ?object
    {
        return DB::table('caregivers as cg')->join('users as u', 'u.id', '=', 'cg.user_id')
            ->where('cg.id', $caregiverId)->first(['u.id', 'u.name']);
    }

    private function notifyAssigned(MnhContract $c, int $caregiverId, string $firstDate): void
    {
        $cg = $this->caregiverUser($caregiverId);
        $payload = ['contract_id' => $c->id, 'event' => 'assigned', 'caregiver_name' => $cg?->name,
            'start_date' => $firstDate, 'end_date' => $this->endDate($c), 'days' => $c->days];
        $this->notifier->notifySafely((int) $c->user_id, NotificationService::TYPE_MNH_CONTRACT, $payload);
        $this->notifier->notifySafely($cg?->id, NotificationService::TYPE_MNH_CONTRACT, $payload + ['for_caregiver' => true]);
    }

    private function notifySwap(MnhContract $c, int $oldCg, int $newCg, string $fromDate): void
    {
        $old = $oldCg ? $this->caregiverUser($oldCg) : null;
        $new = $this->caregiverUser($newCg);
        $base = ['contract_id' => $c->id, 'event' => 'swapped', 'caregiver_name' => $new?->name, 'start_date' => $fromDate, 'end_date' => $this->endDate($c)];
        $this->notifier->notifySafely((int) $c->user_id, NotificationService::TYPE_MNH_CONTRACT, $base);
        $this->notifier->notifySafely($new?->id, NotificationService::TYPE_MNH_CONTRACT, $base + ['for_caregiver' => true, 'event' => 'assigned']);
        $this->notifier->notifySafely($old?->id, NotificationService::TYPE_MNH_CONTRACT, $base + ['for_caregiver' => true, 'event' => 'released']);
    }
}
