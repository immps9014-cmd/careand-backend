<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateCareLogJob;
use App\Models\CareSession;
use App\Support\MedicalCrypto;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * 칩 기반 케어일지 (기능 40, 2026-09-28 S5)
 * 칩 목록의 원본은 온톨로지 care-journal 계층 — ai-service 가 SPARQL 로 읽어 준다(장애 시 같은 원본 JSON).
 */
class CareJournalController extends Controller
{
    /** GET /v1/care-journal/chips — 10분 캐시 */
    public function catalog(): JsonResponse
    {
        $data = Cache::remember('care-journal:catalog', 600, function () {
            try {
                $res = Http::timeout(5)->withToken(config('services.ai.token') ?? '')
                    ->get(rtrim(config('services.ai.base_url'), '/') . '/care-log/chips/catalog');
            } catch (ConnectionException) {
                return null; // AI 지연·중단은 503 으로 안내(500 아님)
            }
            return $res->successful() ? $res->json() : null;
        });
        if (!$data) {
            Cache::forget('care-journal:catalog');
            return response()->json(['success' => false, 'message' => '칩 목록을 불러오지 못했어요. 잠시 후 다시 시도해 주세요.'], 503);
        }
        return response()->json(['success' => true, 'data' => $data]);
    }

    /** GET /v1/care-sessions/{id}/chips */
    public function show(Request $request, int $id): JsonResponse
    {
        $session = $this->ownSession($request, $id);
        return response()->json(['success' => true, 'data' => [
            'chips' => $session->journal_chips ? json_decode($session->journal_chips, true) : [],
            'note' => MedicalCrypto::decrypt($session->journal_note),
            'updated_at' => $session->chips_updated_at,
            'locked' => $this->locked($id),
        ]]);
    }

    /**
     * PUT /v1/care-sessions/{id}/chips {chips:[코드], note?}
     * 진행 중이면 퇴근 때 일지 생성에 쓰이고, 퇴근 뒤면 아직 보호자에게 안 간 일지를 칩 기준으로 다시 만든다.
     */
    public function save(Request $request, int $id): JsonResponse
    {
        $session = $this->ownSession($request, $id);
        $v = $request->validate([
            'chips' => ['present', 'array', 'max:55'],
            'chips.*' => ['string', 'max:40', 'regex:/^[A-Za-z]+$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['chips.*.regex' => '알 수 없는 칩입니다.']);

        if (!in_array($session->status, ['in_progress', 'completed'], true)) {
            return response()->json(['success' => false, 'error_code' => 'INVALID_STATUS', 'message' => '출근 후에 기록할 수 있어요.'], 422);
        }
        if ($this->locked($id)) {
            return response()->json(['success' => false, 'error_code' => 'LOG_SENT', 'message' => '이미 보호자에게 전송된 일지예요. 수정이 필요하면 운영팀에 알려 주세요.'], 409);
        }

        DB::table('care_sessions')->where('id', $id)->update([
            'journal_chips' => json_encode(array_values(array_unique($v['chips']))),
            'journal_note' => MedicalCrypto::encrypt(($v['note'] ?? null) ?: null),
            'chips_updated_at' => now(),
            'updated_at' => now(),
        ]);
        $session->refresh();
        $session->markLogStarted();   // KPI: 퇴근 후 첫 기록이면 일지 작성 시작

        $regenerated = false;
        if ($session->status === 'completed') {
            // 퇴근 때 이미 만든(아직 안 보낸) 일지는 지우고 칩 기준으로 다시 만든다
            DB::table('ai_log_summaries')->where('session_id', $id)->delete();
            GenerateCareLogJob::dispatch($id);
            $regenerated = true;
        }

        return response()->json(['success' => true,
            'message' => $regenerated ? '저장했어요. 일지를 다시 정리하고 있어요.' : '저장했어요. 퇴근하면 일지로 정리돼요.',
            'data' => ['regenerated' => $regenerated]]);
    }

    private function ownSession(Request $request, int $id): CareSession
    {
        $session = CareSession::with('match')->findOrFail($id);
        $cg = $request->user()->caregiver;
        if (!$cg || $session->match->caregiver_id !== $cg->id) {
            abort(403, '본인의 케어 세션만 접근 가능합니다.');
        }
        return $session;
    }

    /** 보호자에게 이미 전송(승인)된 일지면 잠금 */
    private function locked(int $id): bool
    {
        return DB::table('care_sessions')->where('id', $id)->where(fn ($q) => $q->whereNotNull('log_sent_at')->orWhere('review_status', 'approved'))->exists();
    }
}
