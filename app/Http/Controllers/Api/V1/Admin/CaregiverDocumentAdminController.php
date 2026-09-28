<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\CaregiverDocumentController;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\CaregiverDocumentService;
use App\Services\NotificationService;
use App\Support\MedicalCrypto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * 관리자 — 돌봄전문가 서류 검토 (기능 20, 2026-09-28 S5)
 * 파일 열람은 X-Access-Reason(5자 이상) 필수 + 감사로그 caregiver.document.view. 권한은 RBAC 'caregivers' 영역.
 */
class CaregiverDocumentAdminController extends Controller
{
    public function __construct(private CaregiverDocumentService $docs)
    {
    }

    /** GET /v1/admin/caregivers/{id}/documents */
    public function index(int $id): JsonResponse
    {
        $cg = DB::table('caregivers')->where('id', $id)->first(['id', 'bank_name', 'bank_account', 'bank_holder', 'bank_updated_at']);
        if (!$cg) {
            return response()->json(['success' => false, 'message' => '돌봄전문가를 찾을 수 없습니다.'], 404);
        }
        return response()->json(['success' => true, 'data' => [
            'checklist' => $this->docs->checklist($id),
            'missing_required' => $this->docs->missingRequired($id),
            'enforce_on_approve' => (bool) config('caregiver_docs.enforce_on_approve'),
            'payout' => [
                'bank_name' => $cg->bank_name,
                'bank_account_masked' => CaregiverDocumentController::maskAccount(MedicalCrypto::decrypt($cg->bank_account)),
                'bank_holder' => $cg->bank_holder,
                'updated_at' => $cg->bank_updated_at,
            ],
        ]]);
    }

    /** GET /v1/admin/caregivers/{id}/documents/{docId}/file — 원본 열람(사유 필수) */
    public function file(Request $request, int $id, int $docId): Response
    {
        $reason = trim(rawurldecode((string) $request->header('X-Access-Reason', '')));
        if (mb_strlen($reason) < 5) {
            return response()->json(['success' => false, 'error_code' => 'REASON_REQUIRED', 'message' => '열람 사유를 5자 이상 입력해 주세요.'], 422);
        }
        $doc = DB::table('caregiver_documents')->where('id', $docId)->where('caregiver_id', $id)->first();
        if (!$doc) {
            return response()->json(['success' => false, 'message' => '서류를 찾을 수 없습니다.'], 404);
        }
        $raw = $this->docs->read($doc);
        if ($raw === null) {
            return response()->json(['success' => false, 'error_code' => 'FILE_BROKEN', 'message' => '파일이 없거나 원본 해시와 달라 열 수 없습니다.'], 409);
        }
        AuditLog::create([
            'actor_id' => $request->user()->id, 'action' => 'caregiver.document.view', 'entity_type' => 'caregiver_document',
            'entity_id' => $doc->id, 'details' => ['caregiver_id' => $id, 'doc_type' => $doc->doc_type],
            'reason' => mb_substr($reason, 0, 200), 'ip_address' => $request->ip(),
        ]);
        return response($raw, 200, [
            'Content-Type' => $doc->mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="document-' . $doc->id . '"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** POST /v1/admin/caregivers/{id}/documents/{docId}/verify */
    public function verify(Request $request, int $id, int $docId): JsonResponse
    {
        return $this->review($request, $id, $docId, 'verified', null);
    }

    /** POST /v1/admin/caregivers/{id}/documents/{docId}/reject {reason} */
    public function reject(Request $request, int $id, int $docId): JsonResponse
    {
        $v = $request->validate(['reason' => ['required', 'string', 'min:2', 'max:255']], [], ['reason' => '반려 사유']);
        return $this->review($request, $id, $docId, 'rejected', $v['reason']);
    }

    private function review(Request $request, int $id, int $docId, string $status, ?string $reason): JsonResponse
    {
        $doc = DB::table('caregiver_documents')->where('id', $docId)->where('caregiver_id', $id)->first();
        if (!$doc || $doc->status === 'replaced') {
            return response()->json(['success' => false, 'message' => '검토할 수 있는 서류가 아닙니다(새 파일로 교체되었을 수 있음).'], 404);
        }
        DB::table('caregiver_documents')->where('id', $docId)->update([
            'status' => $status, 'reject_reason' => $reason, 'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(), 'updated_at' => now(),
        ]);
        AuditLog::create([
            'actor_id' => $request->user()->id, 'action' => "caregiver.document.$status", 'entity_type' => 'caregiver_document',
            'entity_id' => $docId, 'details' => ['caregiver_id' => $id, 'doc_type' => $doc->doc_type, 'reason' => $reason],
            'ip_address' => $request->ip(),
        ]);
        if ($status === 'rejected') {
            $userId = DB::table('caregivers')->where('id', $id)->value('user_id');
            app(NotificationService::class)->notifySafely($userId ? (int) $userId : null, NotificationService::TYPE_CAREGIVER_DOC_REJECTED, [
                'doc_label' => CaregiverDocumentService::types()[$doc->doc_type]['label'] ?? '서류',
                'reason' => $reason,
            ]);
        }
        return response()->json(['success' => true, 'message' => $status === 'verified' ? '확인 완료로 처리했어요.' : '반려했어요. 돌봄전문가에게 알림을 보냈어요.',
            'data' => ['missing_required' => $this->docs->missingRequired($id)]]);
    }
}
