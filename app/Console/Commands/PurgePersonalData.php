<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 보유기간 지난 개인정보 파기 — 개인정보 처리방침 · 사업계획서 3.3 (2026-09-28, 구현계획 S2-5).
 *   ① 음성 일지 원본 파일: 녹음 후 30일 → 파일 삭제(전사 텍스트·일지는 유지), DB 기록 없는 고아 파일 포함
 *   ② 출퇴근 위치(GPS 좌표): 기록 후 90일 → 좌표 파기(0,0), 시각·이벤트는 정산 근거로 유지
 *   ③ 탈퇴 회원: 탈퇴 후 30일 → 계정 식별정보와 본인·돌봄대상자 개인정보 파기(행은 남겨 정산·통계 무결성 유지)
 *      단, 산모신생아 바우처 자료(산모 정보·계약 서류·이용일지·산후우울 검사)는 ④ 의 보존 기간 동안 남긴다.
 *   ④ 바우처 자료: 탈퇴 회원의 산모 중 마지막 계약이 끝난 날로부터 mnh.retention_years(5년, 개인정보 동의서 「자격 종료 후 5년」)
 *      지난 것 → 산모 이름·주소·건강 항목, 신생아 이름, 서명 서류 본문·서명·PDF, 이용일지·산후우울 검사 파기
 *      (계약 금액·선납·환불·위약금은 매출·정산 근거로 남김). 진행 중 계약이 있으면 끝날 때까지 보존(2026-10-18).
 * 기본은 대상만 보여 주는 시험 실행. 실제 파기는 --execute. 파기 건수는 감사로그(privacy.purge)에 남는다.
 */
class PurgePersonalData extends Command
{
    protected $signature = 'privacy:purge {--execute : 실제로 파기(없으면 대상만 출력)}';

    protected $description = '보유기간이 지난 개인정보(음성 원본·위치·탈퇴 회원) 파기';

    private const GONE = '(파기)';

