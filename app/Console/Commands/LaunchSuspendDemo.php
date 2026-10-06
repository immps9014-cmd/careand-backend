<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 서비스 개시 때 데모 공용 비밀번호 계정(@demo.careand.kr)을 정지한다(2026-10-07 사용자 결정: 삭제 아닌 정지).
 *
 *  - users.status → suspended (로그인·기존 토큰·갱신 모두 막힘 — Authenticate 미들웨어)
 *  - caregivers.status → suspended (매칭 후보·목록은 caregivers.status 만 보므로 이걸 바꿔야 실회원에게 안 뜬다)
 *  - 데모 돌봄전문가에게 걸린 응답 대기(pending) 후보 → expired
 * 바꾸기 전 상태는 storage/app/launch/demo_suspend_<시각>.json 에 남기고 --restore 로 되돌린다(후보 만료는 되돌리지 않음).
 */
class LaunchSuspendDemo extends Command
{
    protected $signature = 'launch:suspend-demo
        {--dry-run : 바꾸지 않고 대상 수만}
        {--restore= : 이 기록 파일(storage/app/launch/…json)로 되돌리기}';
    protected $description = '개시 때 데모 계정(@demo.careand.kr) 일괄 정지 / 되돌리기';

    private const DOMAIN = '%@demo.careand.kr';

    public function handle(): int
    {
        if ($file = $this->option('restore')) {
            return $this->restore($file);
        }

        $users = DB::table('users')->whereNull('deleted_at')->where('email', 'like', self::DOMAIN)
            ->where('status', '!=', 'suspended')->get(['id', 'status']);
        $allIds = DB::table('users')->whereNull('deleted_at')->where('email', 'like', self::DOMAIN)->pluck('id');
        $cgs = DB::table('caregivers')->whereIn('user_id', $allIds)->whereNotIn('status', ['suspended', 'rejected'])
            ->get(['id', 'status']);
        $cgAll = DB::table('caregivers')->whereIn('user_id', $allIds)->pluck('id');
        $cands = DB::table('match_candidates')->whereIn('caregiver_id', $cgAll)->where('response', 'pending')->pluck('id');

        $this->line("데모 계정 {$allIds->count()}개 중 정지 대상: 회원 {$users->count()} · 돌봄전문가 프로필 {$cgs->count()} · 응답 대기 후보 {$cands->count()}");
        if ($this->option('dry-run')) {
            $this->line('(dry-run — 바꾸지 않음)');
            return self::SUCCESS;
        }
        if ($users->isEmpty() && $cgs->isEmpty() && $cands->isEmpty()) {
            $this->info('이미 모두 정지돼 있습니다.');
            return self::SUCCESS;
        }

        $path = 'launch/demo_suspend_' . now('Asia/Seoul')->format('Ymd_His') . '.json';
        Storage::put($path, json_encode([
            'at' => now('Asia/Seoul')->toIso8601String(),
            'users' => $users->pluck('status', 'id'),
            'caregivers' => $cgs->pluck('status', 'id'),
            'expired_candidates' => $cands,
        ], JSON_PRETTY_PRINT));

        DB::transaction(function () use ($users, $cgs, $cands) {
            DB::table('users')->whereIn('id', $users->pluck('id'))->update(['status' => 'suspended', 'updated_at' => now()]);
            DB::table('caregivers')->whereIn('id', $cgs->pluck('id'))->update(['status' => 'suspended', 'updated_at' => now()]);
            DB::table('match_candidates')->whereIn('id', $cands)->where('response', 'pending')
                ->update(['response' => 'expired', 'responded_at' => now(), 'updated_at' => now()]);
        });

        $this->info("정지 완료. 되돌리기: php artisan launch:suspend-demo --restore={$path}");
        return self::SUCCESS;
    }

    private function restore(string $path): int
    {
        if (!Storage::exists($path)) {
            $this->error("기록 파일이 없습니다: storage/app/{$path}");
            return self::FAILURE;
        }
        $snap = json_decode(Storage::get($path), true);
        $n = ['users' => 0, 'caregivers' => 0];
        DB::transaction(function () use ($snap, &$n) {
            // 정지 뒤 운영자가 따로 바꾼 상태는 건드리지 않게 지금 suspended 인 것만 되돌린다
            foreach ($snap['users'] ?? [] as $id => $status) {
                $n['users'] += DB::table('users')->where('id', $id)->where('status', 'suspended')->update(['status' => $status, 'updated_at' => now()]);
            }
            foreach ($snap['caregivers'] ?? [] as $id => $status) {
                $n['caregivers'] += DB::table('caregivers')->where('id', $id)->where('status', 'suspended')->update(['status' => $status, 'updated_at' => now()]);
            }
        });
        $this->info("되돌림: 회원 {$n['users']} · 돌봄전문가 프로필 {$n['caregivers']} (만료된 후보 " . count($snap['expired_candidates'] ?? []) . '건은 그대로)');
        return self::SUCCESS;
    }
}
