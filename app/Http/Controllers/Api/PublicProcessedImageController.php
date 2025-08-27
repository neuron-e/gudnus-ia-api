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

        Log::info('Public processed image viewed', [
            'processed_image_id' => $processedImage->id,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $processedImage->loadMissing('image.project');
        $project = $processedImage->image?->project;

        return response()->json([
            'id' => $processedImage->id,
            'project' => $project ? [
                'name' => $project->name,
                'panel_brand' => $project->panel_brand,
                'panel_model' => $project->panel_model,
                'installation_name' => $project->installation_name,
                'inspector_name' => $project->inspector_name,
                'cell_count' => $project->cell_count,
                'column_count' => $project->column_count,
            ] : null,
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
        ])->withHeaders([
            'Cache-Control' => 'private, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function download(ProcessedImage $processedImage, Request $request)
    {
        $token = $request->query('token');
        $type  = $request->query('type');

        if (!$processedImage->isPublicTokenValid($token)) {
            return response()->json(['message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        Log::info('Public processed image download', [
            'processed_image_id' => $processedImage->id,
            'type' => $type,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        switch ($type) {
            case 'metadata':
                return response()->json($processedImage->metadata ?? []);
            case 'results':
                return response()->json([
                    'errors'   => $processedImage->errors ?? [],
                    'metrics'  => $processedImage->metrics ?? [],
                    'results'  => $processedImage->results ?? [],
                ]);
            case 'original':
                $path = $processedImage->original_path
                    ?: ltrim(parse_url($processedImage->original_url ?? '', PHP_URL_PATH), '/');
                break;
            case 'corrected':
                $path = $processedImage->corrected_path
                    ?: ltrim(parse_url($processedImage->corrected_url ?? '', PHP_URL_PATH), '/');
                break;
            default:
                return response()->json(['message' => 'Bad Request'], Response::HTTP_BAD_REQUEST);
        }

        if ($path) {
            $disk = Storage::disk('s3')->exists($path) ? 's3' : 'wasabi';
            $tmp  = Storage::disk($disk)->temporaryUrl($path, now()->addMinutes(15));
            return redirect($tmp);
        }

        return response()->json(['message' => 'Bad Request'], Response::HTTP_BAD_REQUEST);
    }
}
