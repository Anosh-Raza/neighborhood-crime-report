<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class JobState extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'job_state';

    protected $fillable = [
        'report_id',
        'step',
        'ring',
        'status',
        'attempts',
        'error',
        'meta',
        'updated_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'meta' => 'array',
        'updated_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public function report()
    {
        return $this->belongsTo(Report::class);
    }
}
