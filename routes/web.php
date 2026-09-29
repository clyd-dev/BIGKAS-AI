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
use App\Http\Controllers\MessageController;
use App\Http\Controllers\PracticeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\VerificationCodeController;
use App\Http\Controllers\Student\StudentAuthController;
use App\Http\Controllers\Student\StudentDashboardController;
use App\Http\Controllers\Student\StudentAssessmentController;
use App\Http\Controllers\Student\StudentActivityController;
use App\Http\Controllers\Student\StudentBadgeController;
use App\Http\Controllers\Parent\ParentDashboardController;
use App\Http\Controllers\Parent\ParentMessageController;
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
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:3,1');
    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.email')->middleware('throttle:3,1');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update')->middleware('throttle:3,1');
});

// Logout (must be authenticated)
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// ============================================
// EMAIL VERIFICATION (OTP code via PHPMailer)
// ============================================

Route::get('/verify-code', [VerificationCodeController::class, 'show'])->name('verification-code.show');
Route::post('/verify-code', [VerificationCodeController::class, 'verify'])->name('verification-code.verify')->middleware('throttle:10,1');
Route::post('/verify-code/resend', [VerificationCodeController::class, 'resend'])->name('verification-code.resend')->middleware('throttle:3,1');

// Legacy alias: keeps the `verified` middleware and old tests working.
Route::get('/email/verify', function (\Illuminate\Http\Request $request) {
    $user = $request->user();
    if ($user->hasVerifiedEmail()) {
        return redirect()->route('dashboard');
    }
    $request->session()->put('pending_verification_user_id', $user->id);

    return redirect()->route('verification-code.show');
})->middleware('auth')->name('verification.notice');

// ============================================
// STUDENT PORTAL (PIN-based auth, separate from main auth)
// ============================================

