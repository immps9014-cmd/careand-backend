<?php

namespace App\Policies;

use App\Models\Senior;
use App\Models\User;

class SeniorPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['guardian', 'admin']);
    }

    public function view(User $user, Senior $senior): bool
    {
        if ($user->isAdmin()) return true;
        if ($user->isGuardian() && $user->guardian?->id === $senior->guardian_id) {
            return true;
        }
        if ($user->isCaregiver() && $user->caregiver) {
            return \App\Models\CareMatch::where('caregiver_id', $user->caregiver->id)
                ->whereHas('request', fn ($q) => $q->where('senior_id', $senior->id))
                ->whereIn('status', ['confirmed', 'in_progress', 'completed'])
                ->exists();
        }
        return false;
    }

    public function create(User $user): bool
    {
        return $user->isGuardian();
    }

    public function update(User $user, Senior $senior): bool
    {
        if ($user->isAdmin()) return true;
        return $user->isGuardian() && $user->guardian?->id === $senior->guardian_id;
    }

    public function delete(User $user, Senior $senior): bool
    {
        return $this->update($user, $senior);
    }
}
