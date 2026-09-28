<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * 관리자 2단계 인증 초기화(휴대폰 분실 등 비상 복구) — 다음 로그인 때 인증 앱을 다시 등록하게 된다.
 *   php artisan admin:2fa-reset admin@careand.co.kr
 */
class ResetAdminTwoFactor extends Command
{
    protected $signature = 'admin:2fa-reset {email : 관리자 이메일(아이디)}';

    protected $description = '관리자 2단계 인증 초기화(다음 로그인 때 재등록)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->where('role', 'admin')->first();
        if (!$user) {
            $this->error('해당 관리자 계정이 없습니다.');
            return self::FAILURE;
        }
        $user->forceFill(['totp_secret' => null, 'totp_enabled_at' => null])->save();
        AuditLog::create(['actor_id' => null, 'action' => 'auth.2fa.reset', 'entity_type' => 'auth', 'entity_id' => $user->id,
            'details' => ['by' => 'artisan'], 'ip_address' => '127.0.0.1']);
        $this->info("초기화 완료 — {$user->email} 은 다음 로그인 때 인증 앱을 다시 등록합니다.");
        return self::SUCCESS;
    }
}
