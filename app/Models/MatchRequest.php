<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MatchRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'guardian_id',
        'senior_id',
        'category_id',
        'mode',
        'scheduled_start',
        'duration_min',
        'recurrence_rule',
        'special_request',
        'status',
        'matched_at',
    ];

    protected $casts = [
        'scheduled_start' => 'datetime',
        'matched_at' => 'datetime',
        'recurrence_rule' => 'array',
    ];

    public function guardian()
    {
        return $this->belongsTo(Guardian::class);
    }

    public function senior()
    {
        return $this->belongsTo(Senior::class);
    }

    public function category()
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function candidates()
    {
        return $this->hasMany(MatchCandidate::class, 'request_id');
    }

    public function match()
    {
        return $this->hasOne(CareMatch::class, 'request_id');
    }

    /**
     * 서비스 도메인별 대상자(수혜자) 추상화.
     * 신규 도메인(nursing/housekeeping) 추가 시 아래 match 분기만 확장한다.
     */
    public function recipient()
    {
        return match ($this->service_domain) {
            default => $this->senior,
        };
    }

    public function recipientName(): ?string
    {
        return $this->recipient()?->name;
    }

    /**
     * AI 매칭 feature (GenerateMatchCandidatesJob / generateCandidatesSync 공용).
     * 대상자가 없으면 null — 호출부는 반드시 가드할 것.
     */
    public function recipientFeatures(): ?array
    {
        $recipient = $this->recipient();
        if (!$recipient) {
            return null;
        }

        return match ($this->service_domain) {
            default => [
                'id' => $recipient->id,
                'care_grade' => $recipient->care_grade,
                'diseases' => $recipient->diseases ?? [],
                'lat' => $recipient->home_lat !== null ? (float) $recipient->home_lat : null,
                'lng' => $recipient->home_lng !== null ? (float) $recipient->home_lng : null,
            ],
        };
    }

    /**
     * 체크인 GPS 기준 좌표 [lat, lng]. 좌표 미등록 시 null.
     */
    public function recipientLocation(): ?array
    {
        $recipient = $this->recipient();

        return match ($this->service_domain) {
            default => $recipient && $recipient->home_lat !== null
                ? [(float) $recipient->home_lat, (float) $recipient->home_lng]
                : null,
        };
    }

    /**
     * 체크인 허용 반경(m) — 병원(간병)은 부지가 넓어 도메인별로 다르다.
     */
    public function checkinRadiusMeters(): int
    {
        return match ($this->service_domain) {
            'nursing' => 500,
            default => 200,
        };
    }

}