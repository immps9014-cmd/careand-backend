<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminPaymentList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 결제 내역(관리자, 2026-10-11) — 대시보드 「이번 주 매출」의 건별 목록.
 * 매출 = 카드 결제(payments.status=paid, paid_at 기준) + 산모신생아 바우처 선납(+)·환불(−)(VoucherRevenue).
 * 기간은 KST. 조회 전용(취소·환불은 기존 화면에서).
 */
class PaymentAdminController extends Controller
{
    /**
     * GET /v1/admin/payments?period=today|this_week|last_week|this_month|last_month|custom&from=&to=
     *   &kind=all|card|voucher&status=paid|all&q=&page=&per_page=
     * status=paid(기본)=매출 기준, all=카드 결제 시도 전부(대기·실패·취소 포함, 만든 시각 기준)
     */
    public function index(Request $request): JsonResponse
    {
        ['rows' => $rows, 'summary' => $summary] = AdminPaymentList::build($request);
        $perPage = min(max((int) $request->query('per_page', 30), 1), 100);
        $page = max((int) $request->query('page', 1), 1);
        $total = $rows->count();

        return response()->json([
            'success' => true,
            'data' => $rows->slice(($page - 1) * $perPage, $perPage)->values(),
            'summary' => $summary,
            'meta' => ['total' => $total, 'per_page' => $perPage, 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage))],
        ]);
    }
}
