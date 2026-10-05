<?php

namespace App\Services;

use App\Exceptions\MnhContractException;
use App\Jobs\GenerateMnhDocumentPdfJob;
use App\Models\MnhContract;
use App\Models\MnhDocTemplate;
use App\Models\MnhDocument;
use App\Support\Kst;
use App\Support\MedicalCrypto;
use App\Support\MnhContractPresenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 산모신생아 바우처 전자서명 서류(CAREN-MNH-01 3단계, 2026-10-05).
 *
 * 발행(issue) 때 서식 최신판에 계약 값을 끼워 넣은 본문을 스냅숏으로 저장하고, 서명(sign) 때 입력값·서명 이미지·
 * 서명자·시각을 묶어 SHA-256 을 남긴 뒤 큐에서 PDF 를 만든다(tools/pdf/html2pdf.js, 크롬 PDF 인쇄).
 * 서명 이미지와 PDF 는 의료정보 키로 암호화해 storage/app/mnh-docs 에 둔다(caregiver_documents 와 같은 형식).
 */
class MnhDocumentService
{
    private const TZ = 'Asia/Seoul';

    public function __construct(private MnhContractService $contracts, private NotificationService $notifier)
    {
    }

    public static function types(): array
    {
        return config('mnh_docs.types');
    }

    public static function type(string $type): array
    {
        $t = self::types()[$type] ?? null;
        if (!$t) {
            throw new MnhContractException('UNKNOWN_DOC', '알 수 없는 서류 종류예요.');
        }

        return $t;
    }

    /* ───────────── 서식 ───────────── */

    /** 서식 최신판 — 아직 없으면 config 초안을 v1 로 넣는다 */
    public function template(string $type): MnhDocTemplate
    {
        $t = MnhDocTemplate::where('doc_type', $type)->orderByDesc('version')->first();
        if ($t) {
            return $t;
        }
        $cfg = self::type($type);

        return MnhDocTemplate::create(['doc_type' => $type, 'version' => 1, 'title' => $cfg['label'], 'body' => $cfg['body'],
            'note' => '초안(운영사 검토 전)']);
    }

    public function saveTemplate(string $type, string $title, string $body, ?string $note, ?int $actor): MnhDocTemplate
    {
        $cur = $this->template($type);
        if ($cur->title === $title && $cur->body === $body) {
            return $cur;
        }

        return MnhDocTemplate::create(['doc_type' => $type, 'version' => $cur->version + 1, 'title' => $title,
            'body' => $body, 'note' => $note, 'created_by' => $actor]);
    }

    /** 미니 문법 → HTML. 값·본문 모두 이스케이프하고 우리가 만든 태그만 남긴다. */
    public static function render(string $body, array $vars): string
    {
        $text = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($vars) {
            $v = $vars[$m[1]] ?? null;

            return "\u{E000}" . base64_encode(($v === null || $v === '') ? '(미등록)' : (string) $v) . "\u{E001}";
        }, $body);
        $esc = fn (string $s) => preg_replace_callback("/\u{E000}([A-Za-z0-9+\/=]*)\u{E001}/u",
            fn ($m) => '<b>' . e(base64_decode($m[1])) . '</b>', e($s));

        $html = [];
        foreach (preg_split("/\n\s*\n/", str_replace("\r", '', trim($text))) as $block) {
            $lines = explode("\n", trim($block));
            $list = [];
            foreach ($lines as $line) {
                if (str_starts_with($line, '## ')) {
                    $html[] = '<h3>' . $esc(substr($line, 3)) . '</h3>';
                } elseif (str_starts_with($line, '- ')) {
                    $list[] = '<li>' . $esc(substr($line, 2)) . '</li>';
                } elseif (trim($line) !== '') {
                    if ($list) {
                        $html[] = '<ul>' . implode('', $list) . '</ul>';
                        $list = [];
                    }
                    $html[] = '<p>' . $esc($line) . '</p>';
                }
            }
            if ($list) {
                $html[] = '<ul>' . implode('', $list) . '</ul>';
            }
        }

