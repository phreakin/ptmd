<?php
/**
 * PTMD — Video Pipeline Orchestrator (inc/pipeline.php)
 *
 * Automates the post-production workflow for clips marked complete:
 *   Stage 1: brand_imaging    — composite brand overlay onto source clip via FFmpeg
 *   Stage 2: clip_generation  — resize/crop per-platform clips from the branded source
 *   Stage 3: queueing         — create social_post_queue rows for each platform
 *
 * Public API:
 *   trigger_pipeline_for_clip(int $clipId, array $options): int|false
 *   process_pipeline_job(int $jobId): array
 *   get_pipeline_job_summary(int $jobId): array|false
 */

require_once __DIR__ . '/video_processor.php';

// ---------------------------------------------------------------------------
// Idempotent trigger — create (or return existing) pipeline job for a clip
// ---------------------------------------------------------------------------

/**
 * Start the automated pipeline for a given video_clips row.
 *
 * If an active job already exists for this clip it is returned without
 * creating a duplicate.  Sets the clip status to "complete".
 *
 * Options (all optional — fall back to site_settings defaults):
 *   overlay_path         string  brand overlay asset path (web-root relative)
 *   position             string  overlay position (bottom-right, etc.)
 *   opacity              float   0.0–1.0
 *   scale                int     overlay width as % of video width
 *   platforms            array   platform slugs to produce
 *   auto_queue           int     1 = auto-create social_post_queue rows
 *   schedule_offset_hrs  int     hours from now for scheduled posts
 *   caption_template     string  caption/description for queue entries
 *   label                string  human label for the job
 *
 * @return int  Pipeline job ID, or false on error.
 */
