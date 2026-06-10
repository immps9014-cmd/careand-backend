<?php

namespace App\Policies;

use App\Models\MatchRequest;
use App\Models\User;

class MatchRequestPolicy
{
    public function view(User $user, MatchRequest $request): bool
    {
        if ($user->isAdmin()) return true;
        return $user->isGuardian() && $user->guardian?->id === $request->guardian_id;
    }

    public function update(User $user, MatchRequest $request): bool
    {
        return $this->view($user, $request);
    }
}
