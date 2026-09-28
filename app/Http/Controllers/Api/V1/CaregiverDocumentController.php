<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CaregiverDocumentService;
use App\Support\MedicalCrypto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 돌봄전문가 본인 서류·정산 계좌 (기능 9·20, 2026-09-28 S5)
 * 본인은 업로드·상태 확인만 가능하고, 올린 파일을 다시 내려받지는 못한다(유출 경로 최소화).
 */
class CaregiverDocumentController extends Controller
{
    public function __construct(private CaregiverDocumentService $docs)
    {
    }

    /** GET /v1/caregivers/me/documents */
    public function index(Request $request): JsonResponse
    {
        $cg = $request->user()->caregiver;
        if (!$cg) {
            return response()->json(['success' => false, 'message' => '돌봄전문가 회원만 사용할 수 있습니다.'], 403);
        }
        $row = DB::table('caregivers')->where('id', $cg->id)->first(['bank_name', 'bank_account', 'bank_holder', 'bank_updated_at']);

        return response()->json(['success' => true, 'data' => [
            'checklist' => $this->docs->checklist($cg->id),
            'payout' => [
                'bank_name' => $row->bank_name,
                'bank_account_masked' => self::maskAccount(MedicalCrypto::decrypt($row->bank_account)),
                'bank_holder' => $row->bank_holder,
                'updated_at' => $row->bank_updated_at,
            ],
            'accept' => ['mimes' => config('caregiver_docs.mimes'), 'max_kb' => config('caregiver_docs.max_kb')],
        ]]);
    }

    /** POST /v1/caregivers/me/documents (multipart: doc_type, file, issued_at?) */
    public function store(Request $request): JsonResponse
    {
        $cg = $request->user()->caregiver;
        if (!$cg) {
            return response()->json(['success' => false, 'message' => '돌봄전문가 회원만 사용할 수 있습니다.'], 403);
        }
        $v = $request->validate([
            'doc_type' => ['required', 'string', 'in:' . implode(',', array_keys(CaregiverDocumentService::types()))],
            'file' => ['required', 'file', 'max:' . config('caregiver_docs.max_kb'), 'mimes:' . implode(',', config('caregiver_docs.mimes'))],
            'issued_at' => ['nullable', 'date', 'before_or_equal:today'],
        ], [
            'doc_type.in' => '지원하지 않는 서류 종류입니다.',
            'file.required' => '파일을 선택해 주세요.',
            'file.max' => '파일은 ' . (int) (config('caregiver_docs.max_kb') / 1024) . 'MB 이하만 올릴 수 있어요.',
            'file.mimes' => '사진(jpg·png·heic·webp) 또는 PDF 파일만 올릴 수 있어요.',
            'issued_at.before_or_equal' => '발급일은 오늘 이전이어야 해요.',
        ]);

        $id = $this->docs->store($cg->id, $v['doc_type'], $request->file('file'), $v['issued_at'] ?? null);

        return response()->json(['success' => true, 'message' => '서류가 제출되었어요. 운영팀 확인 후 알려 드릴게요.', 'data' => ['id' => $id]], 201);
    }

    /** PUT /v1/caregivers/me/payout-account {bank_name, bank_account, bank_holder} */
    public function updatePayout(Request $request): JsonResponse
    {
        $cg = $request->user()->caregiver;
        if (!$cg) {
            return response()->json(['success' => false, 'message' => '돌봄전문가 회원만 사용할 수 있습니다.'], 403);
        }
        $v = $request->validate([
            'bank_name' => ['required', 'string', 'max:40'],
            'bank_account' => ['required', 'string', 'regex:/^[0-9\-]{8,30}$/'],
            'bank_holder' => ['required', 'string', 'max:40'],
        ], ['bank_account.regex' => '계좌번호는 숫자와 - 만 입력해 주세요.'], ['bank_name' => '은행', 'bank_account' => '계좌번호', 'bank_holder' => '예금주']);

        DB::table('caregivers')->where('id', $cg->id)->update([
            'bank_name' => $v['bank_name'],
            'bank_account' => MedicalCrypto::encrypt(preg_replace('/\D/', '', $v['bank_account'])),
            'bank_holder' => $v['bank_holder'],
            'bank_updated_at' => now(),
            'updated_at' => now(),
        ]);
        // 계좌를 바꾸면 통장 사본도 다시 확인해야 한다 — 확인 완료였다면 검토 대기로
        DB::table('caregiver_documents')->where('caregiver_id', $cg->id)->where('doc_type', 'bankbook')
            ->where('status', 'verified')->update(['status' => 'submitted', 'reviewed_at' => null, 'reviewed_by' => null, 'updated_at' => now()]);

        return response()->json(['success' => true, 'message' => '정산 계좌가 저장되었어요.']);
    }

    public static function maskAccount(?string $acct): ?string
    {
        if (!$acct) {
            return null;
        }
        return strlen($acct) <= 4 ? '****' : str_repeat('*', strlen($acct) - 4) . substr($acct, -4);
    }
}