function trigger_pipeline_for_clip(int $clipId, array $options = []): int|false
{
    $pdo = get_db();
    if (!$pdo) {
        return false;
    }

    // Load source clip
    $stmt = $pdo->prepare('SELECT * FROM video_clips WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $clipId]);
    $clip = $stmt->fetch();

    if (!$clip) {
        error_log('[PTMD Pipeline] trigger_pipeline_for_clip: clip not found: ' . $clipId);
        return false;
    }

    // Idempotency: return existing active job rather than creating a duplicate
    $stmt = $pdo->prepare(
        'SELECT id FROM pipeline_jobs
         WHERE source_clip_id = :cid AND status NOT IN ("failed","canceled")
         LIMIT 1'
    );
    $stmt->execute(['cid' => $clipId]);
    $existing = $stmt->fetch();
    if ($existing) {
        return (int) $existing['id'];
    }

    // Resolve options with site-setting defaults
    $overlayPath    = (string) ($options['overlay_path']         ?? site_setting('pipeline_brand_overlay',       ''));
    $position       = (string) ($options['position']             ?? site_setting('pipeline_overlay_position',    'bottom-right'));
    $opacity        = (float)  ($options['opacity']              ?? site_setting('pipeline_overlay_opacity',     '1.00'));
    $scale          = (int)    ($options['scale']                ?? site_setting('pipeline_overlay_scale',       '30'));
    $autoQueue      = (int)    ($options['auto_queue']           ?? site_setting('pipeline_auto_queue',          '1'));
    $schedOffset    = (int)    ($options['schedule_offset_hrs']  ?? site_setting('pipeline_schedule_offset_hrs', '24'));
    $caption        = (string) ($options['caption_template']     ?? '');
    $label          = (string) ($options['label']                ?? ($clip['label'] ?? 'Untitled'));

    $defaultPlatforms = json_decode(
        site_setting('pipeline_default_platforms', '["youtube_shorts","tiktok","instagram_reels","facebook_reels","x"]'),
        true
    );
    $platforms = $options['platforms'] ?? ($defaultPlatforms ?: ['youtube_shorts', 'tiktok', 'instagram_reels', 'facebook_reels', 'x']);
    if (!is_array($platforms) || empty($platforms)) {
        $platforms = ['youtube_shorts', 'tiktok', 'instagram_reels', 'facebook_reels', 'x'];
    }
    $platforms = array_values(array_unique($platforms));

    $episodeId = (int) ($clip['episode_id'] ?? 0) ?: null;
    $userId    = (int) ($_SESSION['admin_user_id'] ?? 0) ?: null;

    $brandPreset = [
        'overlay_path' => $overlayPath,
        'position'     => $position,
        'opacity'      => $opacity,
        'scale'        => $scale,
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO pipeline_jobs
         (source_clip_id, episode_id, label, brand_preset_json, platforms_json,
          auto_queue, schedule_offset_hrs, caption_template, current_stage,
          status, created_by, created_at, updated_at)
         VALUES
         (:clip, :ep, :label, :preset, :platforms,
          :queue, :offset, :caption, "pending",
          "pending", :user, NOW(), NOW())'
    );
    $stmt->execute([
        'clip'      => $clipId,
        'ep'        => $episodeId,
        'label'     => $label,
        'preset'    => json_encode($brandPreset, JSON_UNESCAPED_UNICODE),
        'platforms' => json_encode($platforms,   JSON_UNESCAPED_UNICODE),
        'queue'     => $autoQueue,
        'offset'    => $schedOffset,
        'caption'   => $caption,
        'user'      => $userId,
    ]);

    $jobId = (int) $pdo->lastInsertId();

    // Pre-create pipeline_items for all three stages
    _pipeline_create_items($pdo, $jobId, (string) ($clip['source_path'] ?? ''), $platforms);

    // Mark the clip as "complete" so it is not triggered again
    $pdo->prepare('UPDATE video_clips SET status = "complete", updated_at = NOW() WHERE id = :id')
        ->execute(['id' => $clipId]);

    return $jobId;
}

// ---------------------------------------------------------------------------
// Process a pipeline job — runs all pending stages synchronously
// ---------------------------------------------------------------------------

/**
 * Process an existing pipeline job through brand_imaging → clip_generation → queueing.
 *
 * Safe to call multiple times: completed stages are skipped (idempotent).
 *
 * @return array ['ok' => bool, 'message' => string, 'error' => string|null]
 */
function process_pipeline_job(int $jobId): array
{
    $pdo = get_db();
    if (!$pdo) {
        return ['ok' => false, 'error' => 'Database unavailable'];
    }

    $job = _pipeline_load_job($pdo, $jobId);
    if (!$job) {
        return ['ok' => false, 'error' => 'Job not found'];
    }

    if (in_array($job['status'], ['completed', 'canceled'], true)) {
        return ['ok' => true, 'message' => 'Job already ' . $job['status']];
    }

    $pdo->prepare('UPDATE pipeline_jobs SET status = "processing", updated_at = NOW() WHERE id = :id')
        ->execute(['id' => $jobId]);

    $brandPreset = _pipeline_decode_json((string) $job['brand_preset_json']);
    $platforms   = _pipeline_decode_json((string) $job['platforms_json']);

    if (!is_array($brandPreset)) {
        $brandPreset = [];
    }
    if (!is_array($platforms)) {
        $platforms = [];
    }

    // Stage 1 — Brand imaging
    $r = _pipeline_run_brand_imaging($pdo, $job, $brandPreset);
    if (!$r['ok']) {
        _pipeline_fail_job($pdo, $jobId, 'brand_imaging: ' . ($r['error'] ?? ''));
        return $r;
    }
    $brandedPath = (string) $r['branded_path'];

    // Stage 2 — Platform clip generation
    $r = _pipeline_run_clip_generation($pdo, $job, $brandedPath, $platforms);
    if (!$r['ok']) {
        _pipeline_fail_job($pdo, $jobId, 'clip_generation: ' . ($r['error'] ?? ''));
        return $r;
    }
    $platformClips = $r['platform_clips'];

    // Stage 3 — Social queue creation (optional)
    if ((int) $job['auto_queue']) {
        $r = _pipeline_run_queueing($pdo, $job, $platformClips);
        if (!$r['ok']) {
            _pipeline_fail_job($pdo, $jobId, 'queueing: ' . ($r['error'] ?? ''));
            return $r;
        }
    } else {
        $pdo->prepare(
            'UPDATE pipeline_items SET status = "skipped", updated_at = NOW()
             WHERE pipeline_job_id = :jid AND stage = "queueing" AND status = "pending"'
        )->execute(['jid' => $jobId]);
    }

    $pdo->prepare(
        'UPDATE pipeline_jobs SET status = "completed", current_stage = "done", updated_at = NOW() WHERE id = :id'
    )->execute(['id' => $jobId]);

    return ['ok' => true, 'message' => 'Pipeline completed successfully.'];
}

// ---------------------------------------------------------------------------
// Summary helper — for API polling
// ---------------------------------------------------------------------------

/**
 * Return a lightweight status summary for a job.
 *
 * @return array|false
 */
function get_pipeline_job_summary(int $jobId): array|false
{
    $pdo = get_db();
    if (!$pdo) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT * FROM pipeline_jobs WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $jobId]);
    $job = $stmt->fetch();
    if (!$job) {
        return false;
    }

    // Item counts per stage
    $counts = [];
    $stmt   = $pdo->prepare(
        'SELECT stage,
                SUM(status = "done")       AS done,
                SUM(status = "failed")     AS failed,
                SUM(status = "skipped")    AS skipped,
                COUNT(*)                   AS total
         FROM pipeline_items
         WHERE pipeline_job_id = :jid
         GROUP BY stage'
    );
    $stmt->execute(['jid' => $jobId]);
    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['stage']] = $row;
    }

    return [
        'id'            => (int) $job['id'],
        'label'         => $job['label'],
        'status'        => $job['status'],
        'current_stage' => $job['current_stage'],
        'error_message' => $job['error_message'],
        'counts'        => $counts,
        'created_at'    => $job['created_at'],
        'updated_at'    => $job['updated_at'],
    ];
}

