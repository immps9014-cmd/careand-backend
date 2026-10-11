<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\SimpleXlsx;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 관리자 목록 엑셀(.xlsx) 공용 다운로드(2026-10-11).
 * 각 목록의 기존 조회 API 를 그대로 불러(같은 필터, 전 페이지) 칸 정의대로 시트를 만든다 — 화면과 결과가 어긋나지 않게.
 * 규칙은 회원·결제 다운로드와 같다: 'exports' 영역(슈퍼관리자만)·사유 필수·감사로그·사람 이름/연락처 마스킹.
 * 새 목록은 LISTS 에 [제목, 컨트롤러, 메서드, 칸들] 만 더하면 된다.
 */
class ListExportController extends Controller
{
    private const DOMAIN = ['senior' => '시니어돌봄', 'nursing' => '병원간병', 'living_support' => '생활관리', 'housekeeping' => '가사',
        'postpartum' => '산모신생아', 'childcare' => '아이돌봄', 'mental_care' => '마음돌봄'];
    private const VOUCHER = ['contract' => '바우처 계약', 'eligible' => '바우처 대상'];

    /**
     * 칸: [머리글, 응답 키, 형식] — 형식: name(이름 마스킹)·phone·email·kst(시각)·money(금액 숫자)·num·domain·voucher·yn·[코드=>라벨]
     * @return array<string, array{0: string, 1: class-string, 2: string, 3: list<array>}>
     */
    private static function lists(): array
    {
        $req = ['open' => '접수', 'matching' => '매칭 중', 'matched' => '매칭됨', 'expired' => '만료', 'cancelled' => '취소', 'pending' => '대기'];
        $match = ['confirmed' => '확정', 'in_progress' => '진행중', 'completed' => '완료', 'cancelled' => '취소', 'no_show' => '노쇼'];
        $sess = ['scheduled' => '예정', 'in_progress' => '진행중', 'completed' => '완료', 'cancelled' => '취소', 'no_show' => '노쇼'];
        $review = ['pending' => '검수 대기', 'approved' => '승인', 'rejected' => '반려', 'edited' => '수정됨'];

        return [
            'matching' => ['매칭 요청', OperationsController::class, 'matchingRequests', [
                ['요청ID', 'id', 'num'], ['신청 시각', 'created_at', 'kst'], ['대상자', 'senior_name', 'name'], ['보호자', 'guardian_name', 'name'],
                ['분야', 'service_domain', 'domain'], ['세부 종류', 'category'], ['방식', 'mode', ['normal' => '일반', 'emergency' => '긴급']],
                ['희망 일정', 'scheduled_start', 'kst'], ['상태', 'status', $req], ['후보 수', 'candidate_count', 'num'],
                ['담당 돌봄전문가', 'matched_caregiver_name', 'name'], ['바우처', 'voucher', 'voucher'],
            ]],
            'contracts' => ['계약·일정', OperationsController::class, 'contracts', [
                ['계약ID', 'id', 'num'], ['요청ID', 'request_id', 'num'], ['돌봄전문가', 'caregiver_name', 'name'], ['대상자', 'senior_name', 'name'],
                ['분야', 'service_domain', 'domain'], ['시작', 'scheduled_start', 'kst'], ['종료', 'scheduled_end', 'kst'],
                ['예상 금액(원)', 'estimated_amount', 'money'], ['상태', 'status', $match], ['바우처', 'voucher', 'voucher'],
            ]],
            'care-sessions' => ['케어 진행 현황', OperationsController::class, 'careSessions', [
                ['방문ID', 'id', 'num'], ['대상자', 'recipient_name', 'name'], ['보호자', 'guardian_name', 'name'], ['돌봄전문가', 'caregiver_name', 'name'],
                ['분야', 'service_domain', 'domain'], ['예정 시작', 'scheduled_start', 'kst'], ['실제 출근', 'actual_start', 'kst'], ['실제 퇴근', 'actual_end', 'kst'],
                ['돌봄 시간(분)', 'duration_min', 'num'], ['상태', 'status', $sess], ['일지 검수', 'review_status', $review],
                ['반경 밖 거리(m)', 'out_of_range_m', 'num'], ['수동 매칭', 'is_manual', 'yn'], ['바우처', 'voucher', 'voucher'],
            ]],
            'monitoring' => ['위험 알림', OperationsController::class, 'monitoringAlerts', [
                ['알림ID', 'id', 'num'], ['감지 시각', 'detected_at', 'kst'], ['어르신', 'senior_name', 'name'], ['장기요양 등급', 'care_grade', 'num'],
                ['위험 종류', 'risk_type', ['fall' => '낙상', 'delirium' => '섬망', 'depression' => '우울', 'nutrition' => '영양', 'other' => '기타']],
                ['위험 점수', 'risk_score', 'num'], ['위험도', 'severity', ['critical' => '긴급', 'high' => '높음', 'mid' => '중간', 'low' => '낮음']],
                ['상태', 'status', ['new' => '신규', 'acknowledged' => '확인', 'in_progress' => '조치 중', 'resolved' => '해결', 'dismissed' => '무시']],
                ['권고', 'recommendation'], ['처리 메모', 'resolution_note'],
            ]],
            'caregivers' => ['돌봄전문가 자격검증', OperationsController::class, 'caregivers', [
                ['돌봄전문가ID', 'id', 'num'], ['이름', 'name', 'name'], ['휴대폰', 'phone', 'phone'], ['이메일', 'email', 'email'],
                ['가능 분야', 'service_domains'], ['상태', 'status', ['pending' => '승인 대기', 'active' => '승인', 'rejected' => '반려', 'suspended' => '정지']],
                ['자격 종류', 'license_type'], ['자격 진위확인', 'license_verified', 'yn'], ['케어앤에듀 인증', 'careand_certified', 'yn'],
                ['평점', 'rating_avg', 'num'], ['완료 돌봄', 'completed_sessions', 'num'], ['신청일', 'created_at', 'kst'],
            ]],
            'care-logs' => ['AI 일지 검수', OperationsController::class, 'careLogs', [
                ['일지ID', 'id', 'num'], ['돌봄전문가', 'caregiver_name', 'name'], ['대상자', 'senior_name', 'name'], ['보호자', 'guardian_name', 'name'],
                ['분야', 'service_domain', 'domain'], ['출근', 'actual_start', 'kst'], ['퇴근', 'actual_end', 'kst'], ['돌봄 시간(분)', 'duration_min', 'num'],
                ['방문 상태', 'session_status', $sess], ['검수', 'review_status', $review], ['검수 시각', 'reviewed_at', 'kst'],
            ]],
            'mnh-contracts' => ['산모신생아 바우처 계약', MnhAdminController::class, 'contracts', [
                ['계약번호', 'contract_no'], ['산모', 'client_name', 'name'], ['상태', 'status_label'], ['지원유형', 'support_label'], ['일수', 'days', 'num'],
                ['개시일', 'start_date'], ['종료일', 'end_date'], ['총액(원)', 'total_price', 'money'], ['정부지원(원)', 'gov_support', 'money'],
                ['본인부담(원)', 'self_pay', 'money'], ['추가요금(원)', 'addon_total', 'money'], ['결제 수단', 'payment_method_label'],
                ['선납', 'prepaid', 'yn'], ['선납 시각', 'prepaid_at', 'kst'], ['환불(원)', 'refund_amount', 'money'],
                ['담당 관리사', 'caregiver_name', 'name'], ['진행(일)', 'completed_days', 'num'],
            ]],
        ];
    }

