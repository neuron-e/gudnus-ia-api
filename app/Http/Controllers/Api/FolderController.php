<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Folder;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;

class FolderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Project $project)
    {
        // Lista de carpetas raíz de un proyecto
        return Folder::where('project_id', $project->id)
            ->whereNull('parent_id')
            ->with('children') // eager load para jerarquía
            ->get();
    }


    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Project $project)
    {
        $validated = $request->validate([
            'name' => ['required', 'string',
                Rule::unique('folders')->where(fn ($q) =>
                $q->where('parent_id', $request->parent_id)
                    ->where('type', $request->type)
                )
            ],
            'type' => 'required|in:CT,inversor,CB,tracker,string,modulo',
            'parent_id' => 'nullable|exists:folders,id',
        ]);

        $folder = Folder::create([
            'project_id' => $project->id,
            'name' => $validated['name'],
            'type' => $validated['type'],
            'parent_id' => $validated['parent_id'] ?? null,
        ]);

        return response()->json($folder, 201);
    }

    public function storeByExcel(Request $request, Project $project)
    {
        $request->validate([
            'excel' => 'required|file|mimes:xlsx,csv,xls'
        ]);

        // 🧹 1. Eliminar estructura previa
        Folder::where('project_id', $project->id)->delete();
        Log::info("🧨 Estructura del proyecto {$project->id} eliminada");

        // 🧾 2. Leer Excel
        $spreadsheet = IOFactory::load($request->file('excel')->getPathName());
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        // Eliminar la primera fila si es cabecera
        if (isset($rows[1])) {
            unset($rows[1]);
        }

        $createdCount = 0;
        $skippedCount = 0;
        $ignoredRows = 0;

        // Mapa para rastrear carpetas ya creadas y evitar duplicación
        $folderMap = [
            'ct' => [],        // CT -> []
            'inversor' => [],  // CT -> INV -> []
            'cb' => [],        // CT -> [INV ->] CB -> []
            'tracker' => [],   // CT -> [INV ->] CB -> TRK -> []
            'string' => [],    // CT -> [INV ->] CB -> TRK -> STR -> []
            'modulo' => []     // CT -> [INV ->] CB -> TRK -> [STR ->] MOD -> []
        ];

        foreach ($rows as $index => $row) {
            // Saltar fila completamente vacía
            if (empty(array_filter($row))) {
                Log::info("🟡 Fila vacía ignorada (fila $index)");
                continue;
            }

            // Limpiar y preparar los valores
            $ct = !empty(trim($row['A'])) ? trim($row['A']) : null;
            $inv = !empty(trim($row['B'])) ? trim($row['B']) : null;
            $cb = !empty(trim($row['C'])) ? trim($row['C']) : null;
            $tracker = !empty(trim($row['D'])) ? trim($row['D']) : null;
            $string = !empty(trim($row['E'])) ? trim($row['E']) : null;
            $modulo = !empty(trim($row['F'])) ? trim($row['F']) : null;

            // Verificar que al menos hay CT y CB para formar una estructura mínima
            if (!$ct || !$cb) {
                Log::warning("❌ Fila $index ignorada: Faltan CT y/o CB");
                $ignoredRows++;
                continue;
            }

            // Nivel CT (siempre es el nivel raíz)
            $ctName = "CT $ct";
            $ctKey = "ct_$ct";
            $ctParentId = null; // CT no tiene padre
            $ctId = $this->getOrCreateFolder($folderMap, 'ct', $ctKey, $ctName, 'ct', $project->id, $ctParentId, $createdCount, $skippedCount);

            // Nivel INV (opcional)
            $invParentId = $ctId;
            $invId = null;
            if ($inv) {
                $invName = "INV $inv";
                $invKey = "ct_{$ct}_inv_{$inv}";
                $invId = $this->getOrCreateFolder($folderMap, 'inversor', $invKey, $invName, 'inversor', $project->id, $invParentId, $createdCount, $skippedCount);
                $cbParentId = $invId; // CB tendrá como padre al INV
            } else {
                $cbParentId = $ctId; // Si no hay INV, CB tendrá como padre al CT
            }

            // Nivel CB (obligatorio)
            $cbName = "CB $cb";
            $cbKey = $inv ? "ct_{$ct}_inv_{$inv}_cb_{$cb}" : "ct_{$ct}_cb_{$cb}";
            $cbId = $this->getOrCreateFolder($folderMap, 'cb', $cbKey, $cbName, 'cb', $project->id, $cbParentId, $createdCount, $skippedCount);

            // Nivel TRK (obligatorio para módulos)
            if (!$tracker) {
                Log::warning("❌ Fila $index ignorada: Falta Tracker");
                $ignoredRows++;
                continue;
            }

            $trkName = "TRK $tracker";
            $trkKey = $inv
                ? "ct_{$ct}_inv_{$inv}_cb_{$cb}_trk_{$tracker}"
                : "ct_{$ct}_cb_{$cb}_trk_{$tracker}";
            $trkId = $this->getOrCreateFolder($folderMap, 'tracker', $trkKey, $trkName, 'tracker', $project->id, $cbId, $createdCount, $skippedCount);

            // Nivel STR (opcional)
            $strParentId = $trkId;
            $strId = null;
            if ($string) {
                $strName = "STR $string";
                $strKey = $inv
                    ? "ct_{$ct}_inv_{$inv}_cb_{$cb}_trk_{$tracker}_str_{$string}"
                    : "ct_{$ct}_cb_{$cb}_trk_{$tracker}_str_{$string}";
                $strId = $this->getOrCreateFolder($folderMap, 'string', $strKey, $strName, 'string', $project->id, $strParentId, $createdCount, $skippedCount);
                $modParentId = $strId; // MOD tendrá como padre al STR
            } else {
                $modParentId = $trkId; // Si no hay STR, MOD tendrá como padre al TRK
            }

            // Nivel MOD (opcional pero recomendado)
            if ($modulo) {
                $modName = "MOD $modulo";
                $modKey = $string
                    ? ($inv
                        ? "ct_{$ct}_inv_{$inv}_cb_{$cb}_trk_{$tracker}_str_{$string}_mod_{$modulo}"
                        : "ct_{$ct}_cb_{$cb}_trk_{$tracker}_str_{$string}_mod_{$modulo}")
                    : ($inv
                        ? "ct_{$ct}_inv_{$inv}_cb_{$cb}_trk_{$tracker}_mod_{$modulo}"
                        : "ct_{$ct}_cb_{$cb}_trk_{$tracker}_mod_{$modulo}");
                $this->getOrCreateFolder($folderMap, 'modulo', $modKey, $modName, 'modulo', $project->id, $modParentId, $createdCount, $skippedCount);
            }
        }

        Log::info("✅ Proceso terminado: $createdCount creadas, $skippedCount existentes, $ignoredRows filas ignoradas");

        return response()->json([
            'ok' => true,
            'message' => "Importación completada: $createdCount carpetas nuevas, $skippedCount existentes, $ignoredRows filas ignoradas.",
        ]);
    }

    /**
     * Obtiene una carpeta existente o crea una nueva
     *
     * @param array &$folderMap Mapa de carpetas para búsqueda rápida
     * @param string $level Nivel en la jerarquía (ct, inversor, cb, etc)
     * @param string $key Clave única para identificar la carpeta
     * @param string $name Nombre de la carpeta
     * @param string $type Tipo de carpeta
     * @param int $projectId ID del proyecto
     * @param int|null $parentId ID de la carpeta padre
     * @param int &$createdCount Contador de carpetas creadas
     * @param int &$skippedCount Contador de carpetas omitidas
     * @return int ID de la carpeta
     */
    private function getOrCreateFolder(&$folderMap, $level, $key, $name, $type, $projectId, $parentId, &$createdCount, &$skippedCount)
    {
        // Verificar si la carpeta ya existe en nuestro mapa
        if (isset($folderMap[$level][$key])) {
            $skippedCount++;
            return $folderMap[$level][$key];
        }

        $typeMap = [
            'ct' => 'CT',
            'cb' => 'CB',
            'inversor' => 'inversor',
            'tracker' => 'tracker',
            'string' => 'string',
            'modulo' => 'modulo',
        ];

        $type = $typeMap[$type] ?? $type;

        // Buscar en la base de datos para ver si ya existe
        $existing = Folder::where([
            'project_id' => $projectId,
            'parent_id' => $parentId,
            'name' => $name,
            'type' => $type,
        ])->first();

        if ($existing) {
            $folderMap[$level][$key] = $existing->id;
            Log::info("🔁 Ya existe: {$name} ({$type}) bajo parent_id={$parentId}");
            $skippedCount++;
            return $existing->id;
        } else {
            // Crear nueva carpeta
            $folder = Folder::create([
                'project_id' => $projectId,
                'parent_id' => $parentId,
                'name' => $name,
                'type' => $type,
            ]);

            $folderMap[$level][$key] = $folder->id;
            Log::info("📁 Creado: {$folder->name} ({$folder->type}) bajo parent_id={$parentId}");
            $createdCount++;
            return $folder->id;
        }
    }

    public function generateBasicStructure(Project $project, Request $request): \Illuminate\Http\JsonResponse
    {
        $modules = (int) $request->get('modules', 0);

        if ($modules < 1) {
            return response()->json(['error' => 'Número inválido de módulos'], 400);
        }

        $created = [];

        for ($i = 1; $i <= $modules; $i++) {
            $folder = Folder::create([
                'project_id' => $project->id,
                'parent_id' => null, // puedes ajustar si hay una jerarquía
                'name' => "Módulo {$i}",
                'type' => 'modulo', // si usas el campo type
            ]);
            $created[] = $folder;
        }

        return response()->json([
            'ok' => true,
            'created' => $created
        ]);
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
    public function update(Request $request, Project $project, Folder $folder): Folder
    {
        $validated = $request->validate([
            'name' => ['required', 'string',
                Rule::unique('folders')->ignore($folder->id)->where(fn ($q) =>
                $q->where('parent_id', $request->parent_id)
                    ->where('type', $request->type)
                )
            ]
        ]);


        $folder->update($validated);
        return $folder;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Project $project, Folder $folder)
    {
        if ($folder->project_id !== $project->id) {
            return response()->json(['error' => 'La carpeta no pertenece a este proyecto.'], 403);
        }

        $force = $request->input('force', false);

        $folder->load(['images', 'children.images', 'children.children']);

        if (!$force && ($folder->images->isNotEmpty() || $folder->hasImagesInChildren())) {
            return response()->json([
                'error' => 'La carpeta o sus subcarpetas contienen imágenes. Confirmar eliminación forzada.'
            ], 403);
        }

        $stats = [
            'folders' => 0,
            'images' => 0,
            'processed' => 0,
            'unprocessed' => 0,
        ];

        DB::transaction(function () use ($folder, &$stats) {
            $this->forceDeleteRecursively($folder, $stats);
        });

        return response()->json([
            'ok' => true,
            'deleted_folders' => $stats['folders'],
            'deleted_images' => $stats['images'],
            'processed_images' => $stats['processed'],
            'unprocessed_images' => $stats['unprocessed'],
        ]);
    }

    public function emptyMultiple(Request $request, Project $project)
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids)) {
            return response()->json(['error' => 'Formato inválido'], 422);
        }

        $stats = [
            'folders' => 0,
            'images' => 0,
            'processed' => 0,
            'unprocessed' => 0,
        ];

        DB::transaction(function () use ($ids, $project, &$stats) {
            foreach ($ids as $id) {
                $folder = Folder::with('images')
                    ->where('project_id', $project->id)->where('id', $id)->first();

                if ($folder) {
                    foreach ($folder->images as $image) {
                        if ($image->processedImage) $stats['processed']++;
                        else $stats['unprocessed']++;

                        $stats['images']++;
                        $image->delete();
                    }
                    $stats['folders']++;
                }
            }
        });

        return response()->json(['ok' => true, 'stats' => $stats]);
    }

    public function deleteMultiple(Request $request, Project $project)
    {
        $ids = $request->input('ids', []);
        $force = $request->input('force', false);

        if (!is_array($ids)) {
            return response()->json(['error' => 'Formato inválido'], 422);
        }

        $stats = [
            'folders' => 0,
            'images' => 0,
            'processed' => 0,
            'unprocessed' => 0,
        ];
        $skipped = [];
        DB::transaction(function () use ($ids, $project, $force, &$stats, &$skipped) {
            foreach ($ids as $id) {
                $folder = Folder::with(['images', 'children.images', 'children.children'])
                    ->where('project_id', $project->id)
                    ->where('id', $id)->first();

                if (!$folder) continue;

                if (!$force && ($folder->images->isNotEmpty() || $folder->hasImagesInChildren())) {
                    $skipped[] = $id;
                    continue; // Skipped folder with images
                }

                app(self::class)->forceDeleteRecursively($folder, $stats);
            }
        });

        return response()->json([
            'ok' => true,
            'stats' => $stats,
            'skipped' => $skipped,
        ]);
    }


    protected function forceDeleteRecursively(Folder $folder, array &$stats)
    {
        foreach ($folder->children as $child) {
            $this->forceDeleteRecursively($child, $stats);
        }

        foreach ($folder->images as $image) {
            // Contar
            $stats['images']++;
            if ($image->processedImage && $image->processedImage->ai_response_json !== null) {
                $stats['processed']++;
            } else {
                $stats['unprocessed']++;
            }

            // Eliminar archivos físicos si están en disco
            if (Storage::disk('wasabi')->exists($image->original_path)) {
                Storage::disk('wasabi')->delete($image->original_path);
            }
            if ($image->processedImage && Storage::disk('wasabi')->exists($image->processedImage->corrected_path)) {
                Storage::disk('wasabi')->delete($image->processedImage->corrected_path);
            }

            $image->processedImage()?->delete();
            $image->analysisResult()?->delete();
            $image->delete();
        }

        $folder->delete();
        $stats['folders']++;
    }


    public function empty(Request $request, Project $project, Folder $folder)
    {
        if ($folder->project_id !== $project->id) {
            return response()->json(['error' => 'La carpeta no pertenece a este proyecto'], 403);
        }

        DB::transaction(function () use ($folder) {
            $this->deleteImagesRecursively($folder);
        });

        return response()->json(['ok' => true]);
    }

    private function deleteImagesRecursively(Folder $folder)
    {
        foreach ($folder->images as $img) {
            // Eliminar imagen original
            if ($img->original_path && Storage::disk('wasabi')->exists($img->original_path)) {
                Storage::disk('wasabi')->delete($img->original_path);
            }
            if ($img->processedImage && $img->processedImage->corrected_path) {
                Storage::disk('wasabi')->delete($img->processedImage->corrected_path);
                $img->processedImage->delete();
            }

            // Eliminar análisis si existe
            if ($img->analysisResult) {
                $img->analysisResult->delete();
            }

            $img->delete();
        }

        foreach ($folder->children as $child) {
            $this->deleteImagesRecursively($child);
        }
    }

}
