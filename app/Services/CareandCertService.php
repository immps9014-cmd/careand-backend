<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 케어앤에듀 인증 돌봄전문가(2026-10-07) — 기준·부여·취소·조회. 기준값은 config/careand_cert.php.
 * 자격은 certifications 한 줄(program=careand_certified, 취소 전까지 유효)이고, 화면의 인증 마크는 이 줄이 있느냐로 정한다.
 */
class CareandCertService
{
    /** 목록 화면용 유효 자격 caregiver_id 집합(요청 안에서 한 번만 조회, 큐 워커처럼 오래 사는 프로세스 대비 60초) */
    private static ?array $memo = null;
    private static int $memoAt = 0;

    /**
     * 실제 기록 집계 — caregiver_id => [sessions, reviews, rating]
     * @param int[]|null $caregiverIds null 이면 전체
     */
    public function stats(?array $caregiverIds = null): Collection
    {
        $sessions = DB::table('care_sessions as cs')->join('matches as m', 'm.id', '=', 'cs.match_id')
            ->where('cs.status', 'completed')
            ->when($caregiverIds !== null, fn ($q) => $q->whereIn('m.caregiver_id', $caregiverIds))
            ->groupBy('m.caregiver_id')->selectRaw('m.caregiver_id, COUNT(*) n')->pluck('n', 'caregiver_id');
        $reviews = DB::table('reviews as rv')->join('matches as m', 'm.id', '=', 'rv.match_id')
            ->where('rv.reviewer_role', 'guardian')
            ->when($caregiverIds !== null, fn ($q) => $q->whereIn('m.caregiver_id', $caregiverIds))
            ->groupBy('m.caregiver_id')->selectRaw('m.caregiver_id, COUNT(*) n, AVG(rv.rating) a')->get()->keyBy('caregiver_id');

        $ids = $caregiverIds ?? $sessions->keys()->merge($reviews->keys())->unique()->all();
        return collect($ids)->mapWithKeys(fn ($id) => [(int) $id => [
            'sessions' => (int) ($sessions[$id] ?? 0),
            'reviews' => (int) ($reviews[$id]->n ?? 0),
            'rating' => isset($reviews[$id]) ? round((float) $reviews[$id]->a, 2) : null,
        ]]);
    }

    public function criteria(): array
    {
        return [
            'min_sessions' => (int) config('careand_cert.min_sessions'),
            'min_rating' => (float) config('careand_cert.min_rating'),
            'min_reviews' => (int) config('careand_cert.min_reviews'),
        ];
    }

    public function meets(array $s): bool
    {
        $c = $this->criteria();
        return $s['sessions'] >= $c['min_sessions'] && $s['reviews'] >= $c['min_reviews']
            && $s['rating'] !== null && $s['rating'] >= $c['min_rating'];
    }

    /** 유효한 자격(취소 안 된 것) — 없으면 null */
    public function current(int $userId): ?object
    {
        return DB::table('certifications')->where('program', config('careand_cert.program'))
            ->where('user_id', $userId)->whereNull('revoked_at')->orderByDesc('id')->first();
    }

    /** 화면용 요약 — 인증 마크·자격 번호·발급일 */
    public function summary(int $userId): ?array
    {
        $c = $this->current($userId);
        return $c ? ['name' => $c->cert_name, 'issuer' => $c->cert_issuer, 'number' => $c->cert_number, 'issued_date' => $c->issued_date] : null;
    }

    public function isCertifiedCaregiver(int $caregiverId): bool
    {
        if (self::$memo === null || time() - self::$memoAt > 60) {
            self::$memo = array_flip(DB::table('certifications as ct')->join('caregivers as c', 'c.user_id', '=', 'ct.user_id')
                ->where('ct.program', config('careand_cert.program'))->whereNull('ct.revoked_at')
                ->pluck('c.id')->map(fn ($v) => (int) $v)->all());
            self::$memoAt = time();
        }
        return isset(self::$memo[$caregiverId]);
    }

    public static function forgetMemo(): void
    {
        self::$memo = null;
    }

