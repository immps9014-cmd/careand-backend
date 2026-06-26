<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\RegisterOrganizationRequest;
use App\Models\Caregiver;
use App\Models\CaregiverInvite;
use App\Models\Guardian;
use App\Models\Organization;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizationController extends Controller
{
    public function __construct(
        private NotificationService $notifications,
        private OtpService $otp,
    ) {
    }

    /**
     * GET /v1/organizations/me — 내 기관 정보 + 승인 상태.
     */
    public function me(Request $request): JsonResponse
    {
        $org = $request->user()->organization;

        if (!$org) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_REGISTERED',
                'message' => '기관 등록 정보가 없습니다.',
            ], 404);
        }

        return response()->json(['success' => true, 'data' => $org]);
    }

    /**
     * POST /v1/organizations/register — 기관 등록(사업자 정보 + 요청자 guardian 프로필).
     */
    public function register(RegisterOrganizationRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'organization') {
            return response()->json([
                'success' => false,
                'error_code' => 'INVALID_ROLE',
                'message' => '기관 회원만 등록 가능합니다.',
            ], 403);
        }

        if ($user->organization) {
            return response()->json([
                'success' => false,
                'error_code' => 'ALREADY_REGISTERED',
                'message' => '이미 등록된 기관입니다.',
            ], 409);
        }

        $data = $request->validated();

        $org = DB::transaction(function () use ($data, $user) {
            $org = Organization::create(array_merge($data, [
                'user_id' => $user->id,
                'status' => 'pending',
            ]));

            if (!$user->guardian) {
                Guardian::create([
                    'user_id' => $user->id,
                    'relation' => '기관',
                ]);
            }

            return $org;
        });

        return response()->json([
            'success' => true,
            'message' => '기관 등록이 접수되었습니다. 관리자 검수 후 이용하실 수 있습니다.',
            'data' => ['id' => $org->id, 'status' => $org->status],
        ], 201);
    }

    /**
     * GET /v1/organizations/me/caregivers — 소속 간병인 명단 + 대기 중 초대.
     */
    public function caregivers(Request $request): JsonResponse
    {
        $org = $this->requireOrg($request);
        if ($org instanceof JsonResponse) {
            return $org;
        }

        $members = Caregiver::where('org_id', $org->id)
            ->with('user:id,name,phone')
            ->get()
            ->map(fn ($c) => [
                'caregiver_id' => $c->id,
                'name' => $c->user?->name,
                'phone' => $c->user?->phone,
                'service_domains' => $c->service_domains,
                'status' => $c->status,
            ]);

        $invites = CaregiverInvite::where('org_id', $org->id)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->get(['id', 'phone', 'created_at'])
            ->map(fn ($i) => [
                'invite_id' => $i->id,
                'phone' => $i->phone,
                'invited_at' => $i->created_at,
            ]);

        return response()->json([
            'success' => true,
            'data' => ['members' => $members, 'pending_invites' => $invites],
        ]);
    }

    /**
     * POST /v1/organizations/me/caregivers/invite { phone }
     * 가입된 간병인 → 즉시 소속 연결 + 알림. 미가입 → 초대 발송(SMS, 가입 시 자동 연결).
     */
    public function inviteCaregiver(Request $request): JsonResponse
    {
        $org = $this->requireOrg($request);
        if ($org instanceof JsonResponse) {
            return $org;
        }

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
        ]);
        $phone = preg_replace('/\D/', '', $validated['phone']);
        if (strlen($phone) < 10) {
            return response()->json(['success' => false, 'error_code' => 'INVALID_PHONE', 'message' => '올바른 휴대폰 번호를 입력해주세요.'], 422);
        }

        $user = User::where('phone', $phone)->where('role', 'caregiver')->first();

        // 1) 이미 가입된 간병인 → 즉시 연결
        if ($user && $user->caregiver) {
            $cg = $user->caregiver;
            if ($cg->org_id === $org->id) {
                return response()->json(['success' => false, 'error_code' => 'ALREADY_MEMBER', 'message' => '이미 소속된 간병인입니다.'], 409);
            }
            if ($cg->org_id !== null) {
                return response()->json(['success' => false, 'error_code' => 'OTHER_ORG', 'message' => '다른 기관에 소속된 간병인입니다.'], 409);
            }
            $cg->org_id = $org->id;
            $cg->save();
            $this->notifications->notify($user->id, NotificationService::TYPE_ORG_CAREGIVER_JOINED, ['org_name' => $org->name]);

            return response()->json([
                'success' => true,
                'message' => "{$user->name} 간병인을 소속으로 추가했습니다.",
                'data' => ['linked' => true, 'caregiver_id' => $cg->id],
            ]);
        }

        // 2) 미가입(또는 caregiver 아님) → 초대 발송. 가입 시 전화번호로 자동 연결.
        $existing = CaregiverInvite::where('org_id', $org->id)->where('phone', $phone)->where('status', 'pending')->first();
        if ($existing) {
            return response()->json(['success' => false, 'error_code' => 'ALREADY_INVITED', 'message' => '이미 초대한 번호입니다.'], 409);
        }

        $token = Str::random(40);
        CaregiverInvite::create([
            'org_id' => $org->id,
            'phone' => $phone,
            'token' => $token,
            'status' => 'pending',
            'invited_by_user_id' => $request->user()->id,
            'expires_at' => now()->addDays(14),
        ]);

        $link = config('app.url') . "/app/signup?invite={$token}";
        try {
            $this->otp->sendCaregiverInvite($phone, $org->name, $link);
        } catch (\Throwable $e) {
            // SMS 실패해도 초대는 생성됨(기관이 링크 직접 공유 가능)
        }

        return response()->json([
            'success' => true,
            'message' => '미가입 번호입니다. 가입 초대를 발송했습니다. 가입을 완료하면 자동으로 소속에 연결됩니다.',
            'data' => ['linked' => false, 'invited' => true, 'invite_link' => $link],
        ], 201);
    }

    /**
     * DELETE /v1/organizations/me/caregivers/{caregiverId} — 소속 해제.
     */
    public function removeCaregiver(Request $request, int $caregiverId): JsonResponse
    {
        $org = $this->requireOrg($request);
        if ($org instanceof JsonResponse) {
            return $org;
        }

        $cg = Caregiver::where('id', $caregiverId)->where('org_id', $org->id)->with('user:id,name')->first();
        if (!$cg) {
            return response()->json(['success' => false, 'error_code' => 'NOT_MEMBER', 'message' => '소속 간병인이 아닙니다.'], 404);
        }

        $cg->org_id = null;
        $cg->save();
        if ($cg->user) {
            $this->notifications->notify($cg->user->id, NotificationService::TYPE_ORG_CAREGIVER_REMOVED, ['org_name' => $org->name]);
        }

        return response()->json(['success' => true, 'message' => '소속을 해제했습니다.']);
    }

    /**
     * DELETE /v1/organizations/me/invites/{inviteId} — 대기 중 초대 취소.
     */
    public function cancelInvite(Request $request, int $inviteId): JsonResponse
    {
        $org = $this->requireOrg($request);
        if ($org instanceof JsonResponse) {
            return $org;
        }

        $invite = CaregiverInvite::where('id', $inviteId)->where('org_id', $org->id)->where('status', 'pending')->first();
        if (!$invite) {
            return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => '취소할 초대가 없습니다.'], 404);
        }

        $invite->status = 'cancelled';
        $invite->save();

        return response()->json(['success' => true, 'message' => '초대를 취소했습니다.']);
    }

    /**
     * 승인된 기관만 소속 관리 가능. 아니면 JsonResponse 반환.
     */
    private function requireOrg(Request $request): Organization|JsonResponse
    {
        $org = $request->user()->organization;
        if (!$org) {
            return response()->json(['success' => false, 'error_code' => 'NOT_REGISTERED', 'message' => '기관 등록 정보가 없습니다.'], 404);
        }
        if ($org->status !== 'active') {
            return response()->json(['success' => false, 'error_code' => 'NOT_APPROVED', 'message' => '기관 승인 후 소속 간병인을 관리할 수 있습니다.'], 403);
        }
        return $org;
    }
}
