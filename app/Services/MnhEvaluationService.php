<?php

namespace App\Services;

use App\Exceptions\MnhContractException;
use App\Models\MnhContract;
use App\Support\Kst;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 산모신생아 양방향 평가와 종합평가 육각형(CAREN-MNH-01 4단계, 2026-10-05).
 * 평가 항목·육각형 축 정의 SSOT = config/mnh_eval.php.
 */
class MnhEvaluationService
{
    public function __construct(private MnhContractService $contracts, private NotificationService $notifier)
    {
    }

    /** 이 계약에서 일한 돌봄전문가 id(교체 전후 모두) */
    public function contractCaregivers(MnhContract $c): array
    {
        if (!$c->match_request_id) {
            return [];
        }

        return DB::table('matches')->where('request_id', $c->match_request_id)->where('status', '!=', 'cancelled')
            ->distinct()->pluck('caregiver_id')->map(fn ($i) => (int) $i)->all();
    }

    /** 이 돌봄전문가의 이 계약 일정이 다 끝났는지(남은 예정·진행 방문 없음 + 완료 1회 이상) */
    private function caregiverDone(MnhContract $c, int $caregiverId): bool
    {
        $rows = $this->contracts->sessions($c)->where('caregiver_id', $caregiverId)->where('status', '!=', 'cancelled');

        return $rows->isNotEmpty() && $rows->every(fn ($s) => $s->status === 'completed');
    }

    /**
     * 평가 저장. kind=caregiver_to_client 는 그 계약 담당(이었던) 관리사만, org_to_caregiver 는 관리자.
     * final 은 계약(또는 그 관리사 몫)이 끝났을 때 한 번만 — 다시 내면 덮어쓴다.
     */
    public function submit(string $kind, ?MnhContract $c, int $caregiverId, int $evaluatorUserId, array $scores, ?string $comment, string $timing): array
    {
        $items = config("mnh_eval.$kind");
        if (!$items) {
            throw new MnhContractException('UNKNOWN_KIND', '알 수 없는 평가 종류예요.');
        }
        $clean = [];
        foreach ($items as $k => $label) {
            $v = (int) ($scores[$k] ?? 0);
            if ($v < 1 || $v > 5) {
                throw new MnhContractException('SCORE_REQUIRED', "「{$label}」 점수(1~5)를 골라 주세요.");
            }
            $clean[$k] = $v;
        }
        if (!array_key_exists($timing, config('mnh_eval.timings'))) {
            throw new MnhContractException('BAD_TIMING', '수시 또는 종료 평가만 있어요.');
        }
        if ($kind === 'caregiver_to_client' && !$c) {
            throw new MnhContractException('CONTRACT_REQUIRED', '계약이 있어야 이용자를 평가할 수 있어요.');
        }
        if ($c) {
            if (!in_array($caregiverId, $this->contractCaregivers($c), true)) {
                throw new MnhContractException('NOT_IN_CONTRACT', '이 계약을 맡은 돌봄전문가가 아니에요.', 403);
            }
            if ($c->status === 'cancelled' || $c->status === 'applied') {
                throw new MnhContractException('NOT_STARTED', '서비스가 진행 중이거나 끝난 계약만 평가할 수 있어요.');
            }
            if ($timing === 'final' && $c->status !== 'completed' && !$this->caregiverDone($c, $caregiverId)) {
                throw new MnhContractException('NOT_FINISHED', '종료 평가는 서비스가 끝난 뒤에 할 수 있어요. 지금은 수시 평가로 남겨 주세요.');
            }
        }
        $comment = $comment !== null ? mb_substr(trim($comment), 0, 2000) : null;
        $now = now();
        $row = [
            'kind' => $kind, 'contract_id' => $c?->id, 'caregiver_id' => $caregiverId,
            'postpartum_client_id' => $c?->postpartum_client_id, 'evaluator_user_id' => $evaluatorUserId,
            'timing' => $timing, 'scores' => json_encode($clean), 'comment' => $comment ?: null, 'updated_at' => $now,
        ];
        $existing = $timing === 'final' && $c ? DB::table('mnh_evaluations')->where('kind', $kind)->where('contract_id', $c->id)
            ->where('caregiver_id', $caregiverId)->where('timing', 'final')->value('id') : null;
        if ($existing) {
            DB::table('mnh_evaluations')->where('id', $existing)->update($row);
            $id = $existing;
        } else {
            $id = DB::table('mnh_evaluations')->insertGetId($row + ['created_at' => $now]);
        }
        if ($c) {
            $this->contracts->log($c, 'evaluated', null, ['kind' => $kind, 'caregiver_id' => $caregiverId, 'timing' => $timing, 'evaluation_id' => $id], $evaluatorUserId);
        }

        return $this->present(DB::table('mnh_evaluations')->find($id));
    }

