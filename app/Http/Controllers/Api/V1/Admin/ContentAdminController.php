<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Controller;
use App\Models\DomainContent;
use App\Support\Kst;
use App\Support\SidoName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * 관리자 — 영역별 안내 콘텐츠·FAQ·지역 공지(CAREN-REF-01 3단계, 2026-10-10). 권한 영역 'contents'.
 * 게시본을 고치면 바로 웹·앱에 반영된다. 검수(review)는 「법무·운영 확인 완료」 표시 — 고치면 검수 표시가 풀린다.
 */
class ContentAdminController extends Controller
{
    public const KINDS = ['guide' => '안내', 'faq' => 'FAQ', 'notice' => '공지'];

    public const PLACEMENTS = [
        'home' => '홈 공지', 'service_scope' => '신청 화면 · 서비스 범위', 'support' => '고객센터 FAQ',
        'guide' => '이용 가이드 FAQ', 'voucher_guide' => '산모신생아 바우처 안내',
    ];

    /** GET /v1/admin/contents?kind=&placement=&domain=&status= */
    public function index(Request $request): JsonResponse
    {
        $q = DomainContent::query()->orderBy('placement')->orderBy('domain')->orderBy('sort')->orderBy('id');
        foreach (['kind', 'placement', 'status', 'audience', 'platform'] as $k) {
            if ($v = $request->query($k)) {
                $q->where($k, $v);
            }
        }
        if ($request->filled('domain')) {
            $request->query('domain') === 'common' ? $q->whereNull('domain') : $q->where('domain', $request->query('domain'));
        }
        $names = DB::table('users')->whereIn('id', DomainContent::pluck('updated_by')->merge(DomainContent::pluck('reviewed_by'))->filter()->unique())->pluck('name', 'id');

        return response()->json(['success' => true, 'data' => [
            'rows' => $q->get()->map(fn ($c) => ContentController::present($c) + [
                'status' => $c->status, 'source_url' => $c->source_url, 'source_note' => $c->source_note,
                'reviewed_at' => Kst::iso($c->reviewed_at), 'reviewed_by_name' => $names[$c->reviewed_by] ?? null,
                'updated_by_name' => $names[$c->updated_by] ?? null,
            ])->values(),
            'labels' => [
                'kinds' => self::KINDS, 'placements' => self::PLACEMENTS,
                'domains' => collect(config('service_domains'))->map(fn ($d) => $d['label'] ?? '')->all(),
                'audiences' => ['all' => '모두', 'guardian' => '보호자·이용자', 'caregiver' => '돌봄전문가'],
                'platforms' => ['all' => '웹·앱', 'web' => '웹만', 'app' => '앱만'],
                'regions' => SidoName::ALL,
                'block_types' => ['p' => '문단', 'list' => '목록', 'note' => '강조 안내', 'table' => '표', 'link' => '링크'],
            ],
        ]]);
    }

    /** POST /v1/admin/contents */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $row = DomainContent::create($data + ['updated_by' => $request->user()->id]);

        return response()->json(['success' => true, 'message' => '저장했어요.', 'data' => ContentController::present($row)], 201);
    }

    /** PATCH /v1/admin/contents/{id} — 내용을 고치면 검수 표시를 푼다 */
    public function update(Request $request, int $id): JsonResponse
    {
        $row = DomainContent::findOrFail($id);
        $data = $this->validated($request, false);
        $contentChanged = array_intersect_key($data, array_flip(['title', 'blocks', 'regions', 'starts_on', 'ends_on'])) !== [];
        $row->update($data + ['updated_by' => $request->user()->id] + ($contentChanged ? ['reviewed_by' => null, 'reviewed_at' => null] : []));

        return response()->json(['success' => true, 'message' => '저장했어요.', 'data' => ContentController::present($row->fresh())]);
    }

    /** POST /v1/admin/contents/{id}/review — 검수 완료 표시(취소는 {undo:true}) */
    public function review(Request $request, int $id): JsonResponse
    {
        $row = DomainContent::findOrFail($id);
        $undo = (bool) $request->input('undo');
        $row->update($undo ? ['reviewed_by' => null, 'reviewed_at' => null] : ['reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

        return response()->json(['success' => true, 'message' => $undo ? '검수 표시를 풀었어요.' : '검수 완료로 표시했어요.']);
    }

    /** DELETE /v1/admin/contents/{id} */
    public function destroy(int $id): JsonResponse
    {
        DomainContent::whereKey($id)->delete();

        return response()->json(['success' => true, 'message' => '지웠어요.']);
    }

    private function validated(Request $request, bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        $data = $request->validate([
            'kind' => [$req, Rule::in(array_keys(self::KINDS))],
            'placement' => [$req, 'string', 'max:40', 'regex:/^[a-z_]+$/'],
            'domain' => ['nullable', Rule::in(array_keys(config('service_domains')))],
            'audience' => ['sometimes', Rule::in(['all', 'guardian', 'caregiver'])],
            'platform' => ['sometimes', Rule::in(['all', 'web', 'app'])],
            'regions' => ['nullable', 'array'],
            'regions.*' => [Rule::in(SidoName::ALL)],
            'title' => [$req, 'string', 'max:200'],
            'blocks' => [$req, 'array', 'min:1', 'max:40'],
            'blocks.*.type' => ['required', Rule::in(['p', 'list', 'note', 'table', 'link'])],
            'blocks.*.text' => ['nullable', 'string', 'max:3000'],
            'blocks.*.items' => ['nullable', 'array', 'max:50'],
            'blocks.*.items.*' => ['string', 'max:500'],
            'blocks.*.role' => ['nullable', 'string', 'max:30'],
            'blocks.*.tone' => ['nullable', Rule::in(['info', 'warn'])],
            'blocks.*.head' => ['nullable', 'array', 'max:10'],
            'blocks.*.rows' => ['nullable', 'array', 'max:60'],
            'blocks.*.label' => ['nullable', 'string', 'max:100'],
            'blocks.*.href' => ['nullable', 'string', 'max:500', 'regex:#^(/|https://)#'],
            'tone' => ['sometimes', Rule::in(['info', 'warn'])],
            'sort' => ['sometimes', 'integer', 'between:0,9999'],
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'source_url' => ['nullable', 'string', 'max:500'],
            'source_note' => ['nullable', 'string', 'max:500'],
        ]);
        if (array_key_exists('regions', $data) && !$data['regions']) {
            $data['regions'] = null;
        }

        return $data;
    }
}
