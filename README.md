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

The Philippines faces a severe reading literacy crisis. According to EDCOM 2, **70% of Grade 3 learners struggle with basic phonemic awareness**, resulting in a learning gap equivalent to over five years of schooling by adolescence. Traditional assessment tools like the paper-based Phil-IRI are too slow to catch specific error patterns before they become ingrained habits.

### What BIGKAS Does

BIGKAS automates the oral reading assessment process. A teacher records a student reading a passage aloud, and the system:

1. Transcribes the audio using a local `faster-whisper` model (speech-to-text; not yet fine-tuned for Filipino classroom audio)
2. Compares the spoken words against the reference passage word-by-word
3. Computes reading metrics (accuracy %, words per minute, fluency score, error types)
4. Classifies the student's primary reading weakness using a Random Forest ML model
5. Recommends targeted intervention activities for teacher and parent use
6. Tracks progress over time with visual dashboards

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
- **Graceful Degradation** — Falls back to rule-based classification if the ML service is unavailable
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
│  18 tables  │   │  yet fine-tuned)│   │ Rule-based fallback  │
│             │   │  -or- OpenAI    │   │ if unavailable       │
│             │   │  Whisper API    │   │                      │
└─────────────┘   └────────────────┘   └─────────────────────┘
```

### Architectural Patterns

- **MVC (Model-View-Controller)** — Laravel enforces MVC. Controllers handle HTTP, Models handle data, Blade templates handle views.
- **Service Layer** — Complex business logic (reading analysis, ML calls, gamification) lives in `app/Services/`, keeping controllers thin.
- **Dual Authentication** — Standard Laravel session auth for teachers/parents/admins. A completely separate PIN-based session system (`StudentAuth` middleware) for child-friendly student access.
- **ML-First with Rule-Based Fallback** — The system always tries the Python ML API first. If it's unreachable or throws an error, `MLClassificationService` catches the exception and falls back to a deterministic rule-based classifier in `ReadingAnalyzerService`, ensuring zero downtime for assessments.
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

On exception (service down):
ReadingAnalyzerService.php
→ ruleBasedClassification()   (no crash, system continues)
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
│   ├── weakness_classifier.joblib  # Trained Random Forest (currently: synthetic data)
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
Step 1 → Teacher selects learner + reading material
Step 2 → Audio recorded in browser OR file uploaded
Step 3 → SpeechToTextService sends audio to the local faster-whisper
          model (primary, not yet fine-tuned); OpenAI Whisper API is an
          optional cloud alternative (mock transcription used if neither is available)
Step 4 → ReadingAnalyzerService tokenizes & aligns text
          using Levenshtein dynamic programming algorithm
Step 5 → Error analysis:
          - Substitutions (wrong word)
          - Omissions (skipped word)
          - Insertions (added word)
          - Pattern detection (phonetic confusion, vowel errors, blend errors)
Step 6 → Metrics computed:
          - Accuracy rate (% correct words)
          - Words per minute (WPM)
          - Fluency score (0–10, based on pause frequency)
          - Reading level (Frustration / Instructional / Independent)
Step 7 → MLClassificationService calls Python Flask /api/classify
          → 12 pre-computed rate features sent (not raw counts)
          → Returns primary weakness (0–4) + confidence score
          → Falls back to rule-based if Flask unavailable
          → primary_weakness = 0 → Independent Reader; skip interventions
Step 8 → AssessmentResult saved to database
Step 9 → Learner.reading_level updated
Step 10 → InterventionRecommenderService ranks & returns top 7 activities
Step 11 → BadgeService.checkAndAward() evaluates all badge criteria
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
Teacher                    Laravel                   External Services
───────                    ───────                   ─────────────────
Select learner + material
                    →  Assessment::create()
                    →  AssessmentSession::create()
                    →  Redirect to monitor page

[Browser records audio]
Upload audio file
                    →  Store to storage/audio/
                    →  assessment.status = 'recording'

Click "Analyze"
                    →  SpeechToTextService::transcribe()
                                              → Local faster-whisper (primary,
                                                not yet fine-tuned); OpenAI
                                                Whisper API optional fallback
                                              ← { text, words[], duration }
                    →  ReadingAnalyzerService::analyze()
                       - tokenizeText()
                       - alignTexts() (Levenshtein DP)
                       - analyzeErrors()
                       - calculateFluencyScore()
                       - classifyWeakness()
                                              → MLClassificationService
                                              → Flask POST /api/classify
                                              ← { primary: 2, confidence: 0.85 }
                                              (or rule-based fallback)
                    →  Assessment::createResult()
                    →  Learner::update(reading_level)
                    →  InterventionRecommenderService::getRecommendations()
                    →  BadgeService::checkAndAward()
                    ←  Redirect to /assessments/{id}/results

View results page   ←  Metrics + charts + recommended interventions
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

| ID | Name | Indicators |
|---|---|---|
| 0 | Independent Reader | Accuracy ≥ 95%, WPM ≥ 60, fluency ≥ 8 — no weakness; no intervention assigned |
| 1 | Phonemic Awareness | High phonetic/vowel/blend error rates |
| 2 | Decoding Accuracy | Low accuracy, many substitutions |
| 3 | Oral Reading Fluency | Slow WPM, high pause frequency, low fluency score |
| 4 | Reading Comprehension | Accurate but many omissions, low prosody |

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
# WARNING: The current model was trained on SYNTHETIC data (trained_with: "synthetic_data").
# It will be retrained on real student assessment data after teacher review is complete.
# See the ML Training Pipeline section below for details.

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
  "model_loaded": true,
  "classifier_loaded": true,
  "service": "bigkas-ml"
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

# Terminal 3 — Queue worker (for background jobs)
php artisan queue:listen --tries=1
```