    public function present(object $e): array
    {
        $kind = $e->kind;
        $scores = json_decode($e->scores, true) ?: [];

        return [
            'id' => $e->id, 'kind' => $kind, 'contract_id' => $e->contract_id, 'caregiver_id' => $e->caregiver_id,
            'timing' => $e->timing, 'timing_label' => config("mnh_eval.timings.{$e->timing}"),
            'scores' => $scores,
            'items' => collect(config("mnh_eval.$kind") ?? [])->map(fn ($l, $k) => ['key' => $k, 'label' => $l, 'score' => $scores[$k] ?? null])->values(),
            'average' => $scores ? round(array_sum($scores) / count($scores), 2) : null,
            'comment' => $e->comment, 'evaluator_user_id' => $e->evaluator_user_id,
            'created_at' => Kst::iso($e->created_at), 'updated_at' => Kst::iso($e->updated_at),
        ];
    }

    /** 계약의 평가 전부(+ 평가자 이름·대상 관리사 이름) */
    public function forContract(MnhContract $c): Collection
    {
        return DB::table('mnh_evaluations as e')->leftJoin('users as u', 'u.id', '=', 'e.evaluator_user_id')
            ->leftJoin('caregivers as cg', 'cg.id', '=', 'e.caregiver_id')->leftJoin('users as cu', 'cu.id', '=', 'cg.user_id')
            ->where('e.contract_id', $c->id)->orderByDesc('e.id')
            ->get(['e.*', 'u.name as evaluator_name', 'cu.name as caregiver_name'])
            ->map(fn ($e) => $this->present($e) + ['evaluator_name' => $e->evaluator_name, 'caregiver_name' => $e->caregiver_name]);
    }

    /** 계약 종료 → 담당 관리사(이용자 평가)·담당 관리자(기관 평가)에게 평가 요청 */
    public function requestFinal(MnhContract $c): void
    {
        foreach ($this->contractCaregivers($c) as $cgId) {
            $uid = DB::table('caregivers')->where('id', $cgId)->value('user_id');
            $this->notifier->notifySafely($uid ? (int) $uid : null, NotificationService::TYPE_MNH_EVAL_REQUEST,
                ['contract_id' => $c->id, 'contract_no' => $c->contract_no, 'for' => 'caregiver']);
        }
        foreach ($this->notifier->adminsFor('mnh') as $adminUserId) {
            $this->notifier->notifySafely($adminUserId, NotificationService::TYPE_MNH_EVAL_REQUEST,
                ['contract_id' => $c->id, 'contract_no' => $c->contract_no, 'for' => 'org']);
        }
    }

    /* ───────────── 육각형 ───────────── */

