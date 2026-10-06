<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * caregivers.completed_sessions 를 실제 완료 돌봄(care_sessions.status=completed, matches 경유)으로 다시 채운다(2026-10-07).
 * 저장값이 데모 시드(예: 185건)와 섞여 상세 화면 「완료 케어」와 인증 기준(실제 기록)이 어긋났다.
 * 이후엔 퇴근 처리(CareSessionController checkout)가 1씩 올린다. 바꾸기 전 값은 storage/app/recompute/ 에 남긴다.
 * caregivers:recompute-ratings 와 같은 방식.
 */
class RecomputeCaregiverSessions extends Command
{
    protected $signature = 'caregivers:recompute-sessions {--execute : 실제로 고친다(없으면 미리보기만)}';
    protected $description = '돌봄전문가 완료 횟수를 실제 완료 돌봄 기록으로 재계산';

    public function handle(): int
    {
        $real = DB::table('care_sessions as cs')->join('matches as m', 'm.id', '=', 'cs.match_id')
            ->where('cs.status', 'completed')->groupBy('m.caregiver_id')
            ->selectRaw('m.caregiver_id, COUNT(*) n')->pluck('n', 'caregiver_id');
        $rows = DB::table('caregivers')->get(['id', 'completed_sessions']);
        $changes = [];
        foreach ($rows as $c) {
            $n = (int) ($real[$c->id] ?? 0);
            if ((int) $c->completed_sessions !== $n) {
                $changes[$c->id] = ['from' => (int) $c->completed_sessions, 'to' => $n];
            }
        }
        foreach (array_slice($changes, 0, 10, true) as $id => $ch) {
            $this->line("  #{$id}  {$ch['from']}회 → {$ch['to']}회");
        }
        if (!$this->option('execute')) {
            $this->info("미리보기: 전체 {$rows->count()}명 중 " . count($changes) . "명 변경, 실제 완료 기록 보유 {$real->count()}명");
            return self::SUCCESS;
        }
        if ($changes) {
            $path = 'recompute/completed_sessions_' . now('Asia/Seoul')->format('Ymd_His') . '.json';
            Storage::put($path, json_encode($changes, JSON_PRETTY_PRINT));
            DB::transaction(function () use ($changes) {
                foreach ($changes as $id => $ch) {
                    DB::table('caregivers')->where('id', $id)->update(['completed_sessions' => $ch['to'], 'updated_at' => now()]);
                }
            });
            \App\Models\AuditLog::create(['actor_id' => null, 'action' => 'caregivers.recompute_sessions', 'entity_type' => 'caregiver',
                'details' => ['changed' => count($changes), 'backup' => $path], 'ip_address' => '127.0.0.1']);
            $this->info("적용: " . count($changes) . "명 변경(이전 값 storage/app/{$path})");
        } else {
            $this->info('이미 실제 기록과 같습니다.');
        }
        return self::SUCCESS;
    }
}
