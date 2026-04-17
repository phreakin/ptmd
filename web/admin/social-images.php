<?php
/**
 * PTMD Admin — Social Platform Images
 *
 * Upload, validate, and manage per-platform image assets (thumbnails,
 * covers, profiles, banners) together with detected dimensions and
 * platform requirement validation status.
 */

$pageTitle      = 'Social Images | PTMD Admin';
$activePage     = 'social-images';
$pageHeading    = 'Social Platform Images';
$pageSubheading = 'Manage image assets for each social platform and check them against official size requirements.';

include __DIR__ . '/_admin_head.php';

require_once __DIR__ . '/../inc/social_services.php';

$pdo = get_db();

$platforms  = ['YouTube', 'YouTube Shorts', 'TikTok', 'Instagram Reels', 'Facebook Reels', 'X'];
$imageTypes = ['thumbnail', 'cover', 'profile', 'banner', 'story'];

if ($pdo && is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect('/admin/social-images.php', 'Invalid CSRF token.', 'danger');
    }

    $postAction = $_POST['_action'] ?? 'upload';

    // ── Upload ──────────────────────────────────────────────────────────────
    if ($postAction === 'upload') {
        $platform  = trim((string) ($_POST['platform']   ?? ''));
        $imageType = trim((string) ($_POST['image_type'] ?? ''));

        if (!in_array($platform, $platforms, true)) {
            redirect('/admin/social-images.php', 'Invalid platform.', 'danger');
        }
        if (!in_array($imageType, $imageTypes, true)) {
            redirect('/admin/social-images.php', 'Invalid image type.', 'danger');
        }
        if (empty($_FILES['image_file']['name'])) {
            redirect('/admin/social-images.php', 'No file selected.', 'warning');
        }

        $allowed   = $GLOBALS['config']['allowed_image_ext'];
        $savedPath = save_upload($_FILES['image_file'], 'social', $allowed);

        if (!$savedPath) {
            redirect('/admin/social-images.php', 'Upload failed. Ensure the file is a valid image (JPG, PNG, WebP, GIF).', 'danger');
        }

        $absPath  = $GLOBALS['config']['upload_dir'] . '/' . $savedPath;
        $fileSize = (int) ($_FILES['image_file']['size'] ?? 0);

        // Detect dimensions via getimagesize
        $width  = null;
        $height = null;
        $imgInfo = @getimagesize($absPath);
        if (is_array($imgInfo) && isset($imgInfo[0], $imgInfo[1])) {
            $width  = (int) $imgInfo[0];
            $height = (int) $imgInfo[1];
        }

        // Validate against platform requirements
        $validation = validate_social_image($platform, $imageType, $width, $height, $fileSize);

        $pdo->prepare(
            'INSERT INTO social_platform_images
             (platform, image_type, image_path, width, height, file_size, is_valid, validation_errors, created_at, updated_at)
             VALUES (:platform, :image_type, :image_path, :width, :height, :file_size, :is_valid, :errors, NOW(), NOW())'
        )->execute([
            'platform'   => $platform,
            'image_type' => $imageType,
            'image_path' => $savedPath,
            'width'      => $width,
            'height'     => $height,
            'file_size'  => $fileSize ?: null,
            'is_valid'   => $validation['is_valid'] ? 1 : 0,
            'errors'     => empty($validation['errors']) ? null : json_encode($validation['errors'], JSON_UNESCAPED_UNICODE),
        ]);

        $msg  = $validation['is_valid']
            ? 'Image uploaded and passed all platform requirements.'
            : 'Image uploaded but has validation issues: ' . implode(' ', $validation['errors']);
        $type = $validation['is_valid'] ? 'success' : 'warning';
        redirect('/admin/social-images.php', $msg, $type);
    }

    // ── Delete ──────────────────────────────────────────────────────────────
    if ($postAction === 'delete') {
        $delId = (int) ($_POST['id'] ?? 0);
        if ($delId > 0) {
            $row = $pdo->prepare('SELECT image_path FROM social_platform_images WHERE id = :id');
            $row->execute(['id' => $delId]);
            $row = $row->fetch();
            if ($row) {
                $absPath = $GLOBALS['config']['upload_dir'] . '/' . $row['image_path'];
                if (is_file($absPath)) {
                    unlink($absPath);
                }
            }
            $pdo->prepare('DELETE FROM social_platform_images WHERE id = :id')->execute(['id' => $delId]);
            redirect('/admin/social-images.php', 'Image deleted.', 'success');
        }
    }
}

// ── Load images ─────────────────────────────────────────────────────────────
$filterPlatform = trim((string) ($_GET['platform'] ?? ''));
$query  = 'SELECT * FROM social_platform_images';
$params = [];
if ($filterPlatform !== '' && in_array($filterPlatform, $platforms, true)) {
    $query  .= ' WHERE platform = :platform';
    $params['platform'] = $filterPlatform;
}
$query .= ' ORDER BY platform, image_type, created_at DESC';

$images = [];
if ($pdo) {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $images = $stmt->fetchAll();
}

// ── Build requirements reference ────────────────────────────────────────────
$requirements = get_social_image_requirements();
?>

