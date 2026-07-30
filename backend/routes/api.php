<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProcedureController;
use App\Http\Controllers\ProcedureVersionController;
use App\Http\Controllers\TriptychUploadController;
use App\Http\Controllers\KaizenController;
use App\Http\Controllers\HrRequestController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\Api\EtapeWorkflowController;

/*
|--------------------------------------------------------------------------
| API Routes - Knowledge Hub
|--------------------------------------------------------------------------
*/

// ------------------- 1. Public Routes -------------------
// Legacy unversioned paths, kept so any existing client keeps working.
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Versioned auth surface (/api/v1/auth/*).
Route::prefix('v1/auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);

    // Mock SSO — refuses to run outside local/testing (see AuthController::ssoMock).
    Route::post('/sso/mock', [AuthController::class, 'ssoMock']);

    // /me stays reachable for a user with no filiale: SetTenantContext never
    // rejects a request, it only publishes the filiale for RLS to filter on,
    // so clients can still introspect the session to see why they see nothing.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});


// ------------------- 2. Protected Routes -------------------
// (Sanctum Session Guard. Filiale isolation is enforced by the PostgreSQL RLS
// policies; SetTenantContext runs globally and publishes the filiale for them.)
Route::middleware(['auth:sanctum'])->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    // --- GLOBAL SEARCH ENGINE (Module 3) ---
    Route::prefix('v1')->group(function () {
        Route::get('/search', [GlobalSearchController::class, 'search']);
    });

    // --- KNOWLEDGE BASE / HR ARTICLES (Module 1) ---
    Route::prefix('v1')->group(function () {
        // Reading is open to any authenticated user in the filiale.
        Route::get('/articles', [ArticleController::class, 'index']);
        Route::get('/articles/{article}', [ArticleController::class, 'show']);

        // Authoring mirrors the Angular knowledge-base/new|edit route guard.
        Route::post('/articles', [ArticleController::class, 'store'])
            ->middleware('role:admin,hr_admin,expert_metier');
        Route::put('/articles/{article}', [ArticleController::class, 'update'])
            ->middleware('role:admin,hr_admin,expert_metier');
    });

    // --- HR SELF-SERVICE / EMPLOYEE SERVICES (Module 2) ---
    Route::prefix('v1')->group(function () {
        // Employee endpoints — any authenticated user manages their own requests.
        Route::get('/hr-requests/mine', [HrRequestController::class, 'userRequests']);
        Route::post('/hr-requests', [HrRequestController::class, 'store']);

        // HR Admin endpoints. These were previously reachable by ANY authenticated
        // user — listing every employee's requests and approving/rejecting them
        // were protected only by the frontend hiding the UI.
        Route::get('/hr-requests', [HrRequestController::class, 'index'])
            ->middleware('role:admin,hr_admin');
        Route::put('/hr-requests/{id}', [HrRequestController::class, 'updateStatus'])
            ->middleware('role:admin,hr_admin');
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

    // --- TRIPTYCH SURFACE (/api/v1/procedures/*) ---
    // The Angular triptych form + 3-tab viewer talk to these. The unversioned
    // routes above stay put for existing clients.
    Route::prefix('v1')->group(function () {
        Route::post('/procedures/upload-triptych', [TriptychUploadController::class, 'store'])
            ->middleware('can:manage-procedures');

        Route::get('/procedures', [ProcedureController::class, 'index']);
        Route::get('/procedures/{id}', [ProcedureController::class, 'show']);

        Route::post('/procedures', [ProcedureController::class, 'store'])
            ->middleware('can:manage-procedures');
        Route::patch('/procedures/{id}', [ProcedureController::class, 'updateMeta'])
            ->middleware('can:manage-procedures');
        Route::delete('/procedures/{id}', [ProcedureController::class, 'destroy'])
            ->middleware('can:manage-procedures');
    });

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