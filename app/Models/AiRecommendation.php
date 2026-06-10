<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AiRecommendation extends Model
{
    use HasFactory;

    protected $fillable = [
        'senior_id',
        'model_id',
        'recommendation_type',
        'input_summary',
        'result',
        'confidence',
        'was_adopted',
        'generated_at',
    ];

    protected $casts = [
        'input_summary' => 'array',
        'result' => 'array',
        'confidence' => 'decimal:3',
        'was_adopted' => 'boolean',
        'generated_at' => 'datetime',
    ];

    public function senior()
    {
        return $this->belongsTo(Senior::class);
    }

    public function model()
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

}