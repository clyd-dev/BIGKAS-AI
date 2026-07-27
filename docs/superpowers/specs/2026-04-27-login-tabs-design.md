# BIGKAS-AI Login Portal Unification Design

## 1. Overview
Currently, the BIGKAS-AI application has a disjointed login experience. The main landing page directs users to the professional portal (`/login` for Teachers/Parents/Admins), making it difficult for students to find their PIN-based login portal (`/student/login`). 

To resolve this without breaking existing backend validation or mixing conflicting CSS themes (Bootstrap professional vs. custom kid-friendly CSS), we will implement **Approach C: Separate URLs, Connected via Tabs**.

## 2. Component Design: Tab Switcher
We will create a pill-shaped segmented control (tab switcher) that sits above the login forms.
- **Option 1:** Staff & Parents (Icon: `bi-person-badge`)
- **Option 2:** Learners (PIN) (Icon: `bi-emoji-smile`)

## 3. File Modifications

### `resources/views/auth/login.blade.php` (Professional Login)
- Add the HTML/CSS for the Tab Switcher at the top of the login container.
- **Active Tab:** Staff & Parents.
- **Inactive Tab:** Learners (PIN). Acts as a standard anchor `<a>` tag pointing to `route('student.login')`.

### `resources/views/student/auth/login.blade.php` (Learner Login)
- Add the exact same HTML/CSS for the Tab Switcher at the top of the login container.
- **Active Tab:** Learners (PIN).
- **Inactive Tab:** Staff & Parents. Acts as a standard anchor `<a>` tag pointing to `route('login')`.
- Remove the redundant text link ("Ask your teacher..." / "Teacher / Admin Login") at the bottom of the card.

## 4. Data Flow & Error Handling
- **No JavaScript required** for state management.
- Backend routing remains completely untouched.
- Validation redirects will natively preserve the active theme because they return to their respective separate URLs.

## 5. Scope
This design is strictly isolated to the login view templates and does not alter any Controllers, Models, or Routes.
