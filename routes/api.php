<?php

/**
 * BIGKAS-AI API Routes
 *
 * All API routes are automatically prefixed with /api by Laravel.
 * Authentication is handled via Sanctum tokens for API endpoints.
 */

use App\Http\Controllers\Api\AssessmentApiController;
use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\Api\InterventionApiController;
use App\Http\Controllers\Api\LearnerApiController;
use App\Http\Controllers\Api\MaterialApiController;
use App\Http\Controllers\Api\MLApiController;
use App\Http\Controllers\Api\PracticeApiController;
use App\Http\Controllers\Api\ReportApiController;
use App\Http\Controllers\Api\SpeechApiController;
use Illuminate\Support\Facades\Route;

// ============================================
// PUBLIC API ROUTES
// ============================================

Route::middleware('throttle:5,1')->group(function () {
    Route::post('/auth/login', [AuthApiController::class, 'login']);
    Route::post('/auth/register', [AuthApiController::class, 'register']);
});

Route::middleware('throttle:3,1')->group(function () {
    Route::post('/auth/forgot-password', [AuthApiController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [AuthApiController::class, 'resetPassword']);
});

// ML health check (public for monitoring)
Route::get('/ml/health', [MLApiController::class, 'health']);

// ============================================
// AUTHENTICATED API ROUTES
// ============================================

Route::middleware('auth:sanctum')->group(function () {

    // Authentication
    Route::post('/auth/logout', [AuthApiController::class, 'logout']);
    Route::get('/auth/user', [AuthApiController::class, 'currentUser']);

    // Learners
    Route::get('/learners', [LearnerApiController::class, 'index']);
    Route::post('/learners', [LearnerApiController::class, 'store'])->middleware('teacher.assigned');
    Route::get('/learners/{learner}', [LearnerApiController::class, 'show']);
    Route::put('/learners/{learner}', [LearnerApiController::class, 'update']);
    Route::delete('/learners/{learner}', [LearnerApiController::class, 'destroy']);
    Route::get('/learners/{learner}/progress', [LearnerApiController::class, 'progress']);
    Route::get('/learners/{learner}/assessments', [LearnerApiController::class, 'assessments']);

    // Reading Materials
    Route::get('/materials', [MaterialApiController::class, 'index']);
    Route::get('/materials/{material}', [MaterialApiController::class, 'show']);
    Route::post('/materials', [MaterialApiController::class, 'store']);
    Route::put('/materials/{material}', [MaterialApiController::class, 'update']);
    Route::delete('/materials/{material}', [MaterialApiController::class, 'destroy']);

    // Assessments
    Route::get('/assessments', [AssessmentApiController::class, 'index']);
    Route::post('/assessments', [AssessmentApiController::class, 'store'])->middleware('teacher.assigned');
    Route::get('/assessments/{assessment}', [AssessmentApiController::class, 'show']);
    Route::post('/assessments/{assessment}/audio', [AssessmentApiController::class, 'uploadAudio'])->middleware('teacher.assigned');
    Route::post('/assessments/{assessment}/analyze', [AssessmentApiController::class, 'analyze'])->middleware('teacher.assigned');
    Route::get('/assessments/{assessment}/results', [AssessmentApiController::class, 'results']);

    // Interventions
    Route::get('/interventions', [InterventionApiController::class, 'index']);
    Route::get('/interventions/{intervention}', [InterventionApiController::class, 'show']);
    Route::post('/intervention-logs', [InterventionApiController::class, 'assign']);
    Route::put('/intervention-logs/{interventionLog}', [InterventionApiController::class, 'updateLog']);
    Route::get('/recommendations/{assessment}', [InterventionApiController::class, 'recommendations']);

    // Practice
    Route::get('/practice/activities', [PracticeApiController::class, 'activities']);
    Route::post('/practice/sessions', [PracticeApiController::class, 'startSession']);
    Route::post('/practice/sessions/{practiceSession}/complete', [PracticeApiController::class, 'completeSession']);

    // Reports
    Route::get('/reports/learner/{learner}', [ReportApiController::class, 'learnerReport']);
    Route::get('/reports/class/{schoolClass}', [ReportApiController::class, 'classReport']);
    Route::get('/reports/dashboard', [ReportApiController::class, 'dashboardStats']);

    // Speech-to-Text
    Route::post('/speech/transcribe', [SpeechApiController::class, 'transcribe']);
    Route::get('/speech/languages', [SpeechApiController::class, 'languages']);

    // ML Analysis
    Route::post('/ml/classify', [MLApiController::class, 'classify']);
    Route::post('/ml/analyze', [MLApiController::class, 'analyze']);
});
