<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AiModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'model_name',
        'version',
        'status',
        'accuracy',
        'avg_latency_ms',
        'metadata',
        'audited_at',
        'bias_report',
    ];

    protected $casts = [
        'accuracy' => 'decimal:4',
        'avg_latency_ms' => 'decimal:2',
        'metadata' => 'array',
        'bias_report' => 'array',
        'audited_at' => 'datetime',
    ];

    public function recommendations()
    {
        return $this->hasMany(AiRecommendation::class, 'model_id');
    }

    public function inferenceLogs()
    {
        return $this->hasMany(AiInferenceLog::class, 'model_id');
    }

}