<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Report extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'reports';

    protected $fillable = [
        'user_id',
        'listing_url',
        'address',
        'city',
        'country',
        'country_code',
        'location',
        'requested_radius_km',
        'status',
        'coverage_rating',
        'summary',
        'disclaimer',
        'place_vocabulary',
        'languages',
        'ring_counts',
        'crime_type_counts',
        'yearly_trend',
        'error_message',
        'preview',
    ];

    protected $casts = [
        'requested_radius_km' => 'integer',
        'place_vocabulary' => 'array',
        'languages' => 'array',
        'ring_counts' => 'array',
        'crime_type_counts' => 'array',
        'yearly_trend' => 'array',
        'preview' => 'array',
        // Keep location as a raw GeoJSON document (do not cast to array/JSON string).
    ];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING_RING_1 = 'processing_ring_1';

    public const STATUS_PROCESSING_OUTER = 'processing_outer_rings';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_FAILED = 'failed';

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function incidents()
    {
        return $this->hasMany(Incident::class);
    }

    public function jobStates()
    {
        return $this->hasMany(JobState::class);
    }

    public function latitude(): ?float
    {
        return $this->location['coordinates'][1] ?? null;
    }

    public function longitude(): ?float
    {
        return $this->location['coordinates'][0] ?? null;
    }
}
