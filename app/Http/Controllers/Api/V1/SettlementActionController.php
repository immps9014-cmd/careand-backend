<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 정산 명세서 확인·이의제기·입금 처리 (기능 15·23, 2026-09-29 S5 잔여)
 *  돌봄전문가: 확정된 명세서를 「확인」하거나 「이의제기」(사유) — 이의제기는 정산 담당 관리자에게 즉시 알림, 답변 기준 24시간
 *  관리자: 이의제기 답변(해결/계속 검토), 입금 완료 처리(이의제기 열린 건은 불가) → 돌봄전문가에게 입금 알림
 * 은행 이체 자동 연동은 계약이 필요해 「입금 완료」는 운영자가 이체 후 수동으로 처리한다.
 */
class SettlementActionController extends Controller
{
    private function own(Request $request, int $id): ?object
    {
        $cg = $request->user()->caregiver;
        return $cg ? DB::table('settlements')->where('id', $id)->where('caregiver_id', $cg->id)->first() : null;
    }

    /** POST /v1/settlements/{id}/ack */
    public function ack(Request $request, int $id): JsonResponse
    {
        $st = $this->own($request, $id);
        if (!$st) {
            return response()->json(['success' => false, 'message' => '정산서를 찾을 수 없어요.'], 404);
        }
        if ($st->status !== 'confirmed') {
            return response()->json(['success' => false, 'error_code' => 'INVALID_STATUS', 'message' => '확정된 명세서만 확인할 수 있어요.'], 422);
        }
        if ($st->dispute_status === 'open') {
            return response()->json(['success' => false, 'error_code' => 'DISPUTE_OPEN', 'message' => '이의제기 답변을 받은 뒤 확인할 수 있어요.'], 422);
        }
        DB::table('settlements')->where('id', $id)->whereNull('caregiver_ack_at')->update(['caregiver_ack_at' => now(), 'updated_at' => now()]);
        return response()->json(['success' => true, 'message' => '명세서를 확인했어요. 입금되면 알려 드릴게요.']);
    }

    /** POST /v1/settlements/{id}/dispute {reason} */
    public function dispute(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['reason' => 'required|string|min:5|max:1000'], ['reason.min' => '이의 내용을 5자 이상 적어 주세요.'], ['reason' => '이의 내용']);
        $st = $this->own($request, $id);
        if (!$st) {
            return response()->json(['success' => false, 'message' => '정산서를 찾을 수 없어요.'], 404);
        }
        if (!in_array($st->status, ['draft', 'confirmed'], true)) {
            return response()->json(['success' => false, 'error_code' => 'INVALID_STATUS', 'message' => '입금된 정산은 운영팀에 직접 문의해 주세요.'], 422);
        }
        DB::table('settlements')->where('id', $id)->update([
            'dispute_reason' => $v['reason'], 'disputed_at' => now(), 'dispute_status' => 'open',
            'dispute_reply' => null, 'dispute_resolved_at' => null, 'dispute_resolved_by' => null,
            'caregiver_ack_at' => null, 'updated_at' => now(),
        ]);
        $svc = app(NotificationService::class);
        foreach ($svc->adminsFor('settlements') as $a) {
            $svc->notifySafely($a, NotificationService::TYPE_SETTLEMENT_DISPUTED, [
                'settlement_id' => $id, 'caregiver_name' => $request->user()->name, 'net_amount' => (int) $st->net_amount,
            ]);
        }
        return response()->json(['success' => true, 'message' => '이의제기를 접수했어요. 운영팀이 24시간 안에 답변드려요.']);
    }

    /** POST /v1/admin/settlements/{id}/dispute-reply {reply, resolve} */
    public function reply(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['reply' => 'required|string|min:2|max:1000', 'resolve' => 'boolean'], [], ['reply' => '답변']);
        $st = DB::table('settlements')->where('id', $id)->first();
        if (!$st || $st->dispute_status === null) {
            return response()->json(['success' => false, 'message' => '이의제기가 없는 정산서예요.'], 404);
        }
        $resolve = $request->boolean('resolve', true);
        DB::table('settlements')->where('id', $id)->update([
            'dispute_reply' => $v['reply'],
            'dispute_status' => $resolve ? 'resolved' : 'open',
            'dispute_resolved_at' => $resolve ? now() : null,
            'dispute_resolved_by' => $resolve ? $request->user()->id : null,
            'updated_at' => now(),
        ]);
        $userId = DB::table('caregivers')->where('id', $st->caregiver_id)->value('user_id');
        app(NotificationService::class)->notifySafely($userId ? (int) $userId : null, NotificationService::TYPE_SETTLEMENT_DISPUTE_REPLY, [
            'settlement_id' => $id, 'reply' => mb_substr($v['reply'], 0, 80), 'resolved' => $resolve,
        ]);
        return response()->json(['success' => true, 'message' => $resolve ? '답변하고 해결 처리했어요.' : '답변했어요(계속 검토 중).']);
    }

    /** POST /v1/admin/settlements/{id}/paid {bank_tx_id?} — 이체 후 입금 완료 처리 */
    public function paid(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['bank_tx_id' => 'nullable|string|max:100']);
        $st = DB::table('settlements')->where('id', $id)->first();
        if (!$st) {
            return response()->json(['success' => false, 'message' => '정산서를 찾을 수 없어요.'], 404);
        }
        if ($st->status !== 'confirmed') {
            return response()->json(['success' => false, 'error_code' => 'INVALID_STATUS', 'message' => '확정된 정산서만 입금 처리할 수 있어요.'], 422);
        }
        if ($st->dispute_status === 'open') {
            return response()->json(['success' => false, 'error_code' => 'DISPUTE_OPEN', 'message' => '이의제기에 먼저 답변해 주세요.'], 422);
        }
        DB::table('settlements')->where('id', $id)->where('status', 'confirmed')->update([
            'status' => 'paid', 'paid_at' => now(), 'bank_tx_id' => $v['bank_tx_id'] ?? $st->bank_tx_id, 'updated_at' => now(),
        ]);
        $userId = DB::table('caregivers')->where('id', $st->caregiver_id)->value('user_id');
        app(NotificationService::class)->notifySafely($userId ? (int) $userId : null, NotificationService::TYPE_SETTLEMENT_PAID, [
            'settlement_id' => $id, 'net_amount' => (int) $st->net_amount,
        ]);
        return response()->json(['success' => true, 'message' => '입금 완료로 처리하고 돌봄전문가에게 알렸어요.'
            . ($st->caregiver_ack_at ? '' : ' (돌봄전문가가 아직 명세서를 확인하지 않았어요)')]);
    }
}
