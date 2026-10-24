# 📖 BIGKAS — AI/ML Assisted Reading Assessment & Intervention System

> **B**uilding **I**ntelligence for **G**uided **K**nowledge **A**ssessment **S**ystem

An intelligent, bilingual (English/Filipino) reading assessment platform for Grade 3–6 learners in public elementary schools in Sagay City, Negros Occidental, Philippines. BIGKAS uses speech-to-text, machine learning, and evidence-based intervention recommendations to help teachers identify struggling readers, classify their specific weaknesses, and coordinate home-based support with parents — all aligned with DepEd's Phil-IRI standards and MTB-MLE program.

![Laravel](https://img.shields.io/badge/Laravel-12.x-FF2D20?style=flat-square&logo=laravel)
![Python](https://img.shields.io/badge/Python-3.10+-3776AB?style=flat-square&logo=python)
![Flask](https://img.shields.io/badge/Flask-3.0+-000000?style=flat-square&logo=flask)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=flat-square&logo=mysql)
![Scikit-learn](https://img.shields.io/badge/scikit--learn-1.5+-F7931E?style=flat-square&logo=scikit-learn)
![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)
![Version](https://img.shields.io/badge/Version-1.0.0-blue?style=flat-square)

---

## 📋 Table of Contents

1. [Project Overview](#-project-overview)
2. [System Architecture](#-system-architecture)
3. [Folder Structure](#-folder-structure)
4. [Features & Modules](#-features--modules)
5. [Key Workflows](#-key-workflows)
6. [Database Design](#-database-design)
7. [API Documentation](#-api-documentation)
8. [Setup & Installation Guide](#-setup--installation-guide)
9. [Default Credentials](#-default-credentials)
10. [Usage Guide](#-usage-guide)
11. [Machine Learning Component](#-machine-learning-component)
12. [Observations & Known Limitations](#-observations--known-limitations)
13. [Future Enhancements](#-future-enhancements)
14. [Authors & Acknowledgments](#-authors--acknowledgments)

---

## 🎯 Project Overview

### The Problem

The Philippines faces a severe reading literacy crisis. National reviews, including the EDCOM 2 study, describe a serious foundational-literacy gap among Filipino learners. (Quote the exact EDCOM 2 figure from the source document when presenting; it is not reproduced here.) Traditional assessment tools like the paper-based Phil-IRI are too slow to catch specific error patterns before they become ingrained habits.

### What BIGKAS Does

BIGKAS supports the oral reading assessment process. A teacher records a learner reading a grade-matched passage aloud (or the learner reads on their own device with a PIN), and the system:

1. Saves the recording, checks its quality (speech vs. background noise) and transcribes it with a local `faster-whisper` model (stock pre-trained, not yet fine-tuned for Filipino classroom audio)
2. Aligns the spoken words with the reference passage and labels each miscue (mispronunciation, substitution, omission, insertion, repetition, self-correction, off-passage talk, not reached)
3. Computes accuracy %, words per minute, a fluency score and the Phil-IRI reading level
4. Scores the comprehension questions, when the assessment includes them
5. Suggests the main reading weakness with a Random Forest model, and only keeps it when the measured evidence supports it (see [How the system identifies each weakness](#how-the-system-identifies-fluency-phonemic-decoding-and-comprehension-weaknesses))
6. Shows the teacher the result with its explanation and warnings; the teacher accepts, overrides (with a reason) or invalidates it
7. Recommends activities for teacher and parent use, tracks progress, and prints the DepEd Phil-IRI forms

The system is **decision support**: the teacher always has the final say, and the AI's original values are stored beside the teacher's decision.

### Who It's For

| Role | Purpose |
|---|---|
| **Admin** | Manage users, schools, system settings, badges |
| **Teacher** | Conduct assessments, assign interventions, monitor class progress |
| **Parent** | View child's progress, complete home activities, message teachers |
| **Student** | Practice reading, earn badges, complete assigned activities |

### Key Differentiators

- **Bilingual** — English and Filipino only (aligned with DepEd's MTB-MLE program); Hiligaynon is not supported for reading assessment
- **Phil-IRI Aligned** — Three reading levels: Frustration, Instructional, Independent
- **ML-Powered Classification** — Five weakness categories: Independent Reader, Phonemic Awareness, Decoding Accuracy, Oral Reading Fluency, Reading Comprehension
- **Gamified Student Portal** — XP points, streaks, badges, and class leaderboard
- **Teacher in the loop** — Every AI result can be accepted, overridden or invalidated, with a recorded reason
- **Trust signals** — Recording-quality check, speech-recognition confidence and plain-language warnings on each result
- **Honest when the model is down** — If the ML service cannot classify, the reading is still measured and saved, but no weakness is guessed. The result says so, offers **Re-analyze** from the saved recording, and the teacher can set the weakness themselves
- **Scope** — Designed for Grade 3–6 learners in Sagay City Division

---

## 🏗 System Architecture

### High-Level Architecture

```
┌──────────────────────────────────────────────────────────────┐
│                        CLIENT LAYER                          │
│   Web Browser (Bootstrap 5 · Chart.js · Blade Templates)    │
│   Student Portal (kid-friendly UI, PIN-based auth)          │
└───────────────────────────┬──────────────────────────────────┘
                            │ HTTP Requests
┌───────────────────────────▼──────────────────────────────────┐
│                   LARAVEL 12 BACKEND (MVC)                   │
│                                                              │
│  Routes → Middleware → Controllers → Services → Models       │
│                                                              │
│  ┌──────────────┐  ┌──────────────┐  ┌────────────────────┐ │
│  │ Session Auth │  │ Role Middle- │  │  Student PIN Auth  │ │
│  │ (Teacher/    │  │ ware         │  │  (StudentAuth.php) │ │
│  │  Parent/     │  │ (admin,      │  │                    │ │
│  │  Admin)      │  │  teacher,    │  │                    │ │
│  │              │  │  parent)     │  │                    │ │
│  └──────────────┘  └──────────────┘  └────────────────────┘ │
│                                                              │
│  ┌─────────────────────────────────────────────────────────┐ │
│  │                   SERVICE LAYER                         │ │
│  │  ReadingAnalyzerService  · MLClassificationService      │ │
│  │  SpeechToTextService     · InterventionRecommender      │ │
│  │  BadgeService                                           │ │
│  └─────────────────────────────────────────────────────────┘ │
└──────┬────────────────────┬────────────────────┬─────────────┘
       │                    │                    │
┌──────▼──────┐   ┌─────────▼──────┐   ┌────────▼────────────┐
│  MySQL /    │   │  faster-whisper │   │ Python Flask ML API │
│  SQLite DB  │   │  (local,        │   │ localhost:5000       │
│             │   │  primary, not   │   │ Random Forest Model  │
│  18 tables  │   │  yet fine-tuned)│   │ No rule-based backup │
│             │   │  -or- OpenAI    │   │ when model is down   │
│             │   │  Whisper API    │   │                      │
└─────────────┘   └────────────────┘   └─────────────────────┘
```

### Architectural Patterns

- **MVC (Model-View-Controller)** — Laravel enforces MVC. Controllers handle HTTP, Models handle data, Blade templates handle views.
- **Service Layer** — Complex business logic (reading analysis, ML calls, gamification) lives in `app/Services/`, keeping controllers thin.
- **Dual Authentication** — Standard Laravel session auth for teachers/parents/admins. A completely separate PIN-based session system (`StudentAuth` middleware) for child-friendly student access.
- **Model-Only Weakness Labels** — Only the Random Forest may name a weakness. If the Python service is unreachable, has no model loaded or errors, `MLClassificationService::classify()` returns `available: false`, and `resolve()` stores no label, no confidence and the status `unavailable`. There is no rule-based stand-in: it was removed after a panel review, because its accuracy was never measured and a guessed label could reach a child's interventions and practice. The reading's measurements (accuracy, pace, levels, miscues) are unaffected.
- **Dual Speech-to-Text** — `faster-whisper` installed locally in `ml-service/venv` is the **primary** transcription engine (offline, no API key required, not yet fine-tuned on BIGKAS classroom audio); the OpenAI Whisper API is available as an optional cloud alternative.
- **REST API Layer** — A full Sanctum-authenticated API enables future mobile app integrations.

### Python ML Service Integration

```
Laravel (PHP)                     Python Flask
─────────────────                 ─────────────────────
MLClassificationService.php   →   POST /api/classify
                                  ↓
                                  StandardScaler.transform()
                                  ↓
                                  RandomForestClassifier.predict()
                                  ↓
                              ←   JSON { primary_weakness, confidence, scores }

On exception (service down, or no model loaded):
MLClassificationService.php
→ available: false   (no crash, no label: status "unavailable")

When the model answered:
MLClassificationService::resolve() + WeaknessEvidence
→ keep the prediction only if the measured evidence backs it; keep what the model said beside it
```

**Configuration in `.env`:**
```env
ML_API_URL=http://localhost:5000
ML_API_ENABLED=true
```

---

## 📁 Folder Structure

```
bigkas/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/                    # REST API endpoints (Sanctum-authenticated)
│   │   │   │   ├── AssessmentApiController.php
│   │   │   │   ├── AuthApiController.php
│   │   │   │   ├── InterventionApiController.php
│   │   │   │   ├── LearnerApiController.php
│   │   │   │   ├── MaterialApiController.php
│   │   │   │   ├── MLApiController.php
│   │   │   │   ├── PracticeApiController.php
│   │   │   │   ├── ReportApiController.php
│   │   │   │   └── SpeechApiController.php
│   │   │   ├── Parent/                 # Parent portal controllers
│   │   │   │   ├── ParentDashboardController.php
│   │   │   │   └── ParentMessageController.php
│   │   │   ├── Student/                # Student portal controllers
│   │   │   │   ├── StudentAuthController.php       # PIN login/logout
│   │   │   │   ├── StudentAssessmentController.php # Live reading sessions
│   │   │   │   ├── StudentActivityController.php   # Flash cards, guided reading
│   │   │   │   ├── StudentBadgeController.php      # Badges + leaderboard
│   │   │   │   └── StudentDashboardController.php
│   │   │   ├── AdminController.php
│   │   │   ├── AssessmentController.php    # Core assessment flow
│   │   │   ├── AuthController.php
│   │   │   ├── DashboardController.php
│   │   │   ├── InterventionController.php
│   │   │   ├── LearnerController.php
│   │   │   ├── MaterialController.php
│   │   │   ├── MessageController.php
│   │   │   ├── PracticeController.php
│   │   │   ├── ProfileController.php
│   │   │   └── ReportController.php
│   │   └── Middleware/
│   │       ├── RoleMiddleware.php      # Admin/Teacher/Parent role gating
│   │       └── StudentAuth.php        # PIN-based student session authentication
│   ├── Models/                         # Eloquent ORM models (18 models)
│   │   ├── Assessment.php
│   │   ├── AssessmentResult.php
│   │   ├── AssessmentSession.php       # Live teacher-student sessions
│   │   ├── Badge.php
│   │   ├── ComprehensionQuestion.php
│   │   ├── InterventionLog.php
│   │   ├── Intervention.php
│   │   ├── Learner.php                 # Core student model (XP, streaks, PIN)
│   │   ├── Message.php
│   │   ├── PracticeSession.php
│   │   ├── ProgressSnapshot.php
│   │   ├── ReadingMaterial.php
│   │   ├── School.php
│   │   ├── SchoolClass.php
│   │   ├── SystemSetting.php
│   │   └── User.php
│   └── Services/                       # Business logic layer
│       ├── BadgeService.php            # Gamification: check & award badges
│       ├── InterventionRecommenderService.php  # Rank & recommend activities
│       ├── MLClassificationService.php # HTTP client to Python Flask API
│       ├── ReadingAnalyzerService.php  # Core: Levenshtein alignment + metrics
│       └── SpeechToTextService.php     # Local faster-whisper (primary) + OpenAI Whisper API (optional) + mock
│
├── config/
│   ├── bigkas.php          # Phil-IRI levels, weakness categories, WPM benchmarks
│   └── services.php        # API keys: OpenAI, ML service URL
│
├── database/
│   ├── migrations/         # 18 migration files (full schema)
│   └── seeders/            # Demo data (schools, users, learners, materials, etc.)
│
├── ml-service/             # Python ML microservice (CANONICAL — use this one)
│   ├── app.py              # Flask REST API (3 endpoints: /classify, /transcribe, /health)
│   ├── audio_quality.py    # Recording-quality measurement (speech ratio, SNR, background events)
│   ├── weakness_classifier.joblib  # Trained Random Forest (519 teacher-validated clips)
│   ├── feature_scaler.joblib       # StandardScaler fitted on training data
│   ├── model_metadata.json         # Accuracy, feature list, trained_with field
│   ├── requirements.txt    # Python dependencies (includes faster-whisper, the primary STT engine)
│   ├── venv/               # Virtual environment (faster-whisper installed here, not yet fine-tuned)
│   └── training/           # Full ML training pipeline (Steps 0–4)
│       ├── validate_dataset.py         # Step 0: filter incomplete rows → metadata_clean.csv
│       ├── parse_annotations.py        # Step 1: parse annotated transcripts → 12 features
│       ├── generate_provisional_labels.py  # Step 2: rule-based labels 0–4
│       ├── split_features_by_language.py   # Step 3a: split by language for expert review
│       ├── add_transcript_context.py       # Step 3b: add prompt/transcript to review CSVs
│       ├── merge_teacher_labels.py         # Step 3c: merge reviewed CSVs back
│       ├── train_model_real_data.py        # Step 4: train 5-class RF on confirmed labels
│       ├── check_audio_files.py            # Utility: verify audio files against metadata
│       ├── prepare_whisper_manifest.py     # Utility: build Whisper transcription manifest
│       ├── metadata.csv                    # Raw dataset (Google Sheets export)
│       └── metadata_clean.csv              # Filtered dataset (output of Step 0)
│
├── ml/                     # Legacy ML directory (NOT canonical — do not use for deployment)
│   ├── api/
│   │   └── app.py          # Old Flask API (expects raw counts, not rates — wrong contract)
│   └── training/
│       └── (older training scripts)
│
├── public/
│   ├── css/
│   │   ├── app.css         # Main application stylesheet
│   │   └── student.css     # Kid-friendly student portal CSS
│   └── js/
│       ├── app.js          # Main JS utilities
│       ├── audio-recorder.js   # Browser-based audio recording
│       └── student.js      # Student portal: flash cards, PIN input, celebrations
│
├── resources/
│   └── views/              # Blade templates organized by role
│       ├── admin/
│       ├── assessments/
│       ├── auth/
│       ├── dashboard/
│       ├── interventions/
│       ├── learners/
│       ├── layouts/        # app.blade.php, auth.blade.php, student.blade.php
│       ├── materials/
│       ├── parent/
│       ├── partials/       # navbar, sidebar, alerts
│       ├── practice/
│       ├── reports/
│       └── student/        # Student portal views (kid-friendly)
│
└── routes/
    ├── web.php             # All web routes (organized by role/middleware group)
    └── api.php             # REST API routes (Sanctum auth)
```

---

## 🧩 Features & Modules

### Admin Module

| Feature | Description |
|---|---|
| User Management | CRUD for all user accounts; assign roles (Admin, Teacher, Parent, Student) |
| School Management | Register and manage schools in the Sagay City Division |
| Badge Management | Configure 12 achievement badges with XP rewards and unlock criteria |
| System Settings | Key-value configuration store for reading thresholds and system flags |
| Learner Portal Oversight | View XP/streak data, generate/reset PINs, reset gamification stats |
| Intervention Library | Add/edit the bank of evidence-based reading activities |

### Teacher Module

| Feature | Description |
|---|---|
| Learner Management | Add learners with LRN, grade, section, gender, mother tongue |
| Reading Assessment | Record or upload audio → trigger AI pipeline → view results |
| Live Assessment Session | Real-time teacher↔student session with status polling |
| Intervention Assignment | Assign specific activities to learners based on weakness classification |
| Practice Center | Phonemic Awareness drills, Sight Word flashcards, Guided Reading |
| Reports | Individual learner reports and class-level dashboards with charts |
| Messaging | Thread-based messaging with parents regarding specific learners |

### Parent Module

| Feature | Description |
|---|---|
| Child Reading Profile | View accuracy trends, WPM trends, skill breakdown charts |
| Assessment Results | Full assessment history with error breakdown per session |
| Home Activities | Start/complete teacher-assigned intervention activities at home |
| Teacher Messaging | Compose and reply to messages about a specific child |

### Student Portal (PIN-Based, Separate Auth)

| Feature | Description |
|---|---|
| Dashboard | XP total, daily streak, badge count, pending activities |
| Flash Cards | Tap-through word recognition practice with scoring |
| Guided Reading | Select a story → tap each word as read → track progress |
| Activities | Complete teacher-assigned interventions; rate effectiveness |
| Badge Showcase | View all 12 badges (earned vs. locked) with progress ring |
| Class Leaderboard | Ranked by XP; shows streak and badge count |

### Assessment Pipeline (Core Feature)

```
Step 1  → Teacher selects learner, language, assessment type and a grade-matched passage
Step 2  → Audio recorded in the browser (auto-stops at 3 min) or the learner records on their own device
Step 3  → Comprehension answers (if the type needs them) are saved first; the recording is kept on disk
Step 4  → SpeechToTextService → Flask /api/transcribe: recording-quality check, then local
          faster-whisper (OpenAI Whisper API only if configured; mock only in local/testing)
Step 5  → ReadingAnalyzerService aligns spoken words with the passage (edit-distance DP)
Step 6  → MiscueClassifierService labels every word: correct, mispronunciation, substitution,
          omission, not reached, insertion, repetition, self-correction, off-passage, pauses
Step 7  → Metrics: accuracy %, WPM, fluency 0–10, prosody estimate, Phil-IRI reading level
Step 8  → MLClassificationService → Flask /api/classify with 12 rate features
          (Flask or model unavailable: no weakness stored, status "unavailable")
Step 9  → WeaknessEvidence: the prediction is stored only if the measured evidence supports it
Step 10 → Assessment::createResult(): result + full analysis JSON + provenance saved;
          learner reading level updated; linked parents notified
Step 11 → Results page: explanation, recording quality, warnings, recommended activities
Step 12 → Teacher verdict (accept / override / invalidate) is stored beside the AI result
Step 13 → Interventions are assigned; BadgeService.checkAndAward() runs on practice and
          student-portal activity
```

---

## 🔄 Key Workflows

### 1. Standard User Authentication

```
User visits /login
→ Submits email + password
→ AuthController checks is_active flag
→ Auth::attempt() validates credentials
→ last_login_at updated
→ ActivityLog::log('login') recorded
→ Redirected to /dashboard
  → DashboardController checks role:
    admin   → admin dashboard view
    teacher → teacher dashboard view
    parent  → redirect to /parent
    student → student dashboard view
```

### 2. Student PIN Authentication (Separate System)

```
Student visits /student/login
→ Enters 6-digit PIN (auto-submits on completion)
→ StudentAuthController looks up Learner by PIN
→ Checks is_active flag
→ session(['student_learner_id' => $learner->id])
→ Learner::recordActivity() updates streak
→ StudentAuth middleware injects $learner into
  all subsequent requests via $request->attributes
```

### 3. Reading Assessment End-to-End

```
Teacher                    Laravel                       Flask / external
───────                    ───────                       ────────────────
Select learner + material
                    →  Assessment::create() (status: pending)

[Browser records audio]
Click "Analyze"
                    →  ComprehensionService::record()   (answers saved first)
                    →  keepRecording()                  (audio stored, status: processing)
                    →  SpeechToTextService::transcribe()
                                              → Flask POST /api/transcribe
                                                 · audio_quality.measure()
                                                 · faster-whisper (word timestamps)
                                              ← { text, words[], segments[], duration, audio_quality }
                    →  ReadingAnalyzerService::analyze()
                       - extractSpokenTokens(), tokenizeText()
                       - alignTexts() (edit-distance DP)
                       - MiscueClassifierService::classify()
                       - fluency, prosody, reading level, 12 ml_features
                    →  MLClassificationService::classify()
                                              → Flask POST /api/classify
                                              ← { primary_weakness, confidence, scores[5] }
                                              (or unavailable: no label)
                    →  resolve() + WeaknessEvidence::statuses()
                    →  Assessment::createResult()
                    →  Learner::updateReadingLevel(); parents notified
                    ←  Redirect to /assessments/{id}/results

View results page   ←  Level and why, miscues, recording quality, warnings,
                       weakness and its evidence, recommended interventions
Accept / override / invalidate
                    →  AssessmentVerdictController::store()
```

### 4. Badge Award Flow

```
Any action (assessment completed, activity completed, flashcard session)
→ BadgeService::checkAndAward($learner)
→ Loads all active Badge records
→ For each unearned badge, evaluates criteria:
  'assessment_count' → count assessments
  'perfect_score'    → any result with accuracy_rate >= 98
  'streak_days'      → learner.current_streak >= threshold
  'speed_reader'     → any result with WPM >= 100
  'practice_count'   → count practice sessions
  'xp_total'         → total_xp >= threshold
→ If criteria met: insert learner_badges record
→ Learner::addXp($badge->xp_reward)
→ Returns array of newly awarded badge names
```

---

## 🗄 Database Design

### Schema Overview

```
CORE TABLES
├── users               — Accounts for Admin, Teacher, Parent, Student roles
├── schools             — School registry (name, district, division, principal)
├── classes             — Grade sections (school_id, teacher_id, grade, section, year)
└── learners            — Student profiles (LRN, PIN, grade, reading_level, XP, streak)

LINKING TABLE
└── learner_user        — Many-to-many: learner ↔ user (relationship: teacher/parent)

ASSESSMENT TABLES
├── reading_materials       — Passages (title, content, language, grade, difficulty)
├── comprehension_questions — Questions per material (literal/inferential/evaluative)
├── assessments             — Session records (learner, material, assessor, audio_file)
├── assessment_results      — ML outputs (accuracy, WPM, reading_level, primary_weakness)
└── assessment_sessions     — Live teacher↔student sessions (status polling, PIN-free)

INTERVENTION TABLES
├── interventions       — Activity library (20 seeded: 5 per weakness category)
└── intervention_logs   — Assigned + tracked per learner (status, effectiveness_rating)

GAMIFICATION TABLES
├── badges              — 12 achievement badges (criteria JSON, XP reward)
└── learner_badges      — Earned badge records (earned_at, context)

PROGRESS TABLES
├── practice_sessions   — Student practice records (type, score, time_spent)
└── progress_snapshots  — Historical snapshots for longitudinal trend charts

COMMUNICATION
└── messages            — Threaded teacher↔parent messages (sender, receiver, learner_id)

SYSTEM TABLES
├── activity_logs       — Full audit trail (user, action, subject, IP)
└── system_settings     — Key-value config store (setting_key, setting_value, type)

SESSION INFRASTRUCTURE
└── assessment_sessions — Real-time session coordination (status, elapsed, student_progress)
```

### Key Relationships

```
School
  └── has many → Users (teachers), Learners, Classes

SchoolClass
  └── belongs to → School, User (teacher)
  └── has many → Learners

Learner
  ├── belongs to → School, SchoolClass
  ├── many-to-many → Users (via learner_user: relationship type)
  ├── has many → Assessments, InterventionLogs, PracticeSessions
  └── many-to-many → Badges (via learner_badges)

Assessment
  ├── belongs to → Learner, ReadingMaterial, User (assessor)
  └── has one → AssessmentResult

AssessmentResult
  ├── belongs to → Assessment
  └── stores → accuracy_rate, words_per_minute, reading_level,
                primary_weakness (int 0–4, nullable), confidence_score,
                ml_analysis_json (full output)

Intervention
  └── has many → InterventionLogs

InterventionLog
  └── belongs to → Learner, Intervention, User (assigner)
```

### Key Fields Reference

| Table | Important Fields |
|---|---|
| `learners` | `lrn`, `pin` (6-digit), `reading_level`, `total_xp`, `current_streak`, `longest_streak`, `last_activity_date` |
| `assessments` | `audio_file`, `transcription` (JSON), `language` (en/fil/hil), `status` |
| `assessment_results` | `accuracy_rate`, `words_per_minute`, `fluency_score`, `primary_weakness` (int **0–4**, nullable), `confidence_score`, `ml_analysis_json` |
| `interventions` | `target_weakness` (1–4), `activity_type`, `for_teacher`, `for_parent`, `grade_level_min/max`, `effectiveness_score` |
| `badges` | `slug`, `criteria` (JSON), `xp_reward`, `category` |

### Reading Level Thresholds (Phil-IRI Standard)

| Level | Accuracy Range | Meaning |
|---|---|---|
| **Independent** | ≥ 97% | Can read this material on their own |
| **Instructional** | 90–96% | Needs teacher guidance |
| **Frustration** | < 90% | Material is too difficult |

### Weakness Categories

| ID | Name | How it is identified (summary) |
|---|---|---|
| 0 | Independent Reader | No weakness. Accurate and smooth reading; no intervention assigned |
| 1 | Phonemic Awareness | Several sound-alike mispronunciations that make up a large share of the child's errors |
| 2 | Decoding Accuracy | Word-reading accuracy on attempted words below the Phil-IRI cut-offs (< 97% watch, < 90% concern) |
| 3 | Oral Reading Fluency | WPM below the grade benchmark, long pauses, hesitations or repetitions |
| 4 | Reading Comprehension | Comprehension-question score at or below the Phil-IRI cut-offs (measured by questions, not by the voice) |

The full method is described in [How the system identifies each weakness](#how-the-system-identifies-fluency-phonemic-decoding-and-comprehension-weaknesses).

> **Note:** `primary_weakness = 0` means the learner reads at an independent level. The system skips intervention lookup and does not assign activities for class 0.

---

## 🔌 API Documentation

All authenticated routes require a **Bearer token** obtained from `/api/auth/login`.

```
Header: Authorization: Bearer {token}
Content-Type: application/json
```

### Authentication

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/auth/login` | Returns Bearer token |
| `POST` | `/api/auth/register` | Create new account |
| `POST` | `/api/auth/logout` | Revoke current token |
| `GET` | `/api/auth/user` | Get authenticated user info |

**Login Request:**
```json
POST /api/auth/login
{
  "email": "teacher@bigkasai.com",
  "password": "password"
}
```
**Login Response:**
```json
{
  "success": true,
  "data": {
    "token": "1|abc123...",
    "token_type": "Bearer",
    "user": { "id": 2, "name": "Juan Tamad", "role": "teacher" }
  }
}
```

### Learners

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/learners` | List learners (scoped by user role) |
| `POST` | `/api/learners` | Create a learner |
| `GET` | `/api/learners/{id}` | Get learner detail + stats |
| `PUT` | `/api/learners/{id}` | Update learner info |
| `DELETE` | `/api/learners/{id}` | Deactivate learner |
| `GET` | `/api/learners/{id}/progress` | Progress data for charts |
| `GET` | `/api/learners/{id}/assessments` | Assessment history |

### Assessments

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/assessments` | Create assessment session |
| `GET` | `/api/assessments/{id}` | Get session with material content |
| `POST` | `/api/assessments/{id}/audio` | Upload audio file (`multipart/form-data`) |
| `POST` | `/api/assessments/{id}/analyze` | **Trigger full AI pipeline** |
| `GET` | `/api/assessments/{id}/results` | Get results and metrics |

**Analyze Response:**
```json
{
  "success": true,
  "data": {
    "accuracy_rate": 78.5,
    "words_per_minute": 62.3,
    "reading_level": "instructional",
    "errors": {
      "total": 11,
      "substitutions": 7,
      "omissions": 3,
      "insertions": 1
    },
    "classification": {
      "primary_weakness": 2,
      "primary_weakness_name": "Decoding Accuracy",
      "confidence": 0.82,
      "method": "ml"
    },
    "recommendations": [...]
  }
}
```

### Reading Materials

| Method | Endpoint | Description | Auth |
|---|---|---|---|
| `GET` | `/api/materials` | List materials (filter by language, grade, difficulty) | Required |
| `GET` | `/api/materials/{id}` | Full material with content | Required |
| `POST` | `/api/materials` | Create material (admin/teacher only) | Required |
| `PUT` | `/api/materials/{id}` | Update material | Required |
| `DELETE` | `/api/materials/{id}` | Deactivate (admin only) | Required |

### Interventions

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/interventions` | List active interventions (filter by `target_weakness`) |
| `GET` | `/api/interventions/{id}` | Get intervention detail |
| `POST` | `/api/intervention-logs` | Assign intervention to learner |
| `PUT` | `/api/intervention-logs/{id}` | Update log status (pending/in_progress/completed/skipped) |
| `GET` | `/api/recommendations/{assessment}` | Get recommendations for an assessment |

### Speech & ML

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/speech/transcribe` | Transcribe audio file via Whisper |
| `GET` | `/api/speech/languages` | Supported languages |
| `POST` | `/api/ml/classify` | Classify weakness from raw features |
| `POST` | `/api/ml/analyze` | Full analysis with skill scores |
| `GET` | `/api/ml/health` | ML service health check (public) |

### Reports

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/reports/learner/{id}` | Individual learner report data |
| `GET` | `/api/reports/class/{id}` | Class report with distribution |
| `GET` | `/api/reports/dashboard` | Dashboard stats (scoped by role) |

---

## 🚀 Setup & Installation Guide

### Prerequisites

- PHP 8.2+
- Composer 2.x
- Node.js 18+ and npm
- Python 3.10+
- MySQL 8.0+ or SQLite (SQLite works out of the box for development)
- OpenAI API key *(optional — mock mode is available)*

---

### Step 1: Clone & Install Laravel Dependencies

```bash
git clone https://github.com/clyd-dev/BIGKAS-AI bigkas
cd bigkas
composer install
```

### Step 2: Configure Environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` with your settings:

```env
APP_NAME=BIGKAS-AI
APP_URL=http://localhost:8000

# Database (SQLite for dev, MySQL for production)
DB_CONNECTION=sqlite
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=bigkas
# DB_USERNAME=root
# DB_PASSWORD=

# OpenAI Whisper — optional cloud alternative; local faster-whisper
# (not yet fine-tuned) is the primary transcription engine and needs no key
OPENAI_API_KEY=

# Python ML Service
ML_API_URL=http://localhost:5000
ML_API_ENABLED=true
```

### Step 3: Set Up the Database

```bash
# Create the SQLite file (if using SQLite)
touch database/database.sqlite

# Run migrations and seed demo data
php artisan migrate --seed
```

### Step 4: Build Frontend Assets

```bash
npm install
npm run build
```

### Step 5: Set Up the Python ML Service

```bash
cd ml-service/

# Create and activate a virtual environment
python -m venv venv

# Windows
venv\Scripts\activate
# macOS/Linux
source venv/bin/activate

# Install Python dependencies (includes faster-whisper, scikit-learn, flask)
pip install -r requirements.txt

# The trained model files are already committed to the repo:
#   ml-service/weakness_classifier.joblib   ← Random Forest classifier
#   ml-service/feature_scaler.joblib        ← StandardScaler
#   ml-service/model_metadata.json          ← metadata (check trained_with field)
#
# The model was trained on 519 teacher-validated Grade 3-4 clips; see the
# Machine Learning Component section for its measured accuracy and limits.
# Do NOT run training/train_model_real_data.py unless you intend to replace these files.

# Start the Flask API
python app.py
# Runs on http://localhost:5000

# Verify it's working
curl http://localhost:5000/api/health
```

Expected health check response:
```json
{
  "status": "ok",
  "model_version": "3.0.0-12feature-5class-combo-aware",
  "feature_columns": ["accuracy_rate", "words_per_minute", "..."],
  "scaler_loaded": true,
  "whisper_loaded": true,
  "classifier_loaded": true
}
```

### Step 6: Start the Application

```bash
# Terminal 1 — Laravel web server
php artisan serve
# → http://localhost:8000

# Terminal 2 — Python ML service (activate venv first)
cd ml-service/ && venv\Scripts\activate && python app.py
# → http://localhost:5000

# Terminal 3 — Queue worker (optional: notifications are sent synchronously)
php artisan queue:listen --tries=1
```

**Windows shortcut — the engine and the scheduler in one window.** Instead of the
ML-service terminal above plus a second terminal for `php artisan schedule:work`,
double-click `start-bigkas.bat` (or run `.\start-bigkas.ps1`). It starts the Python
engine, waits for the speech model to load, then starts the scheduler, and shows both
in one window tagged `[engine]` and `[scheduler]`. Ctrl+C or closing the window stops
both; nothing is left running behind.

```powershell
.\start-bigkas.ps1                # engine + scheduler (needed for batch assessments)
.\start-bigkas.ps1 -NoScheduler   # engine only, exactly as before (single-child assessments)
```

The scheduler only matters for **batch** assessments and for re-scoring readings saved
while the engine was down. A single-child assessment is scored by the Analyze button
itself and does not depend on it.

### Quick Setup (All-in-One)

```bash
composer run dev
# Runs: Laravel + queue worker + log watcher + Vite dev server concurrently
```

### Running the tests

```bash
php artisan test    # 371 tests, SQLite in-memory
```

### Production Notes

- Set `APP_ENV=production` and `APP_DEBUG=false`
- Use a process manager (Supervisor) to keep the Flask service running
- Configure a proper web server (Nginx/Apache) pointing to `/public`
- Speech-to-text is local by default (`WHISPER_USE_LOCAL=true`); set `OPENAI_API_KEY` only if you want the cloud fallback
- Run the ML service with gunicorn (see `docs/deployment-vps.md`), not `python app.py`
- Rebuild all caches after every pull: `php artisan config:cache && php artisan route:cache && php artisan view:cache`
- Switch `DB_CONNECTION=mysql` with proper credentials

---

## 🔑 Default Credentials

> ⚠️ **Change these immediately in any non-development environment.**

| Role | Email | Password |
|---|---|---|
| Admin | `admin@bigkasai.com` | `admin123` |
| Teacher | `teacher@bigkasai.com` | `password` |
| Parent | `parent@bigkasai.com` | `password` |

**Student access:** Use a 6-digit PIN generated by a teacher or admin.
- Go to Admin Panel → Learner Portal → click the 🔑 key icon next to a learner
- Or: Learner profile page → "Generate PIN" button

**Admin password reset for any user:** Admin Panel → Users → click the 🔑 key icon → resets to `Bigkas@123`

---

## 📖 Usage Guide

### For Teachers: Conducting an Assessment

1. Navigate to **Assessments → New Assessment**
2. Select the learner and an appropriate reading material
3. Click **Start Assessment** — this opens the recording interface
4. Have the student read the passage aloud while you record using your browser's microphone, or upload a pre-recorded audio file
5. Click **Analyze** to run the AI pipeline
6. View results: accuracy %, WPM, error breakdown, weakness classification, and recommended interventions
7. Click **Assign Intervention** to send a specific activity to the learner

### For Teachers: Live Assessment Sessions

1. Go to **Assessments → New Assessment → Create Live Session**
2. The student logs into the Student Portal on their own device using their PIN
3. The assessment appears automatically on their dashboard
4. Monitor the student's progress in real time from the Teacher monitor view

### For Parents: Home Activities

1. Log in and navigate to **My Children → [Child Name] → Home Activities**
2. View pending activities assigned by the teacher
3. Click **Start** when beginning an activity
4. Click **Complete** when finished; rate effectiveness (1–10)
5. Notes and ratings are visible to the teacher

### For Students: Flash Cards

1. Log in with your 6-digit PIN at `/student/login`
2. Tap **Flash Cards** from the home screen
3. Tap the card to flip it — see the word, then decide: **I Know It!** or **Still Learning**
4. Earn XP for each correct card; results saved automatically

---

## 🤖 Machine Learning Component

BIGKAS uses two models and a set of transparent rules. Keeping them apart matters when explaining the system:

| Stage | Technique | Trained by us? |
|---|---|---|
| Speech-to-text | `faster-whisper` **small** (pre-trained, int8, CPU) | No (a LoRA fine-tuning script exists, see below) |
| Word alignment and miscue labelling | Edit-distance dynamic programming + hand-written rules | n/a (deterministic) |
| Weakness suggestion | **Random Forest** on 12 numeric features | Yes, on 519 teacher-validated clips |
| Evidence check | Rules over measured miscue counts (`WeaknessEvidence`) | n/a (deterministic) |
| Final decision | The teacher (`AssessmentVerdictController`) | n/a |

### How the system identifies fluency, phonemic, decoding and comprehension weaknesses

Identification is a **two-step process**: the Random Forest *proposes* a category from 12 measurements, then the **evidence check** only lets a category be stored if the recording's own measured counts support it. Comprehension is the exception: it is measured by questions, not by the voice.

```
audio ─► Whisper (words + timestamps) ─► alignment with the passage ─► miscue labels
                                                   │
                                  12 features ─────┤──────────────► Random Forest ─► proposed class + probabilities
                                                   │                                   │
                              miscue counts ───────┴─► WeaknessEvidence (ok/watch/concern per skill)
                                                                                       │
                       comprehension questions ─► Phil-IRI comprehension score ────────┤
                                                                                       ▼
                                                       stored primary weakness (only if evidence-backed)
                                                                                       ▼
                                                              teacher accepts / overrides / invalidates
```

#### 1. Oral reading fluency (class 3)

*Does the child read at a pace and smoothness expected for their grade?*

| Evidence | How it is measured | Where |
|---|---|---|
| Speed | Words per minute = correct words ÷ recording seconds × 60, compared with the grade benchmark in `config/bigkas.php` (`wpm_benchmarks`, e.g. Grade 3: minimum 70, target 110) | `ReadingAnalyzerService::analyze()` |
| Pauses | Gap between consecutive words from Whisper timestamps: ≥ 1 s is a **hesitation**, ≥ 3 s a **long pause** | `MiscueClassifierService::markPauses()` |
| Repetitions | Repeated words, repeated phrases, false starts such as "ma-mabilis" | `MiscueClassifierService` |
| Smoothness score | `fluency_score` 0–10: starts at 10, loses points for a long average gap, many long gaps, and very slow (< 50 WPM) or rushed (> 150 WPM) reading | `ReadingAnalyzerService::calculateFluencyScore()` |

**Evidence status** (`WeaknessEvidence::fluencyStatus`): WPM below the grade minimum → *concern*; below the grade target → *watch*; 3 or more long pauses → *concern*; 1 long pause or 3 hesitations → *watch*; 3 or more repetitions → *watch*. Features that carry most of the model's weight for this area: `words_per_minute` and `fluency_score` (`pause_frequency` is one of the least influential features).

#### 2. Phonemic awareness (class 1)

*Are the child's errors sound-alike attempts, suggesting trouble linking letters to sounds?*

| Evidence | How it is measured | Where |
|---|---|---|
| Mispronunciations | A wrong word that **sounds like the target**: string similarity ≥ 0.5, or matching soundex / metaphone | `MiscueClassifierService::markReplacements()` |
| Error-pattern shares | Each substitution is bucketed as phonetic confusion, vowel confusion or consonant-blend error; the model receives each bucket as a *share of the child's errors* (`phonetic_error_rate`, `vowel_error_rate`, `blend_error_rate`) | `ReadingAnalyzerService::identifySubstitutionPattern()` |

**Evidence status** (`WeaknessEvidence::phonemicStatus`): at least 3 sound-alike slips **and** they are at least 40% of all slips → *concern*; at least 1 and ≥ 40% → *watch*; otherwise *ok*. This guard exists because the pattern features are shares: a single vowel-like slip would otherwise read as a "100% phonics problem".

> Limit: there is no phoneme-level or pronunciation model. "Sounds like the target word" is judged on the transcribed text.

#### 3. Decoding accuracy (class 2)

*Does the child read the words on the page correctly?*

| Evidence | How it is measured | Where |
|---|---|---|
| Word-reading accuracy | Correct words ÷ words in the passage × 100 (omissions, substitutions and unread words lower it; insertions do not) | `ReadingAnalyzerService::analyze()` |
| Substitution and omission rates | Counts ÷ passage length from the word alignment | `compareTexts()` / `alignTexts()` |

**Evidence status** (`WeaknessEvidence::decodingStatus`), judged on the words actually attempted, using the Phil-IRI cut-offs in `PhilIriLevels`: below 90% → *concern*; below 97% → *watch*; otherwise *ok*. Features the model uses most: `accuracy_rate`, `omission_rate`, `substitution_rate`.

#### 4. Reading comprehension (class 4)

*Does the child understand what was read?*

Comprehension **cannot be heard in a voice recording**, so BIGKAS does not infer it from the voice:

- Each passage has **four comprehension questions**. The learner answers them (from the teacher's screen or the student portal) before analysis.
- `ComprehensionService::record()` scores them as a percentage, and `PhilIriLevels::comprehension()` applies the Phil-IRI cut-offs: **≥ 80% independent, 59–79% instructional, ≤ 58% frustration**.
- `WeaknessEvidence::comprehensionStatus` maps those levels to ok / watch / concern, and returns **unknown** if the questions were not taken, in which case comprehension can never be stored as the weakness.
- The overall reading level is the **lower** of the word-reading level and the comprehension level when both exist (`Assessment::readingLevels()`).

The Random Forest has class 4 in its output only because the schema follows the Phil-IRI skill areas; the training data contains just **3** such examples, so its class-4 output is not used as evidence.

#### Independent reader (class 0)

No weakness. A model prediction of 0 needs no supporting evidence.

#### What happens when the model and the evidence disagree

`MLClassificationService::resolveWeaknessId()`:

1. Take the model's top class. Keep it if it is 0, or if its skill status is *watch* or *concern*.
2. Otherwise walk the model's probabilities from highest to lowest and take the first class that the evidence supports.
3. If none is supported, **store no weakness** (status `model_unsupported`). No rule picks a label in the model's place.

So a stored weakness is always one the model itself ranked and a flagged skill row backs (`tests/Feature/WeaknessMatchesEvidenceTest.php`, `tests/Unit/ResolveWeaknessTest.php`). The model's raw answer is stored beside it (`classifier_output`), so an adjusted label can be audited. The teacher can still override it with a written reason.

### Algorithm

**Random Forest Classifier** (scikit-learn): 100 trees, `max_depth=8`, `min_samples_split=5`, `min_samples_leaf=3`, `class_weight='balanced'`, `random_state=42`. Inputs are standardised with a `StandardScaler` fitted on the training split only. The prediction **confidence** shown to teachers is `max(predict_proba)`, i.e. the vote share of the winning class. It is not a calibrated probability of being right.

### Training Data

| Item | Value |
|---|---|
| Source | Grade 3 and 4 learners reading scripted passages, in Filipino and English; annotated by the team, labels confirmed by teachers |
| Learners | 36 distinct learner IDs |
| Rows in the training snapshot | 527 loaded, **519 usable** (415 train / 104 test) |
| Language | 419 Filipino, 108 English (of the 527 loaded) |
| Grades | 3 and 4 only. Grades 5 and 6 are not yet represented |
| Label source | `teacher_confirmed_label`. Combined labels such as `1,3` are reduced to the first number |
| Class counts | 0 Independent: 68 · 1 Phonemic: 145 · 2 Decoding: 86 · 3 Fluency: 217 · 4 Comprehension: **3** |

How the labelled features are produced (`ml-service/training/`): annotators tag each transcript (`[om_word]`, `[sub: target > spoken]`, `[mis: ...]`, `[ins: ...]`, `[pause]`) → `parse_annotations.py` converts the tags to the 12 features → `generate_provisional_labels.py` proposes a rule-based label → teachers confirm it and give a 0–10 prosody rating → `merge_teacher_labels.py` → `train_model_real_data.py`.

### Feature Inputs (12 Features)

| Feature | Description | Range |
|---|---|---|
| `accuracy_rate` | % of passage words read correctly | 0–100 |
| `words_per_minute` | Correct words per minute | 0–200 |
| `fluency_score` | Pause and pace score | 0–10 |
| `substitution_rate` | Wrong words ÷ passage words | 0–1 |
| `omission_rate` | Skipped words ÷ passage words | 0–1 |
| `insertion_rate` | Extra words ÷ passage words | 0–1 |
| `phonetic_error_rate` | Share of substitutions that are sound-alike | 0–1 |
| `vowel_error_rate` | Share that are vowel confusions | 0–1 |
| `blend_error_rate` | Share that are consonant-blend errors | 0–1 |
| `self_correction_rate` | Repetitions / false starts ÷ passage words | 0–1 |
| `pause_frequency` | Gaps > 1 s ÷ passage words | 0–1 |
| `prosody_score` | Expression score (teacher rating in training; heuristic estimate live) | 0–10 |

### Output Classes

| Class | Name | Notes |
|---|---|---|
| `0` | Independent Reader | No weakness; intervention lookup skipped |
| `1` | Phonemic Awareness | |
| `2` | Decoding Accuracy | |
| `3` | Oral Reading Fluency | |
| `4` | Reading Comprehension | 3 training examples; comprehension is measured by questions |

### Model Performance

Measured on the saved model with `ml-service/model_metadata.json` (version `3.0.0-12feature-5class-combo-aware`) and re-computed from the training CSV:

| Measure | Value |
|---|---|
| Test accuracy (104 held-out clips) | **62.5%** (65 / 104) |
| Majority-class baseline on the same clips | 41.3% |
| 5-fold cross-validation accuracy | 49.9% ± 10.5 points |
| Training accuracy | 83.6% (the model overfits) |
| Teacher's label within the model's top 2 classes | 85.6% |
| Macro F1 / weighted F1 | 0.51 / 0.63 |

| Class | Precision | Recall | F1 | Test clips |
|---|---|---|---|---|
| 0 Independent | 0.85 | 0.79 | 0.81 | 14 |
| 1 Phonemic | 0.54 | 0.52 | 0.53 | 29 |
| 2 Decoding | 0.45 | 0.59 | 0.51 | 17 |
| 3 Fluency | 0.74 | 0.67 | 0.71 | 43 |
| 4 Comprehension | 0.00 | 0.00 | 0.00 | 1 |

Read these numbers with the following in mind:

- The test set is small (104 clips): the 95% interval around 62.5% is roughly 53–72%.
- The split is by clip, and 25 of the 26 test learners also appear in training, so the figure is optimistic for a new child. A learner-grouped re-run gave about 57%.
- Features in training come from human annotation tags, while live features come from Whisper output and automatic rules. Several of the 12 features are computed by different procedures in the two places, so field performance is expected to be lower until the model is retrained on live-extracted features.
- No end-to-end validation against a teacher's own Phil-IRI marking has been run yet.

Feature importance of the saved model: `words_per_minute` 0.19, `accuracy_rate` 0.18, `omission_rate` 0.16, `substitution_rate` 0.12, `prosody_score` 0.10, `fluency_score` 0.08, the rest below 0.07 each.

### Flask API Endpoints (`ml-service/app.py`)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/health` | Reports `model_version`, `scaler_loaded`, `whisper_loaded`, `classifier_loaded` |
| `POST` | `/api/classify` | Classify weakness from 12 pre-computed rate features |
| `POST` | `/api/transcribe` | Measure recording quality, then transcribe locally with `faster-whisper` |

Speech-to-text details: language `fil` is sent to Whisper as `tl`; by default a disfluent prompt and `condition_on_previous_text=False` keep repeats and hesitations in the transcript (`WHISPER_VERBATIM=0` turns this off); a voice-activity check (`audio_quality.py`) returns an empty transcript for silence instead of letting Whisper invent words, and reports signal-to-noise, background sounds and a clean / moderate / noisy rating.

### ML Training Pipeline (Real Data)

Scripts live under `ml-service/training/`:

```bash
# Step 0: Validate and clean the raw metadata export
python training/validate_dataset.py          # → metadata_clean.csv

# Step 1: Parse annotated transcripts, compute 12 features
python training/parse_annotations.py         # → extracted_features.csv

# Step 2: Apply provisional rule-based labels (classes 0–4)
python training/generate_provisional_labels.py   # → labeled_features_for_review.csv

# Step 3: Split by language for teacher review, add transcript context
python training/split_features_by_language.py
python training/add_transcript_context.py
# [Teachers fill teacher_confirmed_label and prosody_score_teacher]

# Step 3c: Merge confirmed labels
python training/merge_teacher_labels.py      # → labeled_features_master_confirmed.csv

# Step 4: Train the 5-class Random Forest
python training/train_model_real_data.py
# ⚠ OVERWRITES ml-service/weakness_classifier.joblib, feature_scaler.joblib
#   and model_metadata.json. Back them up first.
```

To check the saved model without retraining, load `weakness_classifier.joblib` and `feature_scaler.joblib`, rebuild the same split (`test_size=0.2`, `random_state=42`, stratified) and call `accuracy_score`.

Optional Whisper fine-tuning: `ml-service/training/prepare_whisper_manifest.py` builds the manifest and `ml-service/training/bigkas_whisper_data/train_whisper_lora.py` trains a LoRA adapter on whisper-small (speaker-level splits, WER via `jiwer`) and converts it to CTranslate2. If a folder `ml-service/bigkas-whisper-ct2/` exists, `app.py` loads it instead of the stock model. It has not been run yet, and no WER has been measured.

### When the model is unavailable

There is no rule-based fallback. When the Flask service is down, has no model loaded, returns an error or is disabled (`ML_API_ENABLED=false`), the analysis still runs and is saved, but **no weakness is classified**:

| `classification_status` | Meaning | What is stored |
|---|---|---|
| `classified` | The model answered and the measurements back its label | Label, the model's confidence, the model's raw answer |
| `model_unsupported` | The model answered, but none of its ranked choices is backed by the measurements | No label, no confidence |
| `unavailable` | The model could not answer (down, not loaded, error, disabled) | No label, no confidence, the reason |
| *(empty)* | Saved before this was tracked (legacy) | Keeps its label, shown with a warning about its source |

- **Every page reads one answer.** `Assessment::weaknessOutcome()` returns `classified`, `teacher_set`, `unclassified` or `legacy`; no page or service reads the raw column (`tests/Feature/ClassificationPagesTest.php` fails if one does). Unclassified results recommend no interventions or practice and are left out of the admin chart, which reports how many are awaiting classification.
- **Re-analyze, don't re-read.** The results page offers *Re-analyze*, which reruns the saved recording once the model is back. Results with no saved recording (anything before recordings were kept) can only be re-assessed.
- **Told before, not after.** The assessment start page shows whether the analysis service can classify right now. Recording is never blocked.
- **The teacher decides.** A teacher can set the weakness with *Override* on any result, including an unclassified one.
- **Parents** see plain wording ("focus area not yet checked"), never the machinery.

#### When the speech engine is down

The speech engine (faster-whisper) and the classifier load separately inside the same Flask service, so either can be down alone. If Flask is off, both are.

There is **no placeholder transcript**. With no speech engine, a recording is saved but **nothing is scored**: no result row, no accuracy, pace or level, and the teacher is told to tap *Analyze* once the engine is back. (An earlier version returned a canned transcript when `APP_ENV=local`; it scored as a flawless 100% "independent" reading and could be accepted as a child's record.)

A reading that was never measured from the child's voice (a stored placeholder result, or a recording with no speech) shows "Nothing was measured" with no numbers, cannot be accepted, and does not count towards a learner's level, averages, reports, Form 3 or Form 4. The teacher can invalidate it or count the reading themselves with Override. After upgrading, run `php artisan learners:recompute-levels` once (add `--dry-run` to preview).

---

## ⚠️ Observations & Known Limitations

### Technical Limitations

| Limitation | Details |
|---|---|
| **Modest classifier accuracy** | 62.5% on a 104-clip test set (41% majority baseline, about 50% in cross-validation, about 57% when split by learner). It is a suggestion for the teacher to confirm, not a diagnosis. |
| **Small, narrow training set** | 519 usable clips from 36 Grade 3 and 4 learners. Grades 5 and 6 are not represented, and English has no examples of the fluency or comprehension classes. |
| **Comprehension class not learnable** | Only 3 training examples. Comprehension is measured with the comprehension questions and Phil-IRI cut-offs, not predicted from the voice. |
| **Train/serve feature skew** | Training features come from human annotation; live features come from Whisper output and automatic rules (fluency, error-pattern shares, pause threshold and prosody are computed differently). |
| **Local STT not fine-tuned** | `faster-whisper` runs the stock pre-trained `small` model. Accuracy on Filipino and noisy classroom recordings has not been measured (no WER). A LoRA fine-tuning script exists but has not been run. |
| **No end-to-end validation** | The whole pipeline has not yet been compared against a teacher's manual Phil-IRI marking in a classroom. |
| **Prosody is an estimate** | Training used a teacher's 0–10 rating; the live value is a heuristic from word confidence and timing, not an acoustic measure. |
| **No Hiligaynon support** | Reading assessment supports English and Filipino only. `hil` still appears as a schema/locale value but is not supported in practice. |
| **Synchronous analysis** | Analysis runs inside the request (seconds to minutes on a small CPU server). There is no job queue. |
| **Polling-based live sessions** | Live teacher↔student sessions poll every 3 seconds instead of using WebSockets. |
| **Open-ended answers not auto-scored** | Comprehension questions are scored as multiple choice; open-ended answer evaluation is not automated. |
| **Recordings on the public disk** | Audio is stored under `storage/app/public/assessments/audio` with predictable names. Moving it to a private disk behind an authorised route is recommended. |
| **No parental consent workflow** | See Future Enhancements. No formal Data Privacy Act review has been done. |

### Technical Debt / Code Observations

- `SchoolClass` uses `$table = 'classes'` (reserved word in some SQL contexts); consider renaming to `school_classes`.
- `MLClassificationService::submitTrainingData()`, `getModelInfo()` and `analyze()` call endpoints the Flask app does not implement (`/api/training-data`, `/api/model-info`, `/api/analyze`). There is no online learning; retraining is offline.
- `InterventionRecommenderService` (priority, impact, weekly plan) is used by the REST API controllers; the web results page uses `Assessment::getRecommendedInterventions()`.
- `ml-service/app.py` runs Flask with `debug=True` when started directly; production uses gunicorn (see `docs/deployment-vps.md`).
- The legacy `ml/` directory is not canonical; use `ml-service/`.

---

## 🔮 Future Enhancements

- [ ] **Retrain on live-extracted features** — Re-extract the 12 features from Whisper output for the training clips so training matches serving
- [ ] **Learner-level evaluation and more data** — Split by learner, add Grade 5 and 6 and comprehension cases (annotated clips exist in `metadata_clean.csv` that are not in the current training snapshot)
- [ ] **Measure and improve speech-to-text** — Compute WER on BIGKAS audio, then run `train_whisper_lora.py` on a GPU (e.g. Google Colab)
- [ ] **Classroom validation** — Blinded comparison with teachers' manual Phil-IRI marking
- [ ] **Private audio storage and parental consent** — Authorised audio streaming and an in-app consent workflow
- [ ] **WebSocket-based live sessions** — Replace HTTP polling with Laravel Echo + Pusher
- [ ] **Background analysis queue** — Move transcription to a queue for batch assessments (see `docs/2026-10-09-batch-assessment-plan.html`)
- [ ] **Mobile app** — Flutter or React Native app using the existing Sanctum REST API
- [ ] **Offline PWA** — Service worker for offline reading practice and queued submission
- [ ] **Direct comprehension scoring** — NLP for open-ended comprehension answers
- [ ] **Consolidate ml/ and ml-service/** — Delete or merge the legacy `ml/` directory
- [x] **Real-data training pipeline** — Implemented and used to train the current model on teacher-validated clips
- [x] **Teacher verdict and audit trail** — Accept, override or invalidate any AI result with a recorded reason
- [x] **Phil-IRI forms** — Group Screening Test records, Form 2, Form 3A/3B and Form 4

---

## 👥 Authors & Acknowledgments

### Research Team

| Name | Role |
|---|---|
| **Soquita, Ellen Rose** | Project Manager |
| **Buagas, Jeremy Rose** | Developer |
| **Navarro, Frederick** | Developer |
| **Neri, Ramon** | Developer |

### Institution

**State University of Northern Negros**  
College of Computing and Information Sciences  
Bachelor of Science in Information Technology — Capstone Project  
Academic Year 2025–2026

### Acknowledgments

- **Sagay City Division Schools** — For providing the educational context and needs assessment
- **DepEd** — Phil-IRI standards and MTB-MLE program guidelines
- **OpenAI** — Whisper speech-to-text API
- **EDCOM 2** — Research findings on Philippine literacy challenges

---

## 📄 License

This project is developed for academic purposes as a capstone requirement of the BS Information Technology program at State University of Northern Negros. All rights reserved by the authors.

---

*Built with ❤️ for the reading learners of Sagay City, Negros Occidental, Philippines*
