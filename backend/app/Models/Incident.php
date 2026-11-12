<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Incident extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'incidents';

    protected $fillable = [
        'report_id',
        'crime_type',
        'incident_date',
        'location',
        'location_text',
        'precision',
        'distance_km',
        'ring',
        'status',
        'confidence',
        'summary',
        'sources',
        'dedupe_hash',
        'language',
        'unclear_exact_location',
    ];

    protected $casts = [
        'incident_date' => 'datetime',
        // Keep location as a raw GeoJSON document (do not cast to array/JSON string).
        'sources' => 'array',
        'distance_km' => 'float',
        'confidence' => 'float',
        'unclear_exact_location' => 'boolean',
    ];

    public function report()
    {
        return $this->belongsTo(Report::class);
    }
}
