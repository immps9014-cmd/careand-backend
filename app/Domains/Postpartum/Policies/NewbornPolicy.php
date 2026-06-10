<?php

namespace App\Domains\Postpartum\Policies;

use App\Domains\Postpartum\Models\Newborn;
use App\Models\User;

/**
 * 신생아 권한 — 부모(산모)의 권한을 위임받는 형태.
 * 추가로 매칭된 인력에게 일일 기록 작성 권한 부여.
 */
class NewbornPolicy
{
    public function __construct(private PostpartumClientPolicy $clientPolicy)
    {
    }

    public function view(User $user, Newborn $newborn): bool
    {
        return $this->clientPolicy->view($user, $newborn->postpartumClient);
    }

    public function update(User $user, Newborn $newborn): bool
    {
        return $this->clientPolicy->update($user, $newborn->postpartumClient);
    }

    /**
     * 일일 기록 작성 — 매칭된 인력 + 산모 본인 + 본사
     */
    public function logDaily(User $user, Newborn $newborn): bool
    {
        if ($user->hasAnyRole(['super_admin', 'hq_operator', 'branch_manager'])) {
            return true;
        }

        $client = $newborn->postpartumClient;

        if ($user->hasRole('postpartum_client') && $user->id === $client->user_id) {
            return true;
        }

        if ($user->hasAnyRole(['caregiver_postpartum', 'caregiver_multi'])) {
            return true; // TODO: 실제 매칭 검증 추가
        }

        return false;
    }

    /**
     * 이상 알림 처리 — 본사·지점장·매칭 인력
     */
    public function resolveAnomaly(User $user, Newborn $newborn): bool
    {
        return $this->clientPolicy->manage($user, $newborn->postpartumClient);
    }
}
