<?php

/**
 * PTMD Helper Functions
 *
 * Pure utilities used across templates, admin pages, and API endpoints.
 */

// ---------------------------------------------------------------------------
// OUTPUT ESCAPING
// ---------------------------------------------------------------------------

/** Escape a value for safe HTML output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Output-escape and echo immediately. */
function ee(mixed $value): void
{
    echo e($value);
}

// ---------------------------------------------------------------------------
// ASSET / URL HELPERS
// ---------------------------------------------------------------------------

/** Return a root-relative URL to a web asset. */
function asset(string $path): string
{
    return '/assets/' . ltrim($path, '/');
}

/** Return a root-relative URL to an uploaded file. */
function upload_url(string $path): string
{
    return '/uploads/' . ltrim($path, '/');
}

// ---------------------------------------------------------------------------
// SITE SETTINGS  (cached from DB on first call)
// ---------------------------------------------------------------------------

/**
 * Returns a reference to the shared settings cache array (or null when unloaded).
 * Passing true resets the cache so the next call to site_setting() reloads from DB.
 */
function &_ptmd_settings_cache_ref(bool $reset = false): ?array
{
    static $cache = null;
    if ($reset) {
        $cache = null;
    }
    return $cache;
}

/** Read a site_setting value from the database (with in-request cache). */
function site_setting(string $key, string $fallback = ''): string
{
    $cache = &_ptmd_settings_cache_ref();

    if ($cache === null) {
        $cache = [];
        $pdo = get_db();
        if ($pdo) {
            $stmt = $pdo->query('SELECT setting_key, setting_value FROM site_settings');
            foreach ($stmt->fetchAll() as $row) {
                $cache[$row['setting_key']] = (string) $row['setting_value'];
            }
        }
    }

    return $cache[$key] ?? $fallback;
}

/** Force reload of the site_settings cache (call after saving). */
function flush_settings_cache(): void
{
    _ptmd_settings_cache_ref(true);
}

// ---------------------------------------------------------------------------
// AUTH
// ---------------------------------------------------------------------------

/** Is an admin currently logged in? */
function is_logged_in(): bool
{
    return !empty($_SESSION['admin_user_id']);
}

/** Redirect to login if not authenticated. */
function require_login(string $return = ''): void
{
    if (!is_logged_in()) {
        $url = '/admin/login.php';
        if ($return !== '') {
            $url .= '?return=' . urlencode($return);
        }
        header('Location: ' . $url);
        exit;
    }
}

/** Return the current admin user row from DB (cached per-request). */
function current_admin(): ?array
{
    static $user = null;
    static $loaded = false;

    if ($loaded) {
        return $user;
    }

    $loaded = true;

    if (!is_logged_in()) {
        return null;
    }

    $pdo = get_db();
    if (!$pdo) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, username, email, role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => (int) $_SESSION['admin_user_id']]);
    $user = $stmt->fetch() ?: null;

    return $user;
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

/** Return (and generate if missing) a CSRF token for the current session. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/** Validate a submitted CSRF token against the session token. */
function verify_csrf(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

// ---------------------------------------------------------------------------
// REDIRECTS / FLASH
// ---------------------------------------------------------------------------

/** Store a flash message and redirect. */
function redirect(string $url, string $message = '', string $type = 'info'): never
{
    if ($message !== '') {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    }
    header('Location: ' . $url);
    exit;
}

/** Pull and clear the flash message for the current request. */
function pull_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

// ---------------------------------------------------------------------------
// REQUEST HELPERS
// ---------------------------------------------------------------------------

function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_ajax(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

// ---------------------------------------------------------------------------
// STRING / SLUG
// ---------------------------------------------------------------------------

/** Generate a URL-safe slug from a string. */
function slugify(string $text): string
{
    $text = mb_strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9\-]/', '-', $text);
    $text = preg_replace('/-+/', '-', $text);

    return trim($text, '-');
}

// ---------------------------------------------------------------------------
// cases
// ---------------------------------------------------------------------------

function get_featured_case(): ?array
{
    $pdo = get_db();
    if (!$pdo) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM cases WHERE status = :status ORDER BY published_at DESC LIMIT 1'
    );
    $stmt->execute(['status' => 'published']);

    return $stmt->fetch() ?: null;
}

