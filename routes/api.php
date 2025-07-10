<?php

use App\Http\Controllers\Api\AnalysisBatchController;
use App\Http\Controllers\Api\DownloadController;
use App\Http\Controllers\Api\FolderController;
use App\Http\Controllers\Api\LargeZipController;
use App\Http\Controllers\Api\ProjectController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/login', function (Request $request) {
    $user = User::where('email', $request->email)->first();

    if (!$user || !Hash::check($request->password, $user->password)) {
        return response()->json(['message' => 'Credenciales incorrectas'], 401);
    }

    $token = $user->createToken('auth_token')->plainTextToken;

    return response()->json([
        'access_token' => $token,
        'token_type' => 'Bearer',
        'user' => $user,
    ]);
});

Route::get('/wasabi-image', [\App\Http\Controllers\Api\ImageProxyController::class, 'show']);


// Logout
Route::middleware('auth:sanctum')->post('/logout', function (Request $request) {
    $request->user()->currentAccessToken()->delete();
    return response()->json(['message' => 'Sesión cerrada']);
});
// Cancelar generación
Route::delete('/reports/{generation}/cancel', [ProjectController::class, 'cancelReportGeneration']);

Route::delete('/reports/{generationId}', [ProjectController::class, 'deleteReport']);

Route::apiResource('projects', \App\Http\Controllers\Api\ProjectController::class)->parameters(['' => 'project']);

Route::prefix('projects')->group(function () {
    Route::post('/{project}/generate-basic-structure', [\App\Http\Controllers\Api\FolderController::class, 'generateBasicStructure']);
    Route::get('{project}/check-structure', [FolderController::class, 'checkProjectStructure']);
/*    Route::get('/{project}/generate-report', [\App\Http\Controllers\Api\ProjectController::class, 'generateReport']);*/
    Route::post('/{project}/reports/generate', [ProjectController::class, 'generateReport']);
    // Estado de generación
    Route::get('/{project}/reports/status/{generation?}', [ProjectController::class, 'getReportStatus']);

    // Listar reportes del proyecto
    Route::get('/{project}/reports', [ProjectController::class, 'listReports']);

    // ✅ Verificar estado general de reportes del proyecto
    Route::get('/{project}/reports/check', [ProjectController::class, 'checkProjectReports']);

    Route::get('/health/reports', [\App\Http\Controllers\HealthController::class, 'reports']);

    Route::get('/{project}/folders', [\App\Http\Controllers\Api\FolderController::class, 'index']);
    Route::post('/{project}/folders', [\App\Http\Controllers\Api\FolderController::class, 'store']);
    Route::put('/{project}/folders/{folder}', [\App\Http\Controllers\Api\FolderController::class, 'update']);
    Route::post('/{project}/structure', [\App\Http\Controllers\Api\FolderController::class, 'storeByExcel']);
    Route::delete('/{project}/folders/{folder}/empty', [\App\Http\Controllers\Api\FolderController::class, 'empty']);
    Route::delete('/{project}/folders/{folder}', [\App\Http\Controllers\Api\FolderController::class, 'destroy']);

    Route::post('/{project}/folders/bulk-empty', [\App\Http\Controllers\Api\FolderController::class, 'emptyMultiple']);
    Route::post('/{project}/folders/bulk-delete', [\App\Http\Controllers\Api\FolderController::class, 'deleteMultiple']);

    Route::post('/{project}/images/bulk-upload', [App\Http\Controllers\Api\ImageController::class, 'uploadZipByModule']);
    Route::post('/{project}/images/zip-with-mapping', [App\Http\Controllers\Api\ImageController::class, 'uploadWithMapping']);
    Route::get('/{project}/download-images', [App\Http\Controllers\Api\ImageController::class, 'downloadImages']);

    Route::get('/{project}/processing-status', [\App\Http\Controllers\Api\ProjectController::class, 'getProcessingStatus']);


    // Upload ZIP grande
    Route::post('/{project}/upload-large-zip', [LargeZipController::class, 'uploadLargeZip']);

    // ✅ Rutas de descarga masiva
    Route::post('/{project}/downloads/start', [DownloadController::class, 'startMassiveDownload']);
    Route::get('/{project}/downloads', [DownloadController::class, 'listProjectDownloads']);

    // Limpiar análisis antiguos (opcional, para mantenimiento)
    //Route::delete('zip-analysis/cleanup', [LargeZipController::class, 'cleanupOldAnalyses']);

});

