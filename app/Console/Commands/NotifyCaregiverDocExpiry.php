<?php

namespace App\Console\Commands;

use App\Services\CaregiverDocumentService;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 돌봄전문가 서류 만료 알림 — 매일(Kernel, 한국 시각 09:00). CAREN-MNH-02 E(2026-10-18).
 * 유효기간이 있는 서류(config caregiver_docs valid_days: 범죄경력·건강진단·정신질환·아동학대교육·보수교육)가
 * 만료 30일·7일 전, 만료 날에 본인에게 알리고, 그날 알린 건을 묶어 자격검증 관리자에게 한 번 알린다.
 * 같은 단계는 다시 보내지 않는다(caregiver_documents.expiry_notified_days). 만료된 지 7일이 넘은 옛 서류는 알리지 않는다.
 */
class NotifyCaregiverDocExpiry extends Command
{
    protected $signature = 'caregivers:doc-expiry {--dry-run : 보내지 않고 대상만}';
    protected $description = '돌봄전문가 서류 만료 알림(30·7일 전, 만료 날)';

    private const STALE_DAYS = 7;

    public function handle(CaregiverDocumentService $docs, NotificationService $notifier): int
    {
        $stages = collect(config('caregiver_docs.expiry_notice_days', [30, 7]))->map(fn ($d) => (int) $d)->sort()->values();
        $today = Carbon::now('Asia/Seoul')->startOfDay();
        $rows = DB::table('caregiver_documents as d')
            ->join('caregivers as c', 'c.id', '=', 'd.caregiver_id')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->whereIn('d.status', ['verified', 'submitted'])->whereNotNull('d.expires_at')
            ->whereBetween('d.expires_at', [$today->copy()->subDays(self::STALE_DAYS)->toDateString(), $today->copy()->addDays($stages->max())->toDateString()])
            ->whereNull('u.deleted_at')->where('u.status', 'active')
            ->get(['d.id', 'd.caregiver_id', 'd.doc_type', 'd.expires_at', 'd.expiry_notified_days', 'u.id as user_id', 'u.name']);

        $types = [];
        $sent = [];
        foreach ($rows as $r) {
            $types[$r->caregiver_id] ??= CaregiverDocumentService::typesFor((int) $r->caregiver_id);
            $meta = $types[$r->caregiver_id][$r->doc_type] ?? null;
            if (!$meta) {
                continue;   // 이 사람 직군에 해당 없는 서류
            }
            $left = (int) $today->diffInDays(Carbon::parse($r->expires_at, 'Asia/Seoul')->startOfDay(), false);
            $stage = $left <= 0 ? 0 : $stages->first(fn ($s) => $left <= $s);
            if ($r->expiry_notified_days !== null && (int) $r->expiry_notified_days <= $stage) {
                continue;   // 이 단계(또는 더 가까운 단계)는 이미 보냄
            }
            $line = sprintf('#%d %s %s %s', $r->caregiver_id, $r->name, $meta['label'], $left > 0 ? "{$left}일 남음" : ($left === 0 ? '오늘 만료' : '만료됨'));
            $this->line(($this->option('dry-run') ? '  (미발송) ' : '  ') . $line);
            if ($this->option('dry-run')) {
                continue;
            }
            $notifier->notifySafely((int) $r->user_id, NotificationService::TYPE_CAREGIVER_DOC_EXPIRING, [
                'doc_type' => $r->doc_type, 'doc_label' => $meta['label'], 'expires_at' => $r->expires_at, 'days_left' => $left,
            ]);
            DB::table('caregiver_documents')->where('id', $r->id)->update(['expiry_notified_days' => $stage]);
            $sent[] = ['caregiver_id' => (int) $r->caregiver_id, 'name' => $r->name, 'doc_label' => $meta['label'], 'days_left' => $left];
        }

        if ($sent) {
            foreach ($notifier->adminsFor('caregivers') as $adminId) {
                $notifier->notifySafely($adminId, NotificationService::TYPE_CAREGIVER_DOCS_EXPIRING, ['count' => count($sent), 'items' => array_slice($sent, 0, 20)]);
            }
        }
        $this->line(sprintf('대상 %d건 · 보냄 %d건', $rows->count(), count($sent)));

        return self::SUCCESS;
    }
}
