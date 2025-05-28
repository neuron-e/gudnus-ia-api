<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Folder;
use App\Models\Image;
use App\Models\ImageAnalysisResult;
use App\Models\ImageBatch;
use App\Models\ProcessedImage;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Folder $folder)
    {
        return $folder->images;
    }

    /**
     * Store a newly created resource in storage.
     */
    public function upload(Request $request, Folder $folder)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpg,jpeg,png,bmp'
        ]);

        // 🔁 Eliminar imagen existente (BD y storage)
        foreach ($folder->images as $existing) {
            if (Storage::disk('public')->exists($existing->original_path)) {
                Storage::disk('public')->delete($existing->original_path);
            }
            $existing->delete();
        }

        // 📥 Subir imagen original
        $path = $request->file('image')->store("projects/{$folder->project_id}/images", 'public');

        $image = Image::create([
            'folder_id' => $folder->id,
            'project_id' => $folder->project_id,
            'filename' => basename($path),
            'original_path' => $path,
        ]);

        // Procesar recorte
        dispatch(new \App\Jobs\ProcessImageImmediatelyJob($image->id, null));

        return response()->json([
            'ok' => true,
            'image' => $image,
            'msg' => 'Imagen subida y encolada para procesado.',
            'error' => null,
        ]);
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
            $rarPath = $file->getPathname();
            $cmd = "unrar x -y \"$rarPath\" \"$path\"";
            exec($cmd, $output, $code);
            if ($code !== 0) {
                return response()->json(['error' => 'No se pudo extraer el RAR'], 500);
            }
        }

        $modules = Folder::where('project_id', $project->id)
            ->where('type', 'modulo')
            ->with('images')
            ->get();

        $matched = 0;
        $notMatched = 0;

        foreach ($modules as $module) {
            $imgPath = collect(File::allFiles($path))
                ->first(fn($f) => Str::of($f->getFilenameWithoutExtension())->trim()->lower() === Str::of($module->name)->trim()->lower());

            if (!$imgPath) {
                $notMatched++;
                continue;
            }

            // Borrar imagen anterior
            foreach ($module->images as $oldImg) {
                if (Storage::disk('public')->exists($oldImg->original_path)) {
                    Storage::disk('public')->delete($oldImg->original_path);
                }
                $oldImg->delete();
            }

            // Guardar nueva
            $stored = Storage::disk('public')->putFile("projects/{$project->id}/images", $imgPath);

            $image = Image::create([
                'folder_id' => $module->id,
                'project_id' => $project->id,
                'filename' => basename($stored),
                'original_path' => $stored,
            ]);

            // Procesar recorte
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
            $rarPath = $file->getPathname();
            $cmd = "unrar x -y \"$rarPath\" \"$path\"";
            exec($cmd, $output, $code);
            if ($code !== 0) {
                return response()->json(['error' => 'No se pudo extraer el RAR'], 500);
            }
        }

        $asignadas = 0;
        $errors = [];

        $batch = ImageBatch::create([
            'project_id' => $project->id,
            'type' => 'zip-mapping',
            'total' => count($mapping),
            'status' => 'processing',
        ]);


        foreach ($mapping as $asignacion) {
            $nombreImagen = basename($asignacion['imagen']);
            $moduloPath = trim($asignacion['modulo']);

            $fullImagePath = $path . '/' . $nombreImagen;
            if (!file_exists($fullImagePath)) {
                $errors[] = "No se encontró la imagen: $nombreImagen";
                continue;
            }

            $folder = Folder::where('project_id', $project->id)
                ->where('full_path', $moduloPath)
                ->first();

            if (!$folder) {
                $errors[] = "No se encontró el módulo para: $moduloPath";
                continue;
            }

            try {
                $image = $folder->storeImage(file_get_contents($fullImagePath), $nombreImagen);
                dispatch(new \App\Jobs\ProcessImageImmediatelyJob($image->id, $batch->id));
                $asignadas++;
            } catch (\Exception $e) {
                Log::error("Error asignando imagen $nombreImagen al módulo $moduloPath: " . $e->getMessage());
                $batch->increment('errors');
                $batch->update(['error_messages' => array_merge($batch->error_messages ?? [], [$e->getMessage()])]);
                $errors[] = "Error interno al asignar imagen: $nombreImagen";
            }
        }

        File::deleteDirectory($path);

        return response()->json([
            'ok' => true,
            'msg' => "Se asignaron $asignadas imágenes." . (count($errors) ? " Errores: " . count($errors) : ""),
            'errores' => $errors,
        ]);
    }

    public function manualCrop(Request $request, Image $image)
    {
        $data = $request->validate([
            'points' => 'required|array|size:4',
            'points.*' => 'array|size:2'
        ]);

        if (!Storage::disk('public')->exists($image->original_path)) {
            return response()->json(['error' => 'Imagen no encontrada'], 404);
        }

        $inputPath = storage_path("app/public/{$image->original_path}");
        $filename = 'manual_' . Str::random(8) . '.jpg';
        $relativeProcessed = "projects/{$image->project_id}/images/processed/{$filename}";
        $outputPath = storage_path("app/public/{$relativeProcessed}");

        $pointsArg = implode(',', array_map(fn($p) => implode('_', $p), $data['points']));

        $pythonPath = env('PYTHON_PATH', 'python3');
        $scriptPath = storage_path('app/scripts/manual_crop_transform.py');
        $cmd = "\"$pythonPath\" \"$scriptPath\" \"$inputPath\" \"$outputPath\" \"$pointsArg\"";

        exec($cmd, $output, $returnCode);
        $json = json_decode(implode('', $output), true);

        if ($returnCode !== 0 || json_last_error() !== JSON_ERROR_NONE || isset($json['error'])) {
            Log::error("❌ Recorte manual fallido", ['output' => $output]);
            return response()->json(['error' => 'Recorte manual fallido', 'output' => $output], 500);
        }

        // Guardar resultado
        $processed = $image->processedImage ?? new ProcessedImage();
        $processed->corrected_path = $relativeProcessed;
        $image->processedImage()->save($processed);

        $image->update(['status' => 'processed']);

        return response()->json(['ok' => true, 'path' => $relativeProcessed]);
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
        if (!$image->processedImage || !Storage::disk('public')->exists($image->processedImage->corrected_path)) {
            return response()->json(['error' => 'Imagen no disponible'], 404);
        }

        $path = storage_path('app/public/' . $image->processedImage->corrected_path);
        $type = pathinfo($path, PATHINFO_EXTENSION);
        $data = file_get_contents($path);
        $base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);

        return response()->json(['base64' => $base64]);
    }
}
