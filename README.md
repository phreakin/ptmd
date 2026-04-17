# ptmd
Paper Trail MD site

## Validation

- PHP syntax lint: `find web -name '*.php' -print0 | xargs -0 -n1 php -l`
- Lightweight automated tests: `php web/tests/run.php`

## Database

### Fresh install

Run these two files **in order** against an empty database:

```bash
mysql -u <user> -p <dbname> < web/database/schema.sql
mysql -u <user> -p <dbname> < web/database/seed.sql
```

`schema.sql` creates every table the application requires.  
`seed.sql` populates default settings, sample cases, posting sites, posting schedules, and starter assets.

### Upgrading an existing install

See **`web/database/UPGRADE.md`** for the full upgrade guide.

In short, apply only the migration files that correspond to tables not yet in your database — all are safe to re-run:

| File | Adds |
|------|------|
| `migration_posting_sites.sql` | `posting_sites`, `site_posting_options` |
| `migration_content_workflow.sql` | `content_workflows`, `content_workflow_assets`, `content_workflow_posts` |
| `migration_assets.sql` | `assets`, `asset_usage_logs` |
| `migration_clips_blueprints.sql` | `clip_blueprints` |
| `migration_ai_assistant.sql` | `ai_assistant_sessions`, `ai_assistant_messages` |

### Postable Sites

The `posting_sites` table is the single source of truth for all social media
platforms used in content distribution. Each row has a stable `site_key` slug
(e.g. `youtube_shorts`), a human-readable `display_name`, an `is_active` flag,
and a `sort_order` for UI ordering.

Per-site defaults (content type, caption prefix, hashtags, default queue status)
live in the companion `site_posting_options` table, normalised to `site_id`.

The admin page at `/admin/posting-sites.php` provides full CRUD for sites and
their posting options — no code changes required to add, disable, or reorder
a posting target.

**Dispatch** in `inc/social_services.php` routes queue items via a static
`PTMD_SITE_DISPATCH_REGISTRY` keyed on the normalised site key (lowercase,
spaces replaced with underscores). Both display-name strings (e.g.
`YouTube Shorts`) and pre-normalised keys (e.g. `youtube_shorts`) are
accepted for backward compatibility.

The helper `ptmd_platform_to_site_key(string $platform): string` in
`inc/functions.php` performs this normalisation.

### Content Workflow Automation

Use `/admin/content-workflow.php` to run an end-to-end workflow from a topic to
scheduled posting:

1. Topic input creates (or links) a draft case
2. Asset path assignment links the clip/video to the workflow
3. Queue items are auto-created for all active rows in `posting_sites`
4. Due posts can be auto-dispatched from the same page

The workflow is persisted in:

- `content_workflows`
- `content_workflow_assets`
- `content_workflow_posts`

Automated dispatch endpoint for cron:

`/api/social_dispatch_worker.php?token=<automation_worker_token>`

## Brand

Brand reference docs live in `web/docs/brand/` (README, brand guide, posting guide, color JSON, and schedule JSON).

Brand media (logos, overlays, watermarks, intros, thumbnails, images) lives under `web/assets/brand/`.

The canonical asset index is `web/assets/brand/brand-manifest.json`.
