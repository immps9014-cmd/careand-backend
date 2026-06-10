<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ServiceCategory extends Model
{
    use HasFactory;

    protected $table = 'service_categories';

    protected $fillable = [
        'code',
        'name',
        'description',
        'base_rate',
        'is_active',
    ];

    protected $casts = [
        'base_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function matchRequests()
    {
        return $this->hasMany(MatchRequest::class, 'category_id');
    }

}