### Quick Setup (All-in-One)

```bash
composer run dev
# Runs: Laravel + queue worker + log watcher + Vite dev server concurrently
```

### Production Notes

- Set `APP_ENV=production` and `APP_DEBUG=false`
- Run `php artisan config:cache && php artisan route:cache`
- Use a process manager (Supervisor) to keep the Flask service running
- Configure a proper web server (Nginx/Apache) pointing to `/public`
- Set `OPENAI_API_KEY` for real speech-to-text transcription
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

### Algorithm

**Random Forest Classifier** — selected over Decision Tree and Gradient Boosting based on highest test accuracy on synthetic data.

### Training Data

- Current model: **synthetic data** — 2,000 samples (500 per class, 4 original classes)
- `model_metadata.json` `trained_with` field reads `"synthetic_data"` — confirms this
- Real-data training pipeline is **in progress**: reading experts are reviewing annotated transcripts via the 5-step pipeline (`validate_dataset.py` → `parse_annotations.py` → `generate_provisional_labels.py` → teacher review CSVs → `train_model_real_data.py`)
- Once real data is validated, rerun `train_model_real_data.py` to replace the current model

### Feature Inputs (12 Features)

| Feature | Description | Range |
|---|---|---|
| `accuracy_rate` | % of words read correctly | 0–100 |
| `words_per_minute` | Reading speed | 0–200 |
| `fluency_score` | Pause/rhythm score | 0–10 |
| `substitution_rate` | Wrong word rate | 0–1 |
| `omission_rate` | Skipped word rate | 0–1 |
| `insertion_rate` | Extra word rate | 0–1 |
| `phonetic_error_rate` | Sound confusion rate | 0–1 |
| `vowel_error_rate` | Vowel mistake rate | 0–1 |
| `blend_error_rate` | Consonant blend error rate | 0–1 |
| `self_correction_rate` | Self-fix rate | 0–1 |
| `pause_frequency` | Pause density | 0–1 |
| `prosody_score` | Expression/intonation score | 0–10 |

### Output Classes

| Class | Name | Notes |
|---|---|---|
| `0` | Independent Reader | No weakness; intervention lookup skipped |
| `1` | Phonemic Awareness | |
| `2` | Decoding Accuracy | |
| `3` | Oral Reading Fluency | |
| `4` | Reading Comprehension | |

### Model Performance

- **Accuracy:** ~99% on synthetic test set
- **Note:** This figure is inflated because the model was trained on generated data. It is expected to decrease when retrained on real student recordings — this is by design and reflects a more honest measurement.

### Flask API Endpoints (`ml-service/app.py`)

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/health` | Health check — reports model loaded status |
| `POST` | `/api/classify` | Classify weakness from 12 pre-computed rate features |
| `POST` | `/api/transcribe` | Transcribe audio locally using `faster-whisper` |

### ML Training Pipeline (Real Data)

The system includes a full 4-step training pipeline under `ml-service/training/`:

```bash
# Step 0: Validate and clean the raw metadata export
python training/validate_dataset.py
# → produces metadata_clean.csv

# Step 1: Parse annotated transcripts, compute 12 features
python training/parse_annotations.py
# → produces labeled_features_for_review.csv

# Step 2: Apply provisional labels (rule-based, classes 0–4)
python training/generate_provisional_labels.py
# → updates labeled_features_for_review.csv with provisional_weakness_label