    /**
     * 돌봄전문가별 육각형 — [caregiver_id => {axes:[{key,label,score,n,enough,sources:[{source,label,value,n}]}], overall, counts}]
     * @param int[] $caregiverIds
     */
    public function hexagons(array $caregiverIds, ?Carbon $since = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $caregiverIds)));
        if (!$ids) {
            return [];
        }
        $review = $this->reviewScores($ids, $since);
        $org = $this->orgScores($ids, $since);
        $logs = $this->logRates($ids, $since);
        $min = (int) config('mnh_eval.min_samples', 3);
        $reviewLabels = config('review_criteria.postpartum');
        $orgLabels = config('mnh_eval.org_to_caregiver');

        $out = [];
        foreach ($ids as $id) {
            $axes = [];
            foreach (config('mnh_eval.axes') as $key => $axis) {
                $parts = [];
                foreach ($axis['sources'] as $src) {
                    [$type, $k] = explode(':', $src, 2);
                    [$value, $n, $label] = match ($type) {
                        'review' => [$review[$id][$k]['avg'] ?? null, $review[$id][$k]['n'] ?? 0, '이용자 평가 · ' . ($reviewLabels[$k] ?? $k)],
                        'org' => [$org[$id][$k]['avg'] ?? null, $org[$id][$k]['n'] ?? 0, '기관 평가 · ' . ($orgLabels[$k] ?? $k)],
                        'log' => [isset($logs[$id][$k]) ? round(1 + 4 * $logs[$id][$k]['rate'], 2) : null, $logs[$id][$k]['n'] ?? 0,
                            $k === 'punctual' ? '근무 기록 · 출근 정시율' : '근무 기록 · 근무일지·제공기록지 작성률'],
                        default => [null, 0, $src],
                    };
                    $parts[] = ['source' => $src, 'label' => $label, 'value' => $value === null ? null : round($value, 2), 'n' => $n]
                        + ($type === 'log' && isset($logs[$id][$k]) ? ['rate' => round($logs[$id][$k]['rate'], 3)] : []);
                }
                $have = array_filter($parts, fn ($p) => $p['value'] !== null);
                $n = array_sum(array_column($parts, 'n'));
                $axes[] = [
                    'key' => $key, 'label' => $axis['label'],
                    'score' => $have ? round(array_sum(array_column($have, 'value')) / count($have), 2) : null,
                    'n' => $n, 'enough' => $n >= $min, 'sources' => $parts,
                ];
            }
            $scored = array_filter($axes, fn ($a) => $a['score'] !== null);
            $out[$id] = [
                'axes' => $axes,
                'overall' => $scored ? round(array_sum(array_column($scored, 'score')) / count($scored), 2) : null,
                'counts' => [
                    'reviews' => (int) ($review[$id]['_n'] ?? 0),
                    'org_evaluations' => (int) ($org[$id]['_n'] ?? 0),
                    'completed_visits' => (int) ($logs[$id]['journal']['n'] ?? 0),
                ],
            ];
        }

        return $out;
    }

    /** 축별 팀 평균(점수 있는 돌봄전문가만) */
    public static function teamAverage(array $hexagons): array
    {
        $out = [];
        foreach (config('mnh_eval.axes') as $key => $axis) {
            $vals = [];
            foreach ($hexagons as $h) {
                foreach ($h['axes'] as $a) {
                    if ($a['key'] === $key && $a['score'] !== null) {
                        $vals[] = $a['score'];
                    }
                }
            }
            $out[] = ['key' => $key, 'label' => $axis['label'], 'score' => $vals ? round(array_sum($vals) / count($vals), 2) : null, 'caregivers' => count($vals)];
        }

        return $out;
    }

    /** 이용자 후기(산모신생아) 항목 평균 */
    private function reviewScores(array $ids, ?Carbon $since): array
    {
        $q = DB::table('reviews as rv')->join('matches as m', 'm.id', '=', 'rv.match_id')
            ->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->whereIn('m.caregiver_id', $ids)->where('rv.reviewer_role', 'guardian')->where('r.service_domain', 'postpartum')
            ->whereNotNull('rv.scores');
        if ($since) {
            $q->where('rv.created_at', '>=', $since);
        }
        $acc = [];
        foreach ($q->get(['m.caregiver_id', 'rv.scores']) as $row) {
            $s = json_decode((string) $row->scores, true);
            if (!is_array($s) || !$s) {
                continue;
            }
            $cg = (int) $row->caregiver_id;
            $acc[$cg]['_n'] = ($acc[$cg]['_n'] ?? 0) + 1;
            foreach ($s as $k => $v) {
                if (is_numeric($v) && $v >= 1 && $v <= 5) {
                    $acc[$cg][$k]['sum'] = ($acc[$cg][$k]['sum'] ?? 0) + $v;
                    $acc[$cg][$k]['n'] = ($acc[$cg][$k]['n'] ?? 0) + 1;
                }
            }
        }

        return $this->averages($acc);
    }

    /** 기관 평가 항목 평균 */
    private function orgScores(array $ids, ?Carbon $since): array
    {
        $q = DB::table('mnh_evaluations')->where('kind', 'org_to_caregiver')->whereIn('caregiver_id', $ids);
        if ($since) {
            $q->where('created_at', '>=', $since);
        }
        $acc = [];
        foreach ($q->get(['caregiver_id', 'scores']) as $row) {
            $cg = (int) $row->caregiver_id;
            $acc[$cg]['_n'] = ($acc[$cg]['_n'] ?? 0) + 1;
            foreach (json_decode($row->scores, true) ?: [] as $k => $v) {
                $acc[$cg][$k]['sum'] = ($acc[$cg][$k]['sum'] ?? 0) + $v;
                $acc[$cg][$k]['n'] = ($acc[$cg][$k]['n'] ?? 0) + 1;
            }
        }

        return $this->averages($acc);
    }

    private function averages(array $acc): array
    {
        foreach ($acc as $cg => $items) {
            foreach ($items as $k => $v) {
                if ($k !== '_n') {
                    $acc[$cg][$k] = ['avg' => $v['sum'] / $v['n'], 'n' => $v['n']];
                }
            }
        }

        return $acc;
    }

    /** 근무 기록 — 산모신생아 완료 방문의 출근 정시율·기록 작성률 */
    private function logRates(array $ids, ?Carbon $since): array
    {
        $grace = (int) config('mnh_eval.punctual_grace_minutes', 10);
        $q = DB::table('care_sessions as cs')->join('matches as m', 'm.id', '=', 'cs.match_id')
            ->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->whereIn('m.caregiver_id', $ids)->where('r.service_domain', 'postpartum')->where('cs.status', 'completed');
        if ($since) {
            $q->where('cs.scheduled_start', '>=', $since);
        }
        $rows = $q->get(['cs.id', 'm.caregiver_id', 'cs.scheduled_start', 'cs.actual_start', 'cs.journal_chips', 'cs.journal_note']);
        $signed = DB::table('mnh_documents')->where('doc_type', 'provision_record')->where('status', 'signed')
            ->whereIn('care_session_id', $rows->pluck('id'))->pluck('care_session_id')->flip();
        $out = [];
        foreach ($rows->groupBy('caregiver_id') as $cg => $list) {
            $n = $list->count();
            $onTime = $list->filter(fn ($s) => $s->actual_start && $s->scheduled_start
                && Carbon::parse($s->actual_start, 'UTC')->lte(Carbon::parse($s->scheduled_start, 'UTC')->addMinutes($grace)))->count();
            $journal = $list->filter(function ($s) use ($signed) {
                $chips = json_decode((string) $s->journal_chips, true);

                return !empty($chips) || trim((string) $s->journal_note) !== '' || isset($signed[$s->id]);
            })->count();
            $out[(int) $cg] = ['punctual' => ['rate' => $onTime / $n, 'n' => $n], 'journal' => ['rate' => $journal / $n, 'n' => $n]];
        }

        return $out;
    }
}
