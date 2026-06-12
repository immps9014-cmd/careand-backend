<?php

namespace App\Domains\Housekeeping\Policies;

use App\Domains\Housekeeping\Models\ServiceAddress;
use App\Models\CareMatch;
use App\Models\User;

class ServiceAddressPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['guardian', 'admin']);
    }

    public function view(User $user, ServiceAddress $address): bool
    {
        if ($user->isAdmin()) return true;
        if ($user->isGuardian() && $user->guardian?->id === $address->guardian_id) {
            return true;
        }
        if ($user->isCaregiver() && $user->caregiver) {
            return CareMatch::where('caregiver_id', $user->caregiver->id)
                ->whereHas('request', fn ($q) => $q->where('service_address_id', $address->id))
                ->whereIn('status', ['confirmed', 'in_progress', 'completed'])
                ->exists();
        }
        return false;
    }

    public function create(User $user): bool
    {
        return $user->isGuardian();
    }

    public function update(User $user, ServiceAddress $address): bool
    {
        if ($user->isAdmin()) return true;
        return $user->isGuardian() && $user->guardian?->id === $address->guardian_id;
    }

    public function delete(User $user, ServiceAddress $address): bool
    {
        return $this->update($user, $address);
    }
}
