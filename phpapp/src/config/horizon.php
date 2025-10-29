<?php

use Illuminate\Support\Str;

return [
    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    | Appears in notifications and the UI - useful when running multiple instances
    */
    'name' => env('HORIZON_NAME', 'QueueWork'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    | Subdomain for Horizon (null = same domain as your app)
    */
    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    | Access Horizon at: http://localhost:8080/horizon
    */
    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    | CRITICAL: This must match your database.php redis connection name
    | Horizon stores its metadata here (not the actual queue jobs)
    */
    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    | Prevents collisions if running multiple Horizon instances
    */
    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    | Middleware applied to /horizon routes
    */
    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds (seconds)
    |--------------------------------------------------------------------------
    | Fires LongWaitDetected event when queue wait exceeds this
    */
    'waits' => [
        'redis:default' => 60,
        'redis:zip-jobs' => 120, // Longer threshold for zip jobs
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times (minutes)
    |--------------------------------------------------------------------------
    | How long to keep job history in Horizon's UI
    */
    'trim' => [
        'recent' => 60,          // Recent successful jobs: 1 hour
        'pending' => 60,         // Pending jobs: 1 hour
        'completed' => 1440,     // Completed jobs: 24 hours (increased for debugging)
        'recent_failed' => 10080, // Recent failed: 7 days
        'failed' => 10080,       // All failed: 7 days
        'monitored' => 10080,    // Monitored jobs: 7 days
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    | Jobs that won't appear in the completed jobs list
    */
    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [],

    /*
    |--------------------------------------------------------------------------
    | Metrics Snapshots
    |--------------------------------------------------------------------------
    | Number of snapshot points to keep for graphs (hours)
    */
    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    | When true, horizon:terminate won't wait for workers to finish
    | Set to false in dev to ensure clean shutdowns
    */
    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    | Max memory for Horizon master process before restart
    */
    'memory_limit' => 128,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    | THIS IS THE HEART OF HORIZON - defines how workers behave
    */
    
    'defaults' => [
        // Supervisor name (can be anything descriptive)
        'supervisor-zip-jobs' => [
            'connection' => 'redis',  // Must match config/queue.php connection
            'queue' => ['zip-jobs', 'default'],  // Queues this supervisor handles
            'balance' => 'auto',      // auto|simple|false - job distribution strategy
            'autoScalingStrategy' => 'time', // time|size - how to scale workers
            'maxProcesses' => 3,      // Max concurrent worker processes
            'maxTime' => 0,           // Max seconds a worker lives (0=unlimited)
            'maxJobs' => 0,           // Max jobs before worker restart (0=unlimited)
            'memory' => 256,          // Memory limit per worker (MB)
            'tries' => 3,             // Max attempts per job
            'timeout' => 3600,        // Job timeout (1 hour for large zips)
            'nice' => 0,              // Process priority (-20 to 19)
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment-Specific Overrides
    |--------------------------------------------------------------------------
    | Overrides defaults per environment
    */
    'environments' => [
        'production' => [
            'supervisor-zip-jobs' => [
                'maxProcesses' => 3, // More workers in production
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
        ],

        'local' => [
            'supervisor-zip-jobs' => [
                'maxProcesses' => 10, // Fewer workers in dev
                'memory' => 256,
                'timeout' => 3600,
            ],
        ],
    ],
];
