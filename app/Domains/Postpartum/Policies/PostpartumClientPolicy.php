<?php

namespace App\Domains\Postpartum\Policies;

use App\Domains\Postpartum\Models\PostpartumClient;
use App\Models\User;

class PostpartumClientPolicy
{
    /**
     * 통합 RBAC 매트릭스 기반 권한 검사
     *
     * 본부/지점장: 전체 조회·관리
     * 산모 본인: 자기 데이터만
     * 가족 보호자: 산모 동의 범위 내
     * 인력 (caregiver_postpartum / caregiver_multi): 매칭된 산모만
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'hq_operator', 'branch_manager', 'franchisee']);
    }

    public function view(User $user, PostpartumClient $client): bool
    {
        if ($user->hasAnyRole(['super_admin', 'hq_operator'])) {
            return true;
        }

        // 지점장: 자기 지점 산모만
        if ($user->hasRole('branch_manager') && $user->branch_id === $client->branch_id) {
            return true;
        }

        // 가맹점주: data_policies.can_view_client_pii 확인 + 자기 가맹점
        if ($user->hasRole('franchisee')) {
            return $user->branch_id === $client->branch_id
                && $this->franchiseeCanViewPii($user);
        }

        // 산모 본인
        if ($user->hasRole('postpartum_client') && $user->id === $client->user_id) {
            return true;
        }

        // 가족 보호자 (산모 동의 범위 내)
        if ($user->hasRole('family_postpartum')) {
            return $this->isFamilyOf($user, $client);
        }

        // 인력: 자기와 매칭된 산모만
        if ($user->hasAnyRole(['caregiver_postpartum', 'caregiver_multi'])) {
            return $this->isMatchedCaregiver($user, $client);
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole([
            'super_admin', 'hq_operator', 'branch_manager', 'franchisee', 'postpartum_client',
        ]);
    }

    public function update(User $user, PostpartumClient $client): bool
    {
        if ($user->hasAnyRole(['super_admin', 'hq_operator'])) {
            return true;
        }
        if ($user->hasRole('branch_manager') && $user->branch_id === $client->branch_id) {
            return true;
        }
        if ($user->hasRole('postpartum_client') && $user->id === $client->user_id) {
            return true;
        }
        return false;
    }

    public function delete(User $user, PostpartumClient $client): bool
    {
        return $user->hasAnyRole(['super_admin', 'hq_operator']);
    }

    /**
     * 매칭 요청 권한 — 산모 본인 or 본사/지점 관리자
     */
    public function matchRequest(User $user, PostpartumClient $client): bool
    {
        if ($user->hasAnyRole(['super_admin', 'hq_operator'])) {
            return true;
        }
        if ($user->hasRole('branch_manager') && $user->branch_id === $client->branch_id) {
            return true;
        }
        if ($user->hasRole('postpartum_client') && $user->id === $client->user_id) {
            return true;
        }
        return false;
    }

    /**
     * 챗봇 사용 권한 — 산모 본인 + 가족(동의 시) + 본사
     */
    public function chat(User $user, PostpartumClient $client): bool
    {
        if ($user->hasAnyRole(['super_admin', 'hq_operator'])) {
            return true;
        }
        if ($user->hasRole('postpartum_client') && $user->id === $client->user_id) {
            return true;
        }
        if ($user->hasRole('family_postpartum') && $this->isFamilyOf($user, $client)) {
            return true;
        }
        return false;
    }

    /**
     * 관리 권한 — 본사·지점장만 (매칭/이상알림 처리 등)
     */
    public function manage(User $user, PostpartumClient $client): bool
    {
        if ($user->hasAnyRole(['super_admin', 'hq_operator'])) {
            return true;
        }
        if ($user->hasRole('branch_manager') && $user->branch_id === $client->branch_id) {
            return true;
        }
        if ($user->hasAnyRole(['caregiver_postpartum', 'caregiver_multi'])
            && $this->isMatchedCaregiver($user, $client)) {
            return true;
        }
        return false;
    }

    // ===== 보조 =====

    private function isFamilyOf(User $user, PostpartumClient $client): bool
    {
        // family_postpartum_consents 테이블에서 동의 여부 확인 (구현 생략)
        return false;
    }

    private function isMatchedCaregiver(User $user, PostpartumClient $client): bool
    {
        // match_requests + matches 테이블 조회 (인력의 caregiver_id 매핑)
        // return Match::where('caregiver_id', $user->caregiver?->id)
        //     ->whereHas('request', fn($q) => $q->where('postpartum_client_id', $client->id))
        //     ->exists();
        return true; // 골격
    }

    private function franchiseeCanViewPii(User $user): bool
    {
        // franchise_data_policies.can_view_client_pii 조회
        return false; // 기본 차단
    }
}