function get_latest_cases(int $limit = 6): array
{
    $pdo = get_db();
    if (!$pdo) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM cases WHERE status = :status ORDER BY published_at DESC LIMIT :limit'
    );
    $stmt->bindValue(':status', 'published');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function find_case_by_slug(string $slug): ?array
{
    $pdo = get_db();
    if (!$pdo) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM cases WHERE slug = :slug AND status = :status LIMIT 1'
    );
    $stmt->execute(['slug' => $slug, 'status' => 'published']);

    return $stmt->fetch() ?: null;
}

function get_case_tags(int $caseId): array
{
    $pdo = get_db();
    if (!$pdo) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT t.name FROM case_tags t
         INNER JOIN case_tag_map m ON m.tag_id = t.id
         WHERE m.case_id = :id ORDER BY t.name'
    );
    $stmt->execute(['id' => $caseId]);

    return array_column($stmt->fetchAll(), 'name');
}

// ---------------------------------------------------------------------------
// SOCIAL / POSTING SITES
// ---------------------------------------------------------------------------

/**
 * Normalize a platform display name or site_key string to the stable
 * lowercase-underscore site_key used in the dispatch registry and the
 * posting_sites table.
 *
 * Examples:
 *   'YouTube Shorts' → 'youtube_shorts'
 *   'Instagram Reels' → 'instagram_reels'
 *   'X' → 'x'
 */
function ptmd_platform_to_site_key(string $platform): string
{
    return strtolower(str_replace(' ', '_', trim($platform)));
}

/**
 * Load all (or only active) posting sites from the DB, ordered by
 * sort_order then display_name.
 *
 * Returns an empty array when the DB is unavailable so callers can
 * fall back gracefully.
 *
 * Each row contains: id, site_key, display_name, is_active, sort_order,
 * created_at, updated_at.
 *
 * @return array<int, array<string, mixed>>
 */
function get_posting_sites(bool $activeOnly = true): array
{
    $pdo = get_db();
    if (!$pdo) {
        return [];
    }

    $sql = $activeOnly
        ? 'SELECT * FROM posting_sites WHERE is_active = 1 ORDER BY sort_order, display_name'
        : 'SELECT * FROM posting_sites ORDER BY sort_order, display_name';

    return $pdo->query($sql)->fetchAll();
}

// ---------------------------------------------------------------------------
// PAGE TITLE
// ---------------------------------------------------------------------------

function page_title(string $page, ?array $case = null): string
{
    if ($page === 'case' && $case) {
        return e($case['title']) . ' | ' . e(site_setting('site_name', 'Paper Trail MD'));
    }

    $map = [
        'home'      => site_setting('site_name', 'Paper Trail MD'),
        'cases'  => 'cases | ' . site_setting('site_name', 'Paper Trail MD'),
        'about'     => 'About | '    . site_setting('site_name', 'Paper Trail MD'),
        'contact'   => 'Contact | '  . site_setting('site_name', 'Paper Trail MD'),
        'case-chat' => 'Case Chat | '. site_setting('site_name', 'Paper Trail MD'),
    ];

    return $map[$page] ?? site_setting('site_name', 'Paper Trail MD');
}

// ---------------------------------------------------------------------------
// MEDIA / UPLOAD
// ---------------------------------------------------------------------------

/**
 * Move an uploaded file into /uploads/{subdir} with a safe prefixed name.
 * Returns the relative path from /uploads, or null on failure.
 */
function save_upload(array $file, string $subdir, array $allowedExt): ?string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return null;
    }

    $dir = rtrim($GLOBALS['config']['upload_dir'], '/') . '/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return null;
    }

    $safeName = 'ptmd_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
    $dest = $dir . '/' . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return null;
    }

    return $subdir . '/' . $safeName;
}