    /** GET /v1/admin/exports/list/{list}?(그 목록 화면과 같은 필터) */
    public function export(Request $request, string $list)
    {
        $def = self::lists()[$list] ?? null;
        if (!$def) {
            return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => '내려받을 수 없는 목록입니다.'], 404);
        }
        $reason = trim(rawurldecode((string) $request->header('X-Access-Reason', '')));
        if (mb_strlen($reason) < 5) {
            return response()->json(['success' => false, 'error_code' => 'REASON_REQUIRED', 'message' => '개인정보 다운로드 사유를 5자 이상 입력해야 합니다.'], 422);
        }
        [$title, $class, $method, $cols] = $def;
        $filters = collect($request->query())->except(['page', 'per_page'])->all();
        $rows = $this->fetchAll($request, $class, $method, $filters);

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'export.list.xlsx',
            'entity_type' => 'exports',
            'details' => ['list' => $list, 'filters' => $filters, 'rows' => count($rows), 'masked' => true],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'reason' => mb_substr($reason, 0, 300),
        ]);

        $filterText = $filters ? collect($filters)->map(fn ($v, $k) => $k . '=' . (is_array($v) ? implode(',', $v) : $v))->implode(' · ') : '없음';
        $sheet = [
            ['개인정보 마스킹 파일 — 다운로드: ' . $request->user()->email . ' · ' . now('Asia/Seoul')->format('Y-m-d H:i') . ' · 사유: ' . $reason],
            [$title . ' · ' . count($rows) . '건 · 조건: ' . $filterText . ' · 시각은 한국 시각'],
            array_map(fn ($c) => $c[0], $cols),
        ];
        $money = [];
        foreach ($cols as $i => $c) {
            if (($c[2] ?? null) === 'money' || ($c[2] ?? null) === 'num') {
                $money[] = $i;
            }
        }
        foreach ($rows as $r) {
            $sheet[] = array_map(fn ($c) => self::cell($r[$c[1]] ?? null, $c[2] ?? null), $cols);
        }
        $bin = SimpleXlsx::build($title, $sheet, [
            'header_row' => 3, 'money_cols' => $money,
            'widths' => array_map(fn ($c) => max(9, min(28, mb_strwidth($c[0]) + 4)), $cols),
        ]);
        $file = 'careand_' . $list . '_' . now('Asia/Seoul')->format('Ymd_His') . '.xlsx';

        return response($bin, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $file . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** 기존 목록 API 를 같은 필터로 쪽마다 불러 data 를 모은다(최대 5,000건) */
    private function fetchAll(Request $request, string $class, string $method, array $filters): array
    {
        $out = [];
        $ctl = app($class);
        for ($page = 1; $page <= 200 && count($out) < 5000; $page++) {
            $sub = Request::create($request->url(), 'GET', $filters + ['page' => $page, 'per_page' => 100]);
            $sub->setUserResolver(fn () => $request->user());
            $res = $ctl->{$method}($sub);
            $json = $res instanceof JsonResponse ? $res->getData(true) : [];
            $data = $json['data'] ?? [];
            $out = array_merge($out, is_array($data) ? $data : []);
            $last = (int) ($json['meta']['last_page'] ?? 1);
            if ($page >= $last || !$data) {
                break;
            }
        }

        return array_slice($out, 0, 5000);
    }

    private static function cell(mixed $v, mixed $fmt): int|float|string|null
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_array($fmt)) {
            return $fmt[$v] ?? (string) $v;
        }

        return match ($fmt) {
            'name' => ExportController::maskName((string) $v),
            'phone' => ExportController::maskPhone((string) $v),
            'email' => ExportController::maskEmail((string) $v),
            'kst' => self::kst((string) $v),
            'money' => (int) round((float) $v),
            'num' => is_numeric($v) ? $v + 0 : (string) $v,
            'domain' => self::DOMAIN[$v] ?? (string) $v,
            'voucher' => self::VOUCHER[$v] ?? (string) $v,
            'yn' => $v ? '예' : '아니오',
            default => is_array($v) ? implode(', ', array_map('strval', $v)) : (is_bool($v) ? ($v ? '예' : '아니오') : (string) $v),
        };
    }

    /** ISO(+09:00 포함)·DB 원문(UTC) 모두 KST 「Y-m-d H:i」로 */
    private static function kst(string $v): string
    {
        try {
            $c = preg_match('/[T].*([+-]\d{2}:?\d{2}|Z)$/', $v) ? Carbon::parse($v) : Carbon::parse($v, 'UTC');
            return strlen($v) <= 10 ? $v : $c->setTimezone('Asia/Seoul')->format('Y-m-d H:i');
        } catch (\Throwable) {
            return $v;
        }
    }
}
