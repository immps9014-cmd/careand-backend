<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 미매칭 요청 자동 만료 — 예정시각(+유예)이 지나도 매칭되지 않은 open/matching 요청을 expired 처리하고
 * 보호자에게 만료 알림을 발송한다. (매시 정각 스케줄)
 *
 * 사용:
 *   php artisan requests:expire-stale                 # 예정시각 지난 즉시
 *   php artisan requests:expire-stale --grace=60      # 예정시각 +60분 유예
 *   php artisan requests:expire-stale --dry-run       # 변경 없이 대상만 출력
 */
class ExpireStaleRequests extends Command
{
    protected $signature = 'requests:expire-stale {--grace=0 : 예정시각 이후 유예(분)} {--dry-run : 변경 없이 대상만 출력}';
    protected $description = '예정시각이 지난 미매칭 요청(open/matching)을 만료 처리 + 보호자 알림';

    public function handle(NotificationService $notifications): int
    {
        $grace = (int) $this->option('grace');
        $dryRun = (bool) $this->option('dry-run');
        $threshold = now()->subMinutes($grace);

        $rows = DB::table('match_requests as r')
            ->join('guardians as g', 'g.id', '=', 'r.guardian_id')
            ->leftJoin('seniors as s', 's.id', '=', 'r.senior_id')
            ->leftJoin('nursing_patients as np', 'np.id', '=', 'r.nursing_patient_id')
            ->leftJoin('service_addresses as sa', 'sa.id', '=', 'r.service_address_id')
            ->whereIn('r.status', ['open', 'matching'])
            ->whereNotNull('r.scheduled_start')
            ->where('r.scheduled_start', '<', $threshold)
            ->select(
                'r.id',
                'r.guardian_id',
                'r.scheduled_start',
                'g.user_id as guardian_user_id',
                DB::raw('COALESCE(s.name, np.name, sa.label) as target_name')
            )
            ->get();

        if ($rows->isEmpty()) {
            $this->info('만료 대상 없음.');
            return self::SUCCESS;
        }

        $this->info(sprintf('만료 대상 %d건 (기준: %s 이전)', $rows->count(), $threshold->toDateTimeString()));

        if ($dryRun) {
            foreach ($rows as $r) {
                $this->line(sprintf('  - #%d %s (%s)', $r->id, $r->target_name ?? '(미상)', $r->scheduled_start));
            }
            return self::SUCCESS;
        }

        $ids = $rows->pluck('id')->all();
        DB::table('match_requests')->whereIn('id', $ids)
            ->update(['status' => 'expired', 'updated_at' => now()]);

        $notified = 0;
        foreach ($rows as $r) {
            try {
                $n = $notifications->notify((int) $r->guardian_user_id, NotificationService::TYPE_MATCH_REQUEST_EXPIRED, [
                    'request_id' => $r->id,
                    'target_name' => $r->target_name ?? '대상자',
                    'scheduled_at' => Carbon::parse($r->scheduled_start)->format('n월 j일 H:i'),
                ]);
                if ($n) {
                    $notified++;
                }
            } catch (\Throwable $e) {
                $this->warn("알림 실패 #{$r->id}: {$e->getMessage()}");
            }
        }

        $this->info(sprintf('완료: %d건 만료, %d건 알림 발송.', count($ids), $notified));

        return self::SUCCESS;
    }
}
