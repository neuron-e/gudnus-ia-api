<?php

use App\Http\Controllers\Api\AnalysisBatchController;
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

// Logout
Route::middleware('auth:sanctum')->post('/logout', function (Request $request) {
    $request->user()->currentAccessToken()->delete();
    return response()->json(['message' => 'Sesión cerrada']);
});

Route::prefix('projects')->group(function () {
    Route::apiResource('/', \App\Http\Controllers\Api\ProjectController::class)->parameters(['' => 'project']);

    Route::get('/{project}/folders', [\App\Http\Controllers\Api\FolderController::class, 'index']);
    Route::post('/{project}/folders', [\App\Http\Controllers\Api\FolderController::class, 'store']);
    Route::put('/{project}/folders/{folder}', [\App\Http\Controllers\Api\FolderController::class, 'update']);
    Route::post('/{project}/structure', [\App\Http\Controllers\Api\FolderController::class, 'storeByExcel']);
    Route::delete('/{project}/folders/{folder}/empty', [\App\Http\Controllers\Api\FolderController::class, 'empty']);
    Route::delete('/{project}/folders/{folder}', [\App\Http\Controllers\Api\FolderController::class, 'destroy']);

    Route::post('/{project}/folders/empty-multiple', [\App\Http\Controllers\Api\FolderController::class, 'emptyMultiple']);
    Route::post('/{project}/folders/delete-multiple', [\App\Http\Controllers\Api\FolderController::class, 'deleteMultiple']);

    Route::post('/{project}/images/bulk-upload', [App\Http\Controllers\Api\ImageController::class, 'uploadZipByModule']);
    Route::post('/{project}/images/zip-with-mapping', [App\Http\Controllers\Api\ImageController::class, 'uploadWithMapping']);

    Route::get('/{project}/processing-status', [\App\Http\Controllers\Api\ProjectController::class, 'getProcessingStatus']);




});

Route::prefix('folders')->group(function () {
    Route::get('/{folder}/images', [\App\Http\Controllers\Api\ImageController::class, 'index']);
    Route::post('/{folder}/images/upload', [\App\Http\Controllers\Api\ImageController::class, 'upload']);
});

Route::get('/images/{image}/processed', [\App\Http\Controllers\Api\ProcessedImageController::class, 'show']);
Route::get('/images/{image}/analysis', [\App\Http\Controllers\Api\ImageAnalysisResultController::class, 'show']);
Route::post('/images/{image}/process', [\App\Http\Controllers\Api\ProcessedImageController::class, 'process']);
Route::post('/images/{project}/bulk-process', [\App\Http\Controllers\Api\ProcessedImageController::class, 'processBulk']);

Route::get('/images/{image}/base64', [App\Http\Controllers\Api\ImageController::class, 'base64']);
Route::post('/images/{image}/manual-crop', [App\Http\Controllers\Api\ImageController::class, 'manualCrop']);


Route::get('/projects/{project}/processing-status', [AnalysisBatchController::class, 'processingStatus']);


