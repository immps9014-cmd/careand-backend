<?php

namespace App\Domains\Postpartum\Controllers;

use App\Domains\Postpartum\Models\Newborn;
use App\Domains\Postpartum\Models\NewbornDailyLog;
use App\Domains\Postpartum\Models\PostpartumClient;
use App\Domains\Postpartum\Requests\NewbornStoreRequest;
use App\Domains\Postpartum\Requests\NewbornDailyLogStoreRequest;
use App\Domains\Postpartum\Services\NewbornAnomalyDetectorService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 신생아 + 일일 기록
 */
class NewbornController extends Controller
{
    public function __construct(
        private readonly NewbornAnomalyDetectorService $anomalyDetector
    ) {}

    /**
     * 신생아 등록 (산모에 종속)
     * POST /postpartum/clients/{id}/newborns
     */
    public function store(NewbornStoreRequest $request, PostpartumClient $client): JsonResponse
    {
        $this->authorize('update', $client);

        $newborn = Newborn::create(array_merge(
            $request->validated(),
            ['postpartum_client_id' => $client->id]
        ));

        return response()->json([
            'success'   => true,
            'code'      => 'CREATED',
            'message'   => '신생아 등록 완료',
            'data'      => $newborn->load('postpartumClient'),
            'timestamp' => now()->toIso8601String(),
        ], 201);
    }

    public function show(Newborn $newborn): JsonResponse
    {
        $this->authorize('view', $newborn);

        return response()->json([
            'success'   => true,
            'code'      => 'OK',
            'data'      => $newborn->load(['postpartumClient', 'dailyLogs' => fn($q) => $q->limit(20)]),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * 신생아 일일 기록 조회
     * GET /newborns/{id}/daily-logs
     */
    public function dailyLogs(Request $request, Newborn $newborn): JsonResponse
    {
        $this->authorize('view', $newborn);

        $query = NewbornDailyLog::where('newborn_id', $newborn->id)
            ->when($request->filled('from'), fn($q) => $q->where('log_datetime', '>=', $request->date('from')))
            ->when($request->filled('to'), fn($q) => $q->where('log_datetime', '<=', $request->date('to')))
            ->when($request->filled('log_type'), fn($q) => $q->where('log_type', $request->input('log_type')))
            ->orderByDesc('log_datetime');

        $logs = $query->paginate($request->integer('page_size', 50));

        return response()->json([
            'success'   => true,
            'code'      => 'OK',
            'data'      => $logs->items(),
            'meta'      => [
                'page'      => $logs->currentPage(),
                'page_size' => $logs->perPage(),
                'total'     => $logs->total(),
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * 신생아 일일 기록 추가 (인력 또는 보호자)
     * POST /newborns/{id}/daily-logs
     */
    public function storeDailyLog(NewbornDailyLogStoreRequest $request, Newborn $newborn): JsonResponse
    {
        $this->authorize('createDailyLog', $newborn);

        $log = NewbornDailyLog::create(array_merge(
            $request->validated(),
            ['newborn_id' => $newborn->id]
        ));

        // 비동기로 이상 탐지 실행 (큐에 dispatch 권장)
        $alert = $this->anomalyDetector->detectAnomalies($newborn);

        return response()->json([
            'success' => true,
            'code'    => 'CREATED',
            'message' => '일일 기록 추가 완료',
            'data'    => [
                'log'   => $log,
                'alert' => $alert,
            ],
            'timestamp' => now()->toIso8601String(),
        ], 201);
    }
}