// ---------------------------------------------------------------------------
// AI HELPERS
// ---------------------------------------------------------------------------

/**
 * Call the OpenAI Chat Completions API and return the response text.
 * API key is stored in site_settings as 'openai_api_key'.
 * Model is stored as 'openai_model' (default: gpt-4o-mini).
 */
function openai_chat(string $systemPrompt, string $userPrompt, int $maxTokens = 800): array
{
    $apiKey = site_setting('openai_api_key', '');
    $model  = site_setting('openai_model', 'gpt-4o-mini');

    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'OpenAI API key not configured. Set it in Admin → Settings.'];
    }

    $payload = [
        'model'      => $model,
        'max_tokens' => $maxTokens,
        'messages'   => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt],
        ],
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30,
    ]);

    $raw    = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['ok' => false, 'error' => 'cURL error: ' . $err];
    }

    $data = json_decode($raw, true);

    if ($status !== 200 || !is_array($data)) {
        $msg = is_array($data) ? ($data['error']['message'] ?? $raw) : $raw;
        return ['ok' => false, 'error' => 'OpenAI error: ' . $msg];
    }

    $text = $data['choices'][0]['message']['content'] ?? '';

    return [
        'ok'              => true,
        'text'            => trim($text),
        'model'           => $data['model'] ?? $model,
        'prompt_tokens'   => $data['usage']['prompt_tokens'] ?? 0,
        'response_tokens' => $data['usage']['completion_tokens'] ?? 0,
    ];
}

/**
 * Save an AI generation record to the database.
 * Returns the new row ID or 0 on failure.
 */
function save_ai_generation(
    string $feature,
    string $inputPrompt,
    string $outputText,
    string $model,
    int $promptTokens,
    int $responseTokens,
    ?int $caseId = null
): int {
    $pdo = get_db();
    if (!$pdo) {
        return 0;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO ai_generations
         (case_id, feature, input_prompt, output_text, model, prompt_tokens, response_tokens, created_at)
         VALUES (:case_id, :feature, :input_prompt, :output_text, :model, :prompt_tokens, :response_tokens, NOW())'
    );

    $stmt->execute([
        'case_id'      => $caseId,
        'feature'         => $feature,
        'input_prompt'    => $inputPrompt,
        'output_text'     => $outputText,
        'model'           => $model,
        'prompt_tokens'   => $promptTokens,
        'response_tokens' => $responseTokens,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Call OpenAI Chat Completions with a full multi-turn message history.
 * $messages is an array of ['role' => 'user'|'assistant', 'content' => '...'].
 */
function openai_chat_multiturn(string $systemPrompt, array $messages, int $maxTokens = 1200): array
{
    $apiKey = site_setting('openai_api_key', '');
    $model  = site_setting('openai_model', 'gpt-4o-mini');

    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'OpenAI API key not configured. Set it in Admin → Settings.'];
    }

    $payload = [
        'model'      => $model,
        'max_tokens' => $maxTokens,
        'messages'   => array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $messages
        ),
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30,
    ]);

    $raw    = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['ok' => false, 'error' => 'cURL error: ' . $err];
    }

    $data = json_decode($raw, true);

    if ($status !== 200 || !is_array($data)) {
        $msg = is_array($data) ? ($data['error']['message'] ?? $raw) : $raw;
        return ['ok' => false, 'error' => 'OpenAI error: ' . $msg];
    }

    $text = $data['choices'][0]['message']['content'] ?? '';

    return [
        'ok'              => true,
        'text'            => trim($text),
        'model'           => $data['model'] ?? $model,
        'prompt_tokens'   => $data['usage']['prompt_tokens'] ?? 0,
        'response_tokens' => $data['usage']['completion_tokens'] ?? 0,
    ];
}

/**
 * Gather a live read-only snapshot of site data for the Copilot context.
 */
