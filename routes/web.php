<?php

/**
 * BIGKAS-AI Web Routes
 *
 * All web routes for the application.
 */

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InterventionController;
use App\Http\Controllers\LearnerController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\PracticeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

// ============================================
// PUBLIC ROUTES
// ============================================

// Home/Landing page
Route::get('/', function () {
    return view('home');
})->name('home');

// Authentication Routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');
});

// Logout (must be authenticated)
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// ============================================
// AUTHENTICATED ROUTES
// ============================================

Route::middleware('auth')->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // ----------------------------------------
    // Learner Management (admin, teacher)
    // ----------------------------------------
    Route::middleware('role:admin,teacher')->group(function () {
        Route::resource('learners', LearnerController::class);
        Route::get('/learners/{learner}/progress', [LearnerController::class, 'progress'])->name('learners.progress');
    });

    // ----------------------------------------
    // Reading Materials (admin, teacher)
    // ----------------------------------------
    Route::middleware('role:admin,teacher')->group(function () {
        Route::resource('materials', MaterialController::class);
    });

    // ----------------------------------------
    // Assessments (admin, teacher)
    // ----------------------------------------
    Route::middleware('role:admin,teacher')->group(function () {
        Route::get('/assessments', [AssessmentController::class, 'index'])->name('assessments.index');
        Route::get('/assessments/new', [AssessmentController::class, 'create'])->name('assessments.create');
        Route::get('/assessments/start/{learner}', [AssessmentController::class, 'start'])->name('assessments.start');
        Route::post('/assessments', [AssessmentController::class, 'store'])->name('assessments.store');
        Route::get('/assessments/{assessment}', [AssessmentController::class, 'show'])->name('assessments.show');
        Route::post('/assessments/{assessment}/upload-audio', [AssessmentController::class, 'uploadAudio'])->name('assessments.upload-audio');
        Route::post('/assessments/{assessment}/analyze', [AssessmentController::class, 'analyze'])->name('assessments.analyze');
        Route::get('/assessments/{assessment}/results', [AssessmentController::class, 'results'])->name('assessments.results');
    });

    // ----------------------------------------
    // Interventions (admin, teacher)
    // ----------------------------------------
    Route::middleware('role:admin,teacher')->group(function () {
        Route::get('/interventions', [InterventionController::class, 'index'])->name('interventions.index');
        Route::get('/interventions/{intervention}', [InterventionController::class, 'show'])->name('interventions.show');
        Route::post('/interventions/assign', [InterventionController::class, 'assign'])->name('interventions.assign');
        Route::put('/intervention-logs/{interventionLog}', [InterventionController::class, 'updateLog'])->name('intervention-logs.update');
        Route::get('/learners/{learner}/interventions', [InterventionController::class, 'learnerInterventions'])->name('learners.interventions');
    });

    // ----------------------------------------
    // Practice Center (admin, teacher, student)
    // ----------------------------------------
    Route::middleware('role:admin,teacher,student')->group(function () {
        Route::get('/practice', [PracticeController::class, 'index'])->name('practice.index');
        Route::get('/practice/phonemic', [PracticeController::class, 'phonemic'])->name('practice.phonemic');
        Route::get('/practice/sight-words', [PracticeController::class, 'sightWords'])->name('practice.sight-words');
        Route::get('/practice/reading', [PracticeController::class, 'guidedReading'])->name('practice.reading');
        Route::post('/practice/complete', [PracticeController::class, 'complete'])->name('practice.complete');
    });

    // ----------------------------------------
    // Reports (admin, teacher, parent)
    // ----------------------------------------
    Route::middleware('role:admin,teacher,parent')->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/learner/{learner}', [ReportController::class, 'learnerReport'])->name('reports.learner');
        Route::get('/reports/class/{schoolClass}', [ReportController::class, 'classReport'])->name('reports.class');
        Route::get('/reports/learner/{learner}/pdf', [ReportController::class, 'downloadPdf'])->name('reports.pdf');
        Route::get('/reports/learner/{learner}/print', [ReportController::class, 'printReport'])->name('reports.print');
    });

    // ----------------------------------------
    // Profile (all authenticated users)
    // ----------------------------------------
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    // ----------------------------------------
    // Student Portal Routes
    // ----------------------------------------
    Route::middleware('role:student')->prefix('student')->name('student.')->group(function () {
        Route::get('/progress', [DashboardController::class, 'studentProgress'])->name('progress');
        Route::get('/assessments', [AssessmentController::class, 'studentAssessments'])->name('assessments');
        Route::get('/assessments/{assessment}/results', [AssessmentController::class, 'studentResults'])->name('assessments.results');
        Route::get('/interventions', [InterventionController::class, 'studentInterventions'])->name('interventions');
    });

    // ----------------------------------------
    // Admin Routes (admin only)
    // ----------------------------------------
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');

        // User management
        Route::get('/users', [AdminController::class, 'users'])->name('users');
        Route::post('/users/{user}/role', [AdminController::class, 'updateUserRole'])->name('users.role');
        Route::post('/users/{user}/activate', [AdminController::class, 'activateUser'])->name('users.activate');
        Route::post('/users/{user}/deactivate', [AdminController::class, 'deactivateUser'])->name('users.deactivate');
        Route::post('/users/{user}/reset-password', [AdminController::class, 'resetUserPassword'])->name('users.reset-password');

        // School management
        Route::get('/schools', [AdminController::class, 'schools'])->name('schools');
        Route::post('/schools', [AdminController::class, 'storeSchool'])->name('schools.store');
        Route::put('/schools/{school}', [AdminController::class, 'updateSchool'])->name('schools.update');
        Route::delete('/schools/{school}', [AdminController::class, 'deleteSchool'])->name('schools.delete');

        // Intervention management
        Route::get('/interventions', [AdminController::class, 'interventions'])->name('interventions');
        Route::post('/interventions', [AdminController::class, 'storeIntervention'])->name('interventions.store');
        Route::put('/interventions/{intervention}', [AdminController::class, 'updateIntervention'])->name('interventions.update');
        Route::delete('/interventions/{intervention}', [AdminController::class, 'deleteIntervention'])->name('interventions.delete');

        // Materials & Settings
        Route::get('/materials', [AdminController::class, 'materials'])->name('materials');
        Route::get('/settings', [AdminController::class, 'settings'])->name('settings');
        Route::post('/settings', [AdminController::class, 'saveSettings'])->name('settings.save');
    });
});
