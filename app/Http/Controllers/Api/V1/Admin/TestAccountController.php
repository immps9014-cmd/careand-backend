<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 테스트 계정 아이디·비밀번호 목록 — 슈퍼관리자 전용(config/admin_rbac.php 'test-accounts' 영역) (2026-09-29).
 *
 * 비밀번호는 DB 에 해시로만 있어 꺼낼 수 없으므로, 알고 있는 값을 서버 전용 파일
 * storage/app/test_accounts.json(git 제외, apache 0600)에 두고, 조회 때마다 실제 해시와 맞춰 본다.
 * 맞는 계정만 보여 준다 — 누가 비번을 바꿨으면 목록에서 빠지고 '불일치' 건수로만 남는다.
 * 파일 형식: {"accounts":[{"match":"admin@careand.co.kr","password":"...","note":"..."},
 *                         {"match":"*@demo.careand.kr","password":"...","note":"..."}]}  (match 의 * 는 와일드카드)
 */
class TestAccountController extends Controller
{
    private const FILE = 'app/test_accounts.json';

    /** GET /v1/admin/test-accounts */
    public function index(Request $request): JsonResponse
    {
        $path = storage_path(self::FILE);
        if (! is_file($path)) {
            return response()->json(['success' => true, 'data' => [], 'mismatched' => [], 'checked_at' => null]);
        }
        $rules = json_decode((string) file_get_contents($path), true)['accounts'] ?? [];

        // bcrypt 대조가 계정당 ~0.1초라 10분 캐시(파일이 바뀌면 키가 바뀌어 즉시 재검사)
        $key = 'admin:test-accounts:'.md5_file($path).':'.DB::table('users')->max('updated_at');
        $result = Cache::remember($key, 600, fn () => $this->verify($rules));

        AuditLog::create([
            'actor_id' => $request->user()->id, 'action' => 'test_accounts.view', 'entity_type' => 'users', 'entity_id' => null,
            'details' => ['count' => count($result['data'])], 'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return response()->json(['success' => true] + $result);
    }

    private function verify(array $rules): array
    {
        $rows = [];
        $mismatched = [];
        foreach ($rules as $rule) {
            $match = (string) ($rule['match'] ?? '');
            if ($match === '' || ! isset($rule['password'])) {
                continue;
            }
            $users = DB::table('users')->where('email', 'like', str_replace(['%', '_', '*'], ['\%', '\_', '%'], $match))
                ->orderBy('role')->orderBy('id')->get(['id', 'email', 'name', 'role', 'status', 'password']);
            foreach ($users as $u) {
                if (! Hash::check($rule['password'], $u->password)) {
                    $mismatched[] = $u->email;
                    continue;
                }
                $rows[] = [
                    'id' => $u->id, 'email' => $u->email, 'name' => $u->name, 'role' => $u->role, 'status' => $u->status,
                    'password' => $rule['password'], 'note' => $rule['note'] ?? null,
                ];
            }
        }

        return ['data' => $rows, 'mismatched' => $mismatched, 'checked_at' => now()->toIso8601String()];
    }
}
