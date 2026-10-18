<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 모니터링 설문 다시 알림 — 매일(Kernel, 한국 시각 10:00). 서류 보관함 2단계(2026-10-18).
 * 계약 종료 때 자동 발행된 「서비스 만족도 모니터링」에 산모가 아직 답하지 않았으면 발행 1일·3일 뒤에 산모에게 다시 알린다.
 * 3일째에도 답이 없으면 그날 건을 묶어 산모신생아 담당 관리자에게 알린다(전화 모니터링 대상).
 * 단계마다 한 번만(mnh_reminders kind=survey, step 1|2). 발행 STALE_DAYS 일이 지난 설문은 알리지 않는다.
 */
class NotifyMnhSurveyRemind extends Command
{
    protected $signature = 'mnh:survey-remind {--dry-run : 보내지 않고 대상만}';
    protected $description = '바우처 모니터링 설문 미응답 다시 알림(발행 1·3일 뒤)';

    /** step => 발행 후 며칠째부터 */
    private const STEPS = [1 => 1, 2 => 3];
    private const STALE_DAYS = 14;

    public function handle(NotificationService $notifier): int
    {
        $tz = 'Asia/Seoul';
        $today = Carbon::now($tz)->startOfDay();
        $rows = DB::table('mnh_documents as d')
            ->join('mnh_contracts as c', 'c.id', '=', 'd.contract_id')
            ->leftJoin('postpartum_clients as pc', 'pc.id', '=', 'c.postpartum_client_id')
            ->where('d.doc_type', 'satisfaction')->where('d.status', 'issued')->where('c.status', '!=', 'cancelled')
            ->where('d.created_at', '>=', $today->copy()->subDays(self::STALE_DAYS)->utc())
            ->get(['d.id', 'd.created_at', 'd.signer_user_id', 'c.id as contract_id', 'c.contract_no', 'pc.name as client_name']);
        $done = DB::table('mnh_reminders')->where('kind', 'survey')->whereIn('ref_id', $rows->pluck('id'))
            ->get(['ref_id', 'step'])->groupBy('ref_id')->map(fn ($g) => (int) $g->max('step'));

        $sent = 0;
        $escalate = [];
        foreach ($rows as $r) {
            $age = (int) Carbon::parse($r->created_at, 'UTC')->setTimezone($tz)->startOfDay()->diffInDays($today);
            $step = collect(self::STEPS)->filter(fn ($days) => $age >= $days)->keys()->max();
            if (!$step || ($done[$r->id] ?? 0) >= $step) {
                continue;   // 아직 이르거나 이 단계는 이미 보냄
            }
            $this->line(sprintf('%s 설문 #%d %s 산모 · 발행 %d일째 · %d차', $this->option('dry-run') ? '  (미발송)' : ' ', $r->id, $r->client_name ?? '-', $age, $step));
            if ($this->option('dry-run')) {
                continue;
            }
            $notifier->notifySafely($r->signer_user_id ? (int) $r->signer_user_id : null, NotificationService::TYPE_MNH_SURVEY_REMIND, [
                'document_id' => (int) $r->id, 'contract_id' => (int) $r->contract_id, 'step' => $step,
            ]);
            DB::table('mnh_reminders')->insert(['kind' => 'survey', 'ref_id' => $r->id, 'step' => $step, 'sent_at' => now()]);
            $sent++;
            if ($step === max(array_keys(self::STEPS))) {
                $escalate[] = ['client_name' => $r->client_name, 'contract_no' => $r->contract_no, 'days' => $age];
            }
        }

        if ($escalate) {
            foreach ($notifier->adminsFor('mnh') as $adminId) {
                $notifier->notifySafely($adminId, NotificationService::TYPE_MNH_SURVEY_PENDING, ['count' => count($escalate), 'items' => array_slice($escalate, 0, 20)]);
            }
        }
        $this->line(sprintf('미응답 %d건 · 보냄 %d건 · 관리자 전달 %d건', $rows->count(), $sent, count($escalate)));

        return self::SUCCESS;
    }
}
