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
        'redis:images' => 180,        // ✅ 3 minutos - procesamiento más complejo
        'redis:analysis' => 300,      // ✅ 5 minutos - API de Azure puede ser lenta
        'redis:zip-analysis' => 600,  // ✅ 10 minutos - análisis de ZIP grandes
        'redis:downloads' => 1800,    // ✅ 30 minutos - generación ZIP grandes
        'redis:reports' => 3600,      // ✅ 1 hora - reportes muy grandes
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
        'recent' => 1440,        // ✅ 24 horas (era 60 minutos)
        'pending' => 1440,       // ✅ 24 horas
        'completed' => 2880,     // ✅ 48 horas - para debugging de jobs completados
        'recent_failed' => 10080, // 7 días
        'failed' => 10080,        // 7 días
        'monitored' => 10080,     // 7 días
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
            'job' => 48,    // ✅ 48 horas de métricas
            'queue' => 48,
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

    'fast_termination' => true,

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

    'memory_limit' => 256,

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
            // ✅ COLA DEFAULT - Tareas ligeras y generales
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 3,        // ✅ Más procesos base
                'maxProcesses' => 8,        // ✅ Más procesos máximos
                'tries' => 3,
                'timeout' => 300,
                'memory' => 256,            // ✅ Más memoria por worker
                'sleep' => 3,
                'maxJobs' => 1000,
                'rest' => 30,
            ],

            // ✅ COLA IMAGES - Procesamiento y recorte de imágenes
            'supervisor-images' => [
                'connection' => 'redis',
                'queue' => ['images'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'size',
                'minProcesses' => 5,        // ✅ Más procesos base (era 2)
                'maxProcesses' => 12,       // ✅ Más procesos máximos (era 5)
                'tries' => 3,
                'timeout' => 1200,          // ✅ 20 minutos por imagen (era 15)
                'memory' => 512,            // ✅ Más memoria (era 256)
                'sleep' => 3,               // ✅ Menos sleep (era 5)
                'maxJobs' => 100,           // ✅ Más jobs antes de restart (era 50)
                'rest' => 15,
            ],

            // ✅ COLA ANALYSIS - Análisis IA con Azure
            'supervisor-analysis' => [
                'connection' => 'redis',
                'queue' => ['analysis'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'size',
                'minProcesses' => 8,        // ✅ Muchos procesos base (era 5)
                'maxProcesses' => 20,       // ✅ Muchos procesos máximos (era 15)
                'tries' => 4,               // ✅ Reintentos por rate limiting
                'timeout' => 300,           // ✅ 5 minutos por análisis
                'memory' => 256,
                'sleep' => 2,               // ✅ Menos sleep (era 3) pero respetando Azure
                'maxJobs' => 150,           // ✅ Más jobs antes de restart (era 100)
                'rest' => 15,               // ✅ Menos descanso (era 30)
            ],

            // ✅ COLA ZIP-ANALYSIS - Análisis de ZIPs grandes
            'supervisor-zip-analysis' => [
                'connection' => 'redis',
                'queue' => ['zip-analysis'],
                'balance' => 'simple',
                'processes' => 3,           // ✅ Más procesos (era 2)
                'tries' => 2,
                'timeout' => 3600,          // 1 hora
                'memory' => 1024,           // ✅ Más memoria (era 512)
                'sleep' => 5,
                'maxJobs' => 15,            // ✅ Más jobs (era 10)
                'rest' => 30,               // ✅ Menos descanso (era 60)
            ],

            // ✅ COLA DOWNLOADS - Generación de ZIPs de descarga
            'supervisor-downloads' => [
                'connection' => 'redis',
                'queue' => ['downloads'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'size',
                'minProcesses' => 2,
                'maxProcesses' => 4,        // ✅ Más procesos máximos (era 2)
                'tries' => 1,               // Solo 1 intento - control manual
                'timeout' => 14400,         // 4 horas
                'memory' => 2048,           // 2GB por worker
                'sleep' => 5,
                'maxJobs' => 8,             // ✅ Más jobs (era 5)
                'rest' => 300,              // ✅ Menos descanso (era 600)
            ],

            // ✅ COLA REPORTS - Generación de reportes PDF
            'supervisor-reports' => [
                'connection' => 'redis',
                'queue' => ['reports'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 1,
                'maxProcesses' => 3,        // ✅ Más procesos (era 2)
                'tries' => 1,
                'timeout' => 14400,         // 4 horas
                'memory' => 2048,           // 2GB
                'sleep' => 10,
                'maxJobs' => 5,             // ✅ Más jobs (era 3)
                'rest' => 600,              // ✅ Menos descanso (era 1200)
            ],

            // ✅ NUEVA COLA: HIGH-PRIORITY - Para tareas urgentes
            'supervisor-high-priority' => [
                'connection' => 'redis',
                'queue' => ['high-priority'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'size',
                'minProcesses' => 2,
                'maxProcesses' => 6,
                'tries' => 3,
                'timeout' => 600,           // 10 minutos
                'memory' => 512,
                'sleep' => 1,               // Muy poco sleep para urgentes
                'maxJobs' => 50,
                'rest' => 5,
            ],
        ],

        // ✅ CONFIGURACIÓN PARA DESARROLLO/STAGING
        'local' => [
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'simple',
                'processes' => 3,
                'tries' => 3,
                'timeout' => 60,
                'memory' => 128,
            ],

            'supervisor-images' => [
                'connection' => 'redis',
                'queue' => ['images'],
                'balance' => 'simple',
                'processes' => 2,
                'tries' => 3,
                'timeout' => 300,
                'memory' => 256,
            ],

            'supervisor-analysis' => [
                'connection' => 'redis',
                'queue' => ['analysis'],
                'balance' => 'simple',
                'processes' => 3,
                'tries' => 3,
                'timeout' => 180,
                'memory' => 256,
            ],
        ],
    ],
];
