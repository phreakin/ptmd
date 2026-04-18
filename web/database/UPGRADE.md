# PTMD Database — Install & Upgrade Guide

## Fresh install

Run these two files in order against an empty database:

```bash
mysql -u <user> -p <dbname> < web/database/schema.sql
mysql -u <user> -p <dbname> < web/database/seed.sql
```

`schema.sql` creates every table required by the application.  
`seed.sql` populates default settings, sample cases, posting sites, schedules, and starter assets.

---

## Upgrading an existing install

Existing installs only need to run migration files that contain tables or
columns not yet present in their database.  All migration files are
**idempotent** — safe to re-run (`CREATE TABLE IF NOT EXISTS`, etc.).

Apply them in the order listed below, skipping any you have already run:

| File | Adds |
|------|------|
| `migration_posting_sites.sql` | `posting_sites`, `site_posting_options`; backfills platform data |
| `migration_content_workflow.sql` | `content_workflows`, `content_workflow_assets`, `content_workflow_posts`; seeds `automation_worker_token` |
| `migration_assets.sql` | `assets`, `asset_usage_logs` |
| `migration_clips_blueprints.sql` | `clip_blueprints` |
| `migration_ai_assistant.sql` | `ai_assistant_sessions`, `ai_assistant_messages` |
| `migration_chat_v2.sql` | `hidden_at/hidden_by/hide_reason` on chat_messages; `strike_count/trust_level/last_strike_at` on chat_users; `reaction_policy/trivia_enabled/donations_enabled` on chat_rooms; new tables `chat_trivia_questions`, `chat_trivia_sessions`, `chat_trivia_answers`, `chat_donations`; donation site_settings |

Example for a single migration:

```bash
mysql -u <user> -p <dbname> < web/database/migration_assets.sql
```

After all migrations are applied the database should be identical to one
created from scratch with `schema.sql + seed.sql`.

---

## Notes

- All tables use `ENGINE=InnoDB`, `utf8mb4_unicode_ci`, and are compatible
  with MySQL 8+ and MariaDB 10.5+.
- `FOREIGN_KEY_CHECKS` is disabled at the top of `schema.sql` and re-enabled
  at the bottom so that table-creation order does not matter.
- Never run `schema.sql` against a database that already has tables — it
  uses `CREATE TABLE IF NOT EXISTS` but will not update existing columns.
  Use the appropriate migration file for additive changes instead.
