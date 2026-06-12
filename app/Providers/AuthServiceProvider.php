<?php

namespace App\Providers;

use App\Domains\Nursing\Models\NursingPatient;
use App\Domains\Nursing\Policies\NursingPatientPolicy;
use App\Models\CareMatch;
use App\Models\CareSession;
use App\Models\MatchRequest;
use App\Models\Payment;
use App\Models\Senior;
use App\Models\Settlement;
use App\Policies\CareMatchPolicy;
use App\Policies\CareSessionPolicy;
use App\Policies\MatchRequestPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\SeniorPolicy;
use App\Policies\SettlementPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Senior::class => SeniorPolicy::class,
        MatchRequest::class => MatchRequestPolicy::class,
        CareMatch::class => CareMatchPolicy::class,
        CareSession::class => CareSessionPolicy::class,
        Payment::class => PaymentPolicy::class,
        Settlement::class => SettlementPolicy::class,
        NursingPatient::class => NursingPatientPolicy::class,
    ];

    public function boot(): void
    {
        //
    }
}
