<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\OtpSendRequest;
use App\Http\Requests\Auth\OtpVerifyRequest;
use App\Http\Requests\Auth\SignupRequest;
use App\Http\Resources\UserResource;
use App\Models\Guardian;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\OtpService;
use App\Services\TotpService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function __construct(private OtpService $otpService)
    {
    }

    /**
     * POST /v1/auth/otp/send
     */
    public function sendOtp(OtpSendRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');

        // 블랙리스트 번호는 인증번호도 보내지 않는다(기능 19)
        if (\App\Support\Blacklist::blocked($phone)) {
            return response()->json(['success' => false, 'error_code' => 'BLACKLISTED', 'message' => '이 번호로는 가입할 수 없어요. 고객센터로 문의해 주세요.'], 403);
        }

        try {
            $this->otpService->send($phone);
            return response()->json([
                'success' => true,
                'message' => '인증번호가 발송되었습니다.',
                'expires_in_sec' => 180,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error_code' => 'OTP_SEND_FAILED',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /v1/auth/otp/verify
     */
    public function verifyOtp(OtpVerifyRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');
        $code = $request->validated('code');

        if (!$this->otpService->verify($phone, $code)) {
            return response()->json([
                'success' => false,
                'error_code' => 'OTP_INVALID',
                'message' => '인증번호가 올바르지 않거나 만료되었습니다.',
            ], 422);
        }

        // 인증 성공 → 임시 토큰 발급 (회원가입 단계에서 사용)
        $verifyToken = $this->otpService->issueVerifyToken($phone);

        return response()->json([
            'success' => true,
            'phone_verify_token' => $verifyToken,
            'expires_in_sec' => 600,
        ]);
    }

    /**
     * POST /v1/auth/signup
     */
    public function signup(SignupRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (\App\Support\Blacklist::blocked((string) $data['phone'])) {   // 기능 19
            return response()->json(['success' => false, 'error_code' => 'BLACKLISTED', 'message' => '이 번호로는 가입할 수 없어요. 고객센터로 문의해 주세요.'], 403);
        }

        // phone_verify_token 검증
        if (!$this->otpService->validateVerifyToken($data['phone'], $data['phone_verify_token'])) {
            return response()->json([
                'success' => false,
                'error_code' => 'PHONE_NOT_VERIFIED',
                'message' => '휴대폰 인증이 필요합니다.',
            ], 422);
        }

        // 소셜 가입(S4) — 콜백에서 받은 30분짜리 가입 토큰. 비밀번호 없이 가입하고 소셜 계정을 연결한다
        $social = null;
        if (!empty($data['social_token'])) {
            $social = Cache::pull("oauth:signup:{$data['social_token']}");
            if (!$social) {
                return response()->json(['success' => false, 'error_code' => 'SOCIAL_EXPIRED', 'message' => '소셜 로그인 정보가 만료되었습니다. 다시 로그인해 주세요.'], 422);
            }
        }

        $user = DB::transaction(function () use ($data, $social) {
            $user = User::create([
                'email' => $data['email'],
                'phone' => $data['phone'],
                'name' => $data['name'],
                'role' => $data['role'],
                // 소셜 가입은 쓸 일 없는 무작위 비밀번호(비밀번호 로그인 불가 — 필요하면 비밀번호 재설정으로 만든다)
                'password' => $data['password'] ?? Str::random(40),
                'phone_verified_at' => now(),
            ]);
            if ($social) {
                DB::table('social_accounts')->insert([
                    'user_id' => $user->id, 'provider' => $social['provider'], 'provider_user_id' => $social['provider_user_id'],
                    'email' => $social['email'] ?? null, 'last_login_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            // 역할별 프로필 자동 생성
            if ($user->role === 'guardian') {
                Guardian::create([
                    'user_id' => $user->id,
                    'relation' => $data['relation'] ?? null,
                    // 가입 의도 보존: 가사·산모·아이돌봄·마음돌봄 요청자 구분. 그 외는 care.
                    'intent' => in_array($data['intent'] ?? null, ['housekeeping', 'postpartum', 'childcare', 'mental_care'], true) ? $data['intent'] : 'care',
                    // 주로 이용할 서비스(복수 선택, 선택 순서 유지) — 홈 서비스 타일 정렬에 쓴다
                    'preferences' => ! empty($data['services']) ? ['services' => array_values($data['services'])] : null,
                ]);
            }
            // caregiver/organization은 별도 register 단계에서 추가 정보 수집

            return $user;
        });

        // 페이로드 팩토리(싱글턴)가 앞선 발급의 클레임을 들고 있다(S6 시험에서 확인) — 발급 전에 비운다
        JWTAuth::factory()->emptyClaims();
        $token = JWTAuth::customClaims([])->fromUser($user);

        // login/me와 동일하게 역할 프로필을 eager-load — UserResource가 whenLoaded라
        // 미로드 시 guardian.intent가 응답에서 누락되어 가입 직후 홈 개인화가 불발됨.
        $user->load(['guardian', 'caregiver', 'organization', 'admin']);

        return response()->json([
            'success' => true,
            'user' => new UserResource($user),
            'access_token' => $token,
            'refresh_token' => $this->generateRefreshToken($user),
            'token_type' => 'Bearer',
            'expires_in' => config('jwt.ttl') * 60,
            'next_step' => $this->getNextStep($user),
        ], 201);
    }

    /**
     * POST /v1/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        if (!$token = JWTAuth::attempt(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            return response()->json([
                'success' => false,
                'error_code' => 'INVALID_CREDENTIALS',
                'message' => '아이디 또는 비밀번호가 올바르지 않습니다.',
            ], 401);
        }

        /** @var User $user */
        $user = JWTAuth::user();

        if ($user->status !== 'active') {
            return response()->json([
                'success' => false,
                'error_code' => 'USER_SUSPENDED',
                'message' => '정지된 계정입니다.',
            ], 403);
        }

        // 관리자는 비밀번호만으로 토큰을 주지 않는다 — 2단계 인증(TOTP) 필수 (사업계획서 3.3, 2026-09-28 S2).
        // 방금 발급된 토큰은 폐기하고, 5분짜리 확인 토큰으로 /auth/2fa/verify 를 거치게 한다.
        // .env ADMIN_2FA_REQUIRED=false 면 관리자도 아이디·비밀번호만으로 로그인(2026-09-29 운영 결정 — CheckRole 도 같은 값을 본다).
        if ($user->role === 'admin' && config('auth.admin_2fa_required', true)) {
            try { JWTAuth::setToken($token)->invalidate(); } catch (\Throwable) {}
            return $this->startTwoFactor($request, $user);
        }

        // FCM 토큰 갱신 (앱에서 전송 시)
        if ($request->filled('fcm_token')) {
            $user->update(['fcm_token' => $request->input('fcm_token')]);
        }

        return $this->tokenResponse($user, $token);
    }

    /**
     * 관리자 2단계 인증 시작 — 미등록이면 등록용 QR·설정 키를 함께 준다(등록 전에는 접속 불가).
     */
    private function startTwoFactor(Request $request, User $user): JsonResponse
    {
        $challenge = Str::random(48);
        Cache::put("2fa:challenge:{$challenge}", ['user_id' => $user->id, 'fails' => 0], 300);
        $body = [
            'success' => true,
            'requires_2fa' => true,
            'setup_required' => $user->totp_enabled_at === null,
            'challenge_token' => $challenge,
            'expires_in' => 300,
        ];
        if ($user->totp_enabled_at === null) {
            $totp = app(TotpService::class);
            $secret = $totp->generateSecret();
            Cache::put("2fa:setup:{$challenge}", $secret, 300);
            $uri = $totp->otpauthUri($user->email, $secret);
            $body += ['secret' => $secret, 'otpauth_uri' => $uri, 'qr_svg' => $totp->qrSvg($uri)];
        }
        return response()->json($body);
    }

    /**
     * POST /v1/auth/2fa/verify {challenge_token, code}
     * 인증 앱 6자리 코드 확인 → (첫 등록이면 비밀키 저장) → 토큰 발급.
     * 같은 코드 재사용 불가, 확인 토큰당 5회 실패 시 처음부터 다시 로그인.
     */
    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string', 'size:48'],
            'code' => ['required', 'string'],
        ]);
        $key = "2fa:challenge:{$data['challenge_token']}";
        $ch = Cache::get($key);
        if (!$ch) {
            return response()->json(['success' => false, 'error_code' => '2FA_EXPIRED', 'message' => '인증 시간이 지났습니다. 다시 로그인해 주세요.'], 401);
        }
        $user = User::find($ch['user_id']);
        $code = preg_replace('/\D/', '', $data['code']);
        $setupSecret = Cache::get("2fa:setup:{$data['challenge_token']}");
        $secret = $setupSecret ?? $user?->totp_secret;
        $step = ($user && $secret) ? app(TotpService::class)->verify($secret, $code) : null;

        // 재사용 방지 — 같은 사용자의 같은 시간 단계 코드는 한 번만
        $lastKey = "2fa:last_step:{$ch['user_id']}";
        if ($step !== null && (int) Cache::get($lastKey, -1) >= $step) {
            $step = null;
        }
        if ($step === null) {
            $ch['fails']++;
            $this->audit2fa($request, $ch['user_id'], 'auth.2fa.fail');
            if ($ch['fails'] >= 5) {
                Cache::forget($key);
                Cache::forget("2fa:setup:{$data['challenge_token']}");
                return response()->json(['success' => false, 'error_code' => '2FA_LOCKED', 'message' => '인증 코드가 5회 틀렸습니다. 다시 로그인해 주세요.'], 401);
            }
            Cache::put($key, $ch, 300);
            return response()->json(['success' => false, 'error_code' => '2FA_INVALID', 'message' => '인증 코드가 올바르지 않습니다.'], 422);
        }

        Cache::put($lastKey, $step, 120);
        Cache::forget($key);
        if ($setupSecret) {
            $user->forceFill(['totp_secret' => $setupSecret, 'totp_enabled_at' => now()])->save();
            Cache::forget("2fa:setup:{$data['challenge_token']}");
            $this->audit2fa($request, $user->id, 'auth.2fa.enroll');
        }
        $this->audit2fa($request, $user->id, 'auth.2fa.success');

        // mfa 클레임 — 관리자 API(role:admin)는 이 표시가 있는 토큰만 받는다(도입 전 발급 토큰 무효화)
        return $this->tokenResponse($user, JWTAuth::customClaims(['mfa' => true])->fromUser($user), true);
    }

    private function audit2fa(Request $request, int $userId, string $action): void
    {
        try {
            AuditLog::create([
                'actor_id' => $userId, 'action' => $action, 'entity_type' => 'auth', 'entity_id' => $userId,
                'details' => ['ok' => $action !== 'auth.2fa.fail'],
                'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);
        } catch (\Throwable) {}
    }

    private function tokenResponse(User $user, string $token, bool $mfa = false): JsonResponse
    {
        $user->load(['guardian', 'caregiver', 'organization', 'admin']);

        return response()->json([
            'success' => true,
            'user' => new UserResource($user),
            'access_token' => $token,
            'refresh_token' => $this->generateRefreshToken($user, $mfa),
            'token_type' => 'Bearer',
            'expires_in' => config('jwt.ttl') * 60,
        ]);
    }

    /**
     * POST /v1/auth/refresh
     */
    public function refresh(): JsonResponse
    {
        try {
            $newToken = auth('api')->refresh();
            return response()->json([
                'success' => true,
                'access_token' => $newToken,
                'token_type' => 'Bearer',
                'expires_in' => config('jwt.ttl') * 60,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error_code' => 'TOKEN_REFRESH_FAILED',
                'message' => '토큰 갱신에 실패했습니다.',
            ], 401);
        }
    }

    /**
     * POST /v1/auth/logout
     */
    public function logout(): JsonResponse
    {
        auth('api')->logout();

        return response()->json([
            'success' => true,
            'message' => '로그아웃 되었습니다.',
        ]);
    }

    /**
     * GET /v1/auth/me
     */
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();
        $user->load(['guardian', 'caregiver', 'organization', 'admin']);

        return response()->json([
            'success' => true,
            'user' => new UserResource($user),
        ]);
    }

    /**
     * PATCH /v1/auth/me
     * 계정 정보 수정 (이름·연락처·이메일·비밀번호). 비밀번호 변경 시 현재 비밀번호 확인 필요.
     */
    public function updateMe(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        $validated = $request->validate([
            'name'  => ['sometimes', 'string', 'max:50'],
            'phone' => ['sometimes', 'string', 'max:20', 'regex:/^[0-9+\-]+$/', 'unique:users,phone,'.$user->id],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'current_password' => ['required_with:password', 'current_password:api'],
            'password' => ['sometimes', 'string', 'min:8', 'confirmed'],
        ], [
            'current_password.required_with' => '현재 비밀번호를 입력해주세요.',
            'current_password.current_password' => '현재 비밀번호가 일치하지 않습니다.',
            'phone.unique' => '이미 사용 중인 연락처입니다.',
            'email.unique' => '이미 사용 중인 이메일입니다.',
            'password.confirmed' => '새 비밀번호 확인이 일치하지 않습니다.',
            'password.min' => '비밀번호는 8자 이상이어야 합니다.',
        ]);

        $payload = array_intersect_key($validated, array_flip(['name', 'phone', 'email']));
        if (!empty($validated['password'])) {
            $payload['password'] = $validated['password']; // User 모델 casts(password => hashed)로 자동 해시
        }

        if (empty($payload)) {
            return response()->json(['success' => false, 'message' => '변경할 정보가 없습니다.'], 422);
        }

        $user->update($payload);
        $user->load(['guardian', 'caregiver', 'organization', 'admin']);

        return response()->json([
            'success' => true,
            'message' => '계정 정보가 수정되었습니다.',
            'user' => new UserResource($user),
        ]);
    }

    /**
     * DELETE /v1/auth/me — 회원 탈퇴(자가). 현재 비밀번호 확인 필수.
     * 진행 중 매칭요청 취소, 돌봄전문가 계정 비활성 후 status=withdrawn + 소프트삭제, 토큰 무효화.
     */
    public function withdraw(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        $validated = $request->validate([
            'current_password' => ['required', 'current_password:api'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'current_password.required' => '현재 비밀번호를 입력해주세요.',
            'current_password.current_password' => '현재 비밀번호가 일치하지 않습니다.',
        ]);

        $user->loadMissing(['guardian', 'caregiver']);

        \Illuminate\Support\Facades\DB::transaction(function () use ($user) {
            // 보호자: 진행 중(open/matching) 매칭요청 취소
            if ($user->guardian) {
                \Illuminate\Support\Facades\DB::table('match_requests')
                    ->where('guardian_id', $user->guardian->id)
                    ->whereIn('status', ['open', 'matching'])
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);
            }
            // 돌봄전문가: 활성 목록에서 제외(정지 처리)
            if ($user->caregiver) {
                \Illuminate\Support\Facades\DB::table('caregivers')
                    ->where('id', $user->caregiver->id)
                    ->update(['status' => 'suspended', 'updated_at' => now()]);
            }
            $user->status = 'withdrawn';
            $user->save();
            $user->delete(); // 소프트 삭제(deleted_at)
        });

        logger()->info('member.withdraw', [
            'user_id' => $user->id,
            'role' => $user->role,
            'reason' => $validated['reason'] ?? null,
        ]);

        // 현재 토큰 무효화
        auth('api')->logout();

        return response()->json([
            'success' => true,
            'message' => '회원 탈퇴가 완료되었습니다.',
        ]);
    }

    private function generateRefreshToken(User $user, bool $mfa = false): string
    {
        return JWTAuth::customClaims(array_merge([
            'exp' => now()->addMinutes(config('jwt.refresh_ttl'))->timestamp,
            'type' => 'refresh',
        ], $mfa ? ['mfa' => true] : []))->fromUser($user);
    }

    private function getNextStep(User $user): ?string
    {
        return match ($user->role) {
            'caregiver' => 'caregiver_register',
            'organization' => 'organization_register',
            'guardian' => 'add_senior',
            default => null,
        };
    }
}
