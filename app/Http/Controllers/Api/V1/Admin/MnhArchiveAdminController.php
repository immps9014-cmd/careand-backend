<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MnhContract;
use App\Models\MnhDocument;
use App\Services\MnhDocumentService;
use App\Support\Kst;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * 관리자 — 바우처 서류 보관함(2026-10-18). 회원(산모)별 계약 서류·제공기록지·모니터링을 모아 보고 찾는다.
 * 검색: 산모 이름·계약번호·전화 뒤 4자리, 서류 종류·상태·기간(발행일/서명일/방문일)·담당 관리사.
 * 산모별 묶음: 계약마다 서류 종류별 최신 판 + 제공기록지 방문일 달력(완료인데 서명 없는 날 = 빠짐).
 * PDF 묶음 받기는 개인·건강정보 반출이라 X-Access-Reason(5자 이상) 필수 + 감사로그 mnh.documents.export.
 * 근로·프리랜서 계약서(관리사 서류)는 회원 서류가 아니라 「인력 계약」 탭에서만 다룬다.
 */
class MnhArchiveAdminController extends Controller
{
    private const TZ = 'Asia/Seoul';
    private const PER_PAGE = 50;

    public function __construct(private MnhDocumentService $docs)
    {
    }

    /** GET /v1/admin/mnh/documents?q=&doc_type=&status=&date_kind=&from=&to=&caregiver_id=&page= */
    public function search(Request $request): JsonResponse
    {
        $types = self::memberTypes();
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:50'],
            'doc_type' => ['nullable', Rule::in(array_keys($types))],
            'status' => ['nullable', Rule::in(['issued', 'signed', 'void', 'all'])],
            'date_kind' => ['nullable', Rule::in(['issued', 'signed', 'session'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'caregiver_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $q = DB::table('mnh_documents as d')
            ->join('mnh_contracts as c', 'c.id', '=', 'd.contract_id')
            ->leftJoin('postpartum_clients as pc', 'pc.id', '=', 'c.postpartum_client_id')
            ->leftJoin('care_sessions as cs', 'cs.id', '=', 'd.care_session_id')
            ->whereIn('d.doc_type', array_keys($types));

        $status = $f['status'] ?? null;
        if ($status && $status !== 'all') {
            $q->where('d.status', $status);
        } elseif (!$status) {
            $q->where('d.status', '!=', 'void'); // 기본은 취소된 판을 뺀다
        }
        if (!empty($f['doc_type'])) {
            $q->where('d.doc_type', $f['doc_type']);
        }
        if (!empty($f['caregiver_id'])) {
            $cg = (int) $f['caregiver_id'];
            $q->where(fn ($w) => $w->where('d.caregiver_id', $cg)->orWhere('c.caregiver_id', $cg));
        }
        if ($kw = trim((string) ($f['q'] ?? ''))) {
            $q->where(function ($w) use ($kw) {
                $w->where('pc.name', 'like', "%{$kw}%")->orWhere('c.contract_no', 'like', "%{$kw}%");
                if (preg_match('/^\d{4}$/', $kw)) {
                    // 산모 회원 전화 뒤 4자리(users.phone, 하이픈 유무 무관)
                    $w->orWhereIn('c.user_id', DB::table('users')->whereRaw("REPLACE(phone, '-', '') LIKE ?", ["%{$kw}"])->select('id'));
                }
            });
        }
        $col = ['issued' => 'd.created_at', 'signed' => 'd.signed_at', 'session' => 'cs.scheduled_start'][$f['date_kind'] ?? 'issued'];
        if (!empty($f['from'])) {
            $q->where($col, '>=', Carbon::parse($f['from'], self::TZ)->startOfDay()->utc());
        }
        if (!empty($f['to'])) {
            $q->where($col, '<', Carbon::parse($f['to'], self::TZ)->addDay()->startOfDay()->utc());
        }

        $total = (clone $q)->count();
        $byType = (clone $q)->groupBy('d.doc_type')->selectRaw('d.doc_type, count(*) n')->pluck('n', 'd.doc_type');
        $page = (int) ($f['page'] ?? 1);
        $rows = $q->orderByDesc('d.id')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)
            ->get(['d.id', 'd.doc_type', 'd.title', 'd.status', 'd.signed_at', 'd.created_at', 'd.pdf_path', 'd.void_reason',
                'd.caregiver_id as doc_caregiver_id', 'd.contract_id', 'c.contract_no', 'c.caregiver_id as contract_caregiver_id',
                'c.postpartum_client_id', 'pc.name as client_name', 'cs.scheduled_start']);

        $cgNames = self::caregiverNames($rows->flatMap(fn ($r) => [$r->doc_caregiver_id, $r->contract_caregiver_id]));

        return response()->json(['success' => true, 'data' => [
            'rows' => $rows->map(fn ($r) => [
                'id' => $r->id, 'doc_type' => $r->doc_type, 'label' => $types[$r->doc_type]['label'], 'title' => $r->title,
                'status' => $r->status, 'void_reason' => $r->void_reason,
                'issued_at' => Kst::iso($r->created_at), 'signed_at' => Kst::iso($r->signed_at), 'pdf_ready' => (bool) $r->pdf_path,
                'session_date' => self::kstDate($r->scheduled_start),
                'contract_id' => $r->contract_id, 'contract_no' => $r->contract_no,
                'client_id' => $r->postpartum_client_id, 'client_name' => $r->client_name,
                'caregiver_name' => $cgNames[$r->doc_caregiver_id ?: $r->contract_caregiver_id] ?? null,
            ])->values(),
            'total' => $total, 'page' => $page, 'per_page' => self::PER_PAGE,
            'by_type' => $byType,
            'types' => collect($types)->map(fn ($t, $k) => ['type' => $k, 'label' => $t['label']])->values(),
        ]]);
    }

    /** GET /v1/admin/mnh/clients/{id}/documents — 산모 한 명의 계약별 서류 묶음 */
    public function client(int $id): JsonResponse
    {
        $pc = DB::table('postpartum_clients')->where('id', $id)->first();
        abort_if(!$pc, 404);
        $types = self::memberTypes();
        unset($types['provision_record']); // 제공기록지는 방문일 달력으로 따로
        $contracts = MnhContract::where('postpartum_client_id', $id)->orderByDesc('id')->get();
        $docs = MnhDocument::whereIn('contract_id', $contracts->pluck('id'))->orderBy('id')->get()->groupBy('contract_id');
        $cgNames = self::caregiverNames($contracts->pluck('caregiver_id'));
        $phone = $contracts->first()?->user_id ? DB::table('users')->where('id', $contracts->first()->user_id)->value('phone') : null;

        $out = $contracts->map(function (MnhContract $c) use ($docs, $types, $cgNames) {
            $mine = $docs->get($c->id, collect());
            $docRows = [];
            foreach ($types as $type => $cfg) {
                $all = $mine->where('doc_type', $type);
                $live = $all->where('status', '!=', 'void')->last();
                $docRows[] = ['type' => $type, 'label' => $cfg['label'], 'before_start' => (bool) ($cfg['before_start'] ?? false),
                    'doc' => $live ? self::brief($live) : null, 'void_count' => $all->where('status', 'void')->count()];
            }

            return [
                'id' => $c->id, 'contract_no' => $c->contract_no, 'status' => $c->status,
                'start_date' => $c->start_date ? Carbon::parse($c->start_date)->toDateString() : null, 'days' => (int) $c->days,
                'caregiver_name' => $cgNames[$c->caregiver_id] ?? null,
                'documents' => $docRows,
                'provision' => $this->provision($c, $mine->where('doc_type', 'provision_record')),
            ];
        })->values();

        return response()->json(['success' => true, 'data' => [
            'client' => [
                'id' => $pc->id, 'name' => $pc->name,
                'phone_tail' => $phone ? substr(preg_replace('/\D/', '', $phone), -4) : null,
                'delivery_date' => $pc->delivery_date ?: null, 'expected_delivery_date' => $pc->expected_delivery_date ?: null,
            ],
            'contracts' => $out,
            'pdf_count' => MnhDocument::whereIn('contract_id', $contracts->pluck('id'))->where('status', 'signed')->whereNotNull('pdf_path')
                ->whereIn('doc_type', array_keys(self::memberTypes()))->count(),
        ]]);
    }

    /** GET /v1/admin/mnh/clients/{id}/documents/zip?contract_id= — 서명본 PDF 묶음(사유 필수·감사로그) */
    public function zip(Request $request, int $id): Response
    {
        $reason = trim(rawurldecode((string) $request->header('X-Access-Reason', '')));
        if (mb_strlen($reason) < 5) {
            return $this->fail('REASON_REQUIRED', '내려받는 사유를 5자 이상 입력해 주세요.');
        }
        $pc = DB::table('postpartum_clients')->where('id', $id)->first(['id', 'name']);
        abort_if(!$pc, 404);
        $cq = MnhContract::where('postpartum_client_id', $id);
        if ($cid = (int) $request->query('contract_id')) {
            $cq->where('id', $cid);
        }
        $contracts = $cq->get(['id', 'contract_no'])->keyBy('id');
        $types = self::memberTypes();
        $docs = MnhDocument::whereIn('contract_id', $contracts->keys())->where('status', 'signed')->whereNotNull('pdf_path')
            ->whereIn('doc_type', array_keys($types))->orderBy('contract_id')->orderBy('id')->get();
        if ($docs->isEmpty()) {
            return $this->fail('NO_PDF', '내려받을 서명본 PDF가 없어요.', 404);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mnhzip');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $order = array_flip(array_keys($types));
        $added = [];
        $broken = 0;
        foreach ($docs as $d) {
            $pdf = MnhDocumentService::readFile($d->pdf_path);
            if ($pdf === null) {
                $broken++;
                continue;
            }
            $day = $d->doc_type === 'provision_record' && $d->care_session_id
                ? self::kstDate(DB::table('care_sessions')->where('id', $d->care_session_id)->value('scheduled_start'))
                : Carbon::parse($d->signed_at)->setTimezone(self::TZ)->toDateString();
            $name = sprintf('%s/%02d_%s_%s_MNHD-%d.pdf', self::safe($contracts[$d->contract_id]->contract_no ?? (string) $d->contract_id),
                ($order[$d->doc_type] ?? 99) + 1, self::safe($types[$d->doc_type]['label']), $day, $d->id);
            $zip->addFromString($name, $pdf);
            $added[] = $d->id;
        }
        $zip->close();
        if (!$added) {
            @unlink($tmp);

            return $this->fail('FILE_BROKEN', 'PDF 파일을 열 수 없어요. 서류를 다시 확인해 주세요.', 409);
        }

        AuditLog::create([
            'actor_id' => $request->user()->id, 'action' => 'mnh.documents.export', 'entity_type' => 'postpartum_client',
            'entity_id' => $pc->id, 'details' => ['contract_ids' => $contracts->keys()->all(), 'document_ids' => $added, 'broken' => $broken],
            'reason' => mb_substr($reason, 0, 200), 'ip_address' => $request->ip(),
        ]);
        $file = sprintf('바우처서류_%s_%s.zip', self::safe($pc->name ?? (string) $pc->id), Carbon::now(self::TZ)->format('Ymd'));

        return response()->download($tmp, $file, ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store'])
            ->deleteFileAfterSend();
    }

    /** 방문일별 제공기록지 — 완료 방문인데 서명된 기록지가 없으면 missing */
    private function provision(MnhContract $c, $records): array
    {
        $sessions = $c->match_request_id ? DB::table('care_sessions as cs')->join('matches as m', 'm.id', '=', 'cs.match_id')
            ->where('m.request_id', $c->match_request_id)->where('cs.status', '!=', 'cancelled')
            ->orderBy('cs.scheduled_start')->get(['cs.id', 'cs.status', 'cs.scheduled_start']) : collect();
        $bySession = $records->where('status', '!=', 'void')->keyBy('care_session_id');
        $days = $sessions->map(function ($s) use ($bySession) {
            $doc = $bySession->get($s->id);

            return ['session_id' => $s->id, 'date' => self::kstDate($s->scheduled_start), 'session_status' => $s->status,
                'doc' => $doc ? ['id' => $doc->id, 'status' => $doc->status, 'pdf_ready' => (bool) $doc->pdf_path] : null,
                'missing' => $s->status === 'completed' && (!$doc || $doc->status !== 'signed')];
        })->values();

        return ['days' => $days, 'completed' => $days->where('session_status', 'completed')->count(),
            'signed' => $days->filter(fn ($d) => ($d['doc']['status'] ?? null) === 'signed')->count(),
            'missing' => $days->where('missing', true)->count()];
    }

    /** 회원(산모)이 서명하는 서류 종류 — config 순서 그대로(근로계약서 제외) */
    private static function memberTypes(): array
    {
        return array_filter(MnhDocumentService::types(), fn ($t) => ($t['signer'] ?? null) === 'client');
    }

    private static function brief(MnhDocument $d): array
    {
        return ['id' => $d->id, 'status' => $d->status, 'template_version' => $d->template_version,
            'issued_at' => Kst::iso($d->created_at), 'signed_at' => Kst::iso($d->signed_at), 'pdf_ready' => (bool) $d->pdf_path];
    }

    private static function caregiverNames($ids)
    {
        $ids = collect($ids)->filter()->unique()->values();

        return $ids->isEmpty() ? collect() : DB::table('caregivers as cg')->join('users as u', 'u.id', '=', 'cg.user_id')
            ->whereIn('cg.id', $ids)->pluck('u.name', 'cg.id');
    }

    private static function kstDate($utc): ?string
    {
        return $utc ? Carbon::parse($utc, 'UTC')->setTimezone(self::TZ)->toDateString() : null;
    }

    /** zip 안 파일 이름 — 경로 문자·제어 문자 제거 */
    private static function safe(string $s): string
    {
        return trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '_', $s)) ?: '_';
    }

    private function fail(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json(['success' => false, 'error_code' => $code, 'message' => $message], $status);
    }
}