    public function handle(): int
    {
        $run = (bool) $this->option('execute');
        $this->info($run ? '■ 파기 실행' : '■ 시험 실행 — 대상만 출력(파기하려면 --execute)');
        $done = [];

        // ① 음성 원본 30일
        $voices = DB::table('voice_logs')->where('created_at', '<', now()->subDays(30))
            ->where('audio_url', 'not like', 'purged:%')->get(['id', 'audio_url']);
        foreach ($voices as $v) {
            if ($run) {
                if (is_file($v->audio_url)) { @unlink($v->audio_url); }
                DB::table('voice_logs')->where('id', $v->id)->update(['audio_url' => 'purged:' . now()->toDateString(), 'updated_at' => now()]);
            }
        }
        // DB 기록이 없는 음성 파일(삭제된 기록의 잔재)도 30일이 지나면 삭제
        $known = DB::table('voice_logs')->pluck('audio_url')->flip();
        $orphans = [];
        foreach (glob(storage_path('app/voice-logs/*/*')) ?: [] as $f) {
            if (is_file($f) && filemtime($f) < now()->subDays(30)->timestamp && !isset($known[$f])) {
                $orphans[] = $f;
            }
        }
        if ($run) {
            foreach ($orphans as $f) { @unlink($f); }
        }
        $done['voice_files'] = $voices->count() + count($orphans);

        // ② 위치 90일
        $gps = DB::table('attendance_logs')->where('logged_at', '<', now()->subDays(90))
            ->where(fn ($q) => $q->where('lat', '<>', 0)->orWhere('lng', '<>', 0));
        $done['gps_points'] = (clone $gps)->count();
        if ($run && $done['gps_points']) {
            $gps->update(['lat' => 0, 'lng' => 0]);
        }

        // ③ 탈퇴 30일
        $users = DB::table('users')->whereNotNull('deleted_at')->where('deleted_at', '<', now()->subDays(30))
            ->where('email', 'not like', 'withdrawn-%@deleted.invalid')->get(['id', 'role']);
        $done['withdrawn_users'] = $users->count();
        if ($run) {
            foreach ($users as $u) {
                DB::transaction(function () use ($u) {
                    DB::table('users')->where('id', $u->id)->update([
                        'name' => '탈퇴회원', 'email' => "withdrawn-{$u->id}@deleted.invalid", 'phone' => "deleted-{$u->id}",
                        'password' => Hash::make(Str::random(40)), 'fcm_token' => null, 'totp_secret' => null, 'updated_at' => now(),
                    ]);
                    DB::table('caregivers')->where('user_id', $u->id)->update([
                        'birth_date' => '1900-01-01', 'base_address' => self::GONE, 'base_lat' => null, 'base_lng' => null,
                        'license_no' => null, 'license_image_url' => null,
                        'bank_name' => null, 'bank_account' => null, 'bank_holder' => null, 'updated_at' => now(),
                    ]);
                    // 제출 서류(신분증·통장·범죄경력) 파일과 기록 삭제 — S5
                    foreach (DB::table('caregivers')->where('user_id', $u->id)->pluck('id') as $cgId) {
                        app(\App\Services\CaregiverDocumentService::class)->purge((int) $cgId);
                        \App\Support\CaregiverProfileExtras::purge((int) $cgId);   // 비상연락처·사진·희망사항
                    }
                    // 산모 비상연락처(제3자 개인정보)·이용일지 메모(건강정보) — 2026-10-05
                    // 바우처 계약이 있는 산모는 ④ 보존 기간이 끝날 때 함께 파기한다(동의서 「자격 종료 후 5년」, 2026-10-18)
                    $pcIds = DB::table('postpartum_clients')->where('user_id', $u->id)->pluck('id')
                        ->reject(fn ($id) => DB::table('mnh_contracts')->where('postpartum_client_id', $id)->exists())->values();
                    DB::table('postpartum_clients')->whereIn('id', $pcIds)->update(['emergency_contact' => null, 'updated_at' => now()]);
                    DB::table('mnh_client_journals')->whereIn('postpartum_client_id', $pcIds)->update(['note' => null, 'updated_at' => now()]);
                    $gid = DB::table('guardians')->where('user_id', $u->id)->value('id');
                    if ($gid) {
                        DB::table('seniors')->where('guardian_id', $gid)->update([
                            'name' => self::GONE, 'birth_date' => '1900-01-01', 'home_address' => self::GONE, 'home_lat' => null, 'home_lng' => null,
                            'special_notes' => null, 'care_grade_no' => null, 'diseases' => '[]', 'updated_at' => now(),
                        ]);
                        DB::table('nursing_patients')->where('guardian_id', $gid)->update([
                            'name' => self::GONE, 'birth_date' => '1900-01-01', 'hospital_name' => self::GONE, 'special_notes' => null,
                            'diseases' => '[]', 'updated_at' => now(),
                        ]);
                    }
                });
            }
        }

        // ④ 바우처 자료 — 탈퇴 회원의 산모 중 보존 기간이 끝난 것
        $years = (int) config('mnh.retention_years', 5);
        $today = now('Asia/Seoul')->toDateString();
        $held = 0;
        $expired = [];
        $candidates = DB::table('postpartum_clients as pc')->join('users as u', 'u.id', '=', 'pc.user_id')
            ->whereNotNull('u.deleted_at')->where('u.deleted_at', '<', now()->subDays(30))
            ->where('pc.name', '!=', self::GONE)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('mnh_contracts as c')->whereColumn('c.postpartum_client_id', 'pc.id'))
            ->pluck('pc.id');
        foreach ($candidates as $pcId) {
            $until = self::voucherRetainUntil((int) $pcId, $years);
            ($until !== null && $until <= $today) ? $expired[] = (int) $pcId : $held++;
        }
        $done['mnh_held'] = $held;
        $done['mnh_purged'] = count($expired);
        if ($run) {
            foreach ($expired as $pcId) {
                DB::transaction(fn () => self::purgeVoucherClient($pcId));
            }
        }

