<?php

namespace App\Domains\Postpartum\Controllers;

use App\Domains\Postpartum\Models\PostpartumClient;
use App\Domains\Postpartum\Models\VoucherTransaction;
use App\Domains\Postpartum\Services\SbaService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 정부 바우처 거래
 */
class VoucherController extends Controller
{
    public function __construct(private readonly SbaService $sbaService) {}

    /**
     * 바우처 거래 내역
     * GET /postpartum/voucher-transactions
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', VoucherTransaction::class);

        $query = VoucherTransaction::query()
            ->when($request->filled('postpartum_client_id'),
                fn($q) => $q->where('postpartum_client_id', $request->integer('postpartum_client_id')))
            ->when($request->filled('status'),
                fn($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('transaction_date');

        $items = $query->paginate($request->integer('page_size', 20));

        return response()->json([
            'success'   => true,
            'code'      => 'OK',
            'data'      => $items->items(),
            'meta'      => [
                'page'      => $items->currentPage(),
                'page_size' => $items->perPage(),
                'total'     => $items->total(),
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * 바우처 사용 (케어 세션 종료 시 자동 호출)
     * POST /postpartum/voucher-transactions/use
     */
    public function use(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'postpartum_client_id' => ['required', 'integer', Rule::exists('postpartum_clients', 'id')],
            'care_session_id'      => ['required', 'integer'],
            'amount'               => ['required', 'numeric', 'min:0'],
            'days'                 => ['required', 'integer', 'min:1'],
        ]);

        $client = PostpartumClient::findOrFail($validated['postpartum_client_id']);
        $this->authorize('update', $client);

        try {
            $tx = $this->sbaService->useVoucher(
                client: $client,
                careSessionId: $validated['care_session_id'],
                amount: (float) $validated['amount'],
                days: (int) $validated['days'],
            );

            return response()->json([
                'success'   => true,
                'code'      => 'CREATED',
                'message'   => '바우처 사용 완료',
                'data'      => $tx,
                'timestamp' => now()->toIso8601String(),
            ], 201);
        } catch (\DomainException $e) {
            return response()->json([
                'success'   => false,
                'code'      => 'VOUCHER_INSUFFICIENT',
                'message'   => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ], 402);
        }
    }
}
