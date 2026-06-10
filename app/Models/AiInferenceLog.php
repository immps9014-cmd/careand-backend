<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AiInferenceLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'model_id',
        'input_summary',
        'latency_ms',
        'success',
        'error',
        'inferred_at',
    ];

    protected $casts = [
        'input_summary' => 'array',
        'latency_ms' => 'decimal:2',
        'success' => 'boolean',
        'inferred_at' => 'datetime',
    ];

    public function model()
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

}