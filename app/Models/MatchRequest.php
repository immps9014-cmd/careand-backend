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
        'nursing_patient_id',
        'service_address_id',
        'postpartum_client_id',
        'childcare_child_id',
        'mental_care_client_id',
        'service_domain',
        'category_id',
        'mode',
        'scheduled_start',
        'duration_min',
        'recurrence_rule',
        'special_request',
        'requirements',
        'price_estimate',
        'budget_hourly',
        'status',
        'matched_at',
    ];

    protected $casts = [
        'scheduled_start' => 'datetime',
        'matched_at' => 'datetime',
        'recurrence_rule' => 'array',
        'requirements' => 'array',
        'price_estimate' => 'array',
        'budget_hourly' => 'decimal:2',
    ];

    public function guardian()
    {
        return $this->belongsTo(Guardian::class);
    }

    public function senior()
    {
        return $this->belongsTo(Senior::class);
    }

    public function nursingPatient()
    {
        return $this->belongsTo(\App\Domains\Nursing\Models\NursingPatient::class, 'nursing_patient_id');
    }

    public function serviceAddress()
    {
        return $this->belongsTo(\App\Domains\Housekeeping\Models\ServiceAddress::class, 'service_address_id');
    }

    public function postpartumClient()
    {
        return $this->belongsTo(\App\Domains\Postpartum\Models\PostpartumClient::class, 'postpartum_client_id');
    }

    public function childcareChild()
    {
        return $this->belongsTo(\App\Models\Child::class, 'childcare_child_id');
    }

    public function mentalCareClient()
    {
        return $this->belongsTo(\App\Models\MentalCareClient::class, 'mental_care_client_id');
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
     * 신규 도메인 추가 시 아래 match 분기만 확장한다.
     * (postpartum은 좌표 컬럼이 없어 features/location의 default 분기가 null 좌표로 안전 처리됨)
     */
    public function recipient()
    {
        return match ($this->service_domain) {
            'nursing' => $this->nursingPatient,
            'living_support' => $this->serviceAddress,
            'postpartum' => $this->postpartumClient,
            'childcare' => $this->childcareChild,
            'mental_care' => $this->mentalCareClient,
            default => $this->senior,
        };
    }

    /**
     * 산모 id → 「아기 2명 · 생후 12일」(가장 어린 아기 기준). 아기 정보가 없으면 키 없음.
     * 이름·체중은 넣지 않는다 — 돌봄전문가 목록엔 돌봄 준비에 필요한 만큼만.
     * birth_datetime 은 출생일 00:00 으로 저장돼 있어 날짜만 잘라 한국 날짜와 비교한다.
     */
    public static function newbornSummaries(\Illuminate\Support\Collection $clientIds): array
    {
        $ids = $clientIds->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $today = now('Asia/Seoul')->startOfDay();

        return \Illuminate\Support\Facades\DB::table('newborns')
            ->whereIn('postpartum_client_id', $ids)
            ->where('is_alive', 1)
            ->get(['postpartum_client_id', 'birth_datetime'])
            ->groupBy('postpartum_client_id')
            ->map(function ($bs) use ($today) {
                $latest = $bs->max(fn ($b) => substr((string) $b->birth_datetime, 0, 10));
                $days = (int) \Carbon\Carbon::parse($latest, 'Asia/Seoul')->diffInDays($today);

                return '아기 ' . $bs->count() . '명 · 생후 ' . max($days, 0) . '일';
            })
            ->all();
    }

    /** requirements.extra_category_ids → 세부 종류 이름들(함께 필요한 돌봄). 없으면 [] */
    public static function extraCategoryNames(mixed $requirements): array
    {
        if (is_string($requirements)) {
            $requirements = json_decode($requirements, true);
        }
        $ids = array_map('intval', (array) data_get($requirements, 'extra_category_ids', []));
        if (!$ids) {
            return [];
        }
        $names = \Illuminate\Support\Facades\DB::table('service_categories')->whereIn('id', $ids)->pluck('name', 'id');

        return array_values(array_filter(array_map(fn ($id) => $names[$id] ?? null, $ids)));
    }

    public function recipientName(): ?string
    {
        // 가사는 대상이 사람이 아니라 주소 — label('우리집' 등)이 표시명
        return $this->service_domain === 'living_support'
            ? $this->recipient()?->label
            : $this->recipient()?->name;
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
            'nursing' => [
                'id' => $recipient->id,
                'care_grade' => null,
                'diseases' => $recipient->diseases ?? [],
                'lat' => $recipient->hospital_lat !== null ? (float) $recipient->hospital_lat : null,
                'lng' => $recipient->hospital_lng !== null ? (float) $recipient->hospital_lng : null,
            ],
            'living_support' => [
                'id' => $recipient->id,
                'care_grade' => null,
                'diseases' => [],
                'lat' => $recipient->lat !== null ? (float) $recipient->lat : null,
                'lng' => $recipient->lng !== null ? (float) $recipient->lng : null,
            ],
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
            'nursing' => $recipient && $recipient->hospital_lat !== null
                ? [(float) $recipient->hospital_lat, (float) $recipient->hospital_lng]
                : null,
            'living_support' => $recipient && $recipient->lat !== null
                ? [(float) $recipient->lat, (float) $recipient->lng]
                : null,
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

    /**
     * 가사 요청의 필수 스킬 태그 — 인력 풀 하드 필터(수리 요청에 청소 인력 차단).
     * 카테고리 코드 ↔ caregivers.specialties 통제어휘 매핑.
     */
    public function requiredSkillTag(): ?string
    {
        if ($this->service_domain !== 'living_support') {
            return null;
        }

        return match ($this->category?->code) {
            'HK_CLEANING' => 'hk_cleaning',
            'HK_REPAIR' => 'hk_repair',
            'HK_ORGANIZING' => 'hk_organizing',
            default => null,
        };
    }

    /**
     * 동성(같은 성별) 돌봄전문가만 배정해야 하는 카테고리인가.
     * 방문목욕(BATH)은 신체 노출을 동반하므로 존엄·안전상 동성 매칭이 하드 조건.
     * (선호 성별 preferred_gender 의 '소프트'와 달리 반대 성별은 후보에서 하드 제외)
     */
    public function requiresSameGender(): bool
    {
        return $this->category?->code === 'BATH';
    }

}