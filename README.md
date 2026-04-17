# ptmd
Paper Trail MD site

## Requirements

- PHP 8.1+ with PDO/MySQL and cURL extensions
- MySQL 8+ or MariaDB 10.5+
- FFmpeg (optional, required only for video-processing features)

---

## Fresh Install

```bash
# 1. Create the database
mysql -u root -p -e "CREATE DATABASE ptmd CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Apply the canonical schema
mysql -u root -p ptmd < web/database/schema.sql

# 3. Load default seed data (admin user, site settings, sample episodes)
mysql -u root -p ptmd < web/database/seed.sql
```

The default admin credentials loaded by seed.sql are:

| Field    | Value                    |
|----------|--------------------------|
| Username | `admin`                  |
| Password | `admin123!`              |

**Change the password immediately after first login.**

---

## Upgrading an Existing Database

Apply any migration files in chronological order after your last applied migration:

| Migration file                              | What it adds                                  |
|---------------------------------------------|-----------------------------------------------|
| `web/database/migration_ai_assistant.sql`   | `ai_assistant_sessions` + `ai_assistant_messages` tables |

Migration files are idempotent (`CREATE TABLE IF NOT EXISTS`) and safe to re-run.

---

## Schema Validation

Run the built-in schema checker from the admin panel:

1. Log in as admin.
2. Navigate to **Admin → Site Tests**.
3. Click **Run Tests**.
4. Review the **Database Schema** group in the results.

The checker inspects `information_schema` and reports:

- **Blocking** — required table or column is missing; fix by (re-)applying `schema.sql` or the relevant migration.
- **Non-blocking** — unexpected legacy column found; safe to leave but tidy up with a manual `ALTER TABLE … DROP COLUMN`.

### PHP syntax validation

```bash
find web -name '*.php' -print0 | xargs -0 -n1 php -l
```

---

## Keyword / Tag Design

Episode keywords are stored in the **normalised** `episode_tags` + `episode_tag_map` tables, not as a column on `episodes`. Any code that needs a flat comma-separated keyword string must use a `GROUP_CONCAT` join, for example:

```sql
SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ')
FROM episode_tags t
INNER JOIN episode_tag_map m ON m.tag_id = t.id
WHERE m.episode_id = :id
```

Do **not** add an `episodes.keywords` column — the schema validator will flag it as legacy drift.