// ===========================================================================
// Internal helpers — not part of the public API
// ===========================================================================

function _pipeline_create_items(PDO $pdo, int $jobId, string $sourcePath, array $platforms): void
{
    // One brand_imaging item for the whole video
    $pdo->prepare(
        'INSERT INTO pipeline_items (pipeline_job_id, stage, platform, input_path, status, created_at, updated_at)
         VALUES (:jid, "brand_imaging", NULL, :src, "pending", NOW(), NOW())'
    )->execute(['jid' => $jobId, 'src' => $sourcePath]);

    // One clip_generation + one queueing item per platform
    $stmt1 = $pdo->prepare(
        'INSERT INTO pipeline_items (pipeline_job_id, stage, platform, status, created_at, updated_at)
         VALUES (:jid, "clip_generation", :platform, "pending", NOW(), NOW())'
    );
    $stmt2 = $pdo->prepare(
        'INSERT INTO pipeline_items (pipeline_job_id, stage, platform, status, created_at, updated_at)
         VALUES (:jid, "queueing", :platform, "pending", NOW(), NOW())'
    );
    foreach ($platforms as $platform) {
        $stmt1->execute(['jid' => $jobId, 'platform' => $platform]);
        $stmt2->execute(['jid' => $jobId, 'platform' => $platform]);
    }
}

function _pipeline_run_brand_imaging(PDO $pdo, array $job, array $preset): array
{
    $jobId = (int) $job['id'];

    $stmt = $pdo->prepare(
        'SELECT * FROM pipeline_items WHERE pipeline_job_id = :jid AND stage = "brand_imaging" LIMIT 1'
    );
    $stmt->execute(['jid' => $jobId]);
    $item = $stmt->fetch();

    if (!$item) {
        return ['ok' => false, 'error' => 'brand_imaging item row missing'];
    }

    // Already done (resume support)
    if ($item['status'] === 'done') {
        return ['ok' => true, 'branded_path' => (string) ($item['output_path'] ?? $item['input_path'])];
    }

    $pdo->prepare('UPDATE pipeline_items SET status = "processing", updated_at = NOW() WHERE id = :id')
        ->execute(['id' => $item['id']]);
    $pdo->prepare('UPDATE pipeline_jobs SET current_stage = "brand_imaging", updated_at = NOW() WHERE id = :id')
        ->execute(['id' => $jobId]);

    $uploadBase  = rtrim($GLOBALS['config']['upload_dir'], '/');
    $overlayPath = trim((string) ($preset['overlay_path'] ?? ''));

    if ($overlayPath === '') {
        // No overlay configured — treat source as the branded output
        $sourceRel = (string) ($item['input_path'] ?? '');
        $pdo->prepare(
            'UPDATE pipeline_items SET status = "done", output_path = :out, updated_at = NOW() WHERE id = :id'
        )->execute(['out' => $sourceRel, 'id' => $item['id']]);
        $pdo->prepare('UPDATE pipeline_jobs SET branded_clip_id = source_clip_id, updated_at = NOW() WHERE id = :id')
            ->execute(['id' => $jobId]);
        return ['ok' => true, 'branded_path' => $sourceRel];
    }

    $sourceAbs  = $uploadBase . '/' . ltrim((string) ($item['input_path'] ?? ''), '/');
    $overlayAbs = $_SERVER['DOCUMENT_ROOT'] . $overlayPath;

    $outSubdir = 'clips/branded';
    $outDir    = $uploadBase . '/' . $outSubdir;
    $outFile   = 'branded_' . $jobId . '_' . time() . '.mp4';
    $outAbs    = $outDir . '/' . $outFile;
    $outRel    = $outSubdir . '/' . $outFile;

    $result = apply_overlay_to_video(
        $sourceAbs,
        $overlayAbs,
        $outAbs,
        (string)  ($preset['position'] ?? 'bottom-right'),
        (float)   ($preset['opacity']  ?? 1.0),
        (int)     ($preset['scale']    ?? 30)
    );

    if (!$result['ok']) {
        $pdo->prepare(
            'UPDATE pipeline_items SET status = "failed", error_message = :err, ffmpeg_command = :cmd, updated_at = NOW() WHERE id = :id'
        )->execute([
            'err' => $result['error'] ?? 'FFmpeg error',
            'cmd' => $result['command'] ?? '',
            'id'  => $item['id'],
        ]);
        return ['ok' => false, 'error' => $result['error'] ?? 'FFmpeg failed'];
    }

    // Persist branded clip to video_clips
    $sourceClip = [];
    $scId = (int) ($job['source_clip_id'] ?? 0);
    if ($scId) {
        $sc = $pdo->prepare('SELECT * FROM video_clips WHERE id = :id LIMIT 1');
        $sc->execute(['id' => $scId]);
        $sourceClip = $sc->fetch() ?: [];
    }

    $pdo->prepare(
        'INSERT INTO video_clips (episode_id, label, source_path, output_path, platform_target, status, created_at, updated_at)
         VALUES (:ep, :label, :src, :out, NULL, "ready", NOW(), NOW())'
    )->execute([
        'ep'    => $sourceClip['episode_id'] ?? null,
        'label' => ($sourceClip['label'] ?? 'Clip') . ' [branded]',
        'src'   => $sourceClip['source_path'] ?? ($item['input_path'] ?? ''),
        'out'   => $outRel,
    ]);
    $brandedClipId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'UPDATE pipeline_items
         SET status = "done", output_path = :out, video_clip_id = :cid, ffmpeg_command = :cmd, updated_at = NOW()
         WHERE id = :id'
    )->execute([
        'out' => $outRel,
        'cid' => $brandedClipId,
        'cmd' => $result['command'] ?? '',
        'id'  => $item['id'],
    ]);
    $pdo->prepare('UPDATE pipeline_jobs SET branded_clip_id = :cid, updated_at = NOW() WHERE id = :id')
        ->execute(['cid' => $brandedClipId, 'id' => $jobId]);

    return ['ok' => true, 'branded_path' => $outRel];
}

