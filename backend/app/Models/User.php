<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use MongoDB\Laravel\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $connection = 'mongodb';

    protected $collection = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'credits',
        'plan',
        'stripe_customer_id',
        'max_radius_km',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'credits' => 'integer',
            'max_radius_km' => 'integer',
        ];
    }

    public function reports()
    {
        return $this->hasMany(Report::class);
    }

    public function canUseRadius(int $radiusKm): bool
    {
        $max = (int) ($this->max_radius_km ?? config('crime_report.free_radius_km'));

        return $radiusKm <= $max;
    }

    public function hasCredits(): bool
    {
        return (int) $this->credits > 0;
    }

    public function consumeCredit(): void
    {
        $this->credits = max(0, (int) $this->credits - 1);
        $this->save();
    }
}
