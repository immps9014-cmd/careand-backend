<?php

namespace App\Console\Commands;

use App\Models\MnhContract;
use App\Models\MnhDocument;
use App\Services\MnhDocumentService;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 시작 전 서류 미비 사전 알림 — 매일(Kernel, 한국 시각 17:00). 서류 보관함 5단계(2026-10-19).
 * MNH_DOCS_ENFORCE=true 면 시작 전 서류(이용계약서·별지·동의서·준수사항·초기상담·제공계획서)가 모두 서명돼야 첫 출근이 된다.
 * 앞으로 48시간 안에 방문이 있는데 덜 된 계약을 찾아 — 산모에게는 서명할 서류(발행된 것)를, 담당 관리자에게는
 * 계약별 남은 서류(관리자가 발행해야 하는 초기상담·제공계획서 포함)를 묶어 알린다. 같은 계약은 하루 한 번(mnh_reminders kind=docs_due_MMDD).
 * 출근 차단을 켜지 않았어도 돌린다 — 켜기 전에도 서류를 미리 갖추게.
 */
class NotifyMnhDocsBeforeStart extends Command
{
    protected $signature = 'mnh:docs-before-start {--dry-run : 보내지 않고 대상만}';
    protected $description = '바우처 시작 전 서류 미비 사전 알림(48시간 안 방문)';

    private const WINDOW_HOURS = 48;

    public function handle(MnhDocumentService $docs, NotificationService $notifier): int
    {
        $kind = 'docs_due_' . Carbon::now('Asia/Seoul')->format('md');
        $contractIds = DB::table('care_sessions as cs')
            ->join('matches as m', 'm.id', '=', 'cs.match_id')
            ->join('mnh_contracts as c', 'c.match_request_id', '=', 'm.request_id')
            ->whereIn('c.status', ['confirmed', 'active'])->where('cs.status', 'scheduled')
            ->whereBetween('cs.scheduled_start', [now(), now()->addHours(self::WINDOW_HOURS)])
            ->whereNotExists(fn ($q) => $q->from('mnh_reminders as r')->whereColumn('r.ref_id', 'c.id')->where('r.kind', $kind))
            ->distinct()->pluck('c.id');

        $items = [];
        foreach (MnhContract::whereIn('id', $contractIds)->get() as $c) {
            $missing = $docs->contractStatus($c)['missing_before_start'];
            if (!$missing) {
                continue;
            }
            $client = DB::table('postpartum_clients')->where('id', $c->postpartum_client_id)->value('name');
            // 산모가 지금 서명할 수 있는 것(발행됐는데 서명 전) — 나머지는 관리자가 먼저 발행해야 함
            $signable = MnhDocument::where('contract_id', $c->id)->where('status', 'issued')->where('signer_role', 'client')
                ->where('doc_type', '!=', 'provision_record')->count();
            $this->line(sprintf('%s %s %s 산모 · 남은 서류: %s · 산모가 서명할 것 %d건', $this->option('dry-run') ? '  (미발송)' : ' ',
                $c->contract_no, $client ?? '-', implode(', ', $missing), $signable));
            $items[] = ['contract_no' => $c->contract_no, 'client_name' => $client, 'missing' => $missing];
            if ($this->option('dry-run')) {
                continue;
            }
            if ($signable > 0) {
                $notifier->notifySafely((int) $c->user_id, NotificationService::TYPE_MNH_DOCS_DUE, ['contract_id' => $c->id, 'count' => $signable]);
            }
            DB::table('mnh_reminders')->insert(['kind' => $kind, 'ref_id' => $c->id, 'step' => 1, 'sent_at' => now()]);
        }

        if ($items && !$this->option('dry-run')) {
            foreach ($notifier->adminsFor('mnh') as $adminId) {
                $notifier->notifySafely($adminId, NotificationService::TYPE_MNH_DOCS_DUE_DIGEST, [
                    'count' => count($items), 'items' => array_slice($items, 0, 20), 'enforce' => (bool) config('mnh_docs.enforce'),
                ]);
            }
        }
        $this->line(sprintf('48시간 안 방문 계약 %d건 · 서류 미비 %d건', $contractIds->count(), count($items)));

        return self::SUCCESS;
    }
}
