<?php

namespace App\Domains\Nursing\Policies;

use App\Domains\Nursing\Models\NursingPatient;
use App\Models\CareMatch;
use App\Models\User;

class NursingPatientPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['guardian', 'admin']);
    }

    public function view(User $user, NursingPatient $patient): bool
    {
        if ($user->isAdmin()) return true;
        if ($user->isGuardian() && $user->guardian?->id === $patient->guardian_id) {
            return true;
        }
        if ($user->isCaregiver() && $user->caregiver) {
            return CareMatch::where('caregiver_id', $user->caregiver->id)
                ->whereHas('request', fn ($q) => $q->where('nursing_patient_id', $patient->id))
                ->whereIn('status', ['confirmed', 'in_progress', 'completed'])
                ->exists();
        }
        return false;
    }

    public function create(User $user): bool
    {
        return $user->isGuardian();
    }

    public function update(User $user, NursingPatient $patient): bool
    {
        if ($user->isAdmin()) return true;
        return $user->isGuardian() && $user->guardian?->id === $patient->guardian_id;
    }

    public function delete(User $user, NursingPatient $patient): bool
    {
        return $this->update($user, $patient);
    }
}
