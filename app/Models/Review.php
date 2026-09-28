<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'match_id',
        'reviewer_id',
        'reviewer_role',
        'rating',
        'comment',
        'tags',
        'scores',
        'flagged_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'scores' => 'array',
        'flagged_at' => 'datetime',
    ];

    public function match()
    {
        return $this->belongsTo(CareMatch::class, 'match_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

}