        return implode("\n", $html);
    }

    /** 산모 비상연락처 한 줄 — 「김가족(배우자) 010-1234-5678」, 없으면 null(서식에 「(미등록)」) */
    private static function emergencyLine(?string $stored): ?string
    {
        $e = \App\Support\CaregiverProfileExtras::emergency($stored);
        if (!$e) {
            return null;
        }
        $p = (string) ($e['phone'] ?? '');
        $p = strlen($p) === 11 ? substr($p, 0, 3) . '-' . substr($p, 3, 4) . '-' . substr($p, 7) : $p;

        return trim(($e['name'] ?? '') . (!empty($e['relation']) ? '(' . $e['relation'] . ')' : '') . ' ' . $p);
    }

    /** 서식 변수 */
    public function vars(?MnhContract $c, ?int $caregiverId = null, ?object $session = null): array
    {
        $p = config('mnh_docs.provider');
        $won = fn ($n) => $n === null ? null : number_format((int) $n) . '원';
        $kdate = fn ($d) => $d ? Carbon::parse($d)->format('Y년 n월 j일') : null;
        $v = [
            'provider_name' => $p['name'], 'provider_biz_no' => $p['biz_no'], 'provider_ceo' => $p['ceo'],
            'provider_address' => $p['address'], 'provider_phone' => $p['phone'],
            'today' => Carbon::now(self::TZ)->format('Y년 n월 j일'),
        ];
        if ($c) {
            $client = DB::table('postpartum_clients')->where('id', $c->postpartum_client_id)->first(['name', 'birth_date', 'address', 'address_detail', 'emergency_contact']);
            $dow = ['', '월', '화', '수', '목', '금', '토', '일'];
            $v += [
                'contract_no' => $c->contract_no,
                'client_name' => $client->name ?? null,
                'client_birth' => $kdate($client->birth_date ?? null),
                'client_address' => trim(($client->address ?? '') . ' ' . ($client->address_detail ?? '')),
                'client_gender' => '여',   // 산모 — 성별 칸 없이 고정(2026-10-05)
                'client_emergency' => self::emergencyLine($client->emergency_contact ?? null),
                'support_label' => MnhContractPresenter::supportLabel($c),
                'start_date' => $kdate($c->start_date),
                'end_date' => $kdate($this->contracts->endDate($c)),
                'days' => $c->days,
                'weekdays' => implode('', array_map(fn ($d) => $dow[(int) $d] ?? '', $c->weekdays ?: config('mnh.weekdays'))),
                'daily_time' => $c->daily_start . '부터 ' . round($c->daily_minutes / 60, 1) . '시간',
                'total_price' => $won($c->total_price), 'gov_support' => $won($c->gov_support), 'self_pay' => $won($c->self_pay),
                'payment_method' => config('mnh.payment_methods')[$c->payment_method] ?? $c->payment_method,
                'prepaid_amount' => $won($c->prepaid_amount),
                'prepaid_date' => $c->prepaid_at ? $c->prepaid_at->copy()->setTimezone(self::TZ)->format('Y년 n월 j일') : null,
                'receipt_no' => $c->prepaid_receipt_no ?: $c->contract_no . '-R',
            ];
            $caregiverId ??= $c->caregiver_id;
        }
        if ($caregiverId) {
            $v['caregiver_name'] = DB::table('caregivers as cg')->join('users as u', 'u.id', '=', 'cg.user_id')
                ->where('cg.id', $caregiverId)->value('u.name');
        }
        if ($session) {
            $st = Carbon::parse($session->scheduled_start, 'UTC')->setTimezone(self::TZ);
            $as = $session->actual_start ? Carbon::parse($session->actual_start, 'UTC')->setTimezone(self::TZ)->format('H:i') : '?';
            $ae = $session->actual_end ? Carbon::parse($session->actual_end, 'UTC')->setTimezone(self::TZ)->format('H:i') : '진행 중';
            $v['session_date'] = $st->format('Y년 n월 j일');
            $v['session_time'] = "{$as} ~ {$ae}";
            $idx = $c ? array_search($st->toDateString(), $this->contracts->serviceDates($c), true) : false;
            $v['session_seq'] = $idx === false ? '?' : $idx + 1;
        }

        return $v;
    }

    /* ───────────── 발행 ───────────── */

    /** 살아 있는(취소 아님) 같은 서류 */
    private function live(string $type, ?int $contractId, ?int $caregiverId, ?int $sessionId): ?MnhDocument
    {
        // 근로·프리랜서 계약서는 갱신할 수 있어 서명 대기 건만 중복으로 본다
        $q = MnhDocument::where('doc_type', $type)->whereIn('status', $type === 'employment_contract' ? ['issued'] : ['issued', 'signed']);
        $sessionId ? $q->where('care_session_id', $sessionId)
            : ($contractId ? $q->where('contract_id', $contractId) : $q->where('caregiver_id', $caregiverId)->whereNull('contract_id'));

        return $q->orderByDesc('id')->first();
    }

    /**
     * 서류 발행 — 같은 서류가 살아 있으면 그대로 돌려준다(중복 발행 방지).
     * $formData 는 발행자가 채우는 칸(admin 칸: 초기상담·근로계약).
     */
    public function issue(string $type, ?MnhContract $c, ?int $caregiverId = null, ?object $session = null, array $formData = [], ?int $actor = null): MnhDocument
    {
        $cfg = self::type($type);
        if ($existing = $this->live($type, $c?->id, $caregiverId, $session?->id)) {
            return $existing;
        }
        $tpl = $this->template($type);
        $signerUserId = $cfg['signer'] === 'caregiver'
            ? DB::table('caregivers')->where('id', $caregiverId)->value('user_id')
            : $c?->user_id;
        $signerName = $cfg['signer'] === 'caregiver'
            ? DB::table('users')->where('id', $signerUserId)->value('name')
            : DB::table('postpartum_clients')->where('id', $c?->postpartum_client_id)->value('name');

        $doc = MnhDocument::create([
            'doc_type' => $type, 'contract_id' => $c?->id, 'caregiver_id' => $caregiverId ?? $c?->caregiver_id,
            'care_session_id' => $session?->id, 'template_id' => $tpl->id, 'template_version' => $tpl->version,
            'title' => $tpl->title, 'content_html' => self::render($tpl->body, $this->vars($c, $caregiverId, $session)),
            'form_data' => $this->cleanForm($type, $formData, ['admin']) ?: null,
            'status' => 'issued', 'signer_role' => $cfg['signer'], 'signer_user_id' => $signerUserId,
            'signer_name' => $signerName, 'issued_by' => $actor,
        ]);
        if ($c && $type !== 'provision_record') {
            $this->contracts->log($c, 'doc_issued', null, ['doc_id' => $doc->id, 'doc_type' => $type], $actor);
        }
        if ($signerUserId && !in_array($type, ['provision_record'], true)) {
            $this->notifier->notifySafely((int) $signerUserId, NotificationService::TYPE_MNH_DOC_SIGN, [
                'doc_id' => $doc->id, 'contract_id' => $c?->id, 'label' => $doc->title, 'for_caregiver' => $cfg['signer'] === 'caregiver',
            ]);
        }

        return $doc;
    }

    /** 계약 단계별 자동 발행 — trigger: assigned|prepaid|completed */
    public function autoIssue(MnhContract $c, string $trigger, ?int $actor = null): void
    {
        foreach (self::types() as $type => $cfg) {
            if (($cfg['auto'] ?? null) === $trigger) {
                try {
                    $this->issue($type, $c, null, null, [], $actor);
                } catch (\Throwable $e) {
                    Log::warning('바우처 서류 자동 발행 실패', ['contract' => $c->id, 'type' => $type, 'error' => $e->getMessage()]);
                }
            }
        }
    }

    /** 다시 발행 — 계약 조건이 바뀌었을 때. 기존 건은 취소(void)로 남는다. */
    public function reissue(MnhDocument $doc, string $reason, ?int $actor): MnhDocument
    {
        if ($doc->status === 'void') {
            throw new MnhContractException('VOID', '이미 취소된 서류예요.');
        }
        $doc->update(['status' => 'void', 'void_reason' => $reason]);
        $c = $doc->contract_id ? MnhContract::find($doc->contract_id) : null;
        $session = $doc->care_session_id ? DB::table('care_sessions')->where('id', $doc->care_session_id)->first() : null;

        return $this->issue($doc->doc_type, $c, $doc->caregiver_id, $session, $this->cleanForm($doc->doc_type, $doc->form_data ?? [], ['admin']), $actor);
    }

    /** 칸 정리 — 정의된 칸·허용된 작성자만, 문자열 길이 제한 */
    public function cleanForm(string $type, array $data, array $by): array
    {
        $out = [];
        foreach (self::type($type)['fields'] ?? [] as $f) {
            if (!in_array($f['filled_by'], $by, true) || !array_key_exists($f['key'], $data)) {
                continue;
            }
            $v = $data[$f['key']];
            if ($f['type'] === 'checks') {
                $v = array_values(array_intersect((array) $v, $f['options']));
            } elseif ($f['type'] === 'select') {
                $v = array_key_exists((string) $v, $f['options']) ? (string) $v : null;
            } else {
                $v = $v === null ? null : mb_substr(trim((string) $v), 0, $f['type'] === 'textarea' ? 2000 : 100);
            }
            if ($v !== null && $v !== '' && $v !== []) {
                $out[$f['key']] = $v;
            }
        }

        return $out;
    }

    /* ───────────── 서명 ───────────── */

    /**
     * 서명 — $formData 는 서명자(또는 제공기록지면 관리사)가 채운 칸. $signature 는 data:image/png;base64,...
     */
    public function sign(MnhDocument $doc, string $signature, array $formData, ?string $signerName, string $ip, ?string $ua, int $capturedBy, array $filledBy): MnhDocument
    {
        if ($doc->status !== 'issued') {
            throw new MnhContractException('NOT_SIGNABLE', $doc->status === 'signed' ? '이미 서명한 서류예요.' : '취소된 서류예요.');
        }
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $signature, $m)) {
            throw new MnhContractException('BAD_SIGNATURE', '서명을 다시 그려 주세요.');
        }
        $png = base64_decode($m[1], true);
        if ($png === false || strlen($png) < 200 || strlen($png) > 300_000 || !str_starts_with($png, "\x89PNG")) {
            throw new MnhContractException('BAD_SIGNATURE', '서명을 다시 그려 주세요.');
        }
        $form = array_merge($doc->form_data ?? [], $this->cleanForm($doc->doc_type, $formData, $filledBy));
        foreach (self::type($doc->doc_type)['fields'] ?? [] as $f) {
            $required = $f['type'] === 'select' || ($f['filled_by'] === 'admin' && $f['type'] !== 'textarea');
            if ($required && empty($form[$f['key']])) {
                throw new MnhContractException('FIELD_REQUIRED', "「{$f['label']}」을(를) 채워 주세요.");
            }
        }
        if ($doc->doc_type === 'provision_record' && empty(array_filter(array_intersect_key($form, array_flip(['mother_care', 'newborn_care', 'household', 'note']))))) {
            throw new MnhContractException('FIELD_REQUIRED', '제공한 서비스를 하나 이상 표시해 주세요.');
        }

        $path = "mnh-docs/{$doc->id}/signature-" . Str::uuid() . '.enc';
        Storage::disk('local')->put($path, MedicalCrypto::encrypt(base64_encode($png)));
        $now = now();
        $name = $signerName ? mb_substr(trim($signerName), 0, 50) : $doc->signer_name;
        $hash = hash('sha256', implode("\n", [$doc->id, $doc->content_html, json_encode($form, JSON_UNESCAPED_UNICODE),
            hash('sha256', $png), $name, $now->toIso8601String()]));
        $doc->update([
            'form_data' => $form ?: null, 'status' => 'signed', 'signer_name' => $name, 'signature_path' => $path,
            'signed_at' => $now, 'signed_ip' => $ip, 'signed_ua' => $ua ? mb_substr($ua, 0, 255) : null,
            'captured_by' => $capturedBy, 'content_hash' => $hash,
        ]);
        if ($doc->contract_id && $doc->doc_type !== 'provision_record') {
            $this->contracts->log(MnhContract::find($doc->contract_id), 'doc_signed', null, ['doc_id' => $doc->id, 'doc_type' => $doc->doc_type], $capturedBy);
        }
        GenerateMnhDocumentPdfJob::dispatch($doc->id);

        return $doc;
    }

    public static function readFile(?string $path): ?string
    {
        if (!$path || !Storage::disk('local')->exists($path)) {
            return null;
        }
        $raw = base64_decode((string) MedicalCrypto::decrypt(Storage::disk('local')->get($path)), true);

        return $raw === false ? null : $raw;
    }

    /** 서명 위변조 확인 — 저장된 해시와 다시 계산한 값 비교 */
    public function verify(MnhDocument $doc): ?bool
    {
        if ($doc->status !== 'signed') {
            return null;
        }
        $png = self::readFile($doc->signature_path);
        if ($png === null) {
            return false;
        }

        return hash_equals((string) $doc->content_hash, hash('sha256', implode("\n", [$doc->id, $doc->content_html,
            json_encode($doc->form_data ?? [], JSON_UNESCAPED_UNICODE), hash('sha256', $png), $doc->signer_name,
            $doc->signed_at->toIso8601String()])));
    }

    /* ───────────── 표시·PDF ───────────── */

    /** 입력 칸 표 HTML */
    public static function fieldsHtml(MnhDocument $doc): string
    {
        $fields = self::type($doc->doc_type)['fields'] ?? [];
        if (!$fields) {
            return '';
        }
        $rows = '';
        foreach ($fields as $f) {
            $v = $doc->form_data[$f['key']] ?? null;
            $text = match (true) {
                $v === null || $v === [] => '-',
                $f['type'] === 'checks' => implode(', ', (array) $v),
                $f['type'] === 'select' => $f['options'][$v] ?? $v,
                default => (string) $v,
            };
            $rows .= '<tr><th>' . e($f['label']) . '</th><td>' . nl2br(e($text)) . '</td></tr>';
        }

        return '<table class="fields">' . $rows . '</table>';
    }

    /** API 응답 모양 */
    public function present(MnhDocument $doc, bool $admin = false): array
    {
        $cfg = self::type($doc->doc_type);

        return [
            'id' => $doc->id, 'doc_type' => $doc->doc_type, 'label' => $cfg['label'], 'title' => $doc->title,
            'status' => $doc->status, 'signer_role' => $doc->signer_role, 'signer_name' => $doc->signer_name,
            'before_start' => (bool) ($cfg['before_start'] ?? false),
            'contract_id' => $doc->contract_id, 'care_session_id' => $doc->care_session_id, 'caregiver_id' => $doc->caregiver_id,
            'template_version' => $doc->template_version,
            'content_html' => $doc->content_html,
            'fields' => array_map(fn ($f) => $f + ['options' => $f['options'] ?? null], $cfg['fields'] ?? []),
            'form_data' => $doc->form_data ?? (object) [],
            'fields_html' => self::fieldsHtml($doc),
            'signed_at' => Kst::iso($doc->signed_at),
            'pdf_ready' => (bool) $doc->pdf_path,
            'issued_at' => Kst::iso($doc->created_at),
            // 제공기록지는 방문일(한국 날짜)로 보여 준다 — 발행일과 다를 수 있다
            'session_date' => $doc->care_session_id ? Carbon::parse(DB::table('care_sessions')->where('id', $doc->care_session_id)->value('scheduled_start'), 'UTC')
                ->setTimezone(self::TZ)->toDateString() : null,
            'void_reason' => $doc->void_reason,
        ] + ($admin ? ['signed_ip' => $doc->signed_ip, 'content_hash' => $doc->content_hash, 'captured_by' => $doc->captured_by] : []);
    }

    /** PDF 원본 HTML(서명 이미지는 data URI, 글꼴은 시스템 Noto CJK) */
    public function pdfHtml(MnhDocument $doc): string
    {
        $p = config('mnh_docs.provider');
        $sig = self::readFile($doc->signature_path);
        $sigImg = $sig ? '<img src="data:image/png;base64,' . base64_encode($sig) . '" alt="서명">' : '';
        $signedAt = $doc->signed_at ? $doc->signed_at->copy()->setTimezone(self::TZ)->format('Y-m-d H:i:s') . ' (KST)' : '-';
        $no = sprintf('MNHD-%06d', $doc->id);
        $who = $doc->signer_role === 'caregiver' ? '건강관리사' : '이용자(산모)';

        return '<!doctype html><html lang="ko"><head><meta charset="utf-8"><title>' . e($doc->title) . '</title><style>
body{font-family:"Noto Sans CJK KR","Noto Sans KR",sans-serif;color:#1c1b19;font-size:11pt;line-height:1.65}
h1{font-size:18pt;margin:0 0 4px;text-align:center;letter-spacing:.02em}
.meta{text-align:center;color:#666;font-size:9pt;margin-bottom:18px}
h3{font-size:11.5pt;margin:16px 0 4px;border-bottom:1px solid #ddd;padding-bottom:2px}
p{margin:4px 0} ul{margin:4px 0;padding-left:18px}
table.fields{width:100%;border-collapse:collapse;margin:14px 0}
table.fields th,table.fields td{border:1px solid #bbb;padding:6px 8px;vertical-align:top;font-size:10pt;text-align:left}
table.fields th{width:28%;background:#f4f2ee;font-weight:600}
.sign{margin-top:26px;display:flex;justify-content:flex-end;gap:16px;align-items:center}
.sign .box{border:1px solid #bbb;width:220px;height:90px;display:flex;align-items:center;justify-content:center}
.sign img{max-width:210px;max-height:84px}
.audit{margin-top:22px;border-top:1px solid #ddd;padding-top:8px;font-size:8pt;color:#666;word-break:break-all}
</style></head><body>
<h1>' . e($doc->title) . '</h1>
<div class="meta">문서번호 ' . e($no) . ' · 서식 v' . (int) $doc->template_version . ' · 제공기관 ' . e($p['name']) . '</div>
' . $doc->content_html . self::fieldsHtml($doc) . '
<div class="sign"><div>' . e($who) . ' <b>' . e((string) $doc->signer_name) . '</b> (서명)</div><div class="box">' . $sigImg . '</div></div>
<div class="audit">전자서명 일시 ' . e($signedAt) . ' · 접속 IP ' . e((string) $doc->signed_ip) . '<br>무결성 해시(SHA-256) ' . e((string) $doc->content_hash) . '</div>
</body></html>';
    }

    /** PDF 생성(큐) — 근로계약서는 돌봄전문가 서류함(employment_contract)에도 넣는다 */
    public function generatePdf(MnhDocument $doc): void
    {
        if ($doc->status !== 'signed') {
            return;
        }
        $dir = storage_path('app/tmp-mnh');
        @mkdir($dir, 0700, true);
        $in = "{$dir}/doc-{$doc->id}-" . Str::random(8) . '.html';
        $out = substr($in, 0, -5) . '.pdf';
        file_put_contents($in, $this->pdfHtml($doc));
        try {
            $cmd = sprintf('node %s %s %s 2>&1', escapeshellarg(base_path('tools/pdf/html2pdf.js')), escapeshellarg($in), escapeshellarg($out));
            exec($cmd, $output, $code);
            if ($code !== 0 || !is_file($out)) {
                throw new \RuntimeException('PDF 생성 실패: ' . implode(' ', $output));
            }
            $pdf = file_get_contents($out);
            $path = "mnh-docs/{$doc->id}/document-" . Str::uuid() . '.enc';
            Storage::disk('local')->put($path, MedicalCrypto::encrypt(base64_encode($pdf)));
            $doc->update(['pdf_path' => $path, 'pdf_generated_at' => now()]);

            if ($doc->doc_type === 'employment_contract' && $doc->caregiver_id) {
                $this->fileToCaregiverDocs($doc, $pdf);
            }
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /** 플랫폼에서 서명한 근로·프리랜서 계약서 → 돌봄전문가 서류 「employment_contract」 확인 완료로 */
    private function fileToCaregiverDocs(MnhDocument $doc, string $pdf): void
    {
        $path = "caregiver-docs/{$doc->caregiver_id}/" . Str::uuid() . '.enc';
        Storage::disk('local')->put($path, MedicalCrypto::encrypt(base64_encode($pdf)));
        DB::transaction(function () use ($doc, $pdf, $path) {
            DB::table('caregiver_documents')->where('caregiver_id', $doc->caregiver_id)->where('doc_type', 'employment_contract')
                ->whereIn('status', ['submitted', 'verified', 'rejected'])->update(['status' => 'replaced', 'updated_at' => now()]);
            DB::table('caregiver_documents')->insert([
                'caregiver_id' => $doc->caregiver_id, 'doc_type' => 'employment_contract', 'file_path' => $path,
                'original_name' => sprintf('전자서명_계약서_MNHD-%06d.pdf', $doc->id), 'mime' => 'application/pdf',
                'size_bytes' => strlen($pdf), 'sha256' => hash('sha256', $pdf), 'status' => 'verified',
                'issued_at' => $doc->signed_at->copy()->setTimezone(self::TZ)->toDateString(),
                'reviewed_by' => $doc->issued_by, 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    /* ───────────── 현황 ───────────── */

    /** 계약의 서류 현황 — 개시 전 서명 대상 중 미서명 목록 포함 */
    public function contractStatus(MnhContract $c): array
    {
        $docs = MnhDocument::where('contract_id', $c->id)->where('status', '!=', 'void')->orderBy('id')->get();
        $missing = [];
        foreach (self::types() as $type => $cfg) {
            if (($cfg['before_start'] ?? false) && $cfg['signer'] === 'client'
                && !$docs->contains(fn ($d) => $d->doc_type === $type && $d->status === 'signed')) {
                $missing[] = $cfg['label'];
            }
        }

        return ['docs' => $docs, 'missing_before_start' => $missing];
    }
}
