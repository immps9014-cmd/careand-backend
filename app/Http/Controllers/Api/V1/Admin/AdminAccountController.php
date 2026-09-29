<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * 관리자 계정·권한 관리 — 슈퍼관리자 전용(config/admin_rbac.php 'admins' 영역) (2026-09-28, 구현계획 S2-3).
 * 새 관리자는 첫 로그인 때 2단계 인증을 등록해야 접속할 수 있다(ADMIN_2FA_REQUIRED=true 일 때 — 2026-09-29 부터 꺼짐).
 */
class AdminAccountController extends Controller
{
    /** GET /v1/admin/admins */
    public function index(): JsonResponse
    {
        $rows = DB::table('admins as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->select('a.id', 'u.id as user_id', 'u.email', 'u.name', 'u.status', 'a.permission_level', 'a.department',
                'u.totp_enabled_at', 'a.created_at')
            ->orderBy('a.id')->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'user_id' => $r->user_id, 'email' => $r->email, 'name' => $r->name, 'status' => $r->status,
                'permission_level' => $r->permission_level,
                'level_label' => config("admin_rbac.levels.{$r->permission_level}"),
                'department' => $r->department,
                'two_factor' => $r->totp_enabled_at !== null,
                'created_at' => \App\Support\Kst::iso($r->created_at),
            ]);

        return response()->json([
            'success' => true,
            'data' => $rows,
            'levels' => config('admin_rbac.levels'),
            // .env ADMIN_2FA_REQUIRED — 꺼져 있으면 화면이 2단계 인증 열·버튼·안내를 숨긴다(2026-09-29)
            'two_factor_required' => (bool) config('auth.admin_2fa_required', true),
            'areas' => collect(config('admin_rbac.areas'))->map(fn ($v) => ['label' => $v['label'], 'read' => $v['read'], 'write' => $v['write']]),
        ]);
    }

    /** POST /v1/admin/admins — 관리자 계정 추가 */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:50'],
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:10'],
            'permission_level' => ['required', Rule::in(array_keys(config('admin_rbac.levels')))],
            'department' => ['nullable', 'string', 'max:50'],
        ]);

        $admin = DB::transaction(function () use ($data) {
            $user = User::create([
                'email' => $data['email'], 'name' => $data['name'], 'phone' => $data['phone'],
                'password' => $data['password'], 'role' => 'admin', 'status' => 'active',
            ]);
            return Admin::create(['user_id' => $user->id, 'permission_level' => $data['permission_level'], 'department' => $data['department'] ?? null]);
        });
        $this->audit($request, 'admin.account.create', $admin->user_id, ['level' => $data['permission_level']]);

        $message = config('auth.admin_2fa_required', true)
            ? '관리자 계정을 만들었습니다. 첫 로그인 때 2단계 인증을 등록합니다.'
            : '관리자 계정을 만들었습니다. 아이디와 비밀번호로 로그인합니다.';

        return response()->json(['success' => true, 'message' => $message, 'id' => $admin->id], 201);
    }

    /** PATCH /v1/admin/admins/{id} — 등급·부서·상태 변경 */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'permission_level' => ['sometimes', Rule::in(array_keys(config('admin_rbac.levels')))],
            'department' => ['sometimes', 'nullable', 'string', 'max:50'],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
        ]);
        $admin = Admin::findOrFail($id);

        // 마지막 활성 슈퍼관리자를 없애지 않는다(모두가 잠기는 것 방지)
        $losingSuper = $admin->permission_level === 'super'
            && ((isset($data['permission_level']) && $data['permission_level'] !== 'super') || (($data['status'] ?? null) === 'suspended'));
        if ($losingSuper) {
            $supers = DB::table('admins as a')->join('users as u', 'u.id', '=', 'a.user_id')
                ->where('a.permission_level', 'super')->where('u.status', 'active')->count();
            if ($supers <= 1) {
                return response()->json(['success' => false, 'error_code' => 'LAST_SUPER', 'message' => '마지막 슈퍼관리자는 등급을 낮추거나 정지할 수 없습니다.'], 422);
            }
        }

        $before = ['level' => $admin->permission_level, 'department' => $admin->department];
        $admin->fill(array_intersect_key($data, array_flip(['permission_level', 'department'])))->save();
        if (isset($data['status'])) {
            User::where('id', $admin->user_id)->update(['status' => $data['status']]);
        }
        $this->audit($request, 'admin.account.update', $admin->user_id, ['before' => $before, 'after' => $data]);

        return response()->json(['success' => true, 'message' => '변경했습니다. 대상자는 다시 로그인하면 새 권한이 적용됩니다.']);
    }

    /** POST /v1/admin/admins/{id}/reset-2fa — 휴대폰 분실 등 */
    public function resetTwoFactor(Request $request, int $id): JsonResponse
    {
        $admin = Admin::findOrFail($id);
        User::where('id', $admin->user_id)->update(['totp_secret' => null, 'totp_enabled_at' => null]);
        $this->audit($request, 'auth.2fa.reset', $admin->user_id, ['by' => 'admin']);

        return response()->json(['success' => true, 'message' => '2단계 인증을 초기화했습니다. 다음 로그인 때 다시 등록합니다.']);
    }

    private function audit(Request $request, string $action, int $targetUserId, array $details): void
    {
        AuditLog::create([
            'actor_id' => $request->user()->id, 'action' => $action, 'entity_type' => 'admins', 'entity_id' => $targetUserId,
            'details' => $details, 'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'reason' => $request->header('X-Access-Reason') ? mb_substr(rawurldecode($request->header('X-Access-Reason')), 0, 300) : null,
        ]);
    }
}
