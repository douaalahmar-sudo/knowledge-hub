<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProcedureController;
use App\Http\Controllers\ProcedureVersionController;
use App\Http\Controllers\KaizenController;
use App\Http\Controllers\HrRequestController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\Api\EtapeWorkflowController;
use App\Http\Middleware\ResolveTenantContext;

/*
|--------------------------------------------------------------------------
| API Routes - Knowledge Hub
|--------------------------------------------------------------------------
*/

// ------------------- 1. Public Routes -------------------
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);


// ------------------- 2. Protected Routes -------------------
// (Sanctum Session Guard + Tenant Context Resolution Active)
Route::middleware(['auth:sanctum', ResolveTenantContext::class])->group(function () {
    
    Route::post('/logout', [AuthController::class, 'logout']);

    // --- KNOWLEDGE BASE / HR ARTICLES (Module 1) ---
    Route::prefix('v1')->group(function () {
        Route::get('/articles', [ArticleController::class, 'index']);
        Route::post('/articles', [ArticleController::class, 'store']);
        Route::get('/articles/{article}', [ArticleController::class, 'show']);
        Route::put('/articles/{article}', [ArticleController::class, 'update']);
    });

    // --- HR SELF-SERVICE / EMPLOYEE SERVICES (Module 2) ---
    Route::prefix('v1')->group(function () {
        // Employee endpoints
        Route::get('/hr-requests/mine', [HrRequestController::class, 'userRequests']);
        Route::post('/hr-requests', [HrRequestController::class, 'store']);

        // HR Admin endpoints
        Route::get('/hr-requests', [HrRequestController::class, 'index']);
        Route::put('/hr-requests/{id}', [HrRequestController::class, 'updateStatus']);
    });

    // --- PROCEDURES ENGINE (Protected by RBAC) ---
    Route::get('/procedures', [ProcedureController::class, 'index']);
    Route::get('/procedures/{id}', [ProcedureController::class, 'show']);
    
    Route::post('/procedures', [ProcedureController::class, 'store'])
        ->middleware('can:manage-procedures');
    Route::put('/procedures/{id}', [ProcedureController::class, 'update'])
        ->middleware('can:manage-procedures');
    Route::delete('/procedures/{id}', [ProcedureController::class, 'destroy'])
        ->middleware('can:manage-procedures');
    Route::post('/procedures/{procedure}/versions', [ProcedureVersionController::class, 'store'])
        ->middleware('can:manage-procedures');

    // --- WORKFLOW STEPS ENGINE ---
    Route::apiResource('workflow-etapes', EtapeWorkflowController::class);

    // --- KAIZEN WORKFLOW ENGINE (Sprint 4 Core Engine) ---
    Route::get('/kaizen/signals', [KaizenController::class, 'index']);
    
    Route::post('/kaizen/signals', [KaizenController::class, 'store'])
        ->middleware('can:submit-kaizen');

    Route::patch('/kaizen/signals/{kaizen}/in-review', [KaizenController::class, 'inReview'])
        ->middleware('can:resolve-kaizen,kaizen');

    Route::post('/kaizen/signals/{kaizen}/resolve', [KaizenController::class, 'resolve'])
        ->middleware('can:resolve-kaizen,kaizen');

    /*
    |--------------------------------------------------------------------------
    | Out of Scope for Demo (Kept Stubbed for Future Expansions)
    |--------------------------------------------------------------------------
    | Route::apiResource('personnel', PersonnelController::class);
    | Route::get('/academy/quizzes', [AcademyQuizController::class, 'index']);
    | Route::post('/academy/quizzes/{id}/submit', [AcademyQuizController::class, 'submitAttempt']);
    */
});