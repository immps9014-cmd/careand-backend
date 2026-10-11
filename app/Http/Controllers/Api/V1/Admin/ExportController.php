<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 개인정보 다운로드 — 사업계획서 3.3 「개인정보 다운로드는 슈퍼관리자만, 사유 입력·감사로그 필수」·
 * 기능 19 「CSV 다운로드(개인정보 마스킹)」 (2026-09-28, 구현계획 S2-5) — 2026-10-11 부터 엑셀(.xlsx)로 내려준다.
 * 권한: config/admin_rbac.php 'exports' 영역(슈퍼관리자만). 사유: 요청 헤더 X-Access-Reason(5자 이상, 없으면 거부).
 * 이름·이메일·휴대폰은 마스킹하고, 주소 등 상세 개인정보는 넣지 않는다.
 */
class ExportController extends Controller
{
    /** GET /v1/admin/exports/members?role=guardian|caregiver|organization */
    public function members(Request $request)
    {
        $reason = trim(rawurldecode((string) $request->header('X-Access-Reason', '')));
        if (mb_strlen($reason) < 5) {
            return response()->json([
                'success' => false,
                'error_code' => 'REASON_REQUIRED',
                'message' => '개인정보 다운로드 사유를 5자 이상 입력해야 합니다.',
            ], 422);
        }
        $role = $request->query('role');

        $rows = DB::table('users')
            ->leftJoin('caregivers as cg', function ($j) {
                $j->on('cg.user_id', '=', 'users.id')->whereNull('cg.deleted_at');
            })
            ->leftJoin('guardians as g', 'g.user_id', '=', 'users.id')
            ->whereNull('users.deleted_at')
            ->whereIn('users.role', ['guardian', 'caregiver', 'organization'])
            ->when(in_array($role, ['guardian', 'caregiver', 'organization'], true), fn ($q) => $q->where('users.role', $role))
            ->orderBy('users.id')
            ->get(['users.id', 'users.role', 'users.name', 'users.email', 'users.phone', 'users.status', 'users.created_at',
                'cg.status as caregiver_status', 'cg.service_domains', 'g.intent']);

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'export.members.xlsx',   // 2026-10-11 CSV → 엑셀(.xlsx). 이전 기록은 export.members.csv
            'entity_type' => 'exports',
            'details' => ['role' => $role ?: 'all', 'rows' => $rows->count(), 'masked' => true],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'reason' => mb_substr($reason, 0, 300),
        ]);

        $roleLabel = ['guardian' => '보호자', 'caregiver' => '돌봄전문가', 'organization' => '기관'];
        $cgLabel = ['pending' => '검수 대기', 'active' => '승인', 'rejected' => '반려', 'suspended' => '정지'];
        $statusLabel = ['active' => '활성', 'suspended' => '정지', 'withdrawn' => '탈퇴'];
        $sheet = [
            ['개인정보 마스킹 파일 — 다운로드: ' . $request->user()->email . ' · ' . now('Asia/Seoul')->format('Y-m-d H:i') . ' · 사유: ' . $reason],
            ['대상 ' . ($roleLabel[$role] ?? '전체(보호자·돌봄전문가·기관)') . ' · ' . $rows->count() . '명 · 이름·이메일·휴대폰은 가림 · 가입일은 한국 시각'],
            ['회원ID', '구분', '이름', '이메일', '휴대폰', '상태', '가입일', '인력 검증상태', '서비스 도메인·요청 성격'],
        ];
        foreach ($rows as $r) {
            $sheet[] = [
                (int) $r->id,
                $roleLabel[$r->role] ?? $r->role,
                self::maskName($r->name),
                self::maskEmail($r->email),
                self::maskPhone($r->phone),
                $statusLabel[$r->status] ?? (string) $r->status,
                $r->created_at ? \Carbon\Carbon::parse($r->created_at, 'UTC')->setTimezone('Asia/Seoul')->format('Y-m-d') : '',
                $r->caregiver_status ? ($cgLabel[$r->caregiver_status] ?? $r->caregiver_status) : '',
                (string) ($r->role === 'caregiver' ? ($r->service_domains ?? '') : ($r->intent ?? '')),
            ];
        }
        $bin = \App\Support\SimpleXlsx::build('회원', $sheet, ['header_row' => 3, 'widths' => [8, 10, 10, 26, 15, 8, 12, 13, 30]]);
        $file = 'careand_members_' . ($role ?: 'all') . '_' . now('Asia/Seoul')->format('Ymd_His') . '.xlsx';

        return response($bin, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $file . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * GET /v1/admin/exports/payments?(결제 내역 화면과 같은 필터) — 결제 내역 엑셀(.xlsx, 2026-10-11).
     * 사람 이름은 마스킹, 금액은 숫자 칸(합계 가능). 사유·감사로그는 회원 다운로드와 같은 규칙.
     */
    public function payments(Request $request)
    {
        $reason = trim(rawurldecode((string) $request->header('X-Access-Reason', '')));
        if (mb_strlen($reason) < 5) {
            return response()->json([
                'success' => false,
                'error_code' => 'REASON_REQUIRED',
                'message' => '개인정보 다운로드 사유를 5자 이상 입력해야 합니다.',
            ], 422);
        }
        ['rows' => $rows, 'summary' => $s] = \App\Support\AdminPaymentList::build($request);

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'export.payments.xlsx',
            'entity_type' => 'exports',
            'details' => ['filters' => $request->only(['period', 'from', 'to', 'kind', 'status', 'q']), 'range' => [$s['from'], $s['to']], 'rows' => $rows->count(), 'masked' => true],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'reason' => mb_substr($reason, 0, 300),
        ]);

        $kind = ['card' => '카드 결제', 'voucher_prepaid' => '바우처 선납', 'voucher_refund' => '바우처 환불'];
        $status = ['paid' => '완료', 'pending' => '대기', 'failed' => '실패', 'cancelled' => '취소', 'refunded' => '환불'];
        $method = ['card' => '카드', 'account' => '계좌', 'voucher_only' => '바우처만', 'cash' => '현금', 'local_currency' => '지역화폐'];
        $domain = ['senior' => '시니어돌봄', 'nursing' => '병원간병', 'living_support' => '생활관리', 'postpartum' => '산모신생아', 'childcare' => '아이돌봄', 'mental_care' => '마음돌봄', 'housekeeping' => '가사'];

        $sheet = [
            ['개인정보 마스킹 파일 — 다운로드: ' . $request->user()->email . ' · ' . now('Asia/Seoul')->format('Y-m-d H:i') . ' · 사유: ' . $reason],
            ['기간 ' . $s['from'] . ' ~ ' . $s['to'] . ' (한국 시각) · 매출 합계 ' . number_format($s['revenue']) . '원 = 카드 결제 완료 ' . number_format($s['card_paid']['amount']) . '원(' . $s['card_paid']['count'] . '건) + 바우처 순매출 ' . number_format($s['voucher']['net']) . '원'],
            ['일시', '종류', '결제자', '대상자', '담당', '분야', '수단', '주문·계약번호', '금액(원)', '상태'],
        ];
        foreach ($rows as $r) {
            $sheet[] = [
                $r['at'] ? str_replace('T', ' ', substr($r['at'], 0, 16)) : '',
                $kind[$r['kind']] ?? $r['kind'],
                self::maskName($r['payer_name']),
                self::maskName($r['recipient_name']),
                self::maskName($r['caregiver_name']),
                $r['service_domain'] ? ($domain[$r['service_domain']] ?? $r['service_domain']) : '',
                $r['method'] ? ($method[$r['method']] ?? $r['method']) : '',
                (string) ($r['ref'] ?? ''),
                (int) $r['amount'],
                $status[$r['status']] ?? $r['status'],
            ];
        }
        $sheet[] = [];
        $sheet[] = ['', '', '', '', '', '', '', '매출 합계', (int) $s['revenue'], '카드 완료+바우처 선납−환불'];

        $bin = \App\Support\SimpleXlsx::build('결제 내역', $sheet, [
            'header_row' => 3, 'money_cols' => [8], 'widths' => [17, 12, 10, 10, 10, 11, 9, 22, 13, 14],
        ]);
        $file = 'careand_payments_' . $s['from'] . '_' . $s['to'] . '.xlsx';

        return response($bin, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $file . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * GET /v1/admin/exports/settlements?status=draft|confirmed|paid|failed|disputed — 정산 엑셀(.xlsx, 2026-10-11).
     * 정산 화면과 같은 상태 필터. 돌봄전문가 이름은 마스킹, 계좌·이체번호·이의 내용은 넣지 않는다. 금액은 숫자 칸.
     */
    public function settlements(Request $request)
    {
        $reason = trim(rawurldecode((string) $request->header('X-Access-Reason', '')));
        if (mb_strlen($reason) < 5) {
            return response()->json([
                'success' => false,
                'error_code' => 'REASON_REQUIRED',
                'message' => '개인정보 다운로드 사유를 5자 이상 입력해야 합니다.',
            ], 422);
        }
        $st = (string) $request->query('status', '');
        $rows = DB::table('settlements as s')
            ->leftJoin('caregivers as c', 'c.id', '=', 's.caregiver_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->when($st === 'disputed', fn ($q) => $q->where('s.dispute_status', 'open'))
            ->when($st !== '' && $st !== 'disputed', fn ($q) => $q->where('s.status', $st))
            ->orderByDesc('s.period_start')->orderBy('s.id')
            ->get(['s.id', 'u.name', 's.period_start', 's.period_end', 's.gross_amount', 's.withholding_tax_3_3', 's.net_amount',
                's.status', 's.hometax_filing_no', 's.confirmed_at', 's.paid_at', 's.caregiver_ack_at', 's.dispute_status']);

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'export.settlements.xlsx',
            'entity_type' => 'exports',
            'details' => ['status' => $st ?: 'all', 'rows' => $rows->count(), 'masked' => true],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'reason' => mb_substr($reason, 0, 300),
        ]);

        $statusLabel = ['draft' => '작성중', 'confirmed' => '확정·지급대기', 'paid' => '지급완료', 'failed' => '실패'];
        $disputeLabel = ['open' => '이의제기(답변 대기)', 'resolved' => '이의 처리됨'];
        $filterLabel = ['' => '전체', 'draft' => '작성중', 'confirmed' => '확정', 'paid' => '지급완료', 'failed' => '실패', 'disputed' => '이의제기'];
        $kst = fn ($v) => $v ? \Carbon\Carbon::parse($v, 'UTC')->setTimezone('Asia/Seoul')->format('Y-m-d H:i') : '';
        $sum = fn ($k) => (int) $rows->sum(fn ($r) => (int) round((float) $r->{$k}));

        $sheet = [
            ['개인정보 마스킹 파일 — 다운로드: ' . $request->user()->email . ' · ' . now('Asia/Seoul')->format('Y-m-d H:i') . ' · 사유: ' . $reason],
            ['상태 ' . ($filterLabel[$st] ?? $st) . ' · ' . $rows->count() . '건 · 돌봄전문가 ' . $rows->pluck('name')->unique()->count() . '명 · 실지급 합계 ' . number_format($sum('net_amount')) . '원 (시각은 한국 시각)'],
            ['정산ID', '돌봄전문가', '정산 시작일', '정산 종료일', '총액(원)', '원천징수 3.3%(원)', '실지급액(원)', '상태', '이의제기', '홈택스 신고번호', '확정 시각', '입금 시각', '전문가 확인 시각'],
        ];
        foreach ($rows as $r) {
            $sheet[] = [
                (int) $r->id,
                self::maskName($r->name),
                (string) $r->period_start,
                (string) $r->period_end,
                (int) round((float) $r->gross_amount),
                (int) round((float) $r->withholding_tax_3_3),
                (int) round((float) $r->net_amount),
                $statusLabel[$r->status] ?? $r->status,
                $r->dispute_status ? ($disputeLabel[$r->dispute_status] ?? $r->dispute_status) : '',
                (string) ($r->hometax_filing_no ?? ''),
                $kst($r->confirmed_at),
                $kst($r->paid_at),
                $kst($r->caregiver_ack_at),
            ];
        }
        $sheet[] = [];
        $sheet[] = ['', '', '', '합계', $sum('gross_amount'), $sum('withholding_tax_3_3'), $sum('net_amount')];

        $bin = \App\Support\SimpleXlsx::build('정산', $sheet, [
            'header_row' => 3, 'money_cols' => [4, 5, 6], 'widths' => [8, 11, 12, 12, 13, 16, 14, 13, 17, 18, 17, 17, 17],
        ]);
        $file = 'careand_settlements_' . ($st ?: 'all') . '_' . now('Asia/Seoul')->format('Ymd') . '.xlsx';

        return response($bin, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $file . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** 홍길동 → 홍○○, 남궁민수 → 남○○○ */
    public static function maskName(?string $v): string
    {
        $v = (string) $v;
        $len = mb_strlen($v);
        return $len <= 1 ? $v : mb_substr($v, 0, 1) . str_repeat('○', $len - 1);
    }

    /** abcdef@x.com → ab****@x.com */
    public static function maskEmail(?string $v): string
    {
        $v = (string) $v;
        if (!str_contains($v, '@')) {
            return self::maskName($v);
        }
        [$id, $dom] = explode('@', $v, 2);
        return mb_substr($id, 0, 2) . str_repeat('*', max(mb_strlen($id) - 2, 2)) . '@' . $dom;
    }

    /** 01012345678 → 010-****-5678 */
    public static function maskPhone(?string $v): string
    {
        $d = preg_replace('/\D/', '', (string) $v);
        return strlen($d) >= 8 ? substr($d, 0, 3) . '-****-' . substr($d, -4) : ($d === '' ? '' : '****');
    }
}
