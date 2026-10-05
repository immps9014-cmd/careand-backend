<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\MnhContractException;
use App\Http\Controllers\Controller;
use App\Models\MnhContract;
use App\Models\MnhDocument;
use App\Services\MnhDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * 회원 — 바우처 전자서명 서류(CAREN-MNH-01 3단계, 2026-10-05).
 * 이용자(산모)는 자기 계약 서류에, 돌봄전문가는 자기 계약서에 서명한다.
 * 서비스 제공기록지는 관리사 화면에서 관리사가 칸을 채우고 산모가 그 기기에 서명한다.
 */
class MnhDocumentController extends Controller
{
    public function __construct(private MnhDocumentService $docs)
    {
    }

    /** GET /v1/mnh/contracts/{id}/documents — 내 계약 서류(제공기록지 포함) */
    public function forContract(Request $request, int $id): JsonResponse
    {
        $c = MnhContract::where('id', $id)->where('user_id', $request->user()->id)->firstOrFail();
        $rows = MnhDocument::where('contract_id', $c->id)->where('status', '!=', 'void')->orderBy('id')->get();
        $status = $this->docs->contractStatus($c);

        return response()->json(['success' => true, 'data' => [
            'documents' => $rows->map(fn ($d) => $this->brief($d))->values(),
            'missing_before_start' => $status['missing_before_start'],
        ]]);
    }

    /** GET /v1/mnh/my-documents — 내가 서명할(한) 서류 전체(돌봄전문가 계약서 등) */
    public function mine(Request $request): JsonResponse
    {
        $rows = MnhDocument::where('signer_user_id', $request->user()->id)->where('status', '!=', 'void')
            ->where('doc_type', '!=', 'provision_record')->orderByDesc('id')->limit(100)->get();

        return response()->json(['success' => true, 'data' => $rows->map(fn ($d) => $this->brief($d))->values()]);
    }