    /**
     * 자격 부여. 이미 유효한 자격이 있으면 그대로 돌려준다.
     * @param 'auto'|'manual' $basis
     */
    public function grant(int $caregiverId, string $basis, ?int $actorId = null, ?string $note = null): object
    {
        $cg = DB::table('caregivers')->where('id', $caregiverId)->first(['id', 'user_id']);
        abort_unless($cg, 404, '돌봄전문가를 찾을 수 없습니다.');
        if ($existing = $this->current((int) $cg->user_id)) {
            return $existing;
        }
        $stats = $this->stats([$caregiverId])[$caregiverId];

        $id = DB::transaction(function () use ($cg, $basis, $stats, $actorId) {
            // 자격 번호 CAE-2026-0001 — 해마다 1부터, 같은 해 마지막 번호에 잠금
            $year = now('Asia/Seoul')->format('Y');
            $prefix = config('careand_cert.number_prefix') . "-{$year}-";
            $last = DB::table('certifications')->where('cert_number', 'like', $prefix . '%')->lockForUpdate()->max('cert_number');
            $seq = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

            return DB::table('certifications')->insertGetId([
                'user_id' => $cg->user_id,
                'cert_name' => config('careand_cert.name'),
                'cert_type' => 'private',
                'cert_issuer' => config('careand_cert.issuer'),
                'cert_number' => $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                'issued_date' => now('Asia/Seoul')->toDateString(),
                'verified' => 1,
                'verified_by' => $actorId,
                'verified_at' => now(),
                'program' => config('careand_cert.program'),
                'grant_basis' => $basis,
                'grant_stats' => json_encode($stats + $this->criteria()),
            ]);
        });
        self::forgetMemo();

        AuditLog::create(['actor_id' => $actorId, 'action' => 'caregiver.cert.grant', 'entity_type' => 'caregiver', 'entity_id' => $caregiverId,
            'details' => ['certification_id' => $id, 'basis' => $basis, 'stats' => $stats, 'note' => $note], 'ip_address' => request()?->ip() ?? '127.0.0.1']);

        $cert = DB::table('certifications')->find($id);
        try {
            app(NotificationService::class)->notify((int) $cg->user_id, NotificationService::TYPE_CERT_GRANTED, [
                'cert_number' => $cert->cert_number, 'cert_name' => $cert->cert_name,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
        return $cert;
    }

    public function revoke(int $caregiverId, string $reason, ?int $actorId): bool
    {
        $userId = DB::table('caregivers')->where('id', $caregiverId)->value('user_id');
        $cert = $userId ? $this->current((int) $userId) : null;
        if (!$cert) {
            return false;
        }
        DB::table('certifications')->where('id', $cert->id)->update([
            'revoked_at' => now(), 'revoked_by' => $actorId, 'revoked_reason' => $reason, 'verified' => 0,
        ]);
        self::forgetMemo();
        AuditLog::create(['actor_id' => $actorId, 'action' => 'caregiver.cert.revoke', 'entity_type' => 'caregiver', 'entity_id' => $caregiverId,
            'details' => ['certification_id' => $cert->id, 'reason' => $reason], 'ip_address' => request()?->ip() ?? '127.0.0.1']);
        return true;
    }

    /** 후기 등록 직후처럼 한 사람만 바로 확인할 때 — 자동 부여 대상이면 부여(취소 이력 있으면 안 줌) */
    public function autoGrantIfEligible(int $caregiverId): ?object
    {
        if (!config('careand_cert.auto_grant')) {
            return null;
        }
        $cg = DB::table('caregivers')->where('id', $caregiverId)->whereNull('deleted_at')->where('status', 'active')->first(['id', 'user_id']);
        if (!$cg || DB::table('certifications')->where('program', config('careand_cert.program'))->where('user_id', $cg->user_id)->exists()) {
            return null;
        }
        return $this->meets($this->stats([$caregiverId])[$caregiverId]) ? $this->grant($caregiverId, 'auto') : null;
    }

    /**
     * 기준을 넘었는데 아직 자격이 없는 활동 중 돌봄전문가. 한 번이라도 취소된 사람은 자동 대상에서 뺀다(관리자가 다시 줄 수 있음).
     * @return Collection<int, array{caregiver_id:int, user_id:int, stats:array}>
     */
    public function pendingAutoGrants(): Collection
    {
        $had = DB::table('certifications')->where('program', config('careand_cert.program'))->pluck('user_id')->flip();
        $active = DB::table('caregivers')->whereNull('deleted_at')->where('status', 'active')->pluck('user_id', 'id');
        return $this->stats($active->keys()->all())
            ->filter(fn ($s, $cid) => !isset($had[$active[$cid]]) && $this->meets($s))
            ->map(fn ($s, $cid) => ['caregiver_id' => (int) $cid, 'user_id' => (int) $active[$cid], 'stats' => $s])
            ->values();
    }
}
