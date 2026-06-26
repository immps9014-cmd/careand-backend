<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 돌봄전문가가 기피(거절)한 보호대상.
 * target_type = service_domain(senior/nursing/housekeeping), target_id = recipient id.
 * 검색 목록(openRequests)·자동매칭(GenerateMatchCandidatesJob) 양쪽서 제외 필터로 사용.
 */
class CaregiverBlock extends Model
{
    protected $fillable = [
        'caregiver_id',
        'target_type',
        'target_id',
        'reason',
    ];

    public function caregiver()
    {
        return $this->belongsTo(Caregiver::class);
    }
}
