<?php

namespace App\Services;

use App\Support\MedicalCrypto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 돌봄전문가 서류 저장·열람·체크리스트 (기능 9·20, 2026-09-28 S5)
 * - 파일은 base64 → MedicalCrypto(AES-256-GCM) 암호화 → local 디스크(storage/app, 웹 비공개)
 * - 같은 종류를 다시 올리면 이전 건은 replaced(검토 이력은 남김). 확인 완료 건도 새로 올리면 다시 검토 대상
 */
class CaregiverDocumentService
{
    public static function types(): array
    {
        return config('caregiver_docs.types', []);
    }

    /**
     * 이 돌봄전문가에게 해당하는 서류 종류 — domains 가 있으면 직군(service_domains)과 겹칠 때만.
     * 각 항목에 required(직군 반영)를 다시 계산해 둔다.
     */
    public static function typesFor(int $caregiverId): array
    {
        $domains = array_filter(array_map('trim', explode(',', (string) DB::table('caregivers')->where('id', $caregiverId)->value('service_domains'))));
        $out = [];
        foreach (self::types() as $type => $meta) {
            if (!empty($meta['domains']) && !array_intersect($meta['domains'], $domains)) {
                continue;
            }
            $meta['required'] = (bool) ($meta['required'] ?? false)
                || (!empty($meta['required_domains']) && array_intersect($meta['required_domains'], $domains));
            $out[$type] = $meta;
        }
        return $out;
    }

    /**
     * 이용자에게 공개하는 서류 — 확인 완료·유효기간 안의 public 서류만 [{type,label,issued_at,expires_at}] (파일·반려사유 없음)
     */
    public function publicSummary(int $caregiverId): array
    {
        return collect($this->checklist($caregiverId))
            ->filter(fn ($c) => $c['public'] && $c['status'] === 'verified')
            ->map(fn ($c) => ['type' => $c['type'], 'label' => $c['label'], 'issued_at' => $c['document']['issued_at'], 'expires_at' => $c['document']['expires_at']])
            ->values()->all();
    }

    public function store(int $caregiverId, string $type, UploadedFile $file, ?string $issuedAt): int
    {
        $raw = file_get_contents($file->getRealPath());
        $path = "caregiver-docs/{$caregiverId}/" . Str::uuid() . '.enc';
        Storage::disk('local')->put($path, MedicalCrypto::encrypt(base64_encode($raw)));

        $validDays = self::types()[$type]['valid_days'] ?? null;
        $issued = $issuedAt ? Carbon::parse($issuedAt) : null;

        return DB::transaction(function () use ($caregiverId, $type, $file, $raw, $path, $issued, $validDays) {
            DB::table('caregiver_documents')->where('caregiver_id', $caregiverId)->where('doc_type', $type)
                ->whereIn('status', ['submitted', 'verified', 'rejected'])->update(['status' => 'replaced', 'updated_at' => now()]);
            return DB::table('caregiver_documents')->insertGetId([
                'caregiver_id' => $caregiverId,
                'doc_type' => $type,
                'file_path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 190),
                'mime' => $file->getMimeType(),
                'size_bytes' => strlen($raw),
                'sha256' => hash('sha256', $raw),
                'status' => 'submitted',
                'issued_at' => $issued?->toDateString(),
                'expires_at' => ($issued && $validDays) ? $issued->copy()->addDays($validDays)->toDateString() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /** 복호화된 원본 바이트 — 해시가 다르면 null(파일 손상·위변조) */
    public function read(object $doc): ?string
    {
        if (!Storage::disk('local')->exists($doc->file_path)) {
            return null;
        }
        $raw = base64_decode((string) MedicalCrypto::decrypt(Storage::disk('local')->get($doc->file_path)), true);
        return ($raw !== false && hash('sha256', $raw) === $doc->sha256) ? $raw : null;
    }

    /**
     * 종류별 현재 상태 — [{type,label,required,hint,public,needs_issued_at,status(missing|submitted|verified|rejected|expired),document}]
     */
    public function checklist(int $caregiverId): array
    {
        $current = DB::table('caregiver_documents')->where('caregiver_id', $caregiverId)
            ->where('status', '!=', 'replaced')->orderByDesc('id')->get()->keyBy('doc_type');
        $out = [];
        foreach (self::typesFor($caregiverId) as $type => $meta) {
            $d = $current[$type] ?? null;
            $status = $d ? $d->status : 'missing';
            if ($d && $d->expires_at && $d->expires_at < now()->toDateString()) {
                $status = 'expired';
            }
            $out[] = [
                'type' => $type,
                'label' => $meta['label'],
                'required' => (bool) $meta['required'],
                'hint' => $meta['hint'] ?? null,
                'public' => (bool) ($meta['public'] ?? false),
                'needs_issued_at' => !empty($meta['valid_days']),
                'status' => $status,
                'document' => $d ? [
                    'id' => $d->id, 'original_name' => $d->original_name, 'mime' => $d->mime, 'size_bytes' => (int) $d->size_bytes,
                    'issued_at' => $d->issued_at, 'expires_at' => $d->expires_at, 'reject_reason' => $d->reject_reason,
                    'reviewed_at' => $d->reviewed_at, 'created_at' => $d->created_at,
                ] : null,
            ];
        }
        return $out;
    }

    /** 필수 서류 중 「확인」이 아닌 것의 라벨 목록 */
    public function missingRequired(int $caregiverId): array
    {
        return collect($this->checklist($caregiverId))
            ->filter(fn ($c) => $c['required'] && $c['status'] !== 'verified')
            ->pluck('label')->values()->all();
    }

    /** 탈퇴 파기 — 파일과 행 모두 삭제 */
    public function purge(int $caregiverId): int
    {
        Storage::disk('local')->deleteDirectory("caregiver-docs/{$caregiverId}");
        return DB::table('caregiver_documents')->where('caregiver_id', $caregiverId)->delete();
    }
}