function _pipeline_run_clip_generation(PDO $pdo, array $job, string $brandedPath, array $platforms): array
{
    $jobId = (int) $job['id'];

    $pdo->prepare('UPDATE pipeline_jobs SET current_stage = "clip_generation", updated_at = NOW() WHERE id = :id')
        ->execute(['id' => $jobId]);

    $uploadBase = rtrim($GLOBALS['config']['upload_dir'], '/');
    $brandedAbs = $uploadBase . '/' . ltrim($brandedPath, '/');
    $outDir     = $uploadBase . '/clips/platform';

    $platformClips = [];

    foreach ($platforms as $platform) {
        $stmt = $pdo->prepare(
            'SELECT * FROM pipeline_items
             WHERE pipeline_job_id = :jid AND stage = "clip_generation" AND platform = :platform
             LIMIT 1'
        );
        $stmt->execute(['jid' => $jobId, 'platform' => $platform]);
        $item = $stmt->fetch();

        if (!$item) {
            continue;
        }

        // Resume support
        if ($item['status'] === 'done') {
            $platformClips[$platform] = (string) ($item['output_path'] ?? '');
            continue;
        }

        $pdo->prepare(
            'UPDATE pipeline_items SET status = "processing", input_path = :inp, updated_at = NOW() WHERE id = :id'
        )->execute(['inp' => $brandedPath, 'id' => $item['id']]);

        $outAbs = generate_platform_clip($brandedAbs, $outDir, $platform, (string) ($job['label'] ?? ''));

        if (!$outAbs) {
            $pdo->prepare(
                'UPDATE pipeline_items SET status = "failed", error_message = "FFmpeg failed", updated_at = NOW() WHERE id = :id'
            )->execute(['id' => $item['id']]);
            // Non-fatal: skip this platform, continue with others
            continue;
        }

        $outRel = 'clips/platform/' . basename($outAbs);

        // Persist to video_clips
        $pdo->prepare(
            'INSERT INTO video_clips (episode_id, label, source_path, output_path, platform_target, status, created_at, updated_at)
             VALUES (:ep, :label, :src, :out, :platform, "ready", NOW(), NOW())'
        )->execute([
            'ep'       => (int) ($job['episode_id'] ?? 0) ?: null,
            'label'    => ($job['label'] ?? 'Clip') . ' [' . $platform . ']',
            'src'      => $brandedPath,
            'out'      => $outRel,
            'platform' => $platform,
        ]);
        $newClipId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'UPDATE pipeline_items SET status = "done", output_path = :out, video_clip_id = :cid, updated_at = NOW() WHERE id = :id'
        )->execute(['out' => $outRel, 'cid' => $newClipId, 'id' => $item['id']]);

        $platformClips[$platform] = $outRel;
    }

    return ['ok' => true, 'platform_clips' => $platformClips];
}