<!-- Upload form -->
<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-cloud-arrow-up me-2 ptmd-text-teal"></i>Upload Platform Image
    </h2>
    <form method="post" action="/admin/social-images.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action" value="upload">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Platform</label>
                <select class="form-select" name="platform" required>
                    <?php foreach ($platforms as $p): ?>
                        <option><?php ee($p); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Image Type</label>
                <select class="form-select" name="image_type" required>
                    <?php foreach ($imageTypes as $t): ?>
                        <option><?php ee($t); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Image File</label>
                <input class="form-control" type="file" name="image_file" accept="image/*" required>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-ptmd-primary w-100" type="submit">
                    <i class="fa-solid fa-upload me-1"></i>Upload
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Requirements reference -->
<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-3">
        <i class="fa-solid fa-ruler me-2 ptmd-text-teal"></i>Platform Image Requirements
    </h2>
    <div class="table-responsive">
        <table class="ptmd-table">
            <thead>
                <tr>
                    <th>Platform</th>
                    <th>Type</th>
                    <th>Recommended Size</th>
                    <th>Aspect Ratio</th>
                    <th>Max File Size</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requirements as $platform => $types): ?>
                    <?php foreach ($types as $type => $req): ?>
                        <tr>
                            <td class="fw-500"><?php ee($platform); ?></td>
                            <td class="ptmd-muted small"><?php ee($type); ?></td>
                            <td>
                                <?php if ($req['recommended_width'] && $req['recommended_height']): ?>
                                    <?php ee($req['recommended_width']); ?>×<?php ee($req['recommended_height']); ?>px
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($req['aspect_ratio_w'] && $req['aspect_ratio_h']): ?>
                                    <?php ee($req['aspect_ratio_w']); ?>:<?php ee($req['aspect_ratio_h']); ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($req['max_file_size']): ?>
                                    <?php ee(number_format($req['max_file_size'] / (1024 * 1024), 0)); ?> MB
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Platform filter tabs -->
<div class="d-flex flex-wrap gap-2 mb-4">
    <a href="/admin/social-images.php"
       class="btn btn-sm <?php echo $filterPlatform === '' ? 'btn-ptmd-teal' : 'btn-ptmd-outline'; ?>">
        All
    </a>
    <?php foreach ($platforms as $p): ?>
        <a href="/admin/social-images.php?platform=<?php ee(urlencode($p)); ?>"
           class="btn btn-sm <?php echo $filterPlatform === $p ? 'btn-ptmd-teal' : 'btn-ptmd-outline'; ?>">
            <?php ee($p); ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Images table -->
<div class="ptmd-panel p-lg">
    <h2 class="h6 mb-4">Stored Images</h2>
    <?php if ($images): ?>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr>
                        <th>Preview</th>
                        <th>Platform</th>
                        <th>Type</th>
                        <th>Dimensions</th>
                        <th>File Size</th>
                        <th>Status</th>
                        <th>Errors</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($images as $img): ?>
                        <?php
                        $imgPathSafe = htmlspecialchars('/uploads/' . ltrim($img['image_path'], '/'), ENT_QUOTES, 'UTF-8');
                        $errors = !empty($img['validation_errors'])
                            ? (array) json_decode((string) $img['validation_errors'], true)
                            : [];
                        ?>
                        <tr>
                            <td style="width:80px">
                                <img
                                    src="<?php echo $imgPathSafe; ?>"
                                    alt="<?php ee($img['platform'] . ' ' . $img['image_type']); ?>"
                                    style="width:72px;height:48px;object-fit:cover;border-radius:4px;background:var(--ptmd-black)"
                                    loading="lazy"
                                >
                            </td>
                            <td class="fw-500"><?php ee($img['platform']); ?></td>
                            <td class="ptmd-muted small"><?php ee($img['image_type']); ?></td>
                            <td class="ptmd-muted small">
                                <?php if ($img['width'] && $img['height']): ?>
                                    <?php ee($img['width']); ?>×<?php ee($img['height']); ?>px
                                <?php else: ?>
                                    <span class="ptmd-muted">Unknown</span>
                                <?php endif; ?>
                            </td>
                            <td class="ptmd-muted small">
                                <?php if ($img['file_size']): ?>
                                    <?php echo e(number_format((int) $img['file_size'] / 1024, 1)); ?> KB
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $img['is_valid'] === 1): ?>
                                    <span class="ptmd-status ptmd-status-published"
                                          data-tippy-content="Meets all platform requirements">Valid</span>
                                <?php else: ?>
                                    <span class="ptmd-status ptmd-status-draft"
                                          data-tippy-content="<?php ee(implode(' | ', $errors)); ?>">Invalid</span>
                                <?php endif; ?>
                            </td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs);max-width:280px">
                                <?php if (!empty($errors)): ?>
                                    <ul class="mb-0 ps-3">
                                        <?php foreach ($errors as $err): ?>
                                            <li><?php ee((string) $err); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <a href="<?php echo $imgPathSafe; ?>"
                                       target="_blank"
                                       rel="noopener"
                                       class="btn btn-ptmd-ghost btn-sm"
                                       data-tippy-content="Open full image">
                                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                    </a>
                                    <form method="post" action="/admin/social-images.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                        <input type="hidden" name="_action" value="delete">
                                        <input type="hidden" name="id" value="<?php ee((string) $img['id']); ?>">
                                        <button
                                            class="btn btn-ptmd-ghost btn-sm"
                                            style="color:var(--ptmd-error)"
                                            type="submit"
                                            data-confirm="Delete this image? This is permanent."
                                            data-tippy-content="Delete">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="ptmd-muted small">No images stored yet. Upload a platform image above to get started.</p>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_admin_footer.php'; ?>
