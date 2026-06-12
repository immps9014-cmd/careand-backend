<?php

namespace App\Domains\Housekeeping\Models;

use App\Models\Guardian;
use App\Models\MatchRequest;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceAddress extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'guardian_id',
        'label',
        'address',
        'lat',
        'lng',
        'dwelling_type',
        'size_m2',
        'has_pets',
        'entry_note',
    ];

    protected $casts = [
        'has_pets' => 'boolean',
    ];

    public function guardian()
    {
        return $this->belongsTo(Guardian::class);
    }

    public function matchRequests()
    {
        return $this->hasMany(MatchRequest::class, 'service_address_id');
    }
}
