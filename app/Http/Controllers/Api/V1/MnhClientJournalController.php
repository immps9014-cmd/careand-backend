<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Kst;
use App\Support\MedicalCrypto;
use App\Support\MnhClientJournal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 산모 이용일지(2026-10-05) — 본인 소유 산모(postpartum_clients.user_id)만. 규칙·알림은 App\Support\MnhClientJournal.
 * 기관이 확인한 기록은 지우지 못한다(확인 이력 보존).
 */
class MnhClientJournalController extends Controller
{
    /** GET /v1/matching/postpartum-clients/{id}/journal?days=7 */
    public function index(Request $request, int $id): JsonResponse
    {
        if (!$this->owns($request, $id)) {
            return $this->notFound();
        }
        $days = min(max((int) $request->integer('days', 7), 1), 60);
        $since = Carbon::now(Kst::TZ)->startOfDay()->subDays($days - 1)->utc();
        $babies = $this->babies($id);
        $rows = DB::table('mnh_client_journals')->where('postpartum_client_id', $id)
            ->where('logged_at', '>=', $since)->orderByDesc('logged_at')->orderByDesc('id')->limit(500)->get();

        return response()->json(['success' => true, 'data' => [
            'options' => MnhClientJournal::options(),
            'newborns' => $babies->map(fn ($n, $bid) => ['id' => $bid, 'name' => $n])->values(),
            'entries' => $rows->map(fn ($r) => MnhClientJournal::present($r, $babies->all()))->values(),
            'days' => $days,
        ]]);
    }

    /** POST /v1/matching/postpartum-clients/{id}/journal {kind, newborn_id?, logged_at?, values?, note?} */
    public function store(Request $request, int $id): JsonResponse
    {
        if (!$this->owns($request, $id)) {
            return $this->notFound();
        }
        $v = $request->validate(MnhClientJournal::rules());
        [$values, $err] = MnhClientJournal::normalize($v['kind'], $v['values'] ?? [], $v['note'] ?? null);
        if ($err) {
            return response()->json(['success' => false, 'error_code' => 'INVALID_VALUES', 'message' => $err], 422);
        }

        // 시각 — 비우면 지금. 7일 전 ~ 지금(5분 여유)만
        $at = !empty($v['logged_at']) ? Kst::parseInput($v['logged_at']) : Carbon::now('UTC');
        if ($at->gt(Carbon::now('UTC')->addMinutes(5)) || $at->lt(Carbon::now('UTC')->subDays(7))) {
            return response()->json(['success' => false, 'error_code' => 'INVALID_TIME', 'message' => '기록 시각은 최근 7일 안, 지금보다 이전이어야 해요.'], 422);
        }

        // 아기 — 아기 기록이면 필요. 한 명뿐이면 그 아기로
        $babies = $this->babies($id);
        $newbornId = $v['newborn_id'] ?? null;
        $babyKind = in_array($v['kind'], MnhClientJournal::BABY_KINDS, true)
            || ($v['kind'] === 'temperature' && ($values['target'] ?? 'baby') === 'baby');
        if ($newbornId && !$babies->has($newbornId)) {
            return response()->json(['success' => false, 'error_code' => 'INVALID_NEWBORN', 'message' => '아기 정보를 찾을 수 없어요.'], 422);
        }
        if ($babyKind && !$newbornId) {
            if ($babies->count() > 1) {
                return response()->json(['success' => false, 'error_code' => 'NEWBORN_REQUIRED', 'message' => '어느 아기 기록인지 골라 주세요.'], 422);
            }
            $newbornId = $babies->keys()->first();
        }
        if (!$babyKind) {
            $newbornId = null;
        }

        $flag = MnhClientJournal::flagFor($v['kind'], $values);
        $jid = DB::table('mnh_client_journals')->insertGetId([
            'postpartum_client_id' => $id,
            'newborn_id' => $newbornId,
            'author_user_id' => $request->user()->id,
            'kind' => $v['kind'],
            'logged_at' => $at,
            'values' => $values ? json_encode($values, JSON_UNESCAPED_UNICODE) : null,
            'note' => MedicalCrypto::encrypt(trim((string) ($v['note'] ?? '')) ?: null),
            'flag' => $flag,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        MnhClientJournal::notifyOrg($jid);

        $row = DB::table('mnh_client_journals')->where('id', $jid)->first();

        return response()->json(['success' => true,
            'message' => $flag ? '기록했어요. 담당 기관에도 바로 알렸어요.' : '기록했어요.',
            'data' => MnhClientJournal::present($row, $babies->all())], 201);
    }

    /** DELETE /v1/matching/postpartum-clients/{id}/journal/{entryId} — 기관 확인 전, 내가 쓴 기록만 */
    public function destroy(Request $request, int $id, int $entryId): JsonResponse
    {
        if (!$this->owns($request, $id)) {
            return $this->notFound();
        }
        $row = DB::table('mnh_client_journals')->where('id', $entryId)->where('postpartum_client_id', $id)->first();
        if (!$row || (int) $row->author_user_id !== (int) $request->user()->id) {
            return response()->json(['success' => false, 'message' => '기록을 찾을 수 없어요.'], 404);
        }
        if ($row->checked_at) {
            return response()->json(['success' => false, 'error_code' => 'ALREADY_CHECKED', 'message' => '기관이 이미 확인한 기록은 지울 수 없어요.'], 422);
        }
        DB::table('mnh_client_journals')->where('id', $entryId)->delete();

        return response()->json(['success' => true, 'message' => '기록을 지웠어요.']);
    }

    private function owns(Request $request, int $id): bool
    {
        return DB::table('postpartum_clients')
            ->where('id', $id)->where('user_id', $request->user()->id)->whereNull('deleted_at')->exists();
    }

    /** @return \Illuminate\Support\Collection<int, string> id => 이름 */
    private function babies(int $clientId)
    {
        return DB::table('newborns')->where('postpartum_client_id', $clientId)->where('is_alive', 1)
            ->orderBy('birth_order')->orderBy('id')->pluck('name', 'id')->mapWithKeys(fn ($n, $k) => [(int) $k => $n]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => '산모 정보를 찾을 수 없어요.'], 404);
    }
}
