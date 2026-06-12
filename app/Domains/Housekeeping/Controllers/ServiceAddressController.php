<?php

namespace App\Domains\Housekeeping\Controllers;

use App\Domains\Housekeeping\Models\ServiceAddress;
use App\Domains\Housekeeping\Requests\ServiceAddressStoreRequest;
use App\Domains\Housekeeping\Requests\ServiceAddressUpdateRequest;
use App\Domains\Housekeeping\Resources\ServiceAddressResource;
use App\Http\Controllers\Controller;
use App\Services\GeocodingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 가사 서비스 주소 관리 (보호자 소유)
 *
 * Endpoints:
 *  GET    /v1/housekeeping/addresses
 *  POST   /v1/housekeeping/addresses
 *  GET    /v1/housekeeping/addresses/{id}
 *  PATCH  /v1/housekeeping/addresses/{id}
 *  DELETE /v1/housekeeping/addresses/{id}
 */
class ServiceAddressController extends Controller
{
    public function __construct(private GeocodingService $geocoder)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $guardian = $request->user()->guardian;
        if (!$guardian) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_GUARDIAN',
                'message' => '보호자 회원만 조회 가능합니다.',
            ], 403);
        }

        $addresses = ServiceAddress::where('guardian_id', $guardian->id)
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => ServiceAddressResource::collection($addresses),
            'meta' => [
                'total' => $addresses->total(),
                'per_page' => $addresses->perPage(),
                'current_page' => $addresses->currentPage(),
                'last_page' => $addresses->lastPage(),
            ],
        ]);
    }

    public function store(ServiceAddressStoreRequest $request): JsonResponse
    {
        $guardian = $request->user()->guardian;
        if (!$guardian) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_GUARDIAN',
                'message' => '보호자 회원만 등록 가능합니다.',
            ], 403);
        }

        $data = $request->validated();
        $data['guardian_id'] = $guardian->id;

        // 주소 → 좌표 자동 보정 (체크인 검증에 필요)
        if (empty($data['lat']) && empty($data['lng'])) {
            if ($coords = $this->geocoder->geocode($data['address'])) {
                $data['lat'] = $coords['lat'];
                $data['lng'] = $coords['lng'];
            }
        }

        $address = ServiceAddress::create($data);

        return response()->json([
            'success' => true,
            'message' => '주소가 등록되었습니다.',
            'data' => new ServiceAddressResource($address),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $address = ServiceAddress::findOrFail($id);
        $this->authorize('view', $address);

        return response()->json([
            'success' => true,
            'data' => new ServiceAddressResource($address),
        ]);
    }

    public function update(ServiceAddressUpdateRequest $request, int $id): JsonResponse
    {
        $address = ServiceAddress::findOrFail($id);
        $this->authorize('update', $address);

        $data = $request->validated();

        // 주소가 바뀌었는데 좌표가 안 왔으면 재지오코딩
        if (!empty($data['address'])
            && $data['address'] !== $address->address
            && empty($data['lat'])) {
            if ($coords = $this->geocoder->geocode($data['address'])) {
                $data['lat'] = $coords['lat'];
                $data['lng'] = $coords['lng'];
            }
        }

        $address->update($data);

        return response()->json([
            'success' => true,
            'message' => '주소가 수정되었습니다.',
            'data' => new ServiceAddressResource($address->fresh()),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $address = ServiceAddress::findOrFail($id);
        $this->authorize('delete', $address);

        $hasOpenRequest = $address->matchRequests()
            ->whereIn('status', ['open', 'matching', 'matched'])
            ->exists();
        if ($hasOpenRequest) {
            return response()->json([
                'success' => false,
                'error_code' => 'HAS_ACTIVE_REQUEST',
                'message' => '진행 중인 매칭 요청이 있어 삭제할 수 없습니다.',
            ], 422);
        }

        $address->delete();

        return response()->json([
            'success' => true,
            'message' => '주소가 삭제되었습니다.',
        ]);
    }
}
