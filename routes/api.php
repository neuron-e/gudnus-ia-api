<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

use App\Models\User;

use App\Http\Controllers\Api\{
    AnalysisBatchController,
    DownloadController,
    FolderController,
    ImageController,
    ImageProxyController,
    LargeZipController,
    ProcessedImageController,
    ProjectController,
    UnifiedBatchController,
    ImageAnalysisResultController
};

// ===========================
// 🔐 Autenticación básica
// ===========================
Route::middleware('auth:sanctum')->get('/user', fn (Request $request) => $request->user());

Route::post('/login', function (Request $request) {
    $user = User::where('email', $request->email)->first();

    if (!$user || !Hash::check($request->password, $user->password)) {
        return response()->json(['message' => 'Credenciales incorrectas'], 401);
    }

    return response()->json([
        'access_token' => $user->createToken('auth_token')->plainTextToken,
        'token_type' => 'Bearer',
        'user' => $user,
    ]);
});

Route::middleware('auth:sanctum')->post('/logout', function (Request $request) {
    $request->user()->currentAccessToken()->delete();
    return response()->json(['message' => 'Sesión cerrada']);
});

// ===========================
// 📦 Proyecto y estructura
// ===========================
Route::apiResource('projects', ProjectController::class)->parameters(['' => 'project']);

Route::prefix('projects')->group(function () {
    Route::post('/{project}/generate-basic-structure', [FolderController::class, 'generateBasicStructure']);
    Route::get('/{project}/check-structure', [FolderController::class, 'checkProjectStructure']);

    Route::post('/{project}/reports/generate', [ProjectController::class, 'generateReport']);
    Route::get('/{project}/reports/status/{generation?}', [ProjectController::class, 'getReportStatus']);
    Route::get('/{project}/reports', [ProjectController::class, 'listReports']);
    Route::get('/{project}/reports/check', [ProjectController::class, 'checkProjectReports']);

    Route::get('/health/reports', [App\Http\Controllers\HealthController::class, 'reports']);

    // Folders
    Route::get('/{project}/folders', [FolderController::class, 'index']);
    Route::post('/{project}/folders', [FolderController::class, 'store']);
    Route::put('/{project}/folders/{folder}', [FolderController::class, 'update']);
    Route::delete('/{project}/folders/{folder}/empty', [FolderController::class, 'empty']);
    Route::delete('/{project}/folders/{folder}', [FolderController::class, 'destroy']);
    Route::post('/{project}/folders/bulk-empty', [FolderController::class, 'emptyMultiple']);
    Route::post('/{project}/folders/bulk-delete', [FolderController::class, 'deleteMultiple']);
    Route::post('/{project}/structure', [FolderController::class, 'storeByExcel']);

    // Images
    Route::post('/{project}/images/bulk-upload', [ImageController::class, 'uploadZipByModule']);
    Route::post('/{project}/images/zip-with-mapping', [ImageController::class, 'uploadWithMapping']);
    Route::get('/{project}/download-images', [ImageController::class, 'downloadImages']);

    // Status
    Route::get('/{project}/processing-status', [ProjectController::class, 'getProcessingStatus']);

    // ZIPs
    Route::post('/{project}/upload-large-zip', [LargeZipController::class, 'uploadLargeZip']);
    Route::post('/{project}/downloads/start', [DownloadController::class, 'startMassiveDownload']);
    Route::get('/{project}/downloads', [DownloadController::class, 'listProjectDownloads']);
});

