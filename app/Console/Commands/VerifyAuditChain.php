<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 감사로그 해시 체인 검증 — 행이 고쳐지거나 지워졌으면 그 지점을 알려 준다(2026-09-28, 구현계획 S2).
 * 체인 도입 전 행(hash 가 비어 있음)은 건너뛴다.
 */
class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify';

    protected $description = '감사로그 해시 체인 무결성 검증';

    public function handle(): int
    {
        $prev = null; $checked = 0; $broken = [];
        DB::table('audit_logs')->orderBy('id')->chunk(1000, function ($rows) use (&$prev, &$checked, &$broken) {
            foreach ($rows as $r) {
                if ($r->hash === null) { continue; }
                $a = (array) $r;
                if ($r->prev_hash !== $prev) {
                    $broken[] = "#{$r->id} 직전 해시 불일치(앞 행 삭제·변조)";
                }
                if (AuditLog::chainHash($r->prev_hash, $a) !== $r->hash) {
                    $broken[] = "#{$r->id} 내용 변조";
                }
                $prev = $r->hash; $checked++;
            }
        });
        if ($broken) {
            foreach (array_slice($broken, 0, 20) as $b) { $this->error($b); }
            $this->error(sprintf('체인 손상 %d건 (검사 %d행)', count($broken), $checked));
            return self::FAILURE;
        }
        $this->info("체인 정상 — {$checked}행 검사");
        return self::SUCCESS;
    }
}
