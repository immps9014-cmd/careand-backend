<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 보유기간 지난 개인정보 파기 — 개인정보 처리방침 · 사업계획서 3.3 (2026-09-28, 구현계획 S2-5).
 *   ① 음성 일지 원본 파일: 녹음 후 30일 → 파일 삭제(전사 텍스트·일지는 유지)
 *   ② 출퇴근 위치(GPS 좌표): 기록 후 90일 → 좌표 파기(0,0), 시각·이벤트는 정산 근거로 유지
 *   ③ 탈퇴 회원: 탈퇴 후 30일 → 계정 식별정보와 본인·돌봄대상자 개인정보 파기(행은 남겨 정산·통계 무결성 유지)
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
        $done['voice_files'] = $voices->count();

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
                        'license_no' => null, 'license_image_url' => null, 'updated_at' => now(),
                    ]);
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

        foreach (['voice_files' => '음성 원본 파일(30일)', 'gps_points' => '출퇴근 위치 좌표(90일)', 'withdrawn_users' => '탈퇴 회원(30일)'] as $k => $label) {
            $this->line(sprintf('  %-24s %d건', $label, $done[$k]));
        }
        if ($run && array_sum($done) > 0) {
            AuditLog::create(['actor_id' => null, 'action' => 'privacy.purge', 'entity_type' => 'privacy', 'details' => $done, 'ip_address' => '127.0.0.1']);
        }
        return self::SUCCESS;
    }
}
