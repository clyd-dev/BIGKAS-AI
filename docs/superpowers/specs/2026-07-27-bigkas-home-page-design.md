# BIGKAS-AI Home Page & Brand Consistency Design Spec

## 1. Overview
This specification details the redesign of the BIGKAS-AI landing page (`resources/views/home.blade.php`) and the resolution of brand inconsistencies across the application. The goal is to provide a clean, modern, and professional UI/UX that feels like a production-ready, enterprise-grade educational SaaS platform for Philippine public schools.

## 2. Brand & Consistency Updates
- **System Name:** BIGKAS-AI
- **Tagline:** A Machine Learning-Assisted Reading Progress Assessment and Intervention System for Learner Monitoring and Decision Support.
- **Configuration:** 
  - Update `APP_NAME` in `.env` to `BIGKAS-AI`.
  - Ensure `config/app.php` references `BIGKAS-AI` correctly.
  - Consolidate routing so `home.blade.php` is the singular, polished landing page.

## 3. Information Architecture & User Flow (Production SaaS Tone)
The landing page must appeal directly to the end-users (Teachers, Parents, Students) by focusing on practical benefits, ease of use, and professional reliability—avoiding academic or "capstone project" framing.

### 3.1. Navigation Bar
- **Brand:** BIGKAS-AI Logo/Text.
- **Links:** Features, For Teachers, For Parents, Login (Outline button), Register (Primary solid button).

### 3.2. Hero Section
- **Visual:** Clean, welcoming interface illustration or friendly educational graphic.
- **Headline:** Empowering Every Learner's Reading Journey.
- **Sub-headline:** (Official Tagline) A Machine Learning-Assisted Reading Progress Assessment and Intervention System.
- **Value Proposition (User-facing):** "Transform how reading is assessed and improved. Say goodbye to manual grading and hello to automated, precise insights for your classroom and home."
- **Call-to-Action (CTA):** "Get Started for Free" and "Login to Portal".

### 3.3. Key Benefits (Replacing the Problem Statement)
Focus on what the product solves for the user, styled as modern feature highlights:
- **Save Hours of Grading:** Automated scoring replaces manual Phil-IRI tallying.
- **Targeted Insights:** Know exactly what your learners need—from phonemic awareness to decoding.
- **Bridge Home and School:** Seamlessly connect teachers' assessments with parents' home interventions.

### 3.4. How It Works (Simplified for Users)
A clean, 3-step or 4-step user journey:
1. **Record & Read:** Students read aloud using our secure browser-based tool.
2. **Instant AI Analysis:** Our engine instantly evaluates accuracy, fluency, and comprehension.
3. **Actionable Reports:** Get automated, ready-to-print DepEd Forms 3A & 4.
4. **Guided Interventions:** Receive tailored practice activities to help learners improve.

### 3.5. Portals (Who is this for?)
- **For Teachers:** Streamlined class management, automated grading, and intervention tracking.
- **For Parents:** Easy-to-read progress reports and simple home activities.
- **For Students:** A fun, engaging practice center to build reading confidence.

### 3.6. Professional Footer
- Standard SaaS footer layout.
- Links: About, Help Center, Privacy Policy, Terms of Service.
- Copyright: © 2026 BIGKAS-AI. All rights reserved.

## 4. Technical Constraints & Design System
- **Framework:** Laravel 12 (Blade templating).
- **CSS Framework:** Bootstrap 5.
- **Color Palette:** 
  - Primary: Professional Academic Blue (`#0d6efd`).
  - Secondary: Soft, welcoming tones (e.g., `#f8f9fa` for backgrounds, dark slate `#212529` for text).
- **Accessibility:** WCAG AA compliant. Fully responsive for mobile (crucial for parents accessing via phones).
