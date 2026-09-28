<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // 매주 월요일 09:00 KST 주간 정산 (UTC = 일요일 24:00 → 다음 월 00:00)
        $schedule->command('settlements:run-weekly')
            ->weeklyOn(1, '00:00')   // UTC 기준 월요일 00:00 = KST 월요일 09:00
            ->onOneServer()
            ->timezone('UTC')
            ->emailOutputOnFailure(config('mail.from.address', 'admin@careand.co.kr'))
            ->appendOutputTo(storage_path('logs/settlements.log'));

        // 미매칭 요청 자동 만료 (매시 정각) — 예정시각 지난 open/matching → expired + 보호자 알림
        $schedule->command('requests:expire-stale')
            ->hourly()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/expire-stale.log'));

        // 보유기간 지난 개인정보 파기(음성 원본 30일·위치 90일·탈퇴 30일) — 개인정보 처리방침 제3조·제6조 (2026-09-28 S2-5)
        $schedule->command('privacy:purge --execute')
            ->dailyAt('04:40')
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/privacy-purge.log'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
