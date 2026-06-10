<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class HealthTimeseries extends Model
{
    use HasFactory;

    protected $table = 'health_timeseries';

    protected $fillable = [
        'senior_id',
        'metric_name',
        'value',
        'recorded_at',
    ];

    protected $casts = [
        'value' => 'decimal:4',
        'recorded_at' => 'datetime',
    ];

    public function senior()
    {
        return $this->belongsTo(Senior::class);
    }

}