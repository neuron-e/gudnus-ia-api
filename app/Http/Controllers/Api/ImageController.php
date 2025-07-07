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

class ImageController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Folder $folder)
    {
        return $folder->images;
    }

    public function imageProcessedStatus(Image $image)
    {
        return response()->json([
            'processed' => $image->is_processed
        ]);
    }

    public function imageAnalysisStatus(Image $image)
    {
        return response()->json([
            'processed' => $image->is_processed
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function upload(Request $request, Folder $folder)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpg,jpeg,png,bmp'
        ]);

        // Eliminar imagen existente si la hay
        foreach ($folder->images as $existing) {
            if (Storage::disk('wasabi')->exists($existing->original_path)) {
                Storage::disk('wasabi')->delete($existing->original_path);
            }
            $existing->delete();
        }

        // Guardar imagen original
        $path = $request->file('image')->store("projects/{$folder->project_id}/images", 'wasabi');

        $image = Image::create([
            'folder_id' => $folder->id,
            'project_id' => $folder->project_id,
            'filename' => basename($path),
            'original_path' => $path,
        ]);

        // Procesar imagen sin usar job
        $processedImage = app(\App\Services\ImageProcessingService::class)->process($image);

        // 🔴 Verificar si hubo error
        if (!$processedImage || $processedImage->status === 'error') {
            return response()->json([
                'ok' => false,
                'image' => $image->fresh(['processedImage', 'analysisResult']),
                'msg' => 'La imagen fue subida pero no pudo procesarse automáticamente. Puedes usar el recorte manual.',
                'error' => 'processing_failed'
            ], 200); // 👈 status 200 porque la subida fue OK, aunque no se procesó
        }

        // ✅ Imagen procesada correctamente
        return response()->json([
            'ok' => true,
            'image' => $processedImage,
            'msg' => 'Imagen subida y procesada correctamente.',
            'error' => null,
        ]);
    }

    public function generateAnalyzedImage($correctedPath, $aiResponseJson): string|null
    {
        $wasabi = Storage::disk('wasabi');
        if (!$wasabi->exists($correctedPath)) return null;

        $imageData = $wasabi->get($correctedPath);
        $manager = new ImageManager(new ImagickDriver());
        /** @var \Intervention\Image\Image $image */
        $image = $manager->read($imageData);

        // === Interpretar JSON ===
        $parsed = json_decode($aiResponseJson, true);
        if (!$parsed) return null;

        $predictions = $parsed['final'] ?? ($parsed['predictions'] ?? []);
        $minProbability = $parsed['minProbability'] ?? 0.5;

        // === Colores por tipo ===
        $errorColors = [
            'Intensidad' => '#FFA500',
            'Fingers' => '#00BFFF',
            'Black Edges' => '#333333',
            'Microgrietas' => '#FF0000',
        ];

        foreach ($predictions as $prediction) {
            // Saltar si la probabilidad es menor
            if (isset($prediction['probability']) && $prediction['probability'] < $minProbability) {
                continue;
            }

            $box = $prediction['boundingBox'];
            $left = (int) ($box['left'] * $image->width());
            $top = (int) ($box['top'] * $image->height());
            $width = (int) ($box['width'] * $image->width());
            $height = (int) ($box['height'] * $image->height());

            $tag = $prediction['tagName'] ?? '';
            $color = $errorColors[$tag] ?? '#FFFFFF';
            $label = sprintf('%s (%.1f%%)', $tag, $prediction['probability'] * 100);

            // Rectángulo
            $rectangle = new Rectangle($width, $height);
            $rectangle->setBackgroundColor('transparent');
            $rectangle->setBorder($color, 2);
            $image->drawRectangle($left, $top, $rectangle);

            // Texto: blanco, más pequeño
            $font = new Font(resource_path('fonts/Inter_24pt-Regular.ttf'));
            $font->setColor('#FFFFFF');
            $font->setSize(14);
            $image->text($label, $left, $top - 12, $font);
        }

        $tmpPath = storage_path('app/tmp/' . uniqid('analyzed_') . '.jpg');
        $image->toJpeg()->save($tmpPath, 90);

        return $tmpPath;
    }

    private function getFolderPathForZip($folder, $foldersById): string
    {
        $path = [];

        while ($folder) {
            $name = str_replace(['/', '\\'], '-', $folder->name); // evitar conflictos en el ZIP
            $path[] = $name;
            $folder = $foldersById[$folder->parent_id] ?? null;
        }

        return implode('/', array_reverse($path));
    }


    public function downloadImages(Project $project, Request $request)
    {
        $type = $request->query('type'); // original | processed | analyzed | all

        $images = Image::with(['processedImage', 'folder'])
            ->whereHas('folder', fn($q) => $q->where('project_id', $project->id))
            ->get();

        $folders = Folder::where('project_id', $project->id)->get()->keyBy('id');

        $root = Str::slug($project->name, '_');

        if ($images->isEmpty()) {
            return response()->json(['error' => 'No hay imágenes para exportar'], 404);
        }

        $zipName = "export_images_project_{$project->id}_" . now()->format('Ymd_His') . ".zip";
        $zipPath = storage_path("app/tmp/$zipName");

        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['error' => 'No se pudo crear el archivo ZIP'], 500);
        }

        $wasabi = Storage::disk('wasabi');

        foreach ($images as $img) {
            $folderPath = $this->getFolderPathForZip($img->folder, $folders);
            $baseName = pathinfo($img->filename ?? 'image', PATHINFO_FILENAME) ?: 'imagen';

            if (in_array($type, ['original', 'all']) && $wasabi->exists($img->original_path)) {
                $filename = "{$baseName}_original.jpg";
                $zip->addFromString("{$root}/{$folderPath}/original/{$filename}", $wasabi->get($img->original_path));
            }

            if ($img->processedImage && $wasabi->exists($img->processedImage->corrected_path)) {
                if (in_array($type, ['processed', 'all'])) {
                    $filename = "{$baseName}_processed.jpg";
                    $zip->addFromString("{$root}/{$folderPath}/processed/{$filename}", $wasabi->get($img->processedImage->corrected_path));
                }

                // Analyzed (solo si hay JSON)
                if (in_array($type, ['analyzed', 'all']) && $img->processedImage->ai_response_json) {
                    $jsonToUse = $img->processedImage->error_edits_json ?: $img->processedImage->ai_response_json;

                    $analyzedImagePath = $this->generateAnalyzedImage(
                        $img->processedImage->corrected_path,
                        $jsonToUse
                    );

                    if ($analyzedImagePath && file_exists($analyzedImagePath)) {
                        $filename = "{$baseName}_analyzed.jpg";
                        $zip->addFile($analyzedImagePath, "{$root}/{$folderPath}/analyzed/{$filename}");
                    }
                }
            }
        }

        $zip->close();

        // Borrar archivos temporales analizados
        $files = glob(storage_path('app/tmp/analyzed_*.jpg'));
        foreach ($files as $file) {
            @unlink($file);
        }


        return response()->download($zipPath)->deleteFileAfterSend(true);
    }


    public function uploadZipByModule(Request $request, Project $project)
    {
        $request->validate([
            'zip' => 'required|file|mimes:zip,rar',
        ]);

        $file = $request->file('zip');
        $ext = strtolower($file->getClientOriginalExtension());
        $path = storage_path("app/temp_zip_{$project->id}");
        if (File::exists($path)) File::deleteDirectory($path);
        File::makeDirectory($path, 0755, true);

        if ($ext === 'zip') {
            $zip = new \ZipArchive;
            if ($zip->open($file->getPathname()) !== true) {
                return response()->json(['error' => 'No se pudo abrir el ZIP'], 500);
            }
            $zip->extractTo($path);
            $zip->close();
        } elseif ($ext === 'rar') {
            $cmd = "unrar x -y \"{$file->getPathname()}\" \"{$path}\"";
            exec($cmd, $output, $code);
            if ($code !== 0) return response()->json(['error' => 'No se pudo extraer el RAR'], 500);
        }

        $modules = Folder::where('project_id', $project->id)->where('type', 'modulo')->with('images')->get();
        $matched = 0;
        $notMatched = 0;

        foreach ($modules as $module) {
            $imgPath = collect(File::allFiles($path))
                ->first(fn($f) => Str::of($f->getFilenameWithoutExtension())->trim()->lower() === Str::of($module->name)->trim()->lower());

            if (!$imgPath) {
                $notMatched++;
                continue;
            }

            foreach ($module->images as $oldImg) {
                if (Storage::disk('wasabi')->exists($oldImg->original_path)) {
                    Storage::disk('wasabi')->delete($oldImg->original_path);
                }
                $oldImg->delete();
            }

            $stored = Storage::disk('wasabi')->putFile("projects/{$project->id}/images", $imgPath);

            $image = Image::create([
                'folder_id' => $module->id,
                'project_id' => $project->id,
                'filename' => basename($stored),
                'original_path' => $stored,
            ]);

            dispatch(new \App\Jobs\ProcessImageImmediatelyJob($image->id));
            $matched++;
        }

        File::deleteDirectory($path);

        return response()->json([
            'ok' => true,
            'msg' => "Se asignaron $matched imágenes a módulos. $notMatched quedaron sin asignar."
        ]);
    }

    public function uploadWithMapping(Request $request, Project $project)
    {
        $request->validate([
            'zip' => 'required|file|mimes:zip',
            'mapping' => 'required|json',
        ]);

        $mapping = json_decode($request->input('mapping'), true);
        if (!is_array($mapping)) {
            return response()->json(['error' => 'Formato de mapping inválido'], 422);
        }

        // Preparar directorio temporal único
        $file = $request->file('zip');
        $ext = strtolower($file->getClientOriginalExtension());
        $path = storage_path("app/temp_zip_{$project->id}");
        $zipFileName = 'batch_' . uniqid() . '_' . time() . '.zip';
        //$zipStoragePath = "temp_zips/{$zipFileName}";

        // Guardar ZIP en storage persistente
        $zipPath = $file->storeAs('temp_zips', $zipFileName, 'local');
        $fullZipPath = storage_path("app/{$zipPath}");

        Log::info("📦 ZIP guardado en path persistente:", [
            'zip_path' => $fullZipPath,
            'zip_exists' => file_exists($fullZipPath),
            'zip_size' => file_exists($fullZipPath) ? filesize($fullZipPath) : 0
        ]);

        if ($ext === 'zip') {
            if (!file_exists($fullZipPath)) {
                Log::error("❌ El archivo ZIP no existe: $fullZipPath");
                return response()->json(['error' => 'ZIP no encontrado'], 500);
            }

            $zip = new \ZipArchive;
            if ($zip->open($fullZipPath) !== true) {
                return response()->json(['error' => 'No se pudo abrir el ZIP guardado'], 500);
            }
            $zip->extractTo($path);
            $zip->close();
        } elseif ($ext === 'rar') {
            if (!file_exists($fullZipPath)) {
                Log::error("❌ El archivo RAR no existe: $fullZipPath");
                return response()->json(['error' => 'RAR no encontrado'], 500);
            }

            $cmd = "unrar x -y \"$fullZipPath\" \"$path\"";
            exec($cmd, $output, $code);

            if ($code !== 0) {
                Log::error("❌ Fallo al extraer RAR", [
                    'cmd' => $cmd,
                    'code' => $code,
                    'output' => $output
                ]);
                return response()->json(['error' => 'No se pudo extraer el RAR'], 500);
            }
        }

        $batch = ImageBatch::create([
            'project_id' => $project->id,
            'type' => 'zip-mapping',
            'total' => count($mapping),
            'status' => 'processing',
        ]);

        dispatch(new HandleZipMappingJob($project->id, $mapping, $path, $batch->id));

        return response()->json([
            'ok' => true,
            'msg' => 'ZIP recibido correctamente. Se está procesando en segundo plano...',
            'batch_id' => $batch->id,
        ]);
    }

    public function manualCrop(Request $request, Image $image)
    {
        $data = $request->validate([
            'points' => 'required|array|size:4',
            'points.*' => 'array|size:2'
        ]);

        $wasabiDisk = Storage::disk('wasabi');
        if (!$wasabiDisk->exists($image->original_path)) {
            return response()->json(['error' => 'Imagen no encontrada'], 404);
        }

        $tempDir = storage_path("app/temp_crop_{$image->id}");
        File::makeDirectory($tempDir, 0755, true);

        $tempInput = "$tempDir/input.jpg";
        File::put($tempInput, $wasabiDisk->get($image->original_path));

        $filename = 'manual_' . Str::random(8) . '.jpg';
        $relativeProcessed = "projects/{$image->project_id}/images/processed/{$filename}";
        $outputPath = "$tempDir/output.jpg";

        $pointsArg = implode(',', array_map(fn($p) => implode('_', $p), $data['points']));

        $pythonPath = env('PYTHON_PATH', 'python3');
        $scriptPath = storage_path('app/scripts/manual_crop_transform.py');
        $cmd = "\"$pythonPath\" \"$scriptPath\" \"$tempInput\" \"$outputPath\" \"$pointsArg\"";

        exec($cmd, $output, $returnCode);
        $json = json_decode(implode('', $output), true);

        if ($returnCode !== 0 || json_last_error() !== JSON_ERROR_NONE || isset($json['error'])) {
            Log::error("❌ Recorte manual fallido", ['output' => $output]);
            return response()->json(['error' => 'Recorte manual fallido', 'output' => $output], 500);
        }

        $wasabiDisk->put($relativeProcessed, File::get($outputPath));
        File::deleteDirectory($tempDir);

        $processed = $image->processedImage ?? new ProcessedImage();
        $processed->corrected_path = $relativeProcessed;
        $image->processedImage()->save($processed);

        $image->update(['status' => 'processed']);

        return response()->json(['ok' => true,  'image' => $image->fresh(['processedImage'])]); // ⬅ devuelve con relación actualizada;
    }

    public function saveManualErrors(Request $request, $imageId)
    {
        $request->validate([
            'edits' => 'required|array',
        ]);

        $image = Image::with('processedImage')->findOrFail($imageId);

        if (!$image->processedImage) {
            return response()->json(['message' => 'No hay imagen procesada'], 400);
        }

        $image->processedImage->error_edits_json = $request->input('edits');
        $image->processedImage->save();

        return response()->json(['message' => 'Errores manuales guardados correctamente']);
    }


    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function base64(Image $image)
    {
        $wasabiDisk = Storage::disk('wasabi');

        if (!$image->processedImage || !$wasabiDisk->exists($image->processedImage->corrected_path)) {
            return response()->json(['error' => 'Imagen no disponible'], 404);
        }

        $tempPath = storage_path('app/temp_base64_' . $image->id . '.jpg');
        File::put($tempPath, $wasabiDisk->get($image->processedImage->corrected_path));
        $type = pathinfo($tempPath, PATHINFO_EXTENSION);
        $data = file_get_contents($tempPath);
        unlink($tempPath);

        $base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);

        return response()->json(['base64' => $base64]);
    }
}
