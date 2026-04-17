<?php
/**
 * PTMD Admin — Episodes CRUD
 */

$pageTitle   = 'Episodes | PTMD Admin';
$activePage  = 'episodes';
$pageHeading = 'Episodes';

$pdo    = get_db();
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$action = $_GET['action'] ?? ($editId > 0 ? 'edit' : 'list');

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($pdo && is_post()) {
    require_once __DIR__ . '/../inc/bootstrap.php';

    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        redirect('/admin/episodes.php', 'Invalid CSRF token.', 'danger');
    }

    $postAction = $_POST['_action'] ?? 'save';

    if ($postAction === 'delete') {
        $delId = (int) ($_POST['id'] ?? 0);
        if ($delId > 0) {
            $pdo->prepare('DELETE FROM episodes WHERE id = :id')->execute(['id' => $delId]);
            redirect('/admin/episodes.php', 'Episode deleted.', 'success');
        }
    }

    // Save / Update
    $id        = (int) ($_POST['id'] ?? 0);
    $title     = trim((string) ($_POST['title']     ?? ''));
    $slug      = slugify(trim((string) ($_POST['slug'] ?? '')) ?: $title);
    $excerpt   = trim((string) ($_POST['excerpt']   ?? ''));
    $body      = trim((string) ($_POST['body']      ?? ''));
    $videoUrl  = trim((string) ($_POST['video_url'] ?? ''));
    $duration  = trim((string) ($_POST['duration']  ?? ''));
    $keywordsRaw = trim((string) ($_POST['keywords'] ?? ''));
    $status    = in_array($_POST['status'] ?? '', ['draft','published','archived']) ? $_POST['status'] : 'draft';
    $publishedAt = ($status === 'published')
        ? (trim((string) ($_POST['published_at'] ?? '')) ?: date('Y-m-d H:i:s'))
        : null;

    $keywords = [];
    if ($keywordsRaw !== '') {
        $parts = preg_split('/[\r\n,]+/', $keywordsRaw) ?: [];
        $seen = [];
        foreach ($parts as $part) {
            $keyword = trim($part);
            if ($keyword === '') {
                continue;
            }
            $keyword = mb_substr($keyword, 0, 120);
            $key = mb_strtolower($keyword);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $keywords[] = $keyword;
        }
    }

    // Handle thumbnail upload
    $thumbPath = trim((string) ($_POST['thumbnail_image'] ?? ''));
    if (!empty($_FILES['thumbnail_file']['name'])) {
        $saved = save_upload(
            $_FILES['thumbnail_file'],
            'thumbnails',
            $GLOBALS['config']['allowed_image_ext']
        );
        if ($saved) {
            $thumbPath = $saved;
        }
    }

    // Handle video file upload
    $videoFilePath = trim((string) ($_POST['video_file_path'] ?? ''));
    if (!empty($_FILES['video_file']['name'])) {
        $savedVid = save_upload(
            $_FILES['video_file'],
            'episodes',
            $GLOBALS['config']['allowed_video_ext']
        );
        if ($savedVid) {
            $videoFilePath = $savedVid;
        }
    }

    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE episodes SET title=:title, slug=:slug, excerpt=:excerpt, body=:body,
             thumbnail_image=:thumb, video_url=:video_url, video_file_path=:vfp,
             duration=:duration, status=:status, published_at=:pub,
             updated_at=NOW() WHERE id=:id'
        );
        $stmt->execute([
            'title'=>$title, 'slug'=>$slug, 'excerpt'=>$excerpt, 'body'=>$body,
            'thumb'=>$thumbPath, 'video_url'=>$videoUrl, 'vfp'=>$videoFilePath,
            'duration'=>$duration, 'status'=>$status, 'pub'=>$publishedAt, 'id'=>$id,
        ]);
        $episodeId = $id;
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO episodes (title, slug, excerpt, body, thumbnail_image, video_url,
             video_file_path, duration, status, published_at, created_at, updated_at)
             VALUES (:title,:slug,:excerpt,:body,:thumb,:video_url,:vfp,:duration,:status,:pub,NOW(),NOW())'
        );
        $stmt->execute([
            'title'=>$title, 'slug'=>$slug, 'excerpt'=>$excerpt, 'body'=>$body,
            'thumb'=>$thumbPath, 'video_url'=>$videoUrl, 'vfp'=>$videoFilePath,
            'duration'=>$duration, 'status'=>$status, 'pub'=>$publishedAt,
        ]);
        $episodeId = (int) $pdo->lastInsertId();
    }

    if ($episodeId > 0) {
        $pdo->prepare('DELETE FROM episode_tag_map WHERE episode_id = :episode_id')->execute([
            'episode_id' => $episodeId,
        ]);

        foreach ($keywords as $keyword) {
            $tagSlug = slugify($keyword);
            if ($tagSlug === '') {
                continue;
            }

            $pdo->prepare(
                'INSERT IGNORE INTO episode_tags (name, slug, created_at, updated_at)
                 VALUES (:name, :slug, NOW(), NOW())'
            )->execute([
                'name' => $keyword,
                'slug' => $tagSlug,
            ]);

            $tagStmt = $pdo->prepare('SELECT id FROM episode_tags WHERE slug = :slug LIMIT 1');
            $tagStmt->execute(['slug' => $tagSlug]);
            $tagId = (int) ($tagStmt->fetchColumn() ?: 0);
            if ($tagId > 0) {
                $pdo->prepare(
                    'INSERT IGNORE INTO episode_tag_map (episode_id, tag_id)
                     VALUES (:episode_id, :tag_id)'
                )->execute([
                    'episode_id' => $episodeId,
                    'tag_id'     => $tagId,
                ]);
            }
        }
    }

    if ($id > 0) {
        redirect('/admin/episodes.php', 'Episode updated.', 'success');
    }

    redirect('/admin/episodes.php', 'Episode created.', 'success');
}

