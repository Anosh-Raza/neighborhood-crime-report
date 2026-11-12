<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class ArticleCache extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'articles_cache';

    protected $fillable = [
        'url',
        'language',
        'title',
        'source_name',
        'published_at',
        'extracted_json',
        'fetched_at',
    ];

    protected $casts = [
        'extracted_json' => 'array',
        'fetched_at' => 'datetime',
        'published_at' => 'datetime',
    ];
}
