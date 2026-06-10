<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function view(User $user, Payment $payment): bool
    {
        if ($user->isAdmin()) return true;
        return $user->isGuardian() && $payment->guardian_id === $user->guardian?->id;
    }

    public function update(User $user, Payment $payment): bool
    {
        return $this->view($user, $payment);
    }
}
