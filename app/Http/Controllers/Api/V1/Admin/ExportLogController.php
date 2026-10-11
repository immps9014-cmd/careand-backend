<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\KstPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 개인정보·엑셀 다운로드 기록(2026-10-11) — audit_logs 조회 전용.
 * 성공 = 다운로드 컨트롤러가 남긴 export.* (대상·필터·건수·사유), 거부 = 미들웨어(AuditPersonalDataAccess)가 남긴
 * 「GET api/v1/admin/exports/…」 중 응답 코드 400 이상(사유 없음 422·권한 없음 403 등). 성공 건의 미들웨어 기록은 중복이라 뺀다.
 * 'exports' 영역(슈퍼관리자만) — 경로 admin/exports/logs.
 */
class ExportLogController extends Controller
{
    private const KIND = [
        'members' => '회원 목록', 'payments' => '결제 내역', 'settlements' => '정산',
        'list:matching' => '매칭 요청', 'list:contracts' => '계약·일정', 'list:care-sessions' => '케어 진행 현황', 'list:monitoring' => '위험 알림',
        'list:caregivers' => '돌봄전문가 자격검증', 'list:care-logs' => 'AI 일지 검수', 'list:mnh-contracts' => '바우처 계약',
    ];

    /** GET /v1/admin/exports/logs?period=…&from&to&result=all|success|blocked&actor_id=&kind=&page= */
    public function index(Request $request): JsonResponse
    {
        [$from, $to, $fromYmd, $toYmd] = KstPeriod::range((string) $request->query('period', 'this_month'), $request->query('from'), $request->query('to'));
        $result = in_array($request->query('result'), ['success', 'blocked'], true) ? $request->query('result') : 'all';
        $actor = (int) $request->query('actor_id') ?: null;
        $kindFilter = (string) $request->query('kind', '');
        $perPage = min(max((int) $request->query('per_page', 30), 1), 100);
        $page = max((int) $request->query('page', 1), 1);

        $rows = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->where('a.created_at', '>=', $from)->where('a.created_at', '<', $to)
            ->where(fn ($w) => $w->where('a.action', 'like', 'export.%')->orWhere('a.action', 'like', 'GET api/v1/admin/exports/%'))
            ->where('a.action', 'not like', 'GET api/v1/admin/exports/logs%')
            ->when($actor, fn ($q) => $q->where('a.actor_id', $actor))
            ->orderByDesc('a.id')
            ->limit(5000)
            ->get(['a.id', 'a.actor_id', 'a.action', 'a.details', 'a.reason', 'a.ip_address', 'a.created_at', 'u.name as actor_name', 'u.email as actor_email'])
            ->map(function ($r) {
                $d = json_decode((string) $r->details, true) ?: [];
                if (str_starts_with($r->action, 'export.')) {
                    // export.members.xlsx · export.list.xlsx(details.list) …
                    $part = explode('.', $r->action)[1] ?? '';
                    $kind = $part === 'list' ? 'list:' . ($d['list'] ?? '') : $part;
                    $ok = true;
                    $filters = $d['filters'] ?? (isset($d['role']) ? ['role' => $d['role']] : (isset($d['status']) ? ['status' => $d['status']] : []));
                    $count = $d['rows'] ?? null;
                    $status = 200;
                } else {
                    $status = (int) ($d['status'] ?? 0);
                    if ($status > 0 && $status < 400) {
                        return null;   // 성공 건은 export.* 쪽에 자세히 있다
                    }
                    $path = substr($r->action, strlen('GET api/v1/admin/exports/'));
                    $kind = str_starts_with($path, 'list/') ? 'list:' . ($d['params']['list'] ?? '?') : $path;
                    $ok = false;
                    $filters = array_fill_keys($d['query_keys'] ?? [], '…');
                    $count = null;
                }

                return [
                    'id' => (int) $r->id,
                    'at' => \App\Support\Kst::iso($r->created_at),
                    'actor_id' => $r->actor_id ? (int) $r->actor_id : null,
                    'actor_name' => $r->actor_name,
                    'actor_email' => $r->actor_email,
                    'kind' => $kind,
                    'kind_label' => self::KIND[$kind] ?? $kind,
                    'result' => $ok ? 'success' : 'blocked',
                    'status' => $status,
                    'filters' => $filters,
                    'rows' => $count,
                    'reason' => $r->reason,
                    'ip' => $r->ip_address,
                ];
            })
            ->filter()
            ->when($result !== 'all', fn ($c) => $c->where('result', $result))
            ->when($kindFilter !== '', fn ($c) => $c->where('kind', $kindFilter))
            ->values();

        $total = $rows->count();

        return response()->json([
            'success' => true,
            'data' => $rows->slice(($page - 1) * $perPage, $perPage)->values(),
            'summary' => [
                'from' => $fromYmd, 'to' => $toYmd,
                'success' => $rows->where('result', 'success')->count(),
                'blocked' => $rows->where('result', 'blocked')->count(),
                'rows_total' => (int) $rows->sum('rows'),
                // 같은 기간 다운로드한 관리자(필터 칩용)
                'actors' => $rows->whereNotNull('actor_id')->groupBy('actor_id')
                    ->map(fn ($g) => ['id' => $g->first()['actor_id'], 'name' => $g->first()['actor_name'], 'count' => $g->count()])->values(),
                'kinds' => collect(self::KIND)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            ],
            'meta' => ['total' => $total, 'per_page' => $perPage, 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ]);
    }
}
