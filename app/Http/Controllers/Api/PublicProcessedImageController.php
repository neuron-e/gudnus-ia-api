<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\HandleZipMappingJob;
use App\Models\AnalysisBatch;
use App\Models\Folder;
use App\Models\Image;
use App\Models\ImageAnalysisResult;
use App\Models\ImageBatch;
use App\Models\ProcessedImage;
use App\Models\Project;
use App\Services\ImageProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Geometry\Rectangle;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Typography\Font;
use Symfony\Component\HttpFoundation\Response;

class PublicProcessedImageController extends Controller
{
    public function show(ProcessedImage $processedImage, Request $request)
    {
        $token = $request->query('token');
        if (!$processedImage->isPublicTokenValid($token)) {
            return response()->json(['message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json([
            'id' => $processedImage->id,
            'project' => [
                'name' => $processedImage->project?->name,
                'panel_brand' => $processedImage->project?->panel_brand,
                'panel_model' => $processedImage->project?->panel_model,
                'installation_name' => $processedImage->project?->installation_name,
                'inspector_name' => $processedImage->project?->inspector_name,
                'cell_count' => $processedImage->project?->cell_count,
                'column_count' => $processedImage->project?->column_count,
            ],
            'analysis_date'  => $processedImage->created_at?->toIso8601String(),
            'folder_path'    => $processedImage->folder_path ?? null,
            'original_url'   => $processedImage->original_url,
            'corrected_url'  => $processedImage->corrected_url,
            'metrics'        => [
                'integrity'   => data_get($processedImage, 'metrics.integrity'),
                'luminosity'  => data_get($processedImage, 'metrics.luminosity'),
                'uniformity'  => data_get($processedImage, 'metrics.uniformity'),
            ],
            'results'        => $processedImage->results ?? [],
            'errors'         => $processedImage->errors ?? [],
            'download_endpoints' => [
                'original'  => route('api.public.processed-image.download', ['processedImage' => $processedImage->id, 'type' => 'original',  'token' => $token]),
                'corrected' => route('api.public.processed-image.download', ['processedImage' => $processedImage->id, 'type' => 'corrected', 'token' => $token]),
                'metadata'  => route('api.public.processed-image.download', ['processedImage' => $processedImage->id, 'type' => 'metadata',  'token' => $token]),
                'results'   => route('api.public.processed-image.download', ['processedImage' => $processedImage->id, 'type' => 'results',   'token' => $token]),
            ],
        ]);
    }

    public function download(ProcessedImage $processedImage, Request $request)
    {
        $token = $request->query('token');
        $type  = $request->query('type');

        if (!$processedImage->isPublicTokenValid($token)) {
            return response()->json(['message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        switch ($type) {
            case 'original':
                if ($processedImage->original_url) return redirect($processedImage->original_url);
                break;
            case 'corrected':
                if ($processedImage->corrected_url) return redirect($processedImage->corrected_url);
                break;
            case 'metadata':
                return response()->json($processedImage->metadata ?? []);
            case 'results':
                return response()->json([
                    'errors'   => $processedImage->errors ?? [],
                    'metrics'  => $processedImage->metrics ?? [],
                    'results'  => $processedImage->results ?? [],
                ]);
        }

        return response()->json(['message' => 'Bad Request'], Response::HTTP_BAD_REQUEST);
    }
}