        foreach (['voice_files' => '음성 원본 파일(30일)', 'gps_points' => '출퇴근 위치 좌표(90일)', 'withdrawn_users' => '탈퇴 회원(30일)',
            'mnh_held' => '바우처 자료 보존 중(탈퇴 회원)', 'mnh_purged' => "바우처 자료 파기({$years}년 지남)"] as $k => $label) {
            $this->line(sprintf('  %-24s %d건', $label, $done[$k]));
        }
        if ($run && array_sum($done) - $done['mnh_held'] > 0) {
            AuditLog::create(['actor_id' => null, 'action' => 'privacy.purge', 'entity_type' => 'privacy', 'details' => $done, 'ip_address' => '127.0.0.1']);
        }
        return self::SUCCESS;
    }

    /**
     * 바우처 자료 보존 마감일(KST Y-m-d) — 마지막 계약이 끝난 날 + $years 년.
     * 끝난 날: 종료 계약은 마지막 제공일, 취소 계약은 취소된 날(행 수정일). 진행 중(신청·배정·서비스 중) 계약이 있으면 null(계속 보존).
     */
    public static function voucherRetainUntil(int $postpartumClientId, int $years): ?string
    {
        $svc = app(\App\Services\MnhContractService::class);
        $last = null;
        foreach (\App\Models\MnhContract::where('postpartum_client_id', $postpartumClientId)->get() as $c) {
            if (!in_array($c->status, ['completed', 'cancelled'], true)) {
                return null;
            }
            $end = $c->status === 'completed'
                ? ($svc->endDate($c) ?? $c->start_date?->format('Y-m-d'))
                : $c->updated_at?->copy()->setTimezone('Asia/Seoul')->toDateString();
            if ($end && ($last === null || $end > $last)) {
                $last = $end;
            }
        }

        return $last ? \Illuminate\Support\Carbon::parse($last, 'Asia/Seoul')->addYears($years)->toDateString() : null;
    }

    /** 바우처 산모 한 명의 자료 파기 — 금액·상태·날짜는 남기고 사람을 알아볼 수 있는 항목과 건강정보를 지운다 */
    private static function purgeVoucherClient(int $pcId): void
    {
        $now = now();
        DB::table('postpartum_clients')->where('id', $pcId)->update([
            'name' => self::GONE, 'name_encrypted' => null, 'phone_encrypted' => '', 'birth_date' => '1900-01-01',
            'address' => self::GONE, 'address_detail' => null, 'emergency_contact' => null,
            'pregnancy_complications' => null, 'postpartum_conditions' => null, 'medications' => null,
            'special_notes' => null, 'care_profile' => null, 'updated_at' => $now,
        ]);
        DB::table('newborns')->where('postpartum_client_id', $pcId)->update(['name' => self::GONE, 'special_conditions' => null, 'updated_at' => $now]);
        DB::table('mnh_client_journals')->where('postpartum_client_id', $pcId)->delete();
        DB::table('epds_assessments')->where('postpartum_client_id', $pcId)->delete();
        $contractIds = DB::table('mnh_contracts')->where('postpartum_client_id', $pcId)->pluck('id');
        DB::table('mnh_contracts')->whereIn('id', $contractIds)->update(['member_note' => null, 'admin_note' => null, 'updated_at' => $now]);
        foreach (DB::table('mnh_documents')->whereIn('contract_id', $contractIds)->get(['id', 'signature_path', 'pdf_path']) as $d) {
            foreach ([$d->signature_path, $d->pdf_path] as $f) {
                if ($f) {
                    \Illuminate\Support\Facades\Storage::disk('local')->delete($f);
                }
            }
            DB::table('mnh_documents')->where('id', $d->id)->update([
                'content_html' => self::GONE, 'form_data' => null, 'signer_name' => self::GONE, 'signature_path' => null,
                'pdf_path' => null, 'signed_ip' => null, 'signed_ua' => null, 'updated_at' => $now,
            ]);
        }
    }
}