$pageSubheading = $action === 'edit' ? 'Edit Episode' : ($action === 'new' ? 'New Episode' : 'All Episodes');
$pageActions    = '';
$apiKeySet = site_setting('openai_api_key', '') !== '';
if ($action === 'list') {
    $pageActions = '<a href="/admin/episodes.php?action=new" class="btn btn-ptmd-primary"><i class="fa-solid fa-plus me-2"></i>New Episode</a>';
} elseif ($action === 'edit' || $action === 'new') {
    $pageActions = '<a href="/admin/episodes.php" class="btn btn-ptmd-outline"><i class="fa-solid fa-arrow-left me-2"></i>Back</a>';
}

include __DIR__ . '/_admin_head.php';

// ── Fetch episode for edit ────────────────────────────────────────────────────
$ep = null;
$epKeywords = '';
if ($editId > 0 && $pdo) {
    $stmt = $pdo->prepare('SELECT * FROM episodes WHERE id = :id');
    $stmt->execute(['id' => $editId]);
    $ep = $stmt->fetch();
    if ($ep) {
        $epKeywords = implode(', ', get_episode_tags((int) $ep['id']));
    }
}

// ── List view ─────────────────────────────────────────────────────────────────
if ($action === 'list'):
    $episodes = $pdo ? $pdo->query('SELECT * FROM episodes ORDER BY updated_at DESC')->fetchAll() : [];
?>
    <div class="ptmd-panel p-lg">
        <?php if ($episodes): ?>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Duration</th>
                        <th>Published</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($episodes as $ep): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <?php if ($ep['thumbnail_image']): ?>
                                        <img
                                            src="<?php ee($ep['thumbnail_image']); ?>"
                                            alt=""
                                            style="width:48px;height:32px;object-fit:cover;border-radius:4px;border:1px solid var(--ptmd-border)"
                                            loading="lazy"
                                        >
                                    <?php endif; ?>
                                    <a href="/admin/episodes.php?edit=<?php ee((string) $ep['id']); ?>"
                                       class="fw-500 ptmd-text-muted">
                                        <?php ee($ep['title']); ?>
                                    </a>
                                </div>
                            </td>
                            <td>
                                <span class="ptmd-status ptmd-status-<?php ee($ep['status']); ?>">
                                    <?php ee($ep['status']); ?>
                                </span>
                            </td>
                            <td class="ptmd-muted small"><?php ee($ep['duration'] ?? '—'); ?></td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php echo $ep['published_at'] ? e(date('M j, Y', strtotime($ep['published_at']))) : '—'; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-2">
                                    <a href="/admin/episodes.php?edit=<?php ee((string) $ep['id']); ?>"
                                       class="btn btn-ptmd-ghost btn-sm" data-tippy-content="Edit">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>
                                    <a href="/index.php?page=episode&slug=<?php ee($ep['slug']); ?>"
                                       target="_blank" rel="noopener"
                                       class="btn btn-ptmd-ghost btn-sm" data-tippy-content="View public">
                                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                    </a>
                                    <form method="post" action="/admin/episodes.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
                                        <input type="hidden" name="_action" value="delete">
                                        <input type="hidden" name="id" value="<?php ee((string) $ep['id']); ?>">
                                        <button
                                            class="btn btn-ptmd-ghost btn-sm"
                                            type="submit"
                                            style="color:var(--ptmd-error)"
                                            data-confirm="Delete &quot;<?php ee($ep['title']); ?>&quot;? This cannot be undone."
                                            data-tippy-content="Delete"
                                        >
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
            <p class="ptmd-muted">No episodes yet. <a href="/admin/episodes.php?action=new">Create your first episode</a>.</p>
        <?php endif; ?>
    </div>

