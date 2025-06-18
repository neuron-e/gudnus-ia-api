<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalysisBatch;
use App\Models\ImageBatch;
use App\Models\Project;

class AnalysisBatchController extends Controller
{
    public function processingStatus(Project $project)
    {
        $batch = AnalysisBatch::where('project_id', $project->id)
            ->where('status', 'processing')
            ->latest()
            ->first();

        if (!$batch) {
            return response()->json([
                'processing' => false,
                'progress' => 0
            ]);
        }

        $progress = $batch->total_images > 0
            ? round(($batch->processed_images / $batch->total_images) * 100)
            : 0;

        // ✅ Marcar como completado si ha terminado
        if ($progress === 100) {
            $batch->update(['status' => 'completed']);
        }

        return response()->json([
            'processing' => $progress < 100,
            'progress' => $progress,
            'processed' => $batch->processed_images,
            'total' => $batch->total_images,
            'batch_id' => $batch->id
        ]);
    }


    public function processingStatusImage(Project $project)
    {
        $batch = ImageBatch::where('project_id', $project->id)
            ->where('status', 'processing')
            ->latest()
            ->first();

        if (!$batch) {
            return response()->json([
                'processing' => false,
                'progress' => 0
            ]);
        }

        $totalDone = $batch->processed + $batch->errors;
        $progress = $batch->total > 0
            ? round(($totalDone / $batch->total) * 100)
            : 0;

        // ✅ Marcar como completado si ha terminado
        if ($progress === 100) {
            $batch->update(['status' => 'completed']);
        }

        return response()->json([
            'processing' => $progress < 100,
            'progress' => $progress,
            'processed' => $batch->processed,
            'total' => $batch->total,
            'batch_id' => $batch->id
        ]);
    }

}
