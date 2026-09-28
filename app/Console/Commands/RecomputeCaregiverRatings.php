<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 돌봄전문가 평점 재계산 — 운영 데이터 초기화 절차(S6, 2026-09-29) 1단계.
 * caregivers.rating_avg/rating_count 를 실제 보호자 후기(reviews, reviewer_role=guardian)로 다시 채운다.
 * 데모 시드 평점(35명 중 32명, 실제 후기 1건)이 매칭 점수·목록 정렬에 쓰이던 것을 없앤다. 기본은 미리보기, --execute 로 적용.
 */
class RecomputeCaregiverRatings extends Command
{
    protected $signature = 'caregivers:recompute-ratings {--execute : 실제로 고친다(없으면 미리보기만)}';
    protected $description = '돌봄전문가 평점을 실제 보호자 후기로 재계산';

    public function handle(): int
    {
        $real = DB::table('reviews as rv')->join('matches as m', 'm.id', '=', 'rv.match_id')
            ->where('rv.reviewer_role', 'guardian')
            ->groupBy('m.caregiver_id')
            ->selectRaw('m.caregiver_id, COUNT(*) n, AVG(rv.rating) a')->get()->keyBy('caregiver_id');
        $rows = DB::table('caregivers')->get(['id', 'rating_avg', 'rating_count']);
        $changed = 0;
        foreach ($rows as $c) {
            $n = (int) ($real[$c->id]->n ?? 0);
            $a = $n ? round((float) $real[$c->id]->a, 2) : 0.0;
            if ((int) $c->rating_count === $n && abs((float) $c->rating_avg - $a) < 0.005) {
                continue;
            }
            $changed++;
            if ($changed <= 10) {
                $this->line(sprintf('  #%d  %.2f(%d건) → %.2f(%d건)', $c->id, $c->rating_avg, $c->rating_count, $a, $n));
            }
            if ($this->option('execute')) {
                DB::table('caregivers')->where('id', $c->id)->update(['rating_avg' => $a, 'rating_count' => $n, 'updated_at' => now()]);
            }
        }
        $this->info(($this->option('execute') ? '적용' : '미리보기') . ": 전체 {$rows->count()}명 중 {$changed}명 변경, 실제 후기 보유 {$real->count()}명");
        if ($this->option('execute') && $changed) {
            \App\Models\AuditLog::create(['actor_id' => null, 'action' => 'caregivers.recompute_ratings', 'entity_type' => 'caregiver',
                'details' => ['changed' => $changed, 'with_reviews' => $real->count()], 'ip_address' => '127.0.0.1']);
        }
        return self::SUCCESS;
    }
}
