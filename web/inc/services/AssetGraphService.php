<?php

declare(strict_types=1);

/**
 * PTMD — Asset Graph Service
 * Asset relationships, lineage, dependency graphs, and orphan detection.
 */

require_once __DIR__ . '/EventTrackingService.php';

/**
 * Link two records via asset_relations.
 *
 * @param string $sourceTable   e.g. 'cases', 'video_clips', 'assets'
 * @param int    $sourceId
 * @param string $targetTable
 * @param int    $targetId
 * @param string $relationType  e.g. 'used_in', 'derived_from', 'references', 'contains'
 * @param array  $meta          Optional freeform metadata
 * @return bool
 */
function ptmd_asset_link(
    string $sourceTable,
    int $sourceId,
    string $targetTable,
    int $targetId,
    string $relationType = 'used_in',
    array $meta = []
): bool {
    $pdo = get_db();
    if (!$pdo) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO asset_relations
                (source_table, source_id, target_table, target_id, relation_type, meta, created_at)
             VALUES
                (:source_table, :source_id, :target_table, :target_id, :relation_type, :meta, NOW())
             ON DUPLICATE KEY UPDATE
                relation_type = VALUES(relation_type),
                meta = VALUES(meta)'
        );
        $stmt->execute([
            ':source_table'  => $sourceTable,
            ':source_id'     => $sourceId,
            ':target_table'  => $targetTable,
            ':target_id'     => $targetId,
            ':relation_type' => $relationType,
            ':meta'          => !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);

        ptmd_emit_event(
            'asset.linked',
            'assets',
            $sourceTable,
            $sourceId,
            ['target_table' => $targetTable, 'target_id' => $targetId, 'relation_type' => $relationType],
            null, null, null, null, null, null, 'linked', 'system'
        );

        return true;
    } catch (\Throwable $e) {
        error_log('[PTMD AssetGraph] ptmd_asset_link failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get all records that the given asset depends on (upstream).
 *
 * @param string $table
 * @param int    $id
 * @return array
 */
function ptmd_asset_get_dependencies(string $table, int $id): array
{
    $pdo = get_db();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM asset_relations
              WHERE source_table = :table AND source_id = :id
              ORDER BY created_at DESC'
        );
        $stmt->execute([':table' => $table, ':id' => $id]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('[PTMD AssetGraph] ptmd_asset_get_dependencies failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get all records that depend on the given asset (downstream).
 *
 * @param string $table
 * @param int    $id
 * @return array
 */
function ptmd_asset_get_dependents(string $table, int $id): array
{
    $pdo = get_db();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM asset_relations
              WHERE target_table = :table AND target_id = :id
              ORDER BY created_at DESC'
        );
        $stmt->execute([':table' => $table, ':id' => $id]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('[PTMD AssetGraph] ptmd_asset_get_dependents failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get full lineage tree for an asset (recursive, max depth 5).
 *
 * @param string $table
 * @param int    $id
 * @param int    $depth     Current recursion depth
 * @param int    $maxDepth  Stop recursing beyond this depth
 * @return array ['table'=>string, 'id'=>int, 'dependencies'=>[], 'dependents'=>[]]
 */
function ptmd_asset_lineage_tree(string $table, int $id, int $depth = 0, int $maxDepth = 5): array
{
    $node = [
        'table'        => $table,
        'id'           => $id,
        'depth'        => $depth,
        'dependencies' => [],
        'dependents'   => [],
    ];

    if ($depth >= $maxDepth) {
        return $node;
    }

    $deps = ptmd_asset_get_dependencies($table, $id);
    foreach ($deps as $dep) {
        $node['dependencies'][] = ptmd_asset_lineage_tree(
            (string) $dep['target_table'],
            (int) $dep['target_id'],
            $depth + 1,
            $maxDepth
        );
    }

    $dependents = ptmd_asset_get_dependents($table, $id);
    foreach ($dependents as $dependent) {
        $node['dependents'][] = ptmd_asset_lineage_tree(
            (string) $dependent['source_table'],
            (int) $dependent['source_id'],
            $depth + 1,
            $maxDepth
        );
    }

    return $node;
}

/**
 * Scan for orphaned assets in the assets table.
 * An asset is orphaned if it has no asset_relations records AND
 * no usage in ai_generations or social_post_queue.
 *
 * @param int $limit
 * @return array
 */
function ptmd_asset_find_orphans(int $limit = 100): array
{
    $pdo = get_db();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT a.*
               FROM assets a
              WHERE NOT EXISTS (
                  SELECT 1 FROM asset_relations ar
                   WHERE (ar.source_table = "assets" AND ar.source_id = a.id)
                      OR (ar.target_table = "assets" AND ar.target_id = a.id)
              )
                AND NOT EXISTS (
                  SELECT 1 FROM ai_generations ag
                   WHERE ag.case_id IS NOT NULL AND ag.case_id = a.case_id
                  LIMIT 1
              )
                AND NOT EXISTS (
                  SELECT 1 FROM social_post_queue spq
                   WHERE spq.case_id IS NOT NULL AND spq.case_id = a.case_id
                  LIMIT 1
              )
              ORDER BY a.created_at ASC
              LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('[PTMD AssetGraph] ptmd_asset_find_orphans failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Generate and store a file fingerprint (hash) for duplicate detection.
 * Uses MD5 + SHA256. Stores in asset_fingerprints. Returns file hash or null.
 *
 * @param string $filePath  Absolute path to file
 * @return string|null
 */
function ptmd_asset_fingerprint(string $filePath): ?string
{
    if (!is_readable($filePath)) {
        error_log('[PTMD AssetGraph] ptmd_asset_fingerprint: file not readable: ' . $filePath);
        return null;
    }

    try {
        $md5    = md5_file($filePath);
        $sha256 = hash_file('sha256', $filePath);
        $size   = filesize($filePath);

        if ($md5 === false || $sha256 === false || $size === false) {
            return null;
        }

        $pdo = get_db();
        if (!$pdo) {
            return $sha256;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO asset_fingerprints
                (file_path, md5_hash, sha256_hash, file_size, created_at)
             VALUES
                (:file_path, :md5_hash, :sha256_hash, :file_size, NOW())
             ON DUPLICATE KEY UPDATE
                md5_hash   = VALUES(md5_hash),
                sha256_hash = VALUES(sha256_hash),
                file_size  = VALUES(file_size)'
        );
        $stmt->execute([
            ':file_path'   => $filePath,
            ':md5_hash'    => $md5,
            ':sha256_hash' => $sha256,
            ':file_size'   => $size,
        ]);

        return $sha256;
    } catch (\Throwable $e) {
        error_log('[PTMD AssetGraph] ptmd_asset_fingerprint failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Check if a file is a duplicate of any existing asset.
 * Returns existing asset_fingerprint row if duplicate found, null otherwise.
 *
 * @param string $filePath  Absolute path to file to check
 * @return array|null
 */
function ptmd_asset_check_duplicate(string $filePath): ?array
{
    if (!is_readable($filePath)) {
        return null;
    }

    try {
        $sha256 = hash_file('sha256', $filePath);
        if ($sha256 === false) {
            return null;
        }

        $pdo = get_db();
        if (!$pdo) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM asset_fingerprints
              WHERE sha256_hash = :sha256_hash AND file_path != :file_path
              LIMIT 1'
        );
        $stmt->execute([':sha256_hash' => $sha256, ':file_path' => $filePath]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (\Throwable $e) {
        error_log('[PTMD AssetGraph] ptmd_asset_check_duplicate failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Generate a PTMD canonical asset filename.
 * Format: ptmd_{caseSlug}_{assetType}_{platform}_{yyyymmdd}_v{version}
 *
 * @param string   $caseSlug
 * @param string   $assetType  e.g. 'thumbnail', 'clip', 'caption', 'hook'
 * @param string   $platform   e.g. 'tiktok', 'youtube', 'all'
 * @param int|null $version
 * @return string
 */
function ptmd_asset_canonical_name(
    string $caseSlug,
    string $assetType,
    string $platform,
    ?int $version = 1
): string {
    $date    = date('Ymd');
    $version = max(1, (int) $version);
    $slug    = slugify($caseSlug);
    $type    = slugify($assetType);
    $plat    = slugify($platform);

    return "ptmd_{$slug}_{$type}_{$plat}_{$date}_v{$version}";
}

/**
 * Get full asset record with relations, versions, and usage summary.
 *
 * @param int $assetId
 * @return array|null
 */
function ptmd_asset_get_full(int $assetId): ?array
{
    $pdo = get_db();
    if (!$pdo) {
        return null;
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM assets WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $assetId]);
        $asset = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$asset) {
            return null;
        }

        $asset['dependencies'] = ptmd_asset_get_dependencies('assets', $assetId);
        $asset['dependents']   = ptmd_asset_get_dependents('assets', $assetId);

        // Load versions
        $vStmt = $pdo->prepare(
            'SELECT * FROM asset_versions WHERE asset_id = :asset_id ORDER BY version_number DESC'
        );
        $vStmt->execute([':asset_id' => $assetId]);
        $asset['versions'] = $vStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Load fingerprint
        $fStmt = $pdo->prepare(
            'SELECT * FROM asset_fingerprints WHERE asset_id = :asset_id LIMIT 1'
        );
        $fStmt->execute([':asset_id' => $assetId]);
        $fingerprint = $fStmt->fetch(\PDO::FETCH_ASSOC);
        $asset['fingerprint'] = $fingerprint ?: null;

        // Usage summary
        $asset['usage_summary'] = [
            'total_relations' => count($asset['dependencies']) + count($asset['dependents']),
            'version_count'   => count($asset['versions']),
            'has_fingerprint' => $asset['fingerprint'] !== null,
        ];

        return $asset;
    } catch (\Throwable $e) {
        error_log('[PTMD AssetGraph] ptmd_asset_get_full failed: ' . $e->getMessage());
        return null;
    }
}
