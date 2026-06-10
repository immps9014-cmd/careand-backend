<?php

namespace App\Policies;

use App\Models\Settlement;
use App\Models\User;

class SettlementPolicy
{
    public function view(User $user, Settlement $settlement): bool
    {
        if ($user->isAdmin()) return true;
        return $user->isCaregiver() && $settlement->caregiver_id === $user->caregiver?->id;
    }
}
