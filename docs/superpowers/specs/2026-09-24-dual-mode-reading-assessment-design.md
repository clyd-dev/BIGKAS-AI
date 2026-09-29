# Dual-Mode Virtual & Local Reading Assessment

## Overview
Connect the Teacher's "Reading Assessment" page with the Learner Portal. This allows an assessment to be completed either locally on the teacher's device OR remotely/virtually on the student's logged-in device, without needing separate "Session" objects.

## Core Flow (Dual-Mode)
1. **Creation:** Teacher creates an Assessment (status: `pending`). The teacher is redirected to the `assessments.show` page.
2. **Polling (Teacher Side):** The `assessments.show` page polls the backend every 3 seconds to check the assessment's status.
3. **Polling (Learner Side):** The Learner's dashboard polls for any `pending` assessments assigned to them.
4. **Execution Decision:**
   - **Route A (Local):** If the student is beside the teacher, the teacher simply clicks "Start Reading" on their own laptop. The audio is recorded locally using the existing teacher-side JS.
   - **Route B (Virtual/Remote):** If the student is on their own device, they see the pending assessment pop up. They click "Start Recording", which updates the Assessment status to `recording`. The teacher's screen detects this status change, hides the local record buttons, and displays "Student is recording remotely...".
5. **Completion:** The audio is uploaded (by whichever device recorded it). The status updates to `audio_uploaded` or `processing`. The teacher can then click "Analyze Reading" as normal.

## Components & Changes

### 1. Backend / Controller Updates
- **`AssessmentApiController` / `StudentAssessmentController`**:
  - Add endpoint for the Learner Portal to check for pending assessments: `GET /api/student/assessments/pending`.
  - Add endpoint for the Learner Portal to accept/start an assessment: `POST /api/student/assessments/{id}/start` (updates status to `recording`).
  - Add endpoint for the Learner Portal to upload audio: `POST /api/student/assessments/{id}/upload-audio` (updates status and attaches file).
- **`AssessmentController` (Teacher)**:
  - Add a polling endpoint for the `assessments.show` view: `GET /assessments/{id}/status`.

### 2. Learner Portal UI (Student Dashboard)
- Add a JS poller on `student.dashboard` that pings the pending assessments endpoint.
- If a pending assessment is found, show a modal/banner: "Your teacher has assigned a reading passage! [Start]".
- Clicking Start transitions to a reading view that displays the passage, requests microphone access, and records audio (similar to the teacher's local recording).
- On finish, uploads the audio and returns to the dashboard.

### 3. Teacher Portal UI (`assessments.show`)
- Update the page's JS to include a status poller.
- If the status changes to `recording` (meaning the student started it remotely), disable the local "Start Reading" button and show a remote indicator.
- If the status changes to `audio_uploaded`, reveal the "Analyze Reading" button and audio playback.

## Database & Models
- Re-use the existing `Assessment` model.
- No need to use `AssessmentSession` (it can be deprecated/removed later if unused, simplifying the architecture).
- Status flow: `pending` -> `recording` -> `audio_uploaded` -> `completed`.

## Security & Validation
- Ensure students can only fetch and update assessments where `learner_id` matches their authenticated session.
- Ensure audio file sizes and types are validated on both the teacher and student upload endpoints.
