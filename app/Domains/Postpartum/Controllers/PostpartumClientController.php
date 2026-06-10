<?php

namespace App\Domains\Postpartum\Controllers;

use App\Domains\Postpartum\Models\PostpartumClient;
use App\Domains\Postpartum\Requests\PostpartumClientStoreRequest;
use App\Domains\Postpartum\Requests\PostpartumClientUpdateRequest;
use App\Domains\Postpartum\Resources\PostpartumClientResource;
use App\Domains\Postpartum\Services\SbaService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 산모 클라이언트 관리
 *
 * Endpoints:
 *  GET    /postpartum/clients
 *  POST   /postpartum/clients
 *  GET    /postpartum/clients/{id}
 *  PUT    /postpartum/clients/{id}
 *  GET    /postpartum/clients/{id}/voucher/inquire
 */
class PostpartumClientController extends Controller
{
    public function __construct(private readonly SbaService $sbaService) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PostpartumClient::class);

        $query = PostpartumClient::query()
            ->with(['branch', 'newborns'])
            ->when($request->filled('branch_id'),
                fn($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('status'),
                fn($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('created_at');

        $clients = $query->paginate($request->integer('page_size', 20));

        return response()->json([
            'success' => true,
            'code'    => 'OK',
            'data'    => PostpartumClientResource::collection($clients),
            'meta'    => [
                'page'       => $clients->currentPage(),
                'page_size'  => $clients->perPage(),
                'total'      => $clients->total(),
                'last_page'  => $clients->lastPage(),
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function store(PostpartumClientStoreRequest $request): JsonResponse
    {
        $this->authorize('create', PostpartumClient::class);

        $data = $request->validated();
        // PII 암호화
        $data['phone_encrypted'] = encrypt($data['phone'] ?? '');
        $data['name_encrypted']  = encrypt($data['name'] ?? '');
        unset($data['phone']);

        $client = PostpartumClient::create($data);

        return response()->json([
            'success'   => true,
            'code'      => 'CREATED',
            'message'   => '산모 등록 완료',
            'data'      => new PostpartumClientResource($client),
            'timestamp' => now()->toIso8601String(),
        ], 201);
    }

    public function show(PostpartumClient $client): JsonResponse
    {
        $this->authorize('view', $client);

        $client->load(['branch', 'newborns', 'epdsAssessments' => fn($q) => $q->limit(5)]);

        return response()->json([
            'success'   => true,
            'code'      => 'OK',
            'data'      => new PostpartumClientResource($client),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function update(PostpartumClientUpdateRequest $request, PostpartumClient $client): JsonResponse
    {
        $this->authorize('update', $client);

        $client->update($request->validated());

        return response()->json([
            'success'   => true,
            'code'      => 'OK',
            'message'   => '산모 정보 수정 완료',
            'data'      => new PostpartumClientResource($client->fresh()),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * SBA 바우처 자격 조회 (신청 자격, 등급, 본인부담률, 이용 가능 일수)
     */
    public function voucherInquire(PostpartumClient $client): JsonResponse
    {
        $this->authorize('view', $client);

        $eligibility = $this->sbaService->inquireEligibility($client);

        return response()->json([
            'success' => true,
            'code'    => 'OK',
            'data'    => [
                'eligible'        => $eligibility['eligible'],
                'voucher_grade'   => $eligibility['voucher_grade'],
                'self_pay_rate'   => $eligibility['self_pay_rate'],
                'total_days'      => $eligibility['total_days'],
                'used_days'       => $client->voucher_used_days,
                'remaining_days'  => $client->voucherRemainingDays(),
                'total_amount'    => $eligibility['total_amount'],
                'used_amount'     => $client->voucher_amount_used,
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
