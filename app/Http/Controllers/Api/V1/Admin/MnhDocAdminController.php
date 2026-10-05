<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\MnhContractException;
use App\Http\Controllers\Controller;
use App\Models\MnhContract;
use App\Models\MnhDocTemplate;
use App\Models\MnhDocument;
use App\Services\MnhDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * 관리자 — 바우처 전자서명 서류(CAREN-MNH-01 3단계, 2026-10-05).
 * 계약 서류 현황·발행·다시 발행·취소, 서식 판 관리, 돌봄전문가 근로·프리랜서 계약서 발행.
 */
class MnhDocAdminController extends Controller
{
    public function __construct(private MnhDocumentService $docs)
    {
    }

    /** GET /v1/admin/mnh/contracts/{id}/documents */
    public function forContract(int $id): JsonResponse
    {
        $c = MnhContract::findOrFail($id);
        $status = $this->docs->contractStatus($c);
        $rows = MnhDocument::where('contract_id', $c->id)->orderBy('id')->get();

        return response()->json(['success' => true, 'data' => [
            'documents' => $rows->map(fn ($d) => $this->brief($d))->values(),
            'missing_before_start' => $status['missing_before_start'],
            'enforce' => (bool) config('mnh_docs.enforce'),
            'issuable' => collect(MnhDocumentService::types())->filter(fn ($t, $k) => $t['signer'] === 'client' && $k !== 'provision_record')
                ->map(fn ($t, $k) => ['type' => $k, 'label' => $t['label'],
                    'admin_fields' => array_values(array_filter($t['fields'] ?? [], fn ($f) => $f['filled_by'] === 'admin'))])->values(),
        ]]);
    }

    /** POST /v1/admin/mnh/contracts/{id}/documents {doc_type, form_data} */
    public function issue(Request $request, int $id): JsonResponse
    {
        $c = MnhContract::findOrFail($id);
        $data = $request->validate([
            'doc_type' => ['required', Rule::in(array_keys(MnhDocumentService::types()))],
            'form_data' => ['nullable', 'array'],
        ]);
        if (in_array($data['doc_type'], ['provision_record', 'employment_contract'], true)) {
            return $this->fail('NOT_HERE', '제공기록지는 관리사 화면에서, 근로계약서는 인력 계약에서 발행해요.');
        }
        try {
            $doc = $this->docs->issue($data['doc_type'], $c, null, null, $data['form_data'] ?? [], $request->user()->id);
        } catch (MnhContractException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->status);
        }

