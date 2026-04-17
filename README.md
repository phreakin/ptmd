# ptmd
Paper Trail MD site

## Validation

- PHP syntax lint: `find web -name '*.php' -print0 | xargs -0 -n1 php -l`
- Lightweight automated tests: `php web/tests/run.php`

## Database

Run `web/database/schema.sql` first, then `web/database/seed.sql`.

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

### Migration

Existing installs that pre-date the `posting_sites` tables can run:

```
web/database/migration_posting_sites.sql
```

This creates the two new tables and backfills rows from existing platform
strings in `social_platform_preferences`, `social_post_queue`, and
`social_post_schedules`.

If your install predates the content workflow automation tables, also run:

```
web/database/migration_content_workflow.sql
```

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
