<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class CreditLedger extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'credit_ledger';

    protected $fillable = [
        'user_id',
        'delta',
        'reason',
        'report_id',
        'meta',
    ];

    protected $casts = [
        'delta' => 'integer',
        'meta' => 'array',
    ];
}
