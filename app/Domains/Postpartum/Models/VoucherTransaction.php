<?php

namespace App\Domains\Postpartum\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 정부 바우처 거래 (사회서비스 전자바우처 SBA)
 * 쓰지 않음(2026-10-18): 단말기 결제는 플랫폼에 기록하지 않기로 했다 — SbaService 머리말 참고. 표는 비어 있고 남겨만 둔다.
 */
class VoucherTransaction extends Model
{
    use HasFactory;

    protected $table = 'voucher_transactions';

    protected $fillable = [
        'postpartum_client_id', 'voucher_type', 'transaction_type',
        'amount', 'days', 'care_session_id',
        'transaction_date', 'sba_transaction_id', 'sba_response',
        'status', 'failed_reason',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount'           => 'decimal:2',
        'sba_response'     => 'array',
    ];

    public function postpartumClient(): BelongsTo
    {
        return $this->belongsTo(PostpartumClient::class);
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
