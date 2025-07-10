<?php

namespace App\Listeners;

use App\Events\ReportGenerationCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class LogReportMetrics
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(ReportGenerationCompleted $event)
    {
        $report = $event->reportGeneration;
        $duration = $report->completed_at->diffInSeconds($report->created_at);

        Log::info('Reporte completado', [
            'project_id' => $report->project_id,
            'total_images' => $report->total_images,
            'duration_seconds' => $duration,
            'images_per_second' => $report->total_images / $duration,
        ]);
    }
}
