<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 계약 이력 — created|support_set|prepaid|assigned|swapped|postponed|restored|start_changed|note|cancelled|completed */
class MnhContractEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['contract_id', 'type', 'event_date', 'payload', 'actor_user_id'];

    protected $casts = ['payload' => 'array', 'event_date' => 'date:Y-m-d'];
}
