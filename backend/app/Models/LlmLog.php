<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class LlmLog extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'llm_logs';

    protected $fillable = [
        'report_id',
        'purpose',
        'model',
        'tokens_in',
        'tokens_out',
        'cost_usd',
        'success',
        'error',
    ];

    protected $casts = [
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'cost_usd' => 'float',
        'success' => 'boolean',
    ];
}
