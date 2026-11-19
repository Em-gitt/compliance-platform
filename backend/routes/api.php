<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CitationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\RequirementController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update']);
    Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate']);
    Route::post('/users/{user}/activate', [UserController::class, 'activate']);

    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents', [DocumentController::class, 'store']);
    Route::get('/documents/{document}', [DocumentController::class, 'show']);
    Route::get('/documents/{document}/versions', [DocumentController::class, 'versions']);
    Route::post('/documents/{document}/versions', [DocumentController::class, 'storeVersion']);
    Route::match(['put', 'patch', 'delete'], '/documents/{document}/versions/{version?}', function () {
        return response()->json(['message' => 'Document versions cannot be modified or deleted.'], 403);
    });
    Route::delete('/documents/{document}', function () {
        return response()->json(['message' => 'Document versions cannot be modified or deleted.'], 403);
    });
    Route::get('/documents/{id}/versions/{versionId}/download', [DocumentController::class, 'download']);

    Route::get('/alerts', [AlertController::class, 'index']);
    Route::match(['post', 'patch'], '/alerts/{alert}/read', [AlertController::class, 'markAsRead']);
    Route::post('/alerts/read-all', [AlertController::class, 'markAllAsRead']);

    Route::get('/requirements', [RequirementController::class, 'index']);
    Route::get('/compliance-requirements', [RequirementController::class, 'index']);
    Route::post('/requirements', [RequirementController::class, 'store']);
    Route::get('/requirements/{requirement}', [RequirementController::class, 'show']);
    Route::patch('/requirements/{requirement}', [RequirementController::class, 'update']);
    Route::put('/requirements/{requirement}', [RequirementController::class, 'update']);
    Route::post('/requirements/{requirement}/documents', [RequirementController::class, 'linkDocument']);
    Route::delete('/requirements/{requirement}/documents/{requiredDocument}', [RequirementController::class, 'unlinkDocument']);

    Route::get('/audit-logs', [AuditLogController::class, 'index']);
    Route::match(['put', 'patch', 'delete'], '/audit-logs/{id}', [AuditLogController::class, 'immutableReject']);

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::post('/assistant/questions', [AssistantController::class, 'ask'])->middleware('throttle:assistant-questions');
    Route::get('/assistant/questions', [AssistantController::class, 'index']);
    Route::post('/assistant/questions/{id}/feedback', [AssistantController::class, 'feedback']);
    Route::get('/admin/ai-feedback', [AssistantController::class, 'adminFeedbackIndex']);
    Route::get('/admin/ai-feedback/export', [AssistantController::class, 'exportFeedback']);
    Route::get('/citations/{chunk_id}', [CitationController::class, 'show']);
});

Route::get('/health', HealthController::class);