// ===========================
// 🔧 Unified Batches
// ===========================
Route::prefix('projects/{project}')->group(function () {
    // 📋 GESTIÓN DE BATCHES UNIFICADOS
    Route::get('/unified-batches', [UnifiedBatchController::class, 'index'])
        ->name('unified-batches.index');

    Route::post('/unified-batches', [UnifiedBatchController::class, 'store'])
        ->name('unified-batches.store');

    Route::get('/unified-batches/{batchId}', [UnifiedBatchController::class, 'show'])
        ->name('unified-batches.show');

    Route::put('/unified-batches/{batchId}/start', [UnifiedBatchController::class, 'start'])
        ->name('unified-batches.start');

    Route::put('/unified-batches/{batchId}/pause', [UnifiedBatchController::class, 'pause'])
        ->name('unified-batches.pause');

    Route::put('/unified-batches/{batchId}/resume', [UnifiedBatchController::class, 'resume'])
        ->name('unified-batches.resume');

    Route::delete('/unified-batches/{batchId}', [UnifiedBatchController::class, 'cancel'])
        ->name('unified-batches.cancel');

    // 🔄 COMPATIBILIDAD TEMPORAL con rutas legacy (MANTENER durante transición)
    Route::get('/batches', [UnifiedBatchController::class, 'legacyGetProjectBatches'])
        ->name('batches.legacy.index');

    Route::post('/retry-pending-images', [UnifiedBatchController::class, 'legacyRetryPendingImages'])
        ->name('batches.legacy.retry-images');

    Route::post('/retry-pending-analysis', [UnifiedBatchController::class, 'legacyRetryPendingAnalysis'])
        ->name('batches.legacy.retry-analysis');

    Route::post('/force-clean', [UnifiedBatchController::class, 'legacyForceCleanProject'])
        ->name('batches.legacy.force-clean');
});

Route::get('/unified-batches/diagnostic', [UnifiedBatchController::class, 'systemDiagnostic']);

// ===========================
// 📦 Analysis & Image Status
// ===========================
Route::get('/projects/{project}/processing-status', [AnalysisBatchController::class, 'processingStatus']);
Route::get('/projects/{project}/processing-status-image', [AnalysisBatchController::class, 'processingStatusImage']);

Route::get('/batches/{batchId}/details/{type}', [AnalysisBatchController::class, 'getBatchDetails']);
Route::put('/batches/{batchId}/force-complete/{type}', [AnalysisBatchController::class, 'forceCompleteBatch']);
Route::put('/batches/{batchId}/cancel/{type}', [AnalysisBatchController::class, 'cancelBatch']);
Route::post('/batches/cleanup', [AnalysisBatchController::class, 'cleanupOldBatches']);

// ===========================
// 📷 Imágenes y análisis
// ===========================
Route::prefix('folders')->group(function () {
    Route::get('/{folder}/images', [ImageController::class, 'index']);
    Route::post('/{folder}/images/upload', [ImageController::class, 'upload']);
});

Route::get('/images/{image}/processed', [ProcessedImageController::class, 'show']);
Route::get('/images/{image}/analysis', [ImageAnalysisResultController::class, 'show']);
Route::post('/images/{image}/process', [ProcessedImageController::class, 'process']);
Route::post('/images/{project}/bulk-process', [ProcessedImageController::class, 'processBulk']);

Route::get('/images/{image}/base64', [ImageController::class, 'base64']);
Route::post('/images/{image}/manual-crop', [ImageController::class, 'manualCrop']);
Route::post('/images/{image}/manual-errors', [ImageController::class, 'saveManualErrors']);
Route::post('/images/{image}/status-processed', [ImageController::class, 'imageProcessedStatus']);
Route::post('/images/{image}/status-analysis', [ImageController::class, 'imageAnalysisStatus']);

// ===========================
// 🧠 ZIP Analysis (legacy)
// ===========================
Route::get('/wasabi-image', [ImageProxyController::class, 'show']);
Route::get('/downloads/{batchId}/status', [DownloadController::class, 'getDownloadStatus']);
Route::get('/downloads/{batchId}/{filename}', [DownloadController::class, 'downloadFile'])
    ->where('filename', '.*')
    ->name('downloads.file');

Route::delete('/reports/{generation}/cancel', [ProjectController::class, 'cancelReportGeneration']);
Route::delete('/reports/{generationId}', [ProjectController::class, 'deleteReport']);
Route::get('/reports/{id}/download/{file?}', [ProjectController::class, 'downloadReport'])
    ->name('reports.download');
Route::post('/reports/cleanup', [ProjectController::class, 'cleanupExpiredReports']);

Route::get('zip-analysis/{analysisId}/status', [LargeZipController::class, 'getAnalysisStatus']);
Route::post('zip-analysis/{analysisId}/process', [LargeZipController::class, 'processAnalyzedZip']);