function ptmd_copilot_context(): string
{
    $pdo   = get_db();
    $parts = [];

    if ($pdo) {
        $epTotal  = (int) $pdo->query('SELECT COUNT(*) FROM cases')->fetchColumn();
        $epPub    = (int) $pdo->query('SELECT COUNT(*) FROM cases WHERE status = "published"')->fetchColumn();
        $parts[]  = "cases: {$epTotal} total, {$epPub} published.";

        $latestEps = $pdo->query(
            'SELECT title, status FROM cases ORDER BY created_at DESC LIMIT 5'
        )->fetchAll();
        if ($latestEps) {
            $epList  = array_map(fn($e) => '  - "' . $e['title'] . '" (' . $e['status'] . ')', $latestEps);
            $parts[] = "Recent cases:\n" . implode("\n", $epList);
        }

        $queuePending = (int) $pdo->query(
            'SELECT COUNT(*) FROM social_post_queue WHERE status IN ("queued","scheduled")'
        )->fetchColumn();
        $parts[] = "Social queue: {$queuePending} post(s) pending.";

        $mediaTotal = (int) $pdo->query('SELECT COUNT(*) FROM media_library')->fetchColumn();
        $parts[]    = "Media library: {$mediaTotal} file(s).";

        $aiTotal = (int) $pdo->query('SELECT COUNT(*) FROM ai_generations')->fetchColumn();
        $parts[] = "AI generations logged: {$aiTotal}.";
    }

    $parts[] = 'Current date/time: ' . date('D, d M Y H:i T');

    return implode("\n", $parts);
}

/**
 * Build the Admin Copilot system prompt with live site context.
 */
function ptmd_copilot_system_prompt(): string
{
    $siteName = site_setting('site_name', 'Paper Trail MD');
    $context  = ptmd_copilot_context();

    return <<<PROMPT
You are the Admin Copilot for {$siteName}, a documentary-first media brand focused on investigative mini-docs with a sharp, funny tone.

Your job is to help the site admin with EVERY aspect of managing the site — content, publishing, social media, media assets, moderation, and technical configuration.

CURRENT SITE SNAPSHOT:
{$context}

ADMIN MODULES YOU CAN HELP WITH:
- cases (create, edit, publish, archive) → /admin/cases.php
- Video Processor (trim clips, extract short-form content) → /admin/video-processor.php
- Overlay Tool (apply branded overlays to clips) → /admin/overlay-tool.php
- Media Library (thumbnails, intros, watermarks, overlays, clips) → /admin/media.php
- AI Content Studio (titles, keywords, descriptions, captions, thumbnail concepts) → /admin/ai-tools.php
- Social Queue (manage scheduled posts) → /admin/posts.php
- Post Schedule (configure posting cadence per platform) → /admin/social-schedule.php
- Case Chat moderation (approve, flag, block viewer messages) → /admin/chat.php
- Settings (site config, OpenAI API key, brand assets) → /admin/settings.php

GUIDELINES:
- Be concise, direct, and helpful. Match the PTMD tone: investigative, sharp, occasionally funny.
- For content generation (titles, descriptions, keywords, captions, ideas), produce the content right away as a draft — clearly label it as a draft.
- For how-to questions, explain clearly and include the relevant page link.
- For site questions, use the snapshot above to give accurate, current answers.
- Use markdown formatting (bold, bullet lists, code blocks) to keep responses readable.
- Never reveal API keys, password hashes, or internal secrets.
- Never claim you can directly execute code, run commands, or access files on the server.
PROMPT;
}

/**
 * Build the standard PTMD system prompt for all AI features.
 * Incorporates brand context so results are on-brand.
 */
function ptmd_ai_system_prompt(): string
{
    $siteName = site_setting('site_name', 'Paper Trail MD');

    return <<<PROMPT
You are a creative strategist and content producer for {$siteName}, a documentary-first media brand.
The brand focuses on hard-hitting but funny mini-documentaries about social, cultural, and political stories.
The tone is investigative, sharp, modern, and cinematic.
Target audience: intellectually curious adults who appreciate both serious journalism and dry humor.
Always respond in a concise, punchy, brand-appropriate voice.
Output only what is requested — no preamble, no meta-commentary.
PROMPT;
}
