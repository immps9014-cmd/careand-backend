<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * 감사로그 — 행마다 직전 행의 해시를 이어 SHA-256 체인을 만든다(2026-09-28, 구현계획 S2).
 * 체인 계산 중 다른 요청이 끼어들지 않게 DB 네임드 락(audit_chain)으로 직렬화한다.
 * 검증: php artisan audit:verify
 */
class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'actor_id',
        'action',
        'entity_type',
        'entity_id',
        'details',
        'ip_address',
        'user_agent',
        'reason',
        'logged_at',
    ];

    protected $casts = [
        'details' => 'array',
        'logged_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (AuditLog $log) {
            DB::select("SELECT GET_LOCK('audit_chain', 5)");
            $log->logged_at = $log->logged_at ?? now();
            $log->prev_hash = DB::table('audit_logs')->orderByDesc('id')->value('hash');
            $log->hash = self::chainHash($log->prev_hash, $log->getAttributes());
        });
        static::created(fn () => DB::select("SELECT RELEASE_LOCK('audit_chain')"));
    }

    /** 체인 해시 — 저장된 원시 값(details 는 JSON 문자열)으로 계산해야 검증 때 같은 값이 나온다. */
    public static function chainHash(?string $prev, array $a): string
    {
        $logged = $a['logged_at'] ?? '';
        if ($logged instanceof \DateTimeInterface) {
            $logged = $logged->format('Y-m-d H:i:s');
        }
        return hash('sha256', implode('|', [
            $prev ?? '',
            $a['actor_id'] ?? '',
            $a['action'] ?? '',
            $a['entity_type'] ?? '',
            $a['entity_id'] ?? '',
            $a['details'] ?? '',
            $a['ip_address'] ?? '',
            $a['reason'] ?? '',
            (string) $logged,
        ]));
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
