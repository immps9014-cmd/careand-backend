<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * STT 케어용어 인식률 평가 결과(JSON)를 stt_evaluations 에 적재 — 사업계획서 KPI 1 (2026-09-28, 구현계획 S1).
 * 평가는 careand-ai-service/eval/stt_careterm_eval.py 가 하고, 결과 파일을 이 명령으로 넣는다.
 *   php artisan kpi:import-stt /root/caren/careand-ai-service/eval/stt/results/<파일>.json
 */
class ImportSttEvaluation extends Command
{
    protected $signature = 'kpi:import-stt {file : 평가 스크립트가 만든 결과 JSON}';

    protected $description = 'STT 케어용어 인식률 평가 결과를 KPI 테이블에 적재';

    public function handle(): int
    {
        $file = $this->argument('file');
        if (!is_readable($file)) {
            $this->error("파일을 읽을 수 없습니다: {$file}");
            return self::FAILURE;
        }
        $r = json_decode(file_get_contents($file), true);
        foreach (['run_label', 'term_set', 'stt_engine', 'samples', 'terms_total', 'terms_correct', 'evaluated_at'] as $k) {
            if (!isset($r[$k])) {
                $this->error("결과 JSON에 {$k} 가 없습니다.");
                return self::FAILURE;
            }
        }
        if ((int) $r['terms_total'] === 0) {
            $this->error('정답 전사에 케어용어가 하나도 없어 인식률을 계산할 수 없습니다.');
            return self::FAILURE;
        }

        $rate = round($r['terms_correct'] / $r['terms_total'] * 100, 2);
        DB::table('stt_evaluations')->insert([
            'run_label' => $r['run_label'],
            'term_set' => $r['term_set'],
            'stt_engine' => $r['stt_engine'],
            'samples' => (int) $r['samples'],
            'terms_total' => (int) $r['terms_total'],
            'terms_correct' => (int) $r['terms_correct'],
            'rate' => $rate,
            'details' => json_encode($r['details'] ?? null, JSON_UNESCAPED_UNICODE),
            'evaluated_at' => $r['evaluated_at'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info(sprintf('적재 완료: %s — %d/%d = %.2f%% (음성 %d건)',
            $r['run_label'], $r['terms_correct'], $r['terms_total'], $rate, $r['samples']));
        return self::SUCCESS;
    }
}
