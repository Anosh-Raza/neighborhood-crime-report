<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Subscription extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'subscriptions';

    protected $fillable = [
        'user_id',
        'stripe_subscription_id',
        'stripe_price_id',
        'plan',
        'status',
        'current_period_end',
    ];

    protected $casts = [
        'current_period_end' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
