<?php

namespace App\Console\Commands;

use App\Models\CareSession;
use App\Models\Caregiver;
use App\Models\Settlement;
use App\Models\SettlementItem;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 주간 정산 자동 실행 (월요일 09:00 KST 권장)
 *
 * 지난 주 (월~일) 완료 세션을 인력별로 집계하여 Settlement + SettlementItem 생성.
 * 야간 30% 할증 + 3.3% 원천징수 반영.
 *
 * 사용:
 *   php artisan settlements:run-weekly                # 지난 주
 *   php artisan settlements:run-weekly --period=2026-04-27,2026-05-03
 */
class SettlementsRunWeekly extends Command
{
    protected $signature = 'settlements:run-weekly {--period= : YYYY-MM-DD,YYYY-MM-DD}';
    protected $description = '주간 정산 자동 실행 — 완료 세션 집계 + Settlement 생성';

    public function handle(): int
    {
        if ($period = $this->option('period')) {
            [$startStr, $endStr] = explode(',', $period);
            $periodStart = Carbon::parse($startStr)->startOfDay();
            $periodEnd = Carbon::parse($endStr)->endOfDay();
        } else {
            // 지난 주 월요일 ~ 일요일
            $periodStart = now()->subWeek()->startOfWeek()->startOfDay();
            $periodEnd = now()->subWeek()->endOfWeek()->endOfDay();
        }

        $this->info("정산 기간: {$periodStart->toDateString()} ~ {$periodEnd->toDateString()}");

        $alreadySettled = Settlement::where('period_start', $periodStart->toDateString())
            ->pluck('caregiver_id')
            ->toArray();

        $caregiverIds = CareSession::where('status', 'completed')
            ->whereBetween('actual_end', [$periodStart, $periodEnd])
            ->with('match:id,caregiver_id')
            ->get()
            ->pluck('match.caregiver_id')
            ->filter()
            ->unique()
            ->diff($alreadySettled)
            ->values();

        if ($caregiverIds->isEmpty()) {
            $this->warn('정산 대상 인력 없음');
            return self::SUCCESS;
        }

        $created = 0;
        $totalNet = 0;

        foreach ($caregiverIds as $caregiverId) {
            $sessions = CareSession::where('status', 'completed')
                ->whereBetween('actual_end', [$periodStart, $periodEnd])
                ->whereHas('match', fn ($q) => $q->where('caregiver_id', $caregiverId))
                ->with(['match', 'match.request.category'])
                ->get();

            if ($sessions->isEmpty()) continue;

            $gross = 0;
            DB::transaction(function () use ($sessions, $caregiverId, $periodStart, $periodEnd, &$gross) {
                $settlement = Settlement::create([
                    'caregiver_id'        => $caregiverId,
                    'period_start'        => $periodStart->toDateString(),
                    'period_end'          => $periodEnd->toDateString(),
                    'gross_amount'        => 0,
                    'withholding_tax_3_3' => 0,
                    'net_amount'          => 0,
                    'status'              => 'draft',
                ]);

                foreach ($sessions as $s) {
                    if (!$s->match || !$s->actual_start || !$s->actual_end) continue;
                    $rate = $s->match->hourly_rate;
                    $minutes = $s->duration_min ?? $s->actual_start->diffInMinutes($s->actual_end);
                    $base = (int) round($rate * $minutes / 60);

                    // 야간 할증 30% (22~06시)
                    $h = (int) $s->actual_start->format('H');
                    $isNight = $h >= 22 || $h < 6;
                    $premium = $isNight ? (int) round($base * 0.3) : 0;
                    $itemAmount = $base + $premium;

                    SettlementItem::create([
                        'settlement_id' => $settlement->id,
                        'session_id'    => $s->id,
                        'hours'         => round($minutes / 60, 2),
                        'hourly_rate'   => $rate,
                        'amount'        => $itemAmount,
                        'surcharge'     => $premium,
                    ]);

                    $gross += $itemAmount;
                }

                $tax = (int) round($gross * 0.033);
                $settlement->update([
                    'gross_amount'        => $gross,
                    'withholding_tax_3_3' => $tax,
                    'net_amount'          => $gross - $tax,
                ]);
            });

            $caregiver = Caregiver::find($caregiverId);
            $this->line(sprintf('  • caregiver #%d %s : gross=%s', $caregiverId, $caregiver?->user?->name ?? '?', number_format($gross)));
            $created++;
            $totalNet += $gross;
        }

        $this->info("✅ 정산 생성 {$created}건, 총액 " . number_format($totalNet) . '원');
        return self::SUCCESS;
    }
}
