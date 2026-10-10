<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DomainContent;
use App\Support\Kst;
use App\Support\SidoName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 영역별 안내 콘텐츠·FAQ·지역 공지 읽기(CAREN-REF-01 3단계, 2026-10-10). 게시(published)·기간 안의 것만.
 *  GET /v1/public/contents?placement=&domain=&audience=&platform=&region=   — 로그인 없이(공개 문구뿐)
 *  GET /v1/contents/my-notices?platform=                                    — 내 지역(산모 지역·돌봄 주소) 공지
 */
class ContentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = self::live();
        foreach (['placement', 'kind'] as $k) {
            if ($v = $request->query($k)) {
                $q->where($k, $v);
            }
        }
        if ($d = $request->query('domain')) {
            $q->where(fn ($w) => $w->where('domain', $d)->orWhereNull('domain'));
        }
        foreach (['audience', 'platform'] as $k) {
            if ($v = $request->query($k)) {
                $q->whereIn($k, [$v, 'all']);
            }
        }
        if ($r = SidoName::of($request->query('region'))) {
            $q->where(fn ($w) => $w->whereNull('regions')->orWhereJsonContains('regions', $r));
        }

        return response()->json(['success' => true, 'data' => $q->get()->map(fn ($c) => self::present($c))->values()]);
    }

    public function myNotices(Request $request): JsonResponse
    {
        $user = $request->user();
        $regions = DB::table('postpartum_clients')->where('user_id', $user->id)->whereNull('deleted_at')->pluck('region_code')
            ->merge(DB::table('service_addresses as a')->join('guardians as g', 'g.id', '=', 'a.guardian_id')
                ->where('g.user_id', $user->id)->whereNull('a.deleted_at')->pluck('a.address'))
            ->map(fn ($s) => SidoName::of($s))->filter()->unique()->values();
        $audience = $user->role === 'caregiver' ? 'caregiver' : 'guardian';

        $q = self::live()->where('kind', 'notice')->whereIn('audience', [$audience, 'all']);
        if ($p = $request->query('platform')) {
            $q->whereIn('platform', [$p, 'all']);
        }
        $q->where(function ($w) use ($regions) {
            $w->whereNull('regions');
            foreach ($regions as $r) {
                $w->orWhereJsonContains('regions', $r);
            }
        });

        return response()->json(['success' => true, 'data' => $q->get()->map(fn ($c) => self::present($c))->values(), 'regions' => $regions]);
    }

    /** 게시 중 + 오늘(KST)이 기간 안 */
    public static function live(): Builder
    {
        $today = Carbon::now('Asia/Seoul')->toDateString();

        return DomainContent::query()->where('status', 'published')
            ->where(fn ($w) => $w->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
            ->where(fn ($w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->orderBy('sort')->orderBy('id');
    }

    public static function present(DomainContent $c): array
    {
        return [
            'id' => $c->id, 'kind' => $c->kind, 'placement' => $c->placement, 'domain' => $c->domain,
            'audience' => $c->audience, 'platform' => $c->platform, 'regions' => $c->regions,
            'title' => $c->title, 'blocks' => $c->blocks, 'tone' => $c->tone, 'sort' => $c->sort,
            'starts_on' => $c->starts_on?->format('Y-m-d'), 'ends_on' => $c->ends_on?->format('Y-m-d'),
            'reviewed' => $c->reviewed_at !== null, 'updated_at' => Kst::iso($c->updated_at),
        ];
    }
}
