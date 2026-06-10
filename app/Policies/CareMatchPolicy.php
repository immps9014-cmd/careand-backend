<?php

namespace App\Policies;

use App\Models\CareMatch;
use App\Models\User;

class CareMatchPolicy
{
    public function view(User $user, CareMatch $match): bool
    {
        if ($user->isAdmin()) return true;
        if ($user->isCaregiver() && $match->caregiver_id === $user->caregiver?->id) {
            return true;
        }
        if ($user->isGuardian() && $match->request->guardian_id === $user->guardian?->id) {
            return true;
        }
        return false;
    }
}
