<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Blacklist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 관리자 — 회원 블랙리스트 (기능 19, 2026-09-29). RBAC 'blacklist'(조회 운영 3등급, 등록·해제 슈퍼·지점장).
 * 등록: 계정 정지(status=suspended) + 같은 번호 재가입 차단. 해제: 차단만 풀고 계정은 다시 활성(탈퇴 계정 제외).
 */
class BlacklistController extends Controller
{
    /** GET /v1/admin/blacklist?all=1 */
    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('member_blacklist as b')->leftJoin('users as u', 'u.id', '=', 'b.user_id')
            ->leftJoin('users as a', 'a.id', '=', 'b.created_by')
            ->when(!$request->boolean('all'), fn ($q) => $q->whereNull('b.released_at'))
            ->orderByDesc('b.id')->limit(200)
            ->get(['b.id', 'b.user_id', 'u.name', 'b.role', 'b.reason', 'b.created_at', 'a.name as created_by_name', 'b.released_at', 'b.release_reason']);
        return response()->json(['success' => true, 'data' => $rows]);
    }

    /** POST /v1/admin/blacklist {user_id, reason} */
    public function store(Request $request): JsonResponse
    {
        $v = $request->validate(['user_id' => 'required|integer', 'reason' => 'required|string|min:5|max:1000'], [], ['reason' => '등록 사유']);
        $u = DB::table('users')->where('id', $v['user_id'])->first(['id', 'phone', 'role', 'status']);
        if (!$u) {
            return response()->json(['success' => false, 'message' => '회원을 찾을 수 없어요.'], 404);
        }
        if ($u->role === 'admin' || (int) $u->id === (int) $request->user()->id) {
            return response()->json(['success' => false, 'message' => '관리자 계정은 블랙리스트에 올릴 수 없어요.'], 422);
        }
        $hash = Blacklist::hash((string) $u->phone);
        if (DB::table('member_blacklist')->where('phone_hash', $hash)->whereNull('released_at')->exists()) {
            return response()->json(['success' => false, 'message' => '이미 블랙리스트에 있는 번호예요.'], 409);
        }
        DB::transaction(function () use ($u, $v, $hash, $request) {
            DB::table('member_blacklist')->insert([
                'user_id' => $u->id, 'phone_hash' => $hash, 'role' => $u->role, 'reason' => $v['reason'],
                'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($u->status === 'active') {
                DB::table('users')->where('id', $u->id)->update(['status' => 'suspended', 'updated_at' => now()]);
            }
        });
        AuditLog::create(['actor_id' => $request->user()->id, 'action' => 'member.blacklist.add', 'entity_type' => 'user', 'entity_id' => $u->id,
            'details' => ['role' => $u->role], 'reason' => mb_substr($v['reason'], 0, 300), 'ip_address' => $request->ip()]);
        return response()->json(['success' => true, 'message' => '블랙리스트에 올리고 계정을 정지했어요. 같은 번호로는 다시 가입할 수 없어요.'], 201);
    }

    /** POST /v1/admin/blacklist/{id}/release {reason} */
    public function release(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['reason' => 'required|string|min:2|max:255'], [], ['reason' => '해제 사유']);
        $b = DB::table('member_blacklist')->where('id', $id)->whereNull('released_at')->first();
        if (!$b) {
            return response()->json(['success' => false, 'message' => '이미 해제됐거나 없는 항목이에요.'], 404);
        }
        DB::transaction(function () use ($b, $v, $request) {
            DB::table('member_blacklist')->where('id', $b->id)->update([
                'released_at' => now(), 'released_by' => $request->user()->id, 'release_reason' => $v['reason'], 'updated_at' => now(),
            ]);
            if ($b->user_id) {
                DB::table('users')->where('id', $b->user_id)->where('status', 'suspended')->whereNull('deleted_at')->update(['status' => 'active', 'updated_at' => now()]);
            }
        });
        AuditLog::create(['actor_id' => $request->user()->id, 'action' => 'member.blacklist.release', 'entity_type' => 'user', 'entity_id' => $b->user_id,
            'details' => ['blacklist_id' => $b->id], 'reason' => mb_substr($v['reason'], 0, 300), 'ip_address' => $request->ip()]);
        return response()->json(['success' => true, 'message' => '블랙리스트에서 해제했어요.']);
    }
}
