<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MatchRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'guardian_id',
        'senior_id',
        'category_id',
        'mode',
        'scheduled_start',
        'duration_min',
        'recurrence_rule',
        'special_request',
        'status',
        'matched_at',
    ];

    protected $casts = [
        'scheduled_start' => 'datetime',
        'matched_at' => 'datetime',
        'recurrence_rule' => 'array',
    ];

    public function guardian()
    {
        return $this->belongsTo(Guardian::class);
    }

    public function senior()
    {
        return $this->belongsTo(Senior::class);
    }

    public function category()
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function candidates()
    {
        return $this->hasMany(MatchCandidate::class, 'request_id');
    }

    public function match()
    {
        return $this->hasOne(CareMatch::class, 'request_id');
    }

}