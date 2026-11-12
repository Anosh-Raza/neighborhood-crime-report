<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class LocationCache extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'location_cache';

    protected $fillable = [
        'grid_key',
        'country',
        'incidents',
        'updated_at',
    ];

    protected $casts = [
        'incidents' => 'array',
        'updated_at' => 'datetime',
    ];
}
