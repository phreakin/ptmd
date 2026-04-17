<?php
/**
 * PTMD API — OBS-Ready Overlay Pack Generator
 *
 * GET  ?episode_id=N  — Download a ZIP containing overlay assets, an OBS
 *                        scene JSON stub, and a README for the specified episode.
 * GET  (no episode)   — Global pack: all brand overlays + global OBS config.
 *
 * Requires admin session.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/video_processor.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo = get_db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable']);
    exit;
}

$episodeId = isset($_GET['episode_id']) ? (int) $_GET['episode_id'] : 0;
$docRoot   = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/');

// ── Gather episode (if requested) ────────────────────────────────────────────
$episode  = null;
$triggers = [];

if ($episodeId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM episodes WHERE id = :id');
    $stmt->execute(['id' => $episodeId]);
    $episode = $stmt->fetch() ?: null;

    if (!$episode) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Episode not found']);
        exit;
    }

    $tStmt = $pdo->prepare(
        'SELECT * FROM episode_overlay_triggers WHERE episode_id = :eid ORDER BY sort_order, id'
    );
    $tStmt->execute(['eid' => $episodeId]);
    $triggers = $tStmt->fetchAll();
}

// ── Collect overlay asset paths ───────────────────────────────────────────────
$overlayPaths = [];

// Brand overlays from filesystem
$brandDir = $docRoot . '/assets/brand/overlays';
if (is_dir($brandDir)) {
    foreach (glob($brandDir . '/*.{png,gif,webp}', GLOB_BRACE) ?: [] as $f) {
        $overlayPaths[basename($f)] = $f;
    }
}

// DB media library overlays
$dbOverlays = $pdo->query(
    'SELECT file_path FROM media_library WHERE category = "overlay"'
)->fetchAll();
foreach ($dbOverlays as $row) {
    $absPath = rtrim((string) $GLOBALS['config']['upload_dir'], '/') . '/' . $row['file_path'];
    if (is_file($absPath)) {
        $overlayPaths[basename($absPath)] = $absPath;
    }
}

// Episode-specific trigger overlays
foreach ($triggers as $trig) {
    $absPath = $docRoot . $trig['overlay_path'];
    if (is_file($absPath)) {
        $overlayPaths[basename($absPath)] = $absPath;
    }
}

if (empty($overlayPaths)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'No overlay assets found to package.']);
    exit;
}

// ── Build OBS scene JSON stub ─────────────────────────────────────────────────
$siteName   = site_setting('site_name', 'PTMD');
$sceneName  = $episode ? $siteName . ' — ' . $episode['title'] : $siteName . ' — Global Overlay Pack';
$obsScenes  = [];
$obsSources = [];

foreach (array_keys($overlayPaths) as $filename) {
    $sourceName    = pathinfo($filename, PATHINFO_FILENAME);
    $obsSources[]  = [
        'name'       => $sourceName,
        'type'       => 'image_source',
        'settings'   => ['file' => $filename],
    ];
    $obsScenes[]   = [
        'name'        => $filename === 'ptmd_overlay_lower_third.png' ? 'Lower Third' : $sourceName,
        'sources'     => [$sourceName],
    ];
}

// If episode has triggers, add timing guidance
$triggerNotes = [];
if ($triggers) {
    foreach ($triggers as $trig) {
        $triggerNotes[] = [
            'label'      => $trig['label'] ?: 'Untitled',
            'asset'      => basename((string) $trig['overlay_path']),
            'in_sec'     => (float) $trig['timestamp_in'],
            'out_sec'    => (float) $trig['timestamp_out'],
            'position'   => $trig['position'],
            'opacity'    => (float) $trig['opacity'],
            'scale_pct'  => (int)   $trig['scale'],
            'animation'  => $trig['animation_style'],
        ];
    }
}

$obsConfig = [
    'ptmd_version'   => '2.0',
    'generated_at'   => date('c'),
    'pack_name'      => $sceneName,
    'scene_name'     => $sceneName,
    'episode_title'  => $episode['title'] ?? null,
    'sources'        => $obsSources,
    'scenes'         => $obsScenes,
    'overlay_timing' => $triggerNotes ?: null,
    'instructions'   => [
        '1. Import each overlay image as an Image source in OBS.',
        '2. Add them to a new scene named "' . $sceneName . '".',
        '3. For timed overlays, use the "Media Source" transition timings in overlay_timing.',
        '4. Position each source using the position values (bottom-right, top-left, etc.).',
        '5. Set opacity via the "Filters > Color Correction > Opacity" slider.',
    ],
];

// ── Build README ──────────────────────────────────────────────────────────────
$readmeLines = [
    '# ' . $siteName . ' OBS Overlay Pack',
    '',
    '**Scene:** ' . $sceneName,
    '**Generated:** ' . date('Y-m-d H:i:s T'),
    '',
    '## Contents',
    '',
    '| File | Type | Notes |',
    '|------|------|-------|',
    '| obs_scene_config.json | OBS config stub | Import or reference in OBS |',
    '| README.md | This file | |',
];

foreach (array_keys($overlayPaths) as $filename) {
    $readmeLines[] = '| ' . $filename . ' | Overlay image | Add as Image Source in OBS |';
}

if ($triggerNotes) {
    $readmeLines[] = '';
    $readmeLines[] = '## Timeline Cue Points';
    $readmeLines[] = '';
    $readmeLines[] = '| Label | Asset | In (s) | Out (s) | Position | Scale | Animation |';
    $readmeLines[] = '|-------|-------|--------|---------|----------|-------|-----------|';
    foreach ($triggerNotes as $t) {
        $readmeLines[] = sprintf(
            '| %s | %s | %.3f | %.3f | %s | %d%% | %s |',
            $t['label'], $t['asset'], $t['in_sec'], $t['out_sec'],
            $t['position'], $t['scale_pct'], $t['animation']
        );
    }
}

$readmeLines[] = '';
$readmeLines[] = '## Quick Setup';
$readmeLines[] = '1. Open OBS Studio → Scene Collection → New';
$readmeLines[] = '2. Add Sources → Image → Browse to the .png files in this pack.';
$readmeLines[] = '3. Set each source size to match the position described in obs_scene_config.json.';
$readmeLines[] = '4. Use the "Visibility" keybind or Scene Switcher plugin for timed cues.';

$readme = implode("\n", $readmeLines);

// ── Stream ZIP directly ───────────────────────────────────────────────────────
$packSlug = $episode
    ? 'ptmd_obs_pack_ep' . $episodeId . '_' . time()
    : 'ptmd_obs_pack_global_' . time();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $packSlug . '.zip"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

$zip = new ZipArchive();
$tmpFile = tempnam(sys_get_temp_dir(), 'ptmd_obs_');
if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Failed to create ZIP archive.');
}

// Add overlay image files
foreach ($overlayPaths as $filename => $absPath) {
    if (is_file($absPath)) {
        $zip->addFile($absPath, $packSlug . '/' . $filename);
    }
}

// Add JSON config
$zip->addFromString(
    $packSlug . '/obs_scene_config.json',
    json_encode($obsConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// Add README
$zip->addFromString($packSlug . '/README.md', $readme);

$zip->close();

// Output and clean up
readfile($tmpFile);
@unlink($tmpFile);
exit;