        return response()->json(['success' => true, 'message' => $doc->wasRecentlyCreated ? '발행했어요. 이용자에게 서명 요청 알림이 갔어요.' : '이미 발행된 서류예요.',
            'data' => $this->docs->present($doc, true)]);
    }

    /** GET /v1/admin/mnh/documents/{id} — 본문·입력값·무결성 확인 */
    public function show(int $id): JsonResponse
    {
        $doc = MnhDocument::findOrFail($id);

        return response()->json(['success' => true, 'data' => $this->docs->present($doc, true) + ['integrity' => $this->docs->verify($doc)]]);
    }

    /** POST /v1/admin/mnh/documents/{id}/reissue {reason} — 계약 조건이 바뀌었을 때 새로 발행 */
    public function reissue(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        try {
            $doc = $this->docs->reissue(MnhDocument::findOrFail($id), $data['reason'], $request->user()->id);
        } catch (MnhContractException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->status);
        }

        return response()->json(['success' => true, 'message' => '새로 발행했어요. 이전 서류는 취소로 남아요.', 'data' => $this->docs->present($doc, true)]);
    }

    /** POST /v1/admin/mnh/documents/{id}/void {reason} */
    public function void(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        $doc = MnhDocument::findOrFail($id);
        if ($doc->status === 'void') {
            return $this->fail('VOID', '이미 취소된 서류예요.');
        }
        $doc->update(['status' => 'void', 'void_reason' => $data['reason']]);

        return response()->json(['success' => true, 'message' => '서류를 취소했어요.']);
    }

    /** GET /v1/admin/mnh/documents/{id}/pdf */
    public function pdf(int $id): Response
    {
        $doc = MnhDocument::findOrFail($id);
        $pdf = MnhDocumentService::readFile($doc->pdf_path);
        if ($pdf === null) {
            return $this->fail('PDF_NOT_READY', $doc->status === 'signed' ? 'PDF를 만드는 중이에요.' : '서명 전 서류예요.', 409);
        }

        return response($pdf, 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"MNHD-{$doc->id}.pdf\"", 'Cache-Control' => 'private, no-store']);
    }

    /* ───── 서식 ───── */

    /** GET /v1/admin/mnh/templates — 종류별 최신판 + 판 이력 */
    public function templates(): JsonResponse
    {
        $out = [];
        foreach (MnhDocumentService::types() as $type => $cfg) {
            $cur = $this->docs->template($type);
            $out[] = [
                'doc_type' => $type, 'label' => $cfg['label'], 'signer' => $cfg['signer'],
                'before_start' => (bool) ($cfg['before_start'] ?? false), 'auto' => $cfg['auto'] ?? null,
                'fields' => $cfg['fields'] ?? [],
                'version' => $cur->version, 'title' => $cur->title, 'body' => $cur->body, 'note' => $cur->note,
                'updated_at' => \App\Support\Kst::iso($cur->created_at),
                'versions' => MnhDocTemplate::where('doc_type', $type)->orderByDesc('version')->get(['version', 'note', 'created_at'])
                    ->map(fn ($v) => ['version' => $v->version, 'note' => $v->note, 'created_at' => \App\Support\Kst::iso($v->created_at)]),
            ];
        }

        return response()->json(['success' => true, 'data' => [
            'templates' => $out,
            'variables' => array_keys($this->docs->vars(null) + array_flip(['contract_no', 'client_name', 'client_birth', 'client_address',
                'support_label', 'start_date', 'end_date', 'days', 'weekdays', 'daily_time', 'total_price', 'gov_support', 'self_pay',
                'payment_method', 'prepaid_amount', 'prepaid_date', 'receipt_no', 'caregiver_name', 'session_date', 'session_time', 'session_seq'])),
            'provider' => config('mnh_docs.provider'),
        ]]);
    }

    /** PUT /v1/admin/mnh/templates/{type} {title, body, note} — 새 판으로 저장 */
    public function saveTemplate(Request $request, string $type): JsonResponse
    {
        abort_unless(array_key_exists($type, MnhDocumentService::types()), 404);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string', 'max:20000'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $t = $this->docs->saveTemplate($type, $data['title'], $data['body'], $data['note'] ?? null, $request->user()->id);

        return response()->json(['success' => true, 'message' => "v{$t->version}로 저장했어요. 이미 발행한 서류는 그대로예요.", 'data' => ['version' => $t->version]]);
    }

    /** POST /v1/admin/mnh/templates/{type}/preview {body} — 예시 값으로 미리보기 */
    public function preview(Request $request, string $type): JsonResponse
    {
        abort_unless(array_key_exists($type, MnhDocumentService::types()), 404);
        $body = (string) $request->validate(['body' => ['required', 'string', 'max:20000']])['body'];
        $sample = ['contract_no' => 'MNH-2026-0001', 'client_name' => '홍○○', 'client_birth' => '1995년 1월 1일', 'client_address' => '(주소)',
            'support_label' => '단태아 · 첫째아 · (유형) · 표준', 'start_date' => '2026년 11월 2일', 'end_date' => '2026년 11월 13일', 'days' => 10,
            'weekdays' => '월화수목금', 'daily_time' => '09:00부터 8시간', 'total_price' => '(서비스 가격)', 'gov_support' => '(정부지원금)',
            'self_pay' => '(본인부담금)', 'payment_method' => '현금', 'prepaid_amount' => '(받은 금액)', 'prepaid_date' => '2026년 10월 30일',
            'receipt_no' => 'MNH-2026-0001-R', 'caregiver_name' => '김○○', 'session_date' => '2026년 11월 2일', 'session_time' => '09:00 ~ 17:00', 'session_seq' => 1];

        return response()->json(['success' => true, 'data' => ['html' => MnhDocumentService::render($body, $this->docs->vars(null) + $sample)]]);
    }

    /* ───── 인력 계약 ───── */

    /** GET /v1/admin/mnh/employment — 산모신생아 돌봄전문가별 계약서 현황 */
    public function employment(): JsonResponse
    {
        $cgs = DB::table('caregivers as cg')->join('users as u', 'u.id', '=', 'cg.user_id')
            ->whereNull('cg.deleted_at')->whereIn('cg.status', ['active', 'pending'])
            ->whereRaw("FIND_IN_SET('postpartum', cg.service_domains)")
            ->orderBy('u.name')->get(['cg.id', 'u.name', 'cg.status']);
        $docs = MnhDocument::where('doc_type', 'employment_contract')->where('status', '!=', 'void')
            ->whereIn('caregiver_id', $cgs->pluck('id'))->orderByDesc('id')->get()->groupBy('caregiver_id');

        return response()->json(['success' => true, 'data' => $cgs->map(fn ($cg) => [
            'caregiver_id' => $cg->id, 'name' => $cg->name, 'status' => $cg->status,
            'documents' => ($docs[$cg->id] ?? collect())->map(fn ($d) => $this->brief($d))->values(),
        ])->values()]);
    }

    /** POST /v1/admin/mnh/employment {caregiver_id, form_data} */
    public function issueEmployment(Request $request): JsonResponse
    {
        $data = $request->validate(['caregiver_id' => ['required', 'integer', 'exists:caregivers,id'], 'form_data' => ['required', 'array']]);
        $live = MnhDocument::where('doc_type', 'employment_contract')->where('caregiver_id', $data['caregiver_id'])
            ->whereNull('contract_id')->where('status', 'issued')->exists();
        if ($live) {
            return $this->fail('PENDING_EXISTS', '서명 대기 중인 계약서가 있어요. 고치려면 「다시 발행」을 쓰세요.');
        }
        try {
            $doc = $this->issueFreshEmployment((int) $data['caregiver_id'], $data['form_data'], $request->user()->id);
        } catch (MnhContractException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->status);
        }

        return response()->json(['success' => true, 'message' => '계약서를 발행했어요. 돌봄전문가에게 서명 요청 알림이 갔어요.', 'data' => $this->docs->present($doc, true)]);
    }

    private function issueFreshEmployment(int $caregiverId, array $form, int $actor): MnhDocument
    {
        $form = $this->docs->cleanForm('employment_contract', $form, ['admin']);
        foreach (MnhDocumentService::type('employment_contract')['fields'] as $f) {
            if ($f['type'] !== 'textarea' && empty($form[$f['key']])) {
                throw new MnhContractException('FIELD_REQUIRED', "「{$f['label']}」을(를) 채워 주세요.");
            }
        }
        // 서명 끝난 계약서는 이력으로 두고 새 계약(갱신)을 낸다 — issue() 는 근로계약서의 경우 서명 대기 건만 중복으로 본다
        return $this->docs->issue('employment_contract', null, $caregiverId, null, $form, $actor);
    }

    private function brief(MnhDocument $d): array
    {
        $p = $this->docs->present($d, true);
        unset($p['content_html'], $p['fields_html'], $p['fields']);

        return $p;
    }

    private function fail(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json(['success' => false, 'error_code' => $code, 'message' => $message], $status);
    }
}
