<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,
        'redis:images' => 60,
        'redis:analysis' => 120,     // ✅ Más tiempo para análisis IA
        'redis:zip-analysis' => 300, // ✅ NUEVA: Análisis de ZIP grandes
        'redis:reports' => 600,      // ✅ NUEVA: Generación de reportes
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
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
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            // ✅ Cola default (tareas ligeras)
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 2,
                'maxProcesses' => 6,
                'tries' => 3,
                'timeout' => 300,
                'memory' => 128,
                'sleep' => 3,
                'maxJobs' => 1000,
                'rest' => 30,
            ],

            // ✅ Cola de procesamiento de imágenes (recorte)
            'supervisor-images' => [
                'connection' => 'redis',
                'queue' => ['images'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'size',
                'minProcesses' => 3,
                'maxProcesses' => 10, // ✅ Más workers para 2000 imágenes
                'tries' => 3,
                'timeout' => 900,    // 15 minutos por imagen
                'memory' => 256,
                'sleep' => 2,
                'maxJobs' => 50,     // Restart más frecuente para liberar memoria
                'rest' => 15,
            ],

            // ✅ Cola de análisis IA (más crítica)
            'supervisor-analysis' => [
                'connection' => 'redis',
                'queue' => ['analysis'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'size',
                'minProcesses' => 5,
                'maxProcesses' => 15, // ✅ Muchos workers para Azure API
                'tries' => 4,         // ✅ Más reintentos por rate limiting
                'timeout' => 300,     // ✅ 5 minutos por análisis IA
                'memory' => 256,
                'sleep' => 3,         // ✅ Importante para rate limiting Azure
                'maxJobs' => 100,
                'rest' => 30,
            ],

            // ✅ NUEVA: Cola para análisis de ZIP grandes
            'supervisor-zip-analysis' => [
                'connection' => 'redis',
                'queue' => ['zip-analysis'],
                'balance' => 'simple',
                'processes' => 2,     // Pocos workers, jobs muy pesados
                'tries' => 2,
                'timeout' => 3600,    // 1 hora para ZIPs muy grandes
                'memory' => 512,      // Mucha memoria para ZIPs grandes
                'sleep' => 5,
                'maxJobs' => 10,      // Restart frecuente
                'rest' => 60,
            ],

            // ✅ NUEVA: Cola para generación de reportes
            'supervisor-reports' => [
                'connection' => 'redis',
                'queue' => ['reports'],
                'balance' => 'simple',
                'processes' => 2,     // Máximo 2 reportes simultáneos
                'tries' => 2,
                'timeout' => 3600,    // 1 hora para reportes grandes
                'memory' => 1024,     // Mucha memoria para PDFs con miles de imágenes
                'sleep' => 10,
                'maxJobs' => 5,       // Restart muy frecuente
                'rest' => 120,        // 2 minutos de descanso
            ],

            // ✅ Cola para tareas muy pesadas (batch completos)
            'supervisor-heavy' => [
                'connection' => 'redis',
                'queue' => ['heavy-tasks'],
                'balance' => 'simple',
                'processes' => 1,     // Solo 1 worker para no saturar
                'tries' => 1,
                'timeout' => 7200,    // 2 horas máximo
                'memory' => 1024,
                'sleep' => 10,
                'maxJobs' => 3,
                'rest' => 300,        // 5 minutos de descanso
            ],
        ],
    ],
];
