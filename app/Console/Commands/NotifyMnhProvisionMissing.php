<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 제공기록지 누락 알림 — 매일(Kernel, 한국 시각 20:00). 서류 보관함 2단계(2026-10-18).
 * 바우처 계약 방문 중 끝난(completed) 방문인데 서명된 제공기록지가 없으면 그 방문의 건강관리사에게 알리고,
 * 그날 알린 건을 묶어 산모신생아 담당 관리자에게 한 번 알린다. 방문 한 건당 한 번만(mnh_reminders kind=provision).
 * 서버가 멈춰 하루를 건너뛰어도 따라잡도록 최근 LOOKBACK_DAYS 일 방문을 본다.
 */
class NotifyMnhProvisionMissing extends Command
{
    protected $signature = 'mnh:provision-missing {--dry-run : 보내지 않고 대상만}';
    protected $description = '바우처 제공기록지 누락 알림(끝난 방문인데 산모 서명 없음)';

    private const LOOKBACK_DAYS = 7;

    public function handle(NotificationService $notifier): int
    {
        $tz = 'Asia/Seoul';
        $from = Carbon::now($tz)->subDays(self::LOOKBACK_DAYS - 1)->startOfDay()->utc();
        $to = Carbon::now($tz)->addDay()->startOfDay()->utc();
        $rows = DB::table('care_sessions as cs')
            ->join('matches as m', 'm.id', '=', 'cs.match_id')
            ->join('mnh_contracts as c', 'c.match_request_id', '=', 'm.request_id')
            ->leftJoin('caregivers as cg', 'cg.id', '=', 'm.caregiver_id')
            ->leftJoin('users as u', 'u.id', '=', 'cg.user_id')
            ->leftJoin('postpartum_clients as pc', 'pc.id', '=', 'c.postpartum_client_id')
            ->where('cs.status', 'completed')->where('cs.scheduled_start', '>=', $from)->where('cs.scheduled_start', '<', $to)
            ->whereNotExists(fn ($q) => $q->from('mnh_documents as d')->whereColumn('d.care_session_id', 'cs.id')
                ->where('d.doc_type', 'provision_record')->where('d.status', 'signed'))
            ->whereNotExists(fn ($q) => $q->from('mnh_reminders as r')->whereColumn('r.ref_id', 'cs.id')->where('r.kind', 'provision'))
            ->orderBy('cs.scheduled_start')
            ->get(['cs.id', 'cs.scheduled_start', 'c.id as contract_id', 'c.contract_no', 'u.id as user_id', 'u.name as caregiver_name', 'pc.name as client_name']);

        $sent = [];
        foreach ($rows as $r) {
            $date = Carbon::parse($r->scheduled_start, 'UTC')->setTimezone($tz);
            $line = sprintf('방문 #%d %s %s 산모 · 관리사 %s', $r->id, $date->format('m-d'), $r->client_name ?? '-', $r->caregiver_name ?? '-');
            $this->line(($this->option('dry-run') ? '  (미발송) ' : '  ') . $line);
            if ($this->option('dry-run')) {
                continue;
            }
            $notifier->notifySafely($r->user_id ? (int) $r->user_id : null, NotificationService::TYPE_MNH_PROVISION_MISSING, [
                'session_id' => (int) $r->id, 'contract_id' => (int) $r->contract_id, 'client_name' => $r->client_name,
                'date' => $date->format('n월 j일'),
            ]);
            DB::table('mnh_reminders')->insert(['kind' => 'provision', 'ref_id' => $r->id, 'step' => 1, 'sent_at' => now()]);
            $sent[] = ['client_name' => $r->client_name, 'caregiver_name' => $r->caregiver_name, 'date' => $date->format('n/j'), 'contract_no' => $r->contract_no];
        }

        if ($sent) {
            foreach ($notifier->adminsFor('mnh') as $adminId) {
                $notifier->notifySafely($adminId, NotificationService::TYPE_MNH_PROVISION_MISSING_DIGEST, ['count' => count($sent), 'items' => array_slice($sent, 0, 20)]);
            }
        }
        $this->line(sprintf('대상 %d건 · 보냄 %d건', $rows->count(), count($sent)));

        return self::SUCCESS;
    }
}
