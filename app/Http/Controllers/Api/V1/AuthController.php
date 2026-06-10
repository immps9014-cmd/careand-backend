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
use App\Services\OtpService;
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

        // phone_verify_token 검증
        if (!$this->otpService->validateVerifyToken($data['phone'], $data['phone_verify_token'])) {
            return response()->json([
                'success' => false,
                'error_code' => 'PHONE_NOT_VERIFIED',
                'message' => '휴대폰 인증이 필요합니다.',
            ], 422);
        }

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'email' => $data['email'],
                'phone' => $data['phone'],
                'name' => $data['name'],
                'role' => $data['role'],
                'password' => $data['password'],
                'phone_verified_at' => now(),
            ]);

            // 역할별 프로필 자동 생성
            if ($user->role === 'guardian') {
                Guardian::create([
                    'user_id' => $user->id,
                    'relation' => $data['relation'] ?? null,
                ]);
            }
            // caregiver/organization은 별도 register 단계에서 추가 정보 수집

            return $user;
        });

        $token = JWTAuth::fromUser($user);

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
                'message' => '이메일 또는 비밀번호가 올바르지 않습니다.',
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

        // FCM 토큰 갱신 (앱에서 전송 시)
        if ($request->filled('fcm_token')) {
            $user->update(['fcm_token' => $request->input('fcm_token')]);
        }

        return response()->json([
            'success' => true,
            'user' => new UserResource($user),
            'access_token' => $token,
            'refresh_token' => $this->generateRefreshToken($user),
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

    private function generateRefreshToken(User $user): string
    {
        return JWTAuth::customClaims([
            'exp' => now()->addMinutes(config('jwt.refresh_ttl'))->timestamp,
            'type' => 'refresh',
        ])->fromUser($user);
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
