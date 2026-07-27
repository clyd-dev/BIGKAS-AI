# BIGKAS-AI Home Page Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the landing page and unify the brand name to BIGKAS-AI to create a professional, production-ready educational SaaS landing page.

**Architecture:** We will update the shared guest layout (`resources/views/layouts/guest.blade.php`) for the navbar and footer. Then we will rewrite `resources/views/home.blade.php` to include the new Hero, Benefits, How It Works, and Portals sections using Bootstrap 5 utility classes. Finally, we will add a Feature test to assert the new content is rendered correctly.

**Tech Stack:** Laravel 12 (Blade), Bootstrap 5, PHPUnit.

## Global Constraints
- System Name: BIGKAS-AI
- Framework: Laravel 12
- CSS Framework: Bootstrap 5
- WCAG AA compliant contrast for text/backgrounds. Fully responsive for mobile.

---

### Task 1: Update Guest Layout Navbar & Footer

**Files:**
- Create: `tests/Feature/HomePageContentTest.php`
- Modify: `resources/views/layouts/guest.blade.php`

**Interfaces:**
- Consumes: Existing Laravel layout structure.
- Produces: A unified guest layout with an updated navbar (links to `#features`, `#teachers`, `#parents`) and a professional footer.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/HomePageContentTest.php`:
```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomePageContentTest extends TestCase
{
    public function test_home_page_contains_correct_brand_and_footer()
    {
        $response = $this->get('/');
        
        $response->assertStatus(200);
        $response->assertSee('BIGKAS-AI');
        $response->assertSee('Privacy Policy');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter HomePageContentTest`
Expected: FAIL due to missing "Privacy Policy" and missing updated "BIGKAS-AI" brand.

- [ ] **Step 3: Write minimal implementation**

Update `resources/views/layouts/guest.blade.php`:
1. Change navbar brand text from `BIGKAS` to `BIGKAS-AI`.
2. Add navbar links `Features`, `For Teachers`, `For Parents` pointing to anchors `#features`, `#teachers`, `#parents`.
3. Add a footer section right before `</body>` containing: Links (About, Help Center, Privacy Policy, Terms of Service) and Copyright (© 2026 BIGKAS-AI. All rights reserved.).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter HomePageContentTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/views/layouts/guest.blade.php tests/Feature/HomePageContentTest.php
git commit -m "feat: update guest layout navbar and add footer"
```

### Task 2: Implement Home Page Hero and Benefits Sections

**Files:**
- Modify: `resources/views/home.blade.php`
- Modify: `tests/Feature/HomePageContentTest.php`

**Interfaces:**
- Consumes: `layouts.guest`

- [ ] **Step 1: Write the failing test**

Append to `HomePageContentTest.php`:
```php
    public function test_home_page_hero_and_benefits()
    {
        $response = $this->get('/');
        
        $response->assertSee('Empowering Every Learner\'s Reading Journey', false);
        $response->assertSee('Save Hours of Grading');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter test_home_page_hero_and_benefits`
Expected: FAIL

- [ ] **Step 3: Write minimal implementation**

Replace the top part of `resources/views/home.blade.php` (up to and including the current features section):
- **Hero Section:** Headline "Empowering Every Learner's Reading Journey". Sub-headline "A Machine Learning-Assisted Reading Progress Assessment and Intervention System for Learner Monitoring and Decision Support." CTA buttons for Register and Login.
- **Benefits Section** (Give it `id="features"`): 3-column layout highlighting "Save Hours of Grading", "Targeted Insights", and "Bridge Home and School". Remove the old "How It Works" dummy blocks.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter test_home_page_hero_and_benefits`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/views/home.blade.php tests/Feature/HomePageContentTest.php
git commit -m "feat: implement home page hero and benefits sections"
```

### Task 3: Implement Home Page How It Works and Portals Sections

**Files:**
- Modify: `resources/views/home.blade.php`
- Modify: `tests/Feature/HomePageContentTest.php`

**Interfaces:**
- Consumes: Hero and Benefits sections in `home.blade.php`.

- [ ] **Step 1: Write the failing test**

Append to `HomePageContentTest.php`:
```php
    public function test_home_page_how_it_works_and_portals()
    {
        $response = $this->get('/');
        
        $response->assertSee('Instant AI Analysis');
        $response->assertSee('For Parents');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter test_home_page_how_it_works_and_portals`
Expected: FAIL

- [ ] **Step 3: Write minimal implementation**

Append to `resources/views/home.blade.php`:
- **How It Works Section:** 4-step process (Record & Read, Instant AI Analysis, Actionable Reports, Guided Interventions).
- **Portals Section:** Provide elements with `id="teachers"` and `id="parents"`. Add 3 cards for Teachers, Parents, and Students detailing their specific features.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter test_home_page_how_it_works_and_portals`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/views/home.blade.php tests/Feature/HomePageContentTest.php
git commit -m "feat: implement home page how it works and portals sections"
```