# Step 3: Split by language for expert review, add transcript context
python training/split_features_by_language.py
python training/add_transcript_context.py
# → produces labeled_features_for_review_fil.csv + labeled_features_for_review_en.csv

# [Reading experts fill in teacher_confirmed_label column in Google Sheets]

# Step 3c: Merge confirmed labels back
python training/merge_teacher_labels.py
# → produces labeled_features_master_confirmed.csv

# Step 4: Train 5-class Random Forest on confirmed data
python training/train_model_real_data.py
# → overwrites ml-service/weakness_classifier.joblib + feature_scaler.joblib
```

### Rule-Based Fallback

When the Flask service is unavailable, `MLClassificationService` falls back to a deterministic classifier implemented in `ReadingAnalyzerService`. It scores each category using the same 12 features:

- **Independent (0):** accuracy ≥ 95% AND WPM ≥ 60 AND fluency ≥ 8
- **Phonemic (1):** Weighted sum of phonetic/vowel/blend error rates
- **Decoding (2):** Accuracy below 90% + substitution rate
- **Fluency (3):** WPM below grade threshold + fluency score below 6 + pause frequency
- **Comprehension (4):** Omission count + accurate-but-low-prosody pattern

The fallback returns string integers (`'0'`–`'4'`) that `AssessmentController` maps to integer class IDs.

---

## ⚠️ Observations & Known Limitations

### Technical Limitations

| Limitation | Details |
|---|---|
| **Synthetic ML training data** | Current model trained on generated data, not real student recordings. `model_metadata.json` confirms `trained_with: "synthetic_data"`. Real-data retraining is in progress. |
| **No Hiligaynon support** | Reading assessment only supports English and Filipino. The `assessments.language` column and `config/bigkas.php` still list `hil` as a schema/locale value, but Hiligaynon transcription/assessment is not supported in practice — the local `faster-whisper` model is not fine-tuned for it. |
| **Local STT not yet fine-tuned** | The primary transcription engine, local `faster-whisper`, is running the stock pre-trained model (not fine-tuned on BIGKAS classroom audio), so accuracy on Filipino and noisy classroom recordings is limited. The OpenAI Whisper API remains available as an optional cloud alternative. |
| **Polling-based live sessions** | Live teacher↔student sessions use HTTP polling every 3 seconds instead of WebSockets, introducing minor latency. |
| **Comprehension scoring is inferred** | Comprehension weakness is detected from reading behavior patterns (omissions, prosody), not direct comprehension testing. |
| **No open-ended auto-scoring** | Comprehension questions are displayed, but open-ended answer evaluation is not automated. |

### Technical Debt / Code Observations

- `dashboard/admin.blade.php` extends `layouts.app` but is included as a partial — double-extending pattern should be resolved.
- `SchoolClass` uses `$table = 'classes'` (reserved word in some SQL contexts) — consider renaming to `school_classes`.
- Assessment results use both `$assessment->results->first()` and `$assessment->result` (hasOne) inconsistently across views.
- The `InterventionApiController` references methods (`recommend()`) that don't match the `InterventionRecommenderService` public interface.
- Missing `$learners` variable in some Practice Center views that expect it (e.g., `practice/phonemic.blade.php`).

---

## 🔮 Future Enhancements

- [ ] **WebSocket-based live sessions** — Replace HTTP polling with Laravel Echo + Pusher for true real-time teacher↔student communication
- [x] **Real-data training pipeline** — 4-step annotation pipeline (validate → parse → label → train) is implemented; blocked on teacher review completion
- [ ] **Retrain ML with real data** — Pipeline is ready; waiting for reading expert sign-off on reviewed CSVs
- [ ] **Dedicated Filipino STT model** — Fine-tune Whisper on Filipino children's speech (LoRA script exists in `bigkas-ai vault/`; requires GPU via Google Colab)
- [ ] **Mobile app** — Flutter or React Native app using the existing Sanctum REST API
- [ ] **Digital parental consent** — In-app consent workflow before linking a learner to a parent account
- [ ] **PDF export** — Generate printable Phil-IRI-style reports (already has a print view at `/reports/learner/{id}/print`)
- [ ] **Offline PWA** — Service worker for offline reading practice and queued assessment submission
- [ ] **Direct comprehension scoring** — Natural language processing for evaluating open-ended comprehension answers
- [ ] **Consolidate ml/ and ml-service/** — Decide whether to delete the legacy `ml/` directory or merge it into `ml-service/`

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
