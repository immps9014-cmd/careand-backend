<?php

namespace App\Policies;

use App\Models\CareSession;
use App\Models\User;

class CareSessionPolicy
{
    public function view(User $user, CareSession $session): bool
    {
        if ($user->isAdmin()) return true;

        // 인력: 자신의 매칭에 속한 세션
        if ($user->isCaregiver() && $session->match->caregiver_id === $user->caregiver?->id) {
            return true;
        }

        // 보호자: 자신의 매칭 요청에 속한 세션
        if ($user->isGuardian() && $session->match->request->guardian_id === $user->guardian?->id) {
            return true;
        }

        return false;
    }
}
