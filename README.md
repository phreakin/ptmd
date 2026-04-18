## Codebase at a Glance

### What this repository is

`phreakin/ptmd` is a PHP web application for **Paper Trail MD**, designed as a modular media operating system with:

- a public-facing site for cases, content, and live chat
- an admin panel for content operations, media/video tooling, AI-assisted workflows, and social publishing
- database-backed automation for publishing, clipping, asset handling, workflow tracking, and platform operations
- an embedded AI bot / copilot layer that is intended to become a deeply integrated content-system assistant with access to as much structured platform data as possible

This repository is not just a website. It is the foundation for a full **content creation, optimization, management, and publishing system**.

---

### Core tech stack

- **Backend:** plain PHP (no Laravel/Symfony-style framework)
- **Database:** PDO + MySQL / MariaDB
- **Frontend:** server-rendered PHP templates + vanilla JavaScript
- **UI / libraries:** Bootstrap 5, Font Awesome, Tippy, SweetAlert2 (via CDN)
- **Styling:** large custom design system in `web/assets/css/styles.css`
- **Tailwind:** `web/tailwind.config.js` exists, but the app is primarily custom CSS
- **Video tooling:** FFmpeg / ffprobe integration in `web/inc/video_processor.php`

---

### Main folder layout

- `/home/runner/work/ptmd/ptmd/web/index.php`  
  Public front controller / router

- `/home/runner/work/ptmd/ptmd/web/inc/`  
  Shared core logic including bootstrap, config, DB, helpers, auth, chat auth, workflow, social dispatch, and video processing

- `/home/runner/work/ptmd/ptmd/web/pages/`  
  Public page templates such as home, cases, case detail, series, chat, and auth pages

- `/home/runner/work/ptmd/ptmd/web/admin/`  
  Admin pages and shared admin shell files such as `_admin_head.php` and `_admin_footer.php`

- `/home/runner/work/ptmd/ptmd/web/api/`  
  JSON endpoints for chat, edit jobs, AI, social workers, overlays, and future internal service routes

- `/home/runner/work/ptmd/ptmd/web/database/`  
  `schema.sql`, `seed.sql`, additive migration scripts, and upgrade guidance

- `/home/runner/work/ptmd/ptmd/web/assets/`  
  CSS, JS, and brand assets

- `/home/runner/work/ptmd/ptmd/web/tests/`  
  Lightweight custom PHP test harness (`php web/tests/run.php`)

---

### How code is organized conceptually

#### Bootstrap first
Entry points typically include `inc/bootstrap.php`, which loads config, timezone, session bootstrapping, database connection, helpers, and shared functions.

#### Public rendering path
The public router selects a page, then renders through shared layout includes:

- `inc/head.php`
- `inc/header.php`
- `pages/<page>.php`
- `inc/footer.php`

#### Admin rendering path
Each admin page sets metadata, then renders through the shared admin shell:

- `_admin_head.php`
- page content
- `_admin_footer.php`

#### API pattern
Each endpoint:
- validates auth and CSRF when required
- uses PDO for DB operations
- returns JSON
- is expected to remain small, explicit, and task-oriented

#### Security primitives
The codebase uses:
- output escaping helpers such as `e()` and `ee()`
- CSRF tokens
- role checks
- path-safety guards for video/media operations
- explicit auth validation for protected admin/API surfaces

---

### Key functional domains

#### 1. Case / content publishing
The platform revolves around “cases” as the main storytelling unit.
This includes:
- case creation
- editing
- categorization
- publishing
- case metadata and related media

#### 2. Social queue + dispatch workflow
Content publishing is backed by DB-driven queueing and dispatch logic, including:
- platform-specific posting preferences
- schedules
- queue records
- posting attempts
- response logs

Key files include:
- `inc/content_workflow.php`
- `inc/social_services.php`

#### 3. Chat system
The repository includes a public chat system plus moderation support, with public-facing and moderation-related endpoints.

#### 4. Video / edit job pipeline
The codebase includes media processing and worker-oriented tooling for:
- overlay composition
- captions
- clip handling
- processing jobs
- FFmpeg-based workflows

#### 5. AI-assisted content / admin tools
The repository already includes AI-related tables and endpoints and is intended to expand into a much larger AI-assisted system for:
- content support
- admin support
- optimization
- workflow assistance
- analytics interpretation
- asset navigation
- decision support

---

### AI bot / embedded intelligence direction

A major architectural goal of PTMD is to include an **AI Bot / Copilot as part of the content system itself**, not as an isolated utility.

The long-term direction is for the bot to have access to **as much relevant structured platform data as possible** so it can behave like an embedded intelligence layer inside the application.

That means the bot should eventually be able to use or be connected to:
- case data
- clip data
- asset data
- publishing queue data
- social posting logs
- AI generation history
- trend and scoring data
- analytics and performance metrics
- workflow state changes
- admin/user context where appropriate
- future vectorized or retrieval-oriented knowledge layers
- future internal model / embedded LLM support if implemented

The objective is for the bot to become:
- a content strategist
- a workflow copilot
- a research and optimization assistant
- an asset navigator
- an analytics explainer
- a system-aware operational assistant

In practical terms, PTMD is being built so the AI layer can eventually support:
- context-aware admin chat
- recommendation generation
- workflow reasoning
- case / clip / hook optimization
- posting assistance
- internal reporting
- natural-language system navigation
- explainable AI suggestions backed by actual project data

This also means the codebase should continue evolving with:
- strong data structure
- trackable events
- logging
- analytics
- explainability
- modular service boundaries
- API-first thinking
- safe permission-aware AI actions

---

### Architecture direction

This repository should be understood as moving toward a **modular media operating system** rather than a basic CMS.

The intended direction includes:
- automated content creation support
- AI optimization and recommendation systems
- digital asset management
- hook / title / thumbnail experimentation
- social posting orchestration
- tracking / analytics / monitoring
- explainable AI bot integration
- production workflow visibility from idea to published output

The platform should be built so that:
- modules can be added or removed cleanly
- workflows are traceable
- APIs remain clear and extendable
- automation is DB-backed and observable
- AI systems can sit on top of well-structured internal data

---

### Testing

The repository includes a lightweight custom PHP test harness:

```bash
php web/tests/run.php
