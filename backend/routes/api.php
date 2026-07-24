<?php

use App\Http\Controllers\Api\AcademyQuizController;
use App\Http\Controllers\Api\EtapeWorkflowController;
use App\Http\Controllers\Api\PersonnelController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\KaizenReportController;
use App\Http\Controllers\ProcedureController;
use App\Http\Controllers\ProcedureVersionController;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Support\Facades\Route;

// Public Paths
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected Paths (Sanctum Session Guards Active)
Route::middleware(['auth:sanctum', ResolveTenantContext::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // Core Procedures Engine Mapping API routes
    Route::get('/procedures', [ProcedureController::class, 'index']);
    Route::post('/procedures', [ProcedureController::class, 'store']);
    Route::get('/procedures/{id}', [ProcedureController::class, 'show']);
    Route::put('/procedures/{id}', [ProcedureController::class, 'update']);
    Route::delete('/procedures/{id}', [ProcedureController::class, 'destroy']);
    Route::post('/procedures/{procedure}/versions', [ProcedureVersionController::class, 'store']);

    // Workflow Steps Engine
    Route::apiResource('workflow-etapes', EtapeWorkflowController::class);

    // Kaizen Reports Engine (Chapter 7)
    Route::apiResource('kaizen-reports', KaizenReportController::class);

    // Academy & Knowledge Push Engine (Chapter 8)
    Route::get('/academy/quizzes', [AcademyQuizController::class, 'index']);
    Route::post('/academy/quizzes/{id}/submit', [AcademyQuizController::class, 'submitAttempt']);

    // Personnel & HR Directory Engine
    Route::apiResource('personnel', PersonnelController::class);
});