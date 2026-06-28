<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 비로그인 공개 웹(careand.aiclaude.kr/www)용 읽기 전용 엔드포인트.
 * 개인정보(실명/자격번호/정확주소/연락처/좌표)는 노출하지 않는다.
 */
class PublicController extends Controller
{
    /** 도메인 한글 라벨 */
    private const DOMAIN_LABEL = [
        'senior' => '시니어돌봄',
        'nursing' => '병원간병',
        'housekeeping' => '가사서비스',
    ];

    /** 공개 인력(돌봄전문가) 목록 — 마스킹 카드용 */
    public function caregivers(Request $request): JsonResponse
    {
        $domain = $request->query('domain'); // senior|nursing|housekeeping
        $region = $request->query('region'); // "경기" 등 시도 접두
        $sort = $request->query('sort', 'rating'); // rating|sessions

        $q = DB::table('caregivers as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.status', 'active')
            ->whereNull('c.deleted_at');

        if ($domain) {
            $q->whereRaw('FIND_IN_SET(?, c.service_domains)', [$domain]);
        }
        if ($region) {
            $q->where('c.base_address', 'like', $region . '%');
        }

        $q->orderByDesc($sort === 'sessions' ? 'c.completed_sessions' : 'c.rating_avg')
            ->orderByDesc('c.completed_sessions');

        $rows = $q->limit(60)->get([
            'c.id', 'u.name', 'c.gender', 'c.birth_date', 'c.specialties',
            'c.service_domains', 'c.base_address', 'c.rating_avg', 'c.rating_count',
            'c.completed_sessions', 'c.grade_level', 'c.license_verified_at', 'c.career_track',
        ]);

        return response()->json([
            'success' => true,
            'data' => $rows->map(fn ($c) => $this->card($c))->values(),
        ]);
    }

    /** 공개 인력 상세 */
    public function caregiver(int $id): JsonResponse
    {
        $c = DB::table('caregivers as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.id', $id)
            ->where('c.status', 'active')
            ->whereNull('c.deleted_at')
            ->first([
                'c.id', 'u.name', 'c.gender', 'c.birth_date', 'c.specialties',
                'c.service_domains', 'c.base_address', 'c.rating_avg', 'c.rating_count',
                'c.completed_sessions', 'c.grade_level', 'c.license_verified_at', 'c.career_track',
            ]);

        if (!$c) {
            return response()->json(['success' => false, 'message' => 'not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->card($c)]);
    }

    /** 홈 신뢰지표(집계) */
    public function stats(): JsonResponse
    {
        $agg = DB::table('caregivers')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as caregivers, COALESCE(SUM(completed_sessions),0) as sessions, COALESCE(AVG(NULLIF(rating_avg,0)),0) as rating')
            ->first();

        $regions = DB::table('caregivers')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->selectRaw("SUBSTRING_INDEX(base_address,' ',2) as region, COUNT(*) as cnt")
            ->groupBy('region')
            ->orderByDesc('cnt')
            ->limit(8)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'caregivers' => (int) $agg->caregivers,
                'completed_sessions' => (int) $agg->sessions,
                'rating_avg' => round((float) $agg->rating, 1),
                'regions' => $regions,
            ],
        ]);
    }

    /** 행 → 마스킹된 공개 카드 */
    private function card(object $c): array
    {
        $domains = $c->service_domains ? explode(',', $c->service_domains) : [];
        $age = $c->birth_date ? (int) floor((strtotime('now') - strtotime($c->birth_date)) / 31557600) : null;

        // 연령대(10년 단위)로만 노출
        $ageBand = $age !== null ? (intdiv($age, 10) * 10) . '대' : null;

        $tag = null;
        if (in_array($c->career_track, ['premium', 'instructor'], true)) {
            $tag = 'BEST';
        } elseif ($c->career_track === 'excellent') {
            $tag = '우수';
        } elseif ($c->license_verified_at) {
            $tag = '인증';
        }

        return [
            'id' => (int) $c->id,
            'name' => $this->maskName($c->name),
            'gender' => $c->gender,
            'age_band' => $ageBand,
            'region' => $c->base_address ? implode(' ', array_slice(explode(' ', $c->base_address), 0, 2)) : null,
            'specialties' => $this->castArray($c->specialties),
            'domains' => array_values(array_filter(array_map(
                fn ($d) => self::DOMAIN_LABEL[$d] ?? null,
                $domains
            ))),
            'rating' => number_format((float) $c->rating_avg, 1),
            'rating_count' => (int) $c->rating_count,
            'completed_sessions' => (int) $c->completed_sessions,
            'grade_level' => (int) $c->grade_level,
            'license_verified' => $c->license_verified_at !== null,
            'tag' => $tag,
        ];
    }

    /** 김철수 → 김○○ */
    private function maskName(?string $name): string
    {
        if (!$name) return '비공개';
        $len = mb_strlen($name);
        if ($len <= 1) return $name;
        return mb_substr($name, 0, 1) . str_repeat('○', $len - 1);
    }

    private function castArray($val): array
    {
        if (is_array($val)) return $val;
        if (is_string($val)) {
            $d = json_decode($val, true);
            return is_array($d) ? $d : [];
        }
        return [];
    }
}