<?php
// ── Create / Edit form ────────────────────────────────────────────────────────
else:
?>
    <form method="post" action="/admin/episodes.php" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
        <input type="hidden" id="ep_id" name="id" value="<?php ee((string) ($ep['id'] ?? 0)); ?>">
        <input type="hidden" name="_action" value="save">

        <div class="row g-4">

            <!-- Left: main fields -->
            <div class="col-lg-8">

                <div class="ptmd-panel p-xl mb-4">
                    <h2 class="h6 mb-4">Episode Details</h2>
                    <div class="mb-3">
                        <label class="form-label" for="ep_title">Title <span style="color:var(--ptmd-error)">*</span></label>
                        <input class="form-control" id="ep_title" name="title"
                            value="<?php ee($ep['title'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="ep_slug">Slug</label>
                        <input class="form-control" id="ep_slug" name="slug"
                            value="<?php ee($ep['slug'] ?? ''); ?>"
                            placeholder="auto-generated from title if blank">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="ep_excerpt">Excerpt</label>
                        <textarea class="form-control" id="ep_excerpt" name="excerpt"
                            rows="3"><?php ee($ep['excerpt'] ?? ''); ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="ep_body">Body / Article Copy</label>
                        <textarea class="form-control" id="ep_body" name="body"
                            rows="12"><?php ee($ep['body'] ?? ''); ?></textarea>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="ep_keywords">Keywords</label>
                        <input class="form-control" id="ep_keywords" name="keywords"
                            value="<?php ee($epKeywords); ?>"
                            placeholder="comma-separated keywords (e.g. politics, corruption, city hall)">
                        <div class="form-text ptmd-muted">Use commas to separate keywords.</div>
                    </div>
                </div>

                <div class="ptmd-panel p-xl mb-4">
                    <h2 class="h6 mb-4">Video</h2>
                    <div class="mb-3">
                        <label class="form-label" for="ep_video_url">Video Embed URL</label>
                        <input class="form-control" id="ep_video_url" name="video_url"
                            value="<?php ee($ep['video_url'] ?? ''); ?>"
                            placeholder="https://www.youtube.com/embed/…">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="ep_video_file">Upload Video File</label>
                        <input class="form-control" id="ep_video_file" type="file"
                            name="video_file"
                            accept=".mp4,.mov,.webm,.avi,.mkv">
                        <?php if (!empty($ep['video_file_path'])): ?>
                            <div class="form-text ptmd-muted">
                                Current: <?php ee($ep['video_file_path']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="ep_duration">Duration</label>
                        <input class="form-control" id="ep_duration" name="duration"
                            value="<?php ee($ep['duration'] ?? ''); ?>"
                            placeholder="e.g. 18:22">
                    </div>
                </div>

            </div>

            <!-- Right: meta -->
            <div class="col-lg-4">

                <div class="ptmd-panel p-xl mb-4">
                    <h2 class="h6 mb-4">Publish</h2>
                    <div class="mb-3">
                        <label class="form-label" for="ep_status">Status</label>
                        <select class="form-select" id="ep_status" name="status">
                            <?php foreach (['draft','published','archived'] as $s): ?>
                                <option value="<?php ee($s); ?>"
                                    <?php echo ($ep['status'] ?? 'draft') === $s ? 'selected' : ''; ?>>
                                    <?php ee(ucfirst($s)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="ep_pub_at">Published Date</label>
                        <input class="form-control" id="ep_pub_at" type="datetime-local"
                            name="published_at"
                            value="<?php echo !empty($ep['published_at']) ? e(date('Y-m-d\TH:i', strtotime($ep['published_at']))) : ''; ?>">
                    </div>
                </div>

                <div class="ptmd-panel p-xl mb-4">
                    <h2 class="h6 mb-4">Thumbnail</h2>
                    <?php if (!empty($ep['thumbnail_image'])): ?>
                        <img
                            src="<?php ee($ep['thumbnail_image']); ?>"
                            alt="Thumbnail"
                            class="w-100 rounded mb-3"
                            style="aspect-ratio:16/9;object-fit:cover;border:1px solid var(--ptmd-border)"
                        >
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Upload Image</label>
                        <input class="form-control" type="file" name="thumbnail_file"
                            accept=".jpg,.jpeg,.png,.webp,.gif">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="ep_thumb_url">Or enter URL / path</label>
                        <input class="form-control" id="ep_thumb_url" name="thumbnail_image"
                            value="<?php ee($ep['thumbnail_image'] ?? ''); ?>"
                            placeholder="/uploads/thumbnails/…">
                    </div>
                </div>

                <div class="ptmd-panel p-xl mb-4">
                    <h2 class="h6 mb-3">AI Field Suggestions &amp; Optimization</h2>
                    <?php if (!$apiKeySet): ?>
                        <p class="ptmd-muted small mb-0">
                            OpenAI API key is not configured. Set it in
                            <a href="/admin/settings.php">Settings</a> to enable suggestions.
                        </p>
                    <?php else: ?>
                        <div class="mb-3">
                            <label class="form-label" for="ai_suggest_field">Field to suggest</label>
                            <select class="form-select" id="ai_suggest_field">
                                <option value="title">Title</option>
                                <option value="slug">Slug</option>
                                <option value="excerpt">Excerpt</option>
                                <option value="body">Body</option>
                                <option value="keywords">Keywords</option>
                                <option value="video_url">Video URL</option>
                                <option value="duration">Duration</option>
                                <option value="thumbnail_image">Thumbnail URL/path</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="ai_suggest_guidance">Extra guidance (optional)</label>
                            <textarea class="form-control" id="ai_suggest_guidance" rows="2"
                                placeholder="Add any direction for tone, angle, or emphasis..."></textarea>
                        </div>
                        <button class="btn btn-ptmd-outline w-100 mb-3" type="button" id="ai_suggest_btn">
                            <i class="fa-solid fa-wand-magic-sparkles me-2"></i>Suggest Field
                        </button>
                        <button class="btn btn-ptmd-outline w-100 mb-3" type="button" id="ai_optimize_btn">
                            <i class="fa-solid fa-wand-magic-sparkles me-2"></i>Optimize Field
                        </button>
                        <div id="ai_suggest_result_wrap" style="display:none">
                            <label class="form-label" for="ai_suggest_result">Suggestion</label>
                            <textarea class="form-control mb-2" id="ai_suggest_result" rows="6"></textarea>
                            <button class="btn btn-ptmd-primary btn-sm w-100" type="button" id="ai_apply_suggestion_btn">
                                <i class="fa-solid fa-check me-2"></i>Apply Suggestion to Selected Field
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="d-grid gap-2">
                    <button class="btn btn-ptmd-primary" type="submit">
                        <i class="fa-solid fa-floppy-disk me-2"></i>
                        <?php echo $editId > 0 ? 'Save Changes' : 'Create Episode'; ?>
                    </button>
                    <?php if ($editId > 0): ?>
                        <a href="/admin/ai-tools.php" class="btn btn-ptmd-outline" style="border-color:rgba(106,13,173,0.4);color:#c084fc">
                            <i class="fa-solid fa-wand-magic-sparkles me-2"></i>AI Content
                        </a>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </form>
<?php
endif;

if ($action !== 'list' && $apiKeySet) {
    $extraScripts = <<<'HTML'
<script>
'use strict';

const fieldToElementIdMap = {
    title: 'ep_title',
    slug: 'ep_slug',
    excerpt: 'ep_excerpt',
    body: 'ep_body',
    keywords: 'ep_keywords',
    video_url: 'ep_video_url',
    duration: 'ep_duration',
    thumbnail_image: 'ep_thumb_url',
};

const suggestBtn = document.getElementById('ai_suggest_btn');
const fieldSelect = document.getElementById('ai_suggest_field');
const guidanceInput = document.getElementById('ai_suggest_guidance');
const resultWrap = document.getElementById('ai_suggest_result_wrap');
const resultInput = document.getElementById('ai_suggest_result');
const applyBtn = document.getElementById('ai_apply_suggestion_btn');
const optimizeBtn = document.getElementById('ai_optimize_btn');
const csrfInput = document.querySelector('input[name="csrf_token"]');
const episodeIdInput = document.getElementById('ep_id');

async function runFieldAiAction(button, actionLabel, idleLabel, feature, extraPayload = {}) {
    if (!button || !fieldSelect || !resultWrap || !resultInput || !csrfInput) {
        return;
    }
    const selectedField = fieldSelect.value;
    button.disabled = true;
    button.innerHTML = `<i class="fa-solid fa-spinner fa-spin me-2"></i>${actionLabel}`;
    resultWrap.style.display = 'block';
    resultInput.value = `${actionLabel}...`;

    try {
        const fd = new FormData();
        fd.set('csrf_token', csrfInput.value);
        fd.set('feature', feature);
        fd.set('suggest_field', selectedField);
        fd.set('suggest_guidance', guidanceInput?.value ?? '');
        fd.set('suggest_episode', episodeIdInput?.value ?? '');
        fd.set('context_title', document.getElementById('ep_title')?.value ?? '');
        fd.set('context_excerpt', document.getElementById('ep_excerpt')?.value ?? '');
        fd.set('context_body', document.getElementById('ep_body')?.value ?? '');
        fd.set('context_keywords', document.getElementById('ep_keywords')?.value ?? '');
        fd.set('context_video_url', document.getElementById('ep_video_url')?.value ?? '');
        fd.set('context_duration', document.getElementById('ep_duration')?.value ?? '');
        fd.set('context_thumbnail_image', document.getElementById('ep_thumb_url')?.value ?? '');
        Object.entries(extraPayload).forEach(([k, v]) => fd.set(k, v));

        const res = await fetch('/api/ai_generate.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: fd,
        });
        const data = await res.json();

        if (data.ok) {
            resultInput.value = data.text ?? '';
            window.PTMDToast?.success(idleLabel + ' generated.');
        } else {
            resultInput.value = '⚠ ' + (data.error ?? idleLabel + ' failed.');
            window.PTMDToast?.error(data.error ?? idleLabel + ' failed.');
        }
    } catch (err) {
        resultInput.value = '⚠ Network error. Please try again.';
        window.PTMDToast?.error('Network error.');
    } finally {
        button.disabled = false;
        button.innerHTML = `<i class="fa-solid fa-wand-magic-sparkles me-2"></i>${idleLabel}`;
    }
}

    if (suggestBtn && fieldSelect && resultWrap && resultInput && applyBtn && csrfInput) {
    suggestBtn.addEventListener('click', async () => {
        await runFieldAiAction(suggestBtn, 'Suggesting Field', 'Suggest Field', 'episode_field_suggestion');
    });

    if (optimizeBtn) {
        optimizeBtn.addEventListener('click', async () => {
            const selectedField = fieldSelect.value;
            const targetId = fieldToElementIdMap[selectedField];
            const target = targetId ? document.getElementById(targetId) : null;
            const sourceText = target?.value ?? '';
            if (!target) {
                window.PTMDToast?.error('Target field not found.');
                return;
            }
            if (sourceText.trim() === '') {
                window.PTMDToast?.error('Add field content before optimizing.');
                return;
            }
            await runFieldAiAction(
                optimizeBtn,
                'Optimizing Field',
                'Optimize Field',
                'episode_field_optimize',
                { optimize_source: sourceText }
            );
        });
    }

    applyBtn.addEventListener('click', () => {
        const selectedField = fieldSelect.value;
        const targetId = fieldToElementIdMap[selectedField];
        const target = targetId ? document.getElementById(targetId) : null;
        if (!target) {
            window.PTMDToast?.error('Target field not found.');
            return;
        }
        target.value = resultInput.value;
        target.dispatchEvent(new Event('input', { bubbles: true }));
        window.PTMDToast?.success('Suggestion applied.');
    });
}
</script>
HTML;
}
?>

<?php include __DIR__ . '/_admin_footer.php'; ?>
