<?php
/**
 * PTMD Admin — Media Library
 */

require_once __DIR__ . '/../inc/bootstrap.php';

$pageTitle    = 'Media Library | PTMD Admin';
$activePage   = 'media';
$pageHeading  = 'Media Library';
$pageSubheading = 'Manage all brand assets: overlays, thumbnails, logos, watermarks, and more.';

include __DIR__ . '/_admin_head.php';

$pdo = get_db();

if ($pdo && is_post()) {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect(route_admin('media'), 'Invalid CSRF token.', 'danger');
    }

    $postAction = $_POST['_action'] ?? 'upload';

    if ($postAction === 'upload') {
        if (empty($_FILES['media_file']['name'])) {
            redirect(route_admin('media'), 'No file selected.', 'warning');
        }

        $category = $_POST['category'] ?? 'other';
        $subdir   = $category;

        // Determine allowed extensions by category
        $imageExt = $GLOBALS['config']['allowed_image_ext'];
        $videoExt = $GLOBALS['config']['allowed_video_ext'];
        $allowed  = in_array($category, ['clip'], true) ? $videoExt : $imageExt;

        $savedPath = save_upload($_FILES['media_file'], $subdir, $allowed);

        if (!$savedPath) {
            redirect(route_admin('media'), 'Upload failed. Check file type.', 'danger');
        }

        $fileSize = $_FILES['media_file']['size'];
        $mimeType = $_FILES['media_file']['type'];

        $pdo->prepare(
            'INSERT INTO media_library (filename, file_path, file_type, file_size, category, created_at, updated_at)
             VALUES (:name, :path, :type, :size, :cat, NOW(), NOW())'
        )->execute([
            'name' => $_FILES['media_file']['name'],
            'path' => $savedPath,
            'type' => $mimeType,
            'size' => $fileSize,
            'cat'  => $category,
        ]);

        redirect(route_admin('media'), 'File uploaded.', 'success');
    }

    if ($postAction === 'delete') {
        $delId = (int) ($_POST['id'] ?? 0);
        if ($delId > 0) {
            $row = $pdo->prepare('SELECT file_path FROM media_library WHERE id = :id');
            $row->execute(['id' => $delId]);
            $row = $row->fetch();
            if ($row) {
                $absPath = $GLOBALS['config']['upload_dir'] . '/' . $row['file_path'];
                if (is_file($absPath)) {
                    unlink($absPath);
                }
            }
            $pdo->prepare('DELETE FROM media_library WHERE id = :id')->execute(['id' => $delId]);
            redirect(route_admin('media'), 'File deleted.', 'success');
        }
    }
}

$filterCategory = $_GET['category'] ?? '';
$categories = ['thumbnail','intro','overlay','clip','watermark','logo','other'];

$query = 'SELECT * FROM media_library';
$params = [];
if ($filterCategory) {
    $query  .= ' WHERE category = :cat';
    $params['cat'] = $filterCategory;
}
$query .= ' ORDER BY created_at DESC';

$mediaItems = $pdo ? $pdo->prepare($query) : null;
if ($mediaItems) {
    $mediaItems->execute($params);
    $mediaItems = $mediaItems->fetchAll();
} else {
    $mediaItems = [];
}
?>

<!-- Upload form -->
<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-cloud-arrow-up me-2 ptmd-text-teal"></i>Upload Asset
    </h2>
    <form method="post" action="<?php echo e(route_admin('media')); ?>" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" name="_action" value="upload">
        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label">File</label>
                <input class="form-control" type="file" name="media_file" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Category</label>
                <select class="form-select" name="category">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php ee($cat); ?>"><?php ee(ucfirst($cat)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button class="btn btn-ptmd-primary w-100" type="submit">
                    <i class="fa-solid fa-cloud-arrow-up me-2"></i>Upload
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Filter tabs -->
<div class="d-flex flex-wrap gap-2 mb-4">
    <a href="<?php ee(route_admin('media')); ?>"
       class="btn btn-sm <?php echo !$filterCategory ? 'btn-ptmd-teal' : 'btn-ptmd-outline'; ?>">
        All
    </a>
    <?php foreach ($categories as $cat): ?>
        <a href="<?php ee(route_admin('media', ['category' => $cat])); ?>"
           class="btn btn-sm <?php echo $filterCategory === $cat ? 'btn-ptmd-teal' : 'btn-ptmd-outline'; ?>">
            <?php ee(ucfirst($cat)); ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Media grid -->
<?php if ($mediaItems): ?>
    <div class="row g-3">
        <?php foreach ($mediaItems as $media): ?>
            <div class="col-6 col-md-4 col-lg-3 col-xl-2">
                <div class="ptmd-card p-0" style="overflow:hidden">
                    <!-- Preview -->
                    <div style="aspect-ratio:16/9;background:var(--ptmd-black);overflow:hidden;display:flex;align-items:center;justify-content:center">
                        <?php if (str_starts_with($media['file_type'], 'image/')): ?>
                            <img
                                src="/uploads/<?php ee($media['file_path']); ?>"
                                alt="<?php ee($media['filename']); ?>"
                                style="width:100%;height:100%;object-fit:contain"
                                loading="lazy"
                            >
                        <?php elseif (str_starts_with($media['file_type'], 'video/')): ?>
                            <i class="fa-solid fa-film" style="font-size:2rem;color:var(--ptmd-muted)"></i>
                        <?php else: ?>
                            <i class="fa-solid fa-file" style="font-size:2rem;color:var(--ptmd-muted)"></i>
                        <?php endif; ?>
                    </div>
                    <!-- Info -->
                    <div class="p-3">
                        <div class="ptmd-muted small fw-500 mb-1"
                             style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
                             data-tippy-content="<?php ee($media['filename']); ?>">
                            <?php ee($media['filename']); ?>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="ptmd-badge-muted" style="font-size:10px"><?php ee($media['category']); ?></span>
                            <div class="d-flex gap-1">
                                <button
                                    class="btn btn-ptmd-ghost btn-sm"
                                    style="padding:0.2rem 0.4rem"
                                    data-clipboard-text="/uploads/<?php ee($media['file_path']); ?>"
                                    data-tippy-content="Copy path"
                                >
                                    <i class="fa-solid fa-copy" style="font-size:11px"></i>
                                </button>
                                <form method="post" action="<?php echo e(route_admin('media')); ?>" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                    <input type="hidden" name="_action" value="delete">
                                    <input type="hidden" name="id" value="<?php ee((string) $media['id']); ?>">
                                    <button
                                        class="btn btn-ptmd-ghost btn-sm"
                                        style="padding:0.2rem 0.4rem;color:var(--ptmd-error)"
                                        type="submit"
                                        data-confirm="Delete this file? This is permanent."
                                        data-tippy-content="Delete"
                                    >
                                        <i class="fa-solid fa-trash" style="font-size:11px"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="ptmd-panel p-lg">
        <p class="ptmd-muted small">No media found.
            <?php if ($filterCategory): ?>
                <a href="<?php ee(route_admin('media')); ?>">Clear filter</a>
            <?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/_admin_footer.php'; ?>
