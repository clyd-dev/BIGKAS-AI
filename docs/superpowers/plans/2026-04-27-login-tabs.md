# Login Portal Unification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Provide a seamless, unified tabbed login experience for Learners and Staff/Parents across two separate portals.

**Architecture:** We are modifying the two separate login Blade views (`auth/login.blade.php` and `student/auth/login.blade.php`) by adding an identical pill-shaped tab navigation bar to both. This creates the illusion of a single-page application without mixing backend validation logic or CSS frameworks.

**Tech Stack:** Laravel Blade, Bootstrap 5 (CSS & Icons).

## Global Constraints

- Must not modify any Controllers or backend validation logic.
- The tabs must use Bootstrap 5 `.nav-pills`.
- The tabs must use `bi-person-badge` and `bi-emoji-smile` icons.

---

### Task 1: Add Tab Navigation to Professional Login

**Files:**
- Modify: `resources/views/auth/login.blade.php`

**Interfaces:**
- Consumes: N/A
- Produces: Visual tab bar with 'Staff & Parents' active.

- [ ] **Step 1: Implement the Tab Bar in Professional Login**

Modify `resources/views/auth/login.blade.php`. Insert the following block immediately inside the `@section('content')`, *before* the `<h4 class="text-center mb-4">Welcome Back</h4>`:

```html
    {{-- Unified Tab Navigation --}}
    <ul class="nav nav-pills nav-justified mb-4" style="background-color: #f8f9fa; border-radius: 50rem; padding: 0.3rem;">
        <li class="nav-item">
            <a class="nav-link active shadow-sm" href="{{ route('login') }}" style="border-radius: 50rem; font-weight: 600;">
                <i class="bi bi-person-badge me-1"></i> Staff & Parents
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link text-muted" href="{{ route('student.login') }}" style="border-radius: 50rem; font-weight: 600;">
                <i class="bi bi-emoji-smile me-1"></i> Learners (PIN)
            </a>
        </li>
    </ul>
```

- [ ] **Step 2: Commit changes**

```bash
git add resources/views/auth/login.blade.php
git commit -m "feat: add tab navigation to professional login view"
```

---

### Task 2: Add Tab Navigation to Learner Login

**Files:**
- Modify: `resources/views/student/auth/login.blade.php`

**Interfaces:**
- Consumes: N/A
- Produces: Visual tab bar with 'Learners (PIN)' active, and removes redundant footer link.

- [ ] **Step 1: Add Bootstrap Icons dependency**

In `resources/views/student/auth/login.blade.php`, find the CSS links in the `<head>` and append the Bootstrap Icons CDN link:

```html
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('css/student.css') }}" rel="stylesheet">
```

- [ ] **Step 2: Implement the Tab Bar in Learner Login**

In `resources/views/student/auth/login.blade.php`, insert the tab block immediately inside the `<div class="login-card">`, *before* the logo `<div class="mb-3"><span style="font-size: 3rem;">??</span></div>`:

```html
            {{-- Unified Tab Navigation --}}
            <ul class="nav nav-pills nav-justified mb-4" style="background-color: #f8f9fa; border-radius: 50rem; padding: 0.3rem;">
                <li class="nav-item">
                    <a class="nav-link text-muted" href="{{ route('login') }}" style="border-radius: 50rem; font-weight: 600;">
                        <i class="bi bi-person-badge me-1"></i> Staff & Parents
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active shadow-sm" href="{{ route('student.login') }}" style="border-radius: 50rem; font-weight: 600; background-color: var(--kid-primary);">
                        <i class="bi bi-emoji-smile me-1"></i> Learners (PIN)
                    </a>
                </li>
            </ul>
```
*(Note: I added `background-color: var(--kid-primary);` to the active tab here to ensure it matches the kid theme, since Bootstrap's default primary is blue, but the student CSS variables are available here).*

- [ ] **Step 3: Remove the redundant footer link**

In `resources/views/student/auth/login.blade.php`, delete the following block at the bottom of the form:

```html
            {{-- Link back to teacher login --}}
            <div class="mt-3">
                <a href="{{ route('login') }}" class="text-decoration-none" style="font-size: 0.8rem; color: var(--kid-text-light);">
                    Teacher / Admin Login
                </a>
            </div>
```

- [ ] **Step 4: Commit changes**

```bash
git add resources/views/student/auth/login.blade.php
git commit -m "feat: add tab navigation to learner login and remove redundant link"
```
