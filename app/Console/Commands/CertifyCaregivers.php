<?php

namespace App\Console\Commands;

use App\Services\CareandCertService;
use Illuminate\Console\Command;

/**
 * 케어앤에듀 인증 돌봄전문가 자동 부여 — 매일(Kernel). 기준은 config/careand_cert.php(활동·후기 수·평점, 실제 기록 기준).
 * 한 번 취소된 사람은 자동으로 다시 주지 않는다(관리자 화면에서 직접 부여).
 */
class CertifyCaregivers extends Command
{
    protected $signature = 'caregivers:certify {--dry-run : 부여하지 않고 대상만}';
    protected $description = '케어앤에듀 인증 돌봄전문가 자동 부여(활동·평점 기준)';

    public function handle(CareandCertService $svc): int
    {
        $c = $svc->criteria();
        if (!config('careand_cert.auto_grant') && !$this->option('dry-run')) {
            $this->line('자동 부여 꺼짐(CERT_AUTO_GRANT=false)');
            return self::SUCCESS;
        }
        $todo = $svc->pendingAutoGrants();
        $this->line(sprintf('기준: 완료 돌봄 %d회 이상 · 후기 %d건 이상 · 평점 %.1f 이상 → 대상 %d명',
            $c['min_sessions'], $c['min_reviews'], $c['min_rating'], $todo->count()));
        foreach ($todo as $t) {
            $s = $t['stats'];
            if ($this->option('dry-run')) {
                $this->line("  돌봄전문가 #{$t['caregiver_id']} 활동 {$s['sessions']} · 후기 {$s['reviews']} · 평점 {$s['rating']}");
                continue;
            }
            $cert = $svc->grant($t['caregiver_id'], 'auto');
            $this->line("  부여 #{$t['caregiver_id']} {$cert->cert_number}");
        }
        return self::SUCCESS;
    }
}
