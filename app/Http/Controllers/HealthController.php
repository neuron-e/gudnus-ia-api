<?php

namespace App\Http\Controllers;

use App\Models\ReportGeneration;
use Illuminate\Http\Request;

class HealthController extends Controller
{
    public function reports()
    {
        $processingCount = ReportGeneration::where('status', 'processing')->count();
        $oldProcessing = ReportGeneration::where('status', 'processing')
            ->where('created_at', '<', now()->subHours(2))
            ->count();

        $health = [
            'status' => 'healthy',
            'processing_reports' => $processingCount,
            'stuck_reports' => $oldProcessing,
            'timestamp' => now(),
        ];

        if ($oldProcessing > 0) {
            $health['status'] = 'warning';
            $health['message'] = "Hay {$oldProcessing} reportes que llevan más de 2 horas procesando";
        }

        return response()->json($health);
    }
}
