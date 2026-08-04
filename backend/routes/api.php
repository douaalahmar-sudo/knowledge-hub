<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProcedureController;
use App\Http\Controllers\ProcedureVersionController;
use App\Http\Controllers\TriptychUploadController;
use App\Http\Controllers\ArticleAlertController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\KaizenController;
use App\Http\Controllers\HrRequestController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\PrintAuthorizationController;
use App\Http\Controllers\SecurityAlertController;
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

    // --- KNOWLEDGE BASE / ARTICLES (Module 1) ---
    // Authorization here is access_role-based (App\Enums\UserRole), not the
    // role_id/CheckRole system the rest of the API still uses — the two are
    // deliberately separate (see User::hasRole()). Read restrictions (lecteur
    // sees published+current only) and the author/draft-only update rule live
    // inside the controller/UpdateArticleRequest, not route middleware.
    Route::prefix('v1')->group(function () {
        Route::get('/articles', [ArticleController::class, 'index']);
        Route::get('/articles/{article}', [ArticleController::class, 'show']);
        Route::post('/articles', [ArticleController::class, 'store']);
        Route::put('/articles/{article}', [ArticleController::class, 'update']);
        Route::delete('/articles/{article}', [ArticleController::class, 'destroy']);

        // Workflow: draft -> pending_metier -> pending_qualite -> published,
        // reject sends either pending_* stage back to draft. Role gating for
        // each lives in the controller/RejectArticleRequest, not here.
        Route::post('/articles/{article}/submit', [ArticleController::class, 'submit']);
        Route::post('/articles/{article}/validate-metier', [ArticleController::class, 'validateMetier']);
        Route::post('/articles/{article}/validate-qualite', [ArticleController::class, 'validateQualite']);
        Route::post('/articles/{article}/reject', [ArticleController::class, 'reject']);

        // {format} is deliberately unconstrained here (no ->where()): an
        // invalid value should reach the controller and get a controlled 422,
        // not Laravel's raw 404 for a route that simply didn't match.
        Route::post('/articles/{article}/files/{format}', [ArticleController::class, 'uploadFile']);
        Route::get('/articles/{article}/files/{format}', [ArticleController::class, 'retrieveFile']);

        // §11.1 authorized printing — the only exception to §11's Hub-wide
        // print ban, one document and a few minutes at a time. Gated on
        // `authorize-print` (admin/data_owner) inside the controller so the
        // denial keeps its French message. `consume` has no Gate: by then the
        // grant itself is the authorization, and it is checked against its
        // holder and its expiry rather than against a role.
        Route::post('/articles/{article}/print-authorizations', [PrintAuthorizationController::class, 'store']);
        Route::post('/print-authorizations/{authorization}/consume', [PrintAuthorizationController::class, 'consume']);
    });

    // --- ARTICLE ALERTS / SIGNALEMENTS D'ÉCART (§7.2 / §7.3) ---
    // Distinct from the legacy /kaizen/signals routes further down, which are
    // procedure-keyed and untouched by this module.
    //
    // Reporting is open to any authenticated user per §7.2 ("tout
    // collaborateur"); acknowledge/close are gated on `process-article-alerts`
    // (data_owner/admin — the "Gardien du Temple" of §6.1) inside
    // ProcessArticleAlertRequest, not by route middleware, so the denial keeps
    // its French message. The role split on the list itself lives in the
    // controller for the same reason.
    Route::prefix('v1')->group(function () {
        Route::post('/articles/{article}/alerts', [ArticleAlertController::class, 'store']);
        Route::get('/alerts', [ArticleAlertController::class, 'index']);
        Route::post('/alerts/{alert}/acknowledge', [ArticleAlertController::class, 'acknowledge']);
        Route::post('/alerts/{alert}/close', [ArticleAlertController::class, 'close']);
    });

    // --- AUDIT LOG / JOURNAL D'AUDIT (§10.4) ---
    // Read-only, and gated in IndexAuditLogRequest rather than by route
    // middleware so the 403 keeps its French message (same reason as the
    // article and alert endpoints). The `view-audit-logs` Gate is not the only
    // barrier: the RLS policy on `audit_logs` grants SELECT to admin and
    // data_owner alone, so the table stays closed even to a caller who somehow
    // reaches it without passing through here.
    //
    // Writes have no route on purpose — entries come from AuditLogger inside
    // the action being audited, and the table accepts no UPDATE or DELETE.
    Route::prefix('v1')->group(function () {
        Route::get('/audit-logs', [AuditLogController::class, 'index']);

        // §10.4 automated security alerts, for DSI-equivalent staff. Admin
        // only — narrower than the audit trail, see the Gate. No POST: alerts
        // are raised by SecurityAnomalyDetector, never filed by hand.
        Route::get('/security-alerts', [SecurityAlertController::class, 'index']);
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