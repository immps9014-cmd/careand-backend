<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * 아이디 찾기 · 비밀번호 재설정 (비로그인).
 *
 * 휴대폰 인증(/auth/otp/send → /auth/otp/verify)으로 받은 phone_verify_token 을 그대로 쓴다.
 * users.phone 이 unique 라 번호 하나에 계정 하나다. 관리자 계정은 여기서 풀지 않는다(슈퍼관리자가 재설정).
 */
class AccountRecoveryController extends Controller
{
    public function __construct(private OtpService $otpService)
    {
    }

    /**
     * POST /v1/auth/find-id  { phone, phone_verify_token }
     * 가입된 아이디(이메일)를 가려서 돌려준다. 토큰은 쓰지 않고 남겨 둔다 — 이어서 비밀번호 재설정에 쓸 수 있게.
     */
    public function findId(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^01[0-9]\d{7,8}$/'],
            'phone_verify_token' => ['required', 'string'],
        ]);

        if (!$this->otpService->peekVerifyToken($data['phone'], $data['phone_verify_token'])) {
            return $this->tokenInvalid();
        }

        $user = User::where('phone', $data['phone'])->where('role', '!=', 'admin')->first();
        if (!$user) {
            return response()->json([
                'success' => false,
                'error_code' => 'ACCOUNT_NOT_FOUND',
                'message' => '이 번호로 가입된 계정이 없어요. 회원가입을 진행해 주세요.',
            ], 404);
        }

        $this->audit($request, $user->id, 'auth.find_id');

        return response()->json([
            'success' => true,
            'masked_email' => self::maskEmail($user->email),
            'has_password' => !empty($user->password),
            'created_at' => $user->created_at?->timezone('Asia/Seoul')->toIso8601String(),
        ]);
    }

    /**
     * POST /v1/auth/reset-password  { phone, phone_verify_token, password, password_confirmation }
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^01[0-9]\d{7,8}$/'],
            'phone_verify_token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'password.confirmed' => '비밀번호가 일치하지 않습니다.',
        ]);

        $user = User::where('phone', $data['phone'])->where('role', '!=', 'admin')->first();

        // 토큰은 계정 확인 뒤에 소모한다 — 없는 번호로 토큰만 날리지 않게.
        if (!$user) {
            return response()->json([
                'success' => false,
                'error_code' => 'ACCOUNT_NOT_FOUND',
                'message' => '이 번호로 가입된 계정이 없어요. 회원가입을 진행해 주세요.',
            ], 404);
        }
        if (!$this->otpService->validateVerifyToken($data['phone'], $data['phone_verify_token'])) {
            return $this->tokenInvalid();
        }
        if ($user->status !== 'active') {
            return response()->json([
                'success' => false,
                'error_code' => 'USER_SUSPENDED',
                'message' => '이용이 정지된 계정이에요. 고객센터로 문의해 주세요.',
            ], 403);
        }

        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        $this->audit($request, $user->id, 'auth.password_reset');

        return response()->json([
            'success' => true,
            'message' => '비밀번호를 바꿨어요. 새 비밀번호로 로그인해 주세요.',
            'masked_email' => self::maskEmail($user->email),
        ]);
    }

    /**
     * 본인이 알아볼 만큼만 보여준다 — 앞 1/3(최소 2자)과 마지막 1자(6자 이상일 때).
     * hong.gildong@gmail.com → hon*******g@gmail.com, recoverytest0930 → recov**********0
     */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $n = mb_strlen($local);
        if ($n <= 2) {
            $masked = mb_substr($local, 0, 1) . '*';
        } else {
            $head = max(2, intdiv($n, 3));
            $tail = $n >= 6 ? 1 : 0;
            $masked = mb_substr($local, 0, $head) . str_repeat('*', $n - $head - $tail) . ($tail ? mb_substr($local, -1) : '');
        }
        return $domain === '' ? $masked : "{$masked}@{$domain}";
    }

    private function tokenInvalid(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error_code' => 'VERIFY_TOKEN_INVALID',
            'message' => '휴대폰 인증이 만료됐어요. 인증번호를 다시 받아 주세요.',
        ], 422);
    }

    private function audit(Request $request, int $userId, string $action): void
    {
        try {
            AuditLog::create(['actor_id' => $userId, 'action' => $action, 'entity_type' => 'auth', 'entity_id' => $userId,
                'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 500)]);
        } catch (\Throwable) {}
    }
}