function _pipeline_run_queueing(PDO $pdo, array $job, array $platformClips): array
{
    $jobId      = (int)    $job['id'];
    $platforms  = _pipeline_decode_json((string) $job['platforms_json']) ?? [];
    $schedOffset = (int)   ($job['schedule_offset_hrs'] ?? 0);
    $caption    = (string) ($job['caption_template'] ?? '');
    $episodeId  = (int)    ($job['episode_id'] ?? 0) ?: null;

    $pdo->prepare('UPDATE pipeline_jobs SET current_stage = "queueing", updated_at = NOW() WHERE id = :id')
        ->execute(['id' => $jobId]);

    $labels = _pipeline_platform_labels();

    foreach ($platforms as $platform) {
        $stmt = $pdo->prepare(
            'SELECT * FROM pipeline_items
             WHERE pipeline_job_id = :jid AND stage = "queueing" AND platform = :platform
             LIMIT 1'
        );
        $stmt->execute(['jid' => $jobId, 'platform' => $platform]);
        $item = $stmt->fetch();

        if (!$item || $item['status'] === 'done') {
            continue;
        }

        // Skip if no generated clip for this platform
        if (!isset($platformClips[$platform])) {
            $pdo->prepare('UPDATE pipeline_items SET status = "skipped", updated_at = NOW() WHERE id = :id')
                ->execute(['id' => $item['id']]);
            continue;
        }

        $assetPath = '/uploads/' . ltrim($platformClips[$platform], '/');
        $schedFor  = date('Y-m-d H:i:s', strtotime("+{$schedOffset} hours"));
        $dispLabel = $labels[$platform] ?? $platform;

        $pdo->prepare(
            'INSERT INTO social_post_queue
             (episode_id, platform, content_type, caption, asset_path, scheduled_for, status, created_at, updated_at)
             VALUES (:ep, :platform, "clip", :caption, :asset, :sched, "queued", NOW(), NOW())'
        )->execute([
            'ep'       => $episodeId,
            'platform' => $dispLabel,
            'caption'  => $caption,
            'asset'    => $assetPath,
            'sched'    => $schedFor,
        ]);
        $queueId = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE pipeline_items SET status = "done", queue_id = :qid, updated_at = NOW() WHERE id = :id')
            ->execute(['qid' => $queueId, 'id' => $item['id']]);

        // Mark the generated clip as queued
        $cStmt = $pdo->prepare(
            'SELECT video_clip_id FROM pipeline_items
             WHERE pipeline_job_id = :jid AND stage = "clip_generation" AND platform = :platform
             LIMIT 1'
        );
        $cStmt->execute(['jid' => $jobId, 'platform' => $platform]);
        $cRow = $cStmt->fetch();
        if ($cRow && (int) $cRow['video_clip_id']) {
            $pdo->prepare('UPDATE video_clips SET status = "queued", updated_at = NOW() WHERE id = :id')
                ->execute(['id' => (int) $cRow['video_clip_id']]);
        }
    }

    return ['ok' => true];
}

function _pipeline_load_job(PDO $pdo, int $jobId): array|false
{
    $stmt = $pdo->prepare('SELECT * FROM pipeline_jobs WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $jobId]);
    return $stmt->fetch() ?: false;
}

function _pipeline_fail_job(PDO $pdo, int $jobId, string $error): void
{
    $pdo->prepare(
        'UPDATE pipeline_jobs SET status = "failed", error_message = :err, updated_at = NOW() WHERE id = :id'
    )->execute(['err' => $error, 'id' => $jobId]);
    error_log('[PTMD Pipeline] Job #' . $jobId . ' failed: ' . $error);
}

function _pipeline_decode_json(string $json): array|null
{
    if ($json === '' || $json === 'null') {
        return null;
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
}

function _pipeline_platform_labels(): array
{
    return [
        'youtube'         => 'YouTube',
        'youtube_shorts'  => 'YouTube Shorts',
        'tiktok'          => 'TikTok',
        'instagram_reels' => 'Instagram Reels',
        'facebook_reels'  => 'Facebook Reels',
        'x'               => 'X',
    ];
}