    /** GET /v1/mnh/documents/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $doc = $this->authorized($request, $id);

        return response()->json(['success' => true, 'data' => $this->docs->present($doc)]);
    }

    /** POST /v1/mnh/documents/{id}/sign {signature, form_data?, signer_name?} */
    public function sign(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'signature' => ['required', 'string', 'max:420000'],
            'form_data' => ['nullable', 'array'],
            'signer_name' => ['nullable', 'string', 'max:50'],
            'agree' => ['accepted'],
        ]);
        $doc = $this->authorized($request, $id);
        $user = $request->user();
        $isCaregiverCapture = $doc->doc_type === 'provision_record';
        if (!$isCaregiverCapture && (int) $doc->signer_user_id !== (int) $user->id) {
            return $this->fail('NOT_SIGNER', '이 서류는 다른 분이 서명해야 해요.', 403);
        }
        try {
            $doc = $this->docs->sign($doc, $data['signature'], $data['form_data'] ?? [],
                $isCaregiverCapture ? ($data['signer_name'] ?? null) : null,
                (string) $request->ip(), $request->userAgent(), (int) $user->id,
                $isCaregiverCapture ? ['caregiver'] : [$doc->signer_role]);
        } catch (MnhContractException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->status);
        }

        return response()->json(['success' => true, 'message' => '서명했어요. PDF는 잠시 뒤 받을 수 있어요.', 'data' => $this->docs->present($doc->fresh())]);
    }

    /** GET /v1/mnh/documents/{id}/pdf */
    public function pdf(Request $request, int $id): Response
    {
        $doc = $this->authorized($request, $id);
        $pdf = MnhDocumentService::readFile($doc->pdf_path);
        if ($pdf === null) {
            return $this->fail('PDF_NOT_READY', $doc->status === 'signed' ? 'PDF를 만드는 중이에요. 잠시 뒤 다시 눌러 주세요.' : '서명한 뒤에 받을 수 있어요.', 409);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"MNHD-{$doc->id}.pdf\"",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** GET /v1/mnh/documents/{id}/pdf-link — 5분 동안 열리는 서명 링크(모바일 앱은 인증 헤더 없이 브라우저로 연다) */
    public function pdfLink(Request $request, int $id): JsonResponse
    {
        $doc = $this->authorized($request, $id);
        if (!$doc->pdf_path) {
            return $this->fail('PDF_NOT_READY', $doc->status === 'signed' ? 'PDF를 만드는 중이에요. 잠시 뒤 다시 눌러 주세요.' : '서명한 뒤에 받을 수 있어요.', 409);
        }
        $path = \Illuminate\Support\Facades\URL::temporarySignedRoute('mnh.doc.pdf.signed', now()->addMinutes(5), ['id' => $doc->id], false);

        return response()->json(['success' => true, 'data' => ['path' => $path, 'expires_in' => 300]]);
    }

    /** GET /v1/mnh/documents/{id}/pdf-signed?expires=&signature= — 서명 링크로만(라우트 signed:relative) */
    public function pdfSigned(int $id): Response
    {
        $doc = MnhDocument::where('id', $id)->where('status', 'signed')->firstOrFail();
        $pdf = MnhDocumentService::readFile($doc->pdf_path);
        abort_if($pdf === null, 404);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"MNHD-{$doc->id}.pdf\"",
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * GET /v1/mnh/sessions/{sessionId}/provision-record — 담당 관리사가 그날 제공기록지를 연다(없으면 발행).
     * 바우처 계약 방문이 아니면 {voucher:false}.
     */
    public function provisionRecord(Request $request, int $sessionId): JsonResponse
    {
        $row = $this->sessionRow($sessionId);
        $cgId = DB::table('caregivers')->where('user_id', $request->user()->id)->value('id');
        if (!$row || !$row->mnh_contract_id) {
            return response()->json(['success' => true, 'data' => ['voucher' => false]]);
        }
        if (!$cgId || (int) $row->caregiver_id !== (int) $cgId) {
            return $this->fail('FORBIDDEN', '담당 관리사만 볼 수 있어요.', 403);
        }
        if (!in_array($row->status, ['in_progress', 'completed'], true)) {
            return response()->json(['success' => true, 'data' => ['voucher' => true, 'document' => null,
                'message' => '출근한 뒤에 제공기록지를 쓸 수 있어요.']]);
        }
        $c = MnhContract::find($row->mnh_contract_id);
        $doc = $this->docs->issue('provision_record', $c, (int) $row->caregiver_id, $row);

        return response()->json(['success' => true, 'data' => ['voucher' => true, 'document' => $this->docs->present($doc)]]);
    }

    /* ───── 내부 ───── */

    private function sessionRow(int $sessionId): ?object
    {
        return DB::table('care_sessions as cs')->join('matches as m', 'm.id', '=', 'cs.match_id')
            ->join('match_requests as r', 'r.id', '=', 'm.request_id')
            ->where('cs.id', $sessionId)
            ->first(['cs.id', 'cs.status', 'cs.scheduled_start', 'cs.actual_start', 'cs.actual_end', 'm.caregiver_id', 'r.mnh_contract_id']);
    }

    /** 볼 수 있는 사람: 서명자, 계약 회원, 제공기록지면 그 방문의 담당 관리사 */
    private function authorized(Request $request, int $id): MnhDocument
    {
        $doc = MnhDocument::where('id', $id)->where('status', '!=', 'void')->firstOrFail();
        $uid = (int) $request->user()->id;
        $ok = (int) $doc->signer_user_id === $uid
            || ($doc->contract_id && MnhContract::where('id', $doc->contract_id)->where('user_id', $uid)->exists());
        if (!$ok && $doc->care_session_id) {
            $row = $this->sessionRow((int) $doc->care_session_id);
            $ok = $row && (int) DB::table('caregivers')->where('id', $row->caregiver_id)->value('user_id') === $uid;
        }
        abort_unless($ok, 404);

        return $doc;
    }

    private function brief(MnhDocument $d): array
    {
        $p = $this->docs->present($d);
        unset($p['content_html'], $p['fields_html'], $p['fields'], $p['form_data']);

        return $p;
    }

    private function fail(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json(['success' => false, 'error_code' => $code, 'message' => $message], $status);
    }
}
