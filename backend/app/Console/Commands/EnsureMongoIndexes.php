<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EnsureMongoIndexes extends Command
{
    protected $signature = 'mongo:ensure-indexes';

    protected $description = 'Create MongoDB indexes for Houzing collections';

    public function handle(): int
    {
        $db = DB::connection('mongodb')->getMongoDB();

        $db->selectCollection('users')->createIndex(['email' => 1], ['unique' => true]);

        $db->selectCollection('reports')->createIndex(['location' => '2dsphere']);
        $db->selectCollection('reports')->createIndex(['user_id' => 1, 'created_at' => -1]);
        $db->selectCollection('reports')->createIndex(['status' => 1]);

        $db->selectCollection('incidents')->createIndex(['location' => '2dsphere']);
        $db->selectCollection('incidents')->createIndex(['report_id' => 1, 'ring' => 1]);
        $db->selectCollection('incidents')->createIndex(
            ['report_id' => 1, 'dedupe_hash' => 1],
            ['unique' => true]
        );
        $db->selectCollection('incidents')->createIndex(['crime_type' => 1]);
        $db->selectCollection('incidents')->createIndex(['incident_date' => 1]);
        $db->selectCollection('incidents')->createIndex(['report_id' => 1, 'distance_km' => 1]);

        $db->selectCollection('articles_cache')->createIndex(['url' => 1], ['unique' => true]);
        $db->selectCollection('articles_cache')->createIndex(
            ['fetched_at' => 1],
            ['expireAfterSeconds' => 60 * 60 * 24 * 30]
        );

        $db->selectCollection('location_cache')->createIndex(['grid_key' => 1], ['unique' => true]);
        $db->selectCollection('location_cache')->createIndex(['updated_at' => 1]);

        $db->selectCollection('job_state')->createIndex(['report_id' => 1, 'step' => 1, 'ring' => 1]);
        $db->selectCollection('job_state')->createIndex(['status' => 1, 'updated_at' => 1]);

        $db->selectCollection('subscriptions')->createIndex(['user_id' => 1]);
        $db->selectCollection('subscriptions')->createIndex(['stripe_subscription_id' => 1]);

        $db->selectCollection('credit_ledger')->createIndex(['user_id' => 1, 'created_at' => -1]);
        $db->selectCollection('llm_logs')->createIndex(['report_id' => 1]);
        $db->selectCollection('llm_logs')->createIndex(['created_at' => -1]);

        $this->info('MongoDB indexes ensured.');

        return self::SUCCESS;
    }
}