Route::get('/downloads/{batchId}/{filename}', [DownloadController::class, 'downloadFile'])
    ->where('filename', '.*') // para que funcione con rutas anidadas
    ->name('downloads.file');

// Estado del análisis
Route::get('zip-analysis/{analysisId}/status', [LargeZipController::class, 'getAnalysisStatus']);

// Procesar ZIP analizado
Route::post('zip-analysis/{analysisId}/process', [LargeZipController::class, 'processAnalyzedZip']);

Route::get('/reports/{id}/download/{file?}', [ProjectController::class, 'downloadReport'])
    ->name('reports.download');

// Limpiar reportes expirados (admin)
Route::post('/reports/cleanup', [ProjectController::class, 'cleanupExpiredReports']);

Route::prefix('folders')->group(function () {
    Route::get('/{folder}/images', [\App\Http\Controllers\Api\ImageController::class, 'index']);
    Route::post('/{folder}/images/upload', [\App\Http\Controllers\Api\ImageController::class, 'upload']);
});

Route::get('/downloads/{batchId}/status', [\App\Http\Controllers\Api\DownloadController::class, 'getDownloadStatus']);


Route::get('/images/{image}/processed', [\App\Http\Controllers\Api\ProcessedImageController::class, 'show']);
Route::get('/images/{image}/analysis', [\App\Http\Controllers\Api\ImageAnalysisResultController::class, 'show']);
Route::post('/images/{image}/process', [\App\Http\Controllers\Api\ProcessedImageController::class, 'process']);
Route::post('/images/{project}/bulk-process', [\App\Http\Controllers\Api\ProcessedImageController::class, 'processBulk']);

Route::get('/images/{image}/base64', [App\Http\Controllers\Api\ImageController::class, 'base64']);
Route::post('/images/{image}/manual-crop', [App\Http\Controllers\Api\ImageController::class, 'manualCrop']);
Route::post('/images/{image}/manual-errors', [App\Http\Controllers\Api\ImageController::class, 'saveManualErrors']);
Route::post('/images/{image}/status-processed', [App\Http\Controllers\Api\ImageController::class, 'imageProcessedStatus']);
Route::post('/images/{image}/status-analysis', [App\Http\Controllers\Api\ImageController::class, 'imageAnalysisStatus']);

Route::get('/projects/{project}/processing-status', [AnalysisBatchController::class, 'processingStatus']);
Route::get('/projects/{project}/processing-status-image', [AnalysisBatchController::class, 'processingStatusImage']);

// ✅ Nuevas rutas para manejo de batches colgados
Route::get('/projects/{project}/batches', [AnalysisBatchController::class, 'getProjectBatches']);
Route::post('/projects/{project}/retry-pending-images', [AnalysisBatchController::class, 'retryPendingImages']);
Route::post('/projects/{project}/retry-pending-analysis', [AnalysisBatchController::class, 'retryPendingAnalysis']);
Route::post('/projects/{project}/force-clean', [AnalysisBatchController::class, 'forceCleanProject']); // ✅ NUEVO

// Rutas para batches específicos - con parámetro de tipo
Route::get('/batches/{batchId}/details/{type}', [AnalysisBatchController::class, 'getBatchDetails']);
Route::put('/batches/{batchId}/force-complete/{type}', [AnalysisBatchController::class, 'forceCompleteBatch']);
Route::put('/batches/{batchId}/cancel/{type}', [AnalysisBatchController::class, 'cancelBatch']);

// Limpieza general
Route::post('/batches/cleanup', [AnalysisBatchController::class, 'cleanupOldBatches']);
