<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 영역별 안내 콘텐츠·FAQ·지역 공지(CAREN-REF-01 3단계) — 형식은 마이그레이션 2026_10_10_000004 주석 */
class DomainContent extends Model
{
    protected $fillable = [
        'kind', 'placement', 'domain', 'audience', 'platform', 'regions', 'title', 'blocks', 'tone', 'sort', 'status',
        'starts_on', 'ends_on', 'source_url', 'source_note', 'reviewed_by', 'reviewed_at', 'updated_by',
    ];

    protected $casts = [
        'regions' => 'array', 'blocks' => 'array', 'sort' => 'integer',
        'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'reviewed_at' => 'datetime',
    ];
}