Route::prefix('student')->name('student.')->group(function () {
    // Student login (public)
    Route::get('/login', [StudentAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [StudentAuthController::class, 'login'])->name('login.submit')->middleware('throttle:10,1');
    Route::post('/logout', [StudentAuthController::class, 'logout'])->name('logout');

    // Authenticated student routes
    Route::middleware('student.auth')->group(function () {
        Route::get('/dashboard', [StudentDashboardController::class, 'index'])->name('dashboard');

        // Live assessment reading
        Route::get('/assessment/pending', [StudentAssessmentController::class, 'pending'])->name('assessment.pending');
        Route::get('/assessment/{assessment}/read', [StudentAssessmentController::class, 'read'])->name('assessment.read');
        Route::post('/assessment/{assessment}/start', [StudentAssessmentController::class, 'start'])->name('assessment.start');
        Route::post('/assessment/{assessment}/upload-audio', [StudentAssessmentController::class, 'uploadAudio'])->name('assessment.upload-audio');

        // Activities
        Route::get('/activities', [StudentActivityController::class, 'index'])->name('activities');
        Route::get('/activities/{log}', [StudentActivityController::class, 'show'])->name('activities.show');
        Route::post('/activities/{log}/start', [StudentActivityController::class, 'start'])->name('activities.start');
        Route::post('/activities/{log}/complete', [StudentActivityController::class, 'complete'])->name('activities.complete');

        // Flash cards & guided reading
        Route::get('/flashcards', [StudentActivityController::class, 'flashCards'])->name('flashcards');
        Route::post('/flashcards/save', [StudentActivityController::class, 'saveFlashCardResult'])->name('flashcards.save');
        Route::get('/guided-reading', [StudentActivityController::class, 'guidedReading'])->name('guided-reading');

        // Badges & leaderboard
        Route::get('/badges', [StudentBadgeController::class, 'index'])->name('badges');
        Route::get('/leaderboard', [StudentBadgeController::class, 'leaderboard'])->name('leaderboard');
    });
});

// ============================================
// AUTHENTICATED ROUTES (Teacher/Admin/Parent)
// ============================================

Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Notifications (bell icon)
    Route::post('/notifications/{id}/read', [\App\Http\Controllers\NotificationController::class, 'read'])->name('notifications.read');
    
    // ----------------------------------------
    // Learner Management (admin, teacher)
    // ----------------------------------------
    Route::middleware('role:admin,teacher')->group(function () {
        Route::resource('learners', LearnerController::class);
        Route::get('/learners/{learner}/progress', [LearnerController::class, 'progress'])->name('learners.progress');
        Route::post('/learners/{learner}/generate-pin', [AssessmentController::class, 'generatePin'])->name('learners.generate-pin');
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
        Route::get('/assessments/{assessment}/status', [AssessmentController::class, 'status'])->name('assessments.status');
        Route::post('/assessments/{assessment}/upload-audio', [AssessmentController::class, 'uploadAudio'])->name('assessments.upload-audio');
        Route::post('/assessments/{assessment}/retry', [AssessmentController::class, 'retry'])->name('assessments.retry');
        Route::post('/assessments/{assessment}/analyze', [AssessmentController::class, 'analyze'])->name('assessments.analyze');
        Route::get('/assessments/{assessment}/results', [AssessmentController::class, 'results'])->name('assessments.results');

        // Live assessment sessions
        Route::post('/assessments/session/create', [AssessmentController::class, 'createSession'])->name('assessments.session.create');
        Route::get('/assessments/session/{session}/monitor', [AssessmentController::class, 'monitorSession'])->name('assessments.session.monitor');
        Route::get('/assessments/session/{session}/poll', [AssessmentController::class, 'pollSession'])->name('assessments.session.poll');
        Route::post('/assessments/session/{session}/start-recording', [AssessmentController::class, 'sessionStartRecording'])->name('assessments.session.start-recording');
        Route::post('/assessments/session/{session}/cancel', [AssessmentController::class, 'cancelSession'])->name('assessments.session.cancel');
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
    // Practice Center (admin, teacher)
    // ----------------------------------------
    Route::middleware('role:admin,teacher')->group(function () {
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
    // Messages (admin, teacher)
    // ----------------------------------------
    Route::middleware('role:admin,teacher')->group(function () {
        Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
        Route::get('/messages/compose', [MessageController::class, 'create'])->name('messages.create');
        Route::post('/messages', [MessageController::class, 'store'])->name('messages.store');
        Route::get('/messages/{message}', [MessageController::class, 'show'])->name('messages.show');
        Route::post('/messages/{message}/reply', [MessageController::class, 'reply'])->name('messages.reply');
    });

    // ----------------------------------------
    // Profile (all authenticated users)
    // ----------------------------------------
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    // ----------------------------------------
    // Admin Routes (admin only)
    // ----------------------------------------
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');

        // User management
        Route::get('/users', [AdminController::class, 'users'])->name('users');
        Route::post('/users', [AdminController::class, 'createUser'])->name('users.create');
        Route::put('/users/{user}', [AdminController::class, 'updateUser'])->name('users.update');
        Route::post('/users/{user}/role', [AdminController::class, 'updateUserRole'])->name('users.role');
        Route::post('/users/{user}/activate', [AdminController::class, 'activateUser'])->name('users.activate');
        Route::post('/users/{user}/deactivate', [AdminController::class, 'deactivateUser'])->name('users.deactivate');
        Route::post('/users/{user}/reset-password', [AdminController::class, 'resetUserPassword'])->name('users.reset-password');

        // School management
        Route::get('/schools', [AdminController::class, 'schools'])->name('schools');
        Route::post('/schools', [AdminController::class, 'storeSchool'])->name('schools.store');
        Route::put('/schools/{school}', [AdminController::class, 'updateSchool'])->name('schools.update');
        Route::delete('/schools/{school}', [AdminController::class, 'deleteSchool'])->name('schools.delete');

        // Grade & Section (class) management
        Route::post('/classes', [AdminController::class, 'storeClass'])->name('classes.store');
        Route::put('/classes/{schoolClass}', [AdminController::class, 'updateClass'])->name('classes.update');
        Route::delete('/classes/{schoolClass}', [AdminController::class, 'deleteClass'])->name('classes.delete');

        // Classes Overview & Activity Logs
        Route::get('/classes', [AdminController::class, 'classesOverview'])->name('classes');
        Route::get('/logs', [AdminController::class, 'activityLogs'])->name('logs');

        // Intervention management
        Route::get('/interventions', [AdminController::class, 'interventions'])->name('interventions');
        Route::post('/interventions', [AdminController::class, 'storeIntervention'])->name('interventions.store');
        Route::put('/interventions/{intervention}', [AdminController::class, 'updateIntervention'])->name('interventions.update');
        Route::delete('/interventions/{intervention}', [AdminController::class, 'deleteIntervention'])->name('interventions.delete');

        // Materials & Settings
        Route::get('/materials', [AdminController::class, 'materials'])->name('materials');
        Route::get('/settings', [AdminController::class, 'settings'])->name('settings');
        Route::post('/settings', [AdminController::class, 'saveSettings'])->name('settings.save');

        // Badge management
        Route::get('/badges', [AdminController::class, 'badges'])->name('badges');
        Route::post('/badges', [AdminController::class, 'storeBadge'])->name('badges.store');
        Route::put('/badges/{badge}', [AdminController::class, 'updateBadge'])->name('badges.update');
        Route::post('/badges/{badge}/toggle', [AdminController::class, 'toggleBadge'])->name('badges.toggle');

        // Phil-IRI Reading Profile (Form 4 matrix + Form 3A detail)
        Route::get('/phil-iri', [AdminController::class, 'philIri'])->name('phil-iri');

        // Learner portal oversight
        Route::get('/learner-portal', [AdminController::class, 'learnerPortal'])->name('learner-portal');
        Route::post('/learner-portal/{learner}/generate-pin', [AdminController::class, 'generateLearnerPin'])->name('learner-portal.generate-pin');
        Route::post('/learner-portal/{learner}/reset-xp', [AdminController::class, 'resetLearnerXp'])->name('learner-portal.reset-xp');
    });

    // ----------------------------------------
    // Parent Portal (parent only)
    // ----------------------------------------
    Route::middleware('role:parent')->prefix('parent')->name('parent.')->group(function () {
        Route::get('/', [ParentDashboardController::class, 'index'])->name('dashboard');

        // Feature 2: Learner's Reading Profile
        Route::get('/children/{learner}', [ParentDashboardController::class, 'learnerProfile'])->name('children.profile');

        // Feature 3: Assessment Results
        Route::get('/children/{learner}/assessments', [ParentDashboardController::class, 'assessmentResults'])->name('children.assessments');
        Route::get('/children/{learner}/assessments/{assessment}', [ParentDashboardController::class, 'assessmentDetail'])->name('children.assessment-detail');

        // Feature 1: Home Intervention Activities
        Route::get('/children/{learner}/interventions', [ParentDashboardController::class, 'interventions'])->name('children.interventions');
        Route::post('/children/{learner}/interventions/{interventionLog}', [ParentDashboardController::class, 'updateIntervention'])->name('children.intervention-update');

        // Feature 4: Messages
        Route::get('/messages', [ParentMessageController::class, 'index'])->name('messages.index');
        Route::get('/messages/compose', [ParentMessageController::class, 'create'])->name('messages.create');
        Route::post('/messages', [ParentMessageController::class, 'store'])->name('messages.store');
        Route::get('/messages/{message}', [ParentMessageController::class, 'show'])->name('messages.show');
        Route::post('/messages/{message}/reply', [ParentMessageController::class, 'reply'])->name('messages.reply');
    });
});
