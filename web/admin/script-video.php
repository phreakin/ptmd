<?php
/**
 * PTMD Admin — Script Video Creator
 *
 * Provides two creation modes:
 *  1. AI mode   — user supplies a topic/brief; OpenAI generates the scene plan.
 *  2. Manual mode — user writes the full script; scenes are auto-parsed or
 *                   entered row-by-row.
 *
 * Renders an async job history panel below the creation form.
 */

$pageTitle      = 'Script Video | PTMD Admin';
$activePage     = 'script-video';
$pageHeading    = 'Create From Script';
$pageSubheading = 'Generate a video from an AI-written scene plan or a manually written script.';

include __DIR__ . '/_admin_head.php';

$pdo = get_db();

// Load episodes for optional linking
$episodes = $pdo
    ? $pdo->query('SELECT id, title FROM episodes ORDER BY title')->fetchAll()
    : [];

// Load recent jobs (latest 20)
$recentJobs = $pdo
    ? $pdo->query(
        'SELECT j.*, u.username AS created_by_name
         FROM script_video_jobs j
         LEFT JOIN users u ON u.id = j.created_by
         ORDER BY j.created_at DESC
         LIMIT 20'
    )->fetchAll()
    : [];
?>

<!-- ── Mode toggle + create form ──────────────────────────────────────────── -->
<div class="ptmd-panel p-xl mb-4">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-clapperboard me-2 ptmd-text-teal"></i>New Script Video
    </h2>

    <!-- Mode tabs -->
    <div class="d-flex gap-2 mb-4" id="svModeTabs" role="tablist">
        <button
            type="button"
            class="btn btn-ptmd-teal btn-sm sv-tab-btn active"
            data-mode="manual"
            aria-pressed="true"
        >
            <i class="fa-solid fa-pen-to-square me-1"></i>Manual Script
        </button>
        <button
            type="button"
            class="btn btn-ptmd-outline btn-sm sv-tab-btn"
            data-mode="ai"
            aria-pressed="false"
        >
            <i class="fa-solid fa-wand-magic-sparkles me-1"></i>AI Mode
        </button>
    </div>

    <form id="svCreateForm">
        <input type="hidden" id="sv_csrf"       value="<?php ee(csrf_token()); ?>">
        <input type="hidden" id="sv_input_mode" name="input_mode" value="manual">

        <!-- Shared fields -->
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label" for="sv_title">Video Title <span class="text-danger">*</span></label>
                <input
                    class="form-control"
                    id="sv_title"
                    name="title"
                    maxlength="255"
                    placeholder="e.g. The Hidden Cost of Medical Debt"
                    required
                >
            </div>
            <div class="col-md-6">
                <label class="form-label" for="sv_episode">Link to Episode (optional)</label>
                <select class="form-select" id="sv_episode" name="episode_id">
                    <option value="">— None —</option>
                    <?php foreach ($episodes as $ep): ?>
                        <option value="<?php ee((string) $ep['id']); ?>"><?php ee($ep['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Manual mode panel -->
        <div id="svPanelManual" class="sv-panel">
            <div class="mb-3">
                <label class="form-label" for="sv_script">Script
                    <span class="ptmd-muted small">— separate scenes with a blank line</span>
                </label>
                <textarea
                    class="form-control"
                    id="sv_script"
                    name="script_source"
                    rows="10"
                    maxlength="20000"
                    placeholder="Write your script here.&#10;&#10;Each paragraph (separated by a blank line) becomes a scene.&#10;&#10;The duration of each scene is estimated from word count."
                ></textarea>
                <div class="form-text ptmd-muted" style="font-size:var(--text-xs)">
                    Max 20,000 characters. Scenes are separated by blank lines.
                    <span id="sv_char_count" class="ms-2 fw-500">0 / 20000</span>
                </div>
            </div>

            <!-- Scene preview (populated after parse/preview click) -->
            <div id="svScenePreview" class="d-none">
                <h3 class="h6 mb-3 ptmd-text-teal">
                    <i class="fa-solid fa-list-ol me-1"></i>Parsed Scenes
                </h3>
                <div id="svSceneList"></div>
            </div>
        </div>

        <!-- AI mode panel -->
        <div id="svPanelAi" class="sv-panel d-none">
            <div class="mb-3">
                <label class="form-label" for="sv_ai_prompt">Topic / Brief <span class="text-danger">*</span></label>
                <textarea
                    class="form-control"
                    id="sv_ai_prompt"
                    name="ai_prompt"
                    rows="4"
                    maxlength="2000"
                    placeholder="Describe the video you want. e.g. 'A 60-second explainer about how hospital billing errors affect uninsured patients in the U.S., told from a patient's perspective.'"
                ></textarea>
                <div class="form-text ptmd-muted" style="font-size:var(--text-xs)">
                    Max 2,000 characters. The AI will generate 4-8 scenes with narration text.
                </div>
            </div>

            <!-- AI scene preview (populated after Generate click) -->
            <div id="svAiScenePreview" class="d-none">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3 class="h6 mb-0 ptmd-text-teal">
                        <i class="fa-solid fa-list-ol me-1"></i>AI-Generated Scenes
                        <span class="ptmd-muted small fw-400 ms-2">— edit before submitting</span>
                    </h3>
                    <button type="button" class="btn btn-ptmd-outline btn-sm" id="svRegenerateBtn">
                        <i class="fa-solid fa-rotate me-1"></i>Regenerate
                    </button>
                </div>
                <div id="svAiSceneList"></div>
            </div>
        </div>

        <!-- Action buttons -->
        <div class="d-flex flex-wrap gap-3 mt-4 align-items-center">
            <!-- Manual: preview parsed scenes -->
            <button type="button" class="btn btn-ptmd-outline btn-sm" id="svPreviewBtn">
                <i class="fa-solid fa-eye me-1"></i>Preview Scenes
            </button>
            <!-- AI: generate scenes -->
            <button type="button" class="btn btn-ptmd-outline btn-sm d-none" id="svAiGenerateBtn">
                <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Generate Scenes
            </button>
            <!-- Submit job -->
            <button type="submit" class="btn btn-ptmd-primary" id="svSubmitBtn">
                <i class="fa-solid fa-play me-1"></i>Create &amp; Render
            </button>
            <!-- Spinner -->
            <span class="ptmd-muted small d-none" id="svSpinner">
                <i class="fa-solid fa-spinner fa-spin me-1"></i>Processing…
            </span>
        </div>

        <!-- Inline error / success -->
        <div id="svFormAlert" class="alert ptmd-alert mt-3 d-none" role="alert"></div>
    </form>
</div>

<!-- ── Job progress tracker ───────────────────────────────────────────────── -->
<div id="svProgressPanel" class="ptmd-panel p-lg mb-4 d-none">
    <h2 class="h6 mb-3">
        <i class="fa-solid fa-spinner fa-spin me-2 ptmd-text-teal" id="svProgressIcon"></i>
        <span id="svProgressTitle">Rendering…</span>
    </h2>
    <div class="progress mb-2" style="height:12px">
        <div
            class="progress-bar bg-ptmd-teal"
            id="svProgressBar"
            role="progressbar"
            style="width:0%"
            aria-valuenow="0"
            aria-valuemin="0"
            aria-valuemax="100"
        ></div>
    </div>
    <div class="d-flex justify-content-between align-items-center">
        <span class="ptmd-muted small" id="svProgressPhase"></span>
        <span class="ptmd-muted small" id="svProgressPct">0%</span>
    </div>
    <div id="svProgressOutput" class="mt-3 d-none">
        <a href="#" class="btn btn-ptmd-teal btn-sm" id="svOutputLink" target="_blank" rel="noopener">
            <i class="fa-solid fa-download me-1"></i>Download / Play Output
        </a>
    </div>
</div>

<!-- ── Job history ────────────────────────────────────────────────────────── -->
<div class="ptmd-panel p-lg">
    <h2 class="h6 mb-4">
        <i class="fa-solid fa-clock-rotate-left me-2 ptmd-text-teal"></i>Job History
    </h2>

    <?php if ($recentJobs): ?>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Mode</th>
                        <th>Status</th>
                        <th>Progress</th>
                        <th>Created</th>
                        <th>By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentJobs as $job): ?>
                        <tr id="sv-job-row-<?php ee((string) $job['id']); ?>">
                            <td class="fw-500"><?php ee($job['title']); ?></td>
                            <td>
                                <span class="ptmd-badge-muted"><?php ee($job['input_mode']); ?></span>
                            </td>
                            <td>
                                <span class="ptmd-status ptmd-status-<?php ee($job['status']); ?>">
                                    <?php ee($job['status']); ?>
                                </span>
                            </td>
                            <td style="min-width:80px">
                                <div class="progress" style="height:6px">
                                    <div
                                        class="progress-bar bg-ptmd-teal"
                                        role="progressbar"
                                        style="width:<?php ee((string) $job['progress']); ?>%"
                                        aria-valuenow="<?php ee((string) $job['progress']); ?>"
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                    ></div>
                                </div>
                                <span style="font-size:var(--text-xs)" class="ptmd-muted">
                                    <?php ee((string) $job['progress']); ?>%
                                </span>
                            </td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php echo e(date('M j, Y g:i A', strtotime($job['created_at']))); ?>
                            </td>
                            <td class="ptmd-muted small">
                                <?php ee($job['created_by_name'] ?? '—'); ?>
                            </td>
                            <td>
                                <div class="d-flex gap-2 flex-wrap">
                                    <?php if ($job['output_path']): ?>
                                        <a
                                            href="<?php echo e(upload_url((string) $job['output_path'])); ?>"
                                            class="btn btn-ptmd-ghost btn-sm"
                                            target="_blank"
                                            rel="noopener"
                                            data-tippy-content="Download / Play"
                                        >
                                            <i class="fa-solid fa-download"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php if (in_array($job['status'], ['pending', 'processing'], true)): ?>
                                        <button
                                            type="button"
                                            class="btn btn-ptmd-ghost btn-sm sv-cancel-btn"
                                            data-job-id="<?php ee((string) $job['id']); ?>"
                                            data-tippy-content="Cancel job"
                                        >
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($job['error_message']): ?>
                                        <span
                                            class="btn btn-ptmd-ghost btn-sm ptmd-text-error"
                                            data-tippy-content="<?php ee($job['error_message']); ?>"
                                        >
                                            <i class="fa-solid fa-triangle-exclamation"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="ptmd-muted small">No script-video jobs yet.</p>
    <?php endif; ?>
</div>

<?php
$extraScripts = <<<'SCRIPTS'
<script>
(function () {
    'use strict';

    // ── State ────────────────────────────────────────────────────────────────
    const csrf      = document.getElementById('sv_csrf').value;
    let   activeJob = null;
    let   pollTimer = null;
    let   aiScenes  = [];   // scenes from AI preview, keyed for submission

    // ── Mode toggle ──────────────────────────────────────────────────────────
    document.querySelectorAll('.sv-tab-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const mode = this.dataset.mode;
            document.getElementById('sv_input_mode').value = mode;

            document.querySelectorAll('.sv-tab-btn').forEach(function (b) {
                b.classList.remove('btn-ptmd-teal', 'active');
                b.classList.add('btn-ptmd-outline');
                b.setAttribute('aria-pressed', 'false');
            });
            this.classList.remove('btn-ptmd-outline');
            this.classList.add('btn-ptmd-teal', 'active');
            this.setAttribute('aria-pressed', 'true');

            document.getElementById('svPanelManual').classList.toggle('d-none', mode !== 'manual');
            document.getElementById('svPanelAi').classList.toggle('d-none', mode !== 'ai');
            document.getElementById('svPreviewBtn').classList.toggle('d-none', mode !== 'manual');
            document.getElementById('svAiGenerateBtn').classList.toggle('d-none', mode !== 'ai');
        });
    });

    // ── Character counter ────────────────────────────────────────────────────
    const scriptTA  = document.getElementById('sv_script');
    const charCount = document.getElementById('sv_char_count');
    scriptTA.addEventListener('input', function () {
        charCount.textContent = this.value.length + ' / 20000';
    });

    // ── Parse / preview manual scenes ────────────────────────────────────────
    document.getElementById('svPreviewBtn').addEventListener('click', function () {
        const script = scriptTA.value.trim();
        if (!script) { return showAlert('Paste your script first.', 'warning'); }

        const paragraphs = script.split(/\n{2,}/);
        const scenes = paragraphs.map(function (p) { return p.trim(); }).filter(Boolean);
        if (!scenes.length) { return showAlert('No scenes found. Separate scenes with blank lines.', 'warning'); }

        renderSceneList(document.getElementById('svSceneList'), scenes.map(function (text, i) {
            return { scene_order: i + 1, narration_text: text, duration_sec: '' };
        }));
        document.getElementById('svScenePreview').classList.remove('d-none');
    });

    // ── AI generate scenes ────────────────────────────────────────────────────
    document.getElementById('svAiGenerateBtn').addEventListener('click', function () {
        generateAiScenes();
    });
    document.getElementById('svRegenerateBtn').addEventListener('click', function () {
        generateAiScenes();
    });

    function generateAiScenes() {
        const prompt = document.getElementById('sv_ai_prompt').value.trim();
        const title  = document.getElementById('sv_title').value.trim();
        if (!prompt) { return showAlert('Enter an AI prompt first.', 'warning'); }

        setSpinner(true, 'Generating scene plan…');
        clearAlert();

        const fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('_action', 'generate_scenes');
        fd.append('ai_prompt', prompt);
        fd.append('title', title);

        fetch('/api/script_video.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                setSpinner(false);
                if (!data.ok) { return showAlert(data.error || 'AI generation failed.', 'danger'); }

                aiScenes = data.scenes;
                renderAiSceneList(data.scenes);
                document.getElementById('svAiScenePreview').classList.remove('d-none');
            })
            .catch(function () {
                setSpinner(false);
                showAlert('Network error. Please try again.', 'danger');
            });
    }

    // ── Form submit ───────────────────────────────────────────────────────────
    document.getElementById('svCreateForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const mode  = document.getElementById('sv_input_mode').value;
        const title = document.getElementById('sv_title').value.trim();
        if (!title) { return showAlert('Title is required.', 'warning'); }

        clearAlert();
        setSpinner(true, 'Creating job…');

        const fd = new FormData(this);
        fd.set('csrf_token', csrf);
        fd.set('_action', 'create');

        // Append AI scenes as structured POST data if in AI mode
        if (mode === 'ai' && aiScenes.length) {
            aiScenes.forEach(function (s, i) {
                fd.append('scenes[' + i + '][narration_text]', s.narration_text || '');
                fd.append('scenes[' + i + '][visual_prompt]',  s.visual_prompt  || '');
                fd.append('scenes[' + i + '][duration_sec]',   s.duration_sec   || 5);
            });
        }

        fetch('/api/script_video.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                setSpinner(false);
                if (!data.ok) { return showAlert(data.error || 'Job creation failed.', 'danger'); }

                showAlert('Job #' + data.job_id + ' created (' + (data.scene_count || 0) + ' scenes). Rendering…', 'success');
                startPolling(data.job_id);
            })
            .catch(function () {
                setSpinner(false);
                showAlert('Network error. Please try again.', 'danger');
            });
    });

    // ── Cancel buttons ────────────────────────────────────────────────────────
    document.querySelectorAll('.sv-cancel-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const jobId = this.dataset.jobId;
            Swal.fire({
                title: 'Cancel job #' + jobId + '?',
                icon:  'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, cancel it',
                cancelButtonText: 'Keep running',
            }).then(function (result) {
                if (!result.isConfirmed) { return; }
                const fd = new FormData();
                fd.append('csrf_token', csrf);
                fd.append('_action', 'cancel');
                fd.append('job_id', jobId);
                fetch('/api/script_video.php', { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.ok) { location.reload(); }
                        else { Swal.fire('Error', data.error || 'Could not cancel job.', 'error'); }
                    });
            });
        });
    });

    // ── Progress polling ──────────────────────────────────────────────────────
    function startPolling(jobId) {
        activeJob = jobId;
        document.getElementById('svProgressPanel').classList.remove('d-none');
        document.getElementById('svProgressTitle').textContent = 'Job #' + jobId + ' — Rendering…';
        pollJob(jobId);
    }

    function pollJob(jobId) {
        clearTimeout(pollTimer);
        fetch('/api/script_video.php?job_id=' + encodeURIComponent(jobId))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) { return; }
                updateProgressUI(data);
                if (data.status === 'completed' || data.status === 'failed' || data.status === 'canceled') {
                    onJobDone(data);
                } else {
                    pollTimer = setTimeout(function () { pollJob(jobId); }, 3000);
                }
            })
            .catch(function () {
                pollTimer = setTimeout(function () { pollJob(jobId); }, 5000);
            });
    }

    function updateProgressUI(data) {
        const pct = Math.min(100, Math.max(0, data.progress || 0));
        document.getElementById('svProgressBar').style.width = pct + '%';
        document.getElementById('svProgressBar').setAttribute('aria-valuenow', pct);
        document.getElementById('svProgressPct').textContent  = pct + '%';
        document.getElementById('svProgressPhase').textContent = data.phase || '';
    }

    function onJobDone(data) {
        const icon  = document.getElementById('svProgressIcon');
        const title = document.getElementById('svProgressTitle');

        icon.classList.remove('fa-spin');
        if (data.status === 'completed') {
            icon.className = 'fa-solid fa-circle-check me-2 ptmd-text-teal';
            title.textContent = 'Job #' + activeJob + ' — Completed';
            if (data.output_path) {
                const outputDiv  = document.getElementById('svProgressOutput');
                const outputLink = document.getElementById('svOutputLink');
                outputLink.href  = data.output_path;
                outputDiv.classList.remove('d-none');
            }
            setTimeout(function () { location.reload(); }, 5000);
        } else if (data.status === 'failed') {
            icon.className = 'fa-solid fa-circle-xmark me-2 ptmd-text-error';
            title.textContent = 'Job #' + activeJob + ' — Failed';
            showAlert('Render failed: ' + (data.error || 'Unknown error.'), 'danger');
        } else {
            icon.className = 'fa-solid fa-ban me-2 ptmd-muted';
            title.textContent = 'Job #' + activeJob + ' — Canceled';
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    function renderSceneList(container, scenes) {
        container.innerHTML = scenes.map(function (s, i) {
            return '<div class="ptmd-panel p-md mb-2 d-flex gap-3 align-items-start">'
                + '<span class="ptmd-badge-muted" style="min-width:24px;text-align:center">' + (i + 1) + '</span>'
                + '<p class="mb-0 small flex-grow-1" style="white-space:pre-wrap">' + escHtml(s.narration_text) + '</p>'
                + '</div>';
        }).join('');
    }

    function renderAiSceneList(scenes) {
        const container = document.getElementById('svAiSceneList');
        container.innerHTML = scenes.map(function (s, i) {
            return '<div class="ptmd-panel p-md mb-3 border">'
                + '<div class="d-flex gap-2 align-items-center mb-2">'
                +   '<span class="ptmd-badge-muted">Scene ' + (i + 1) + '</span>'
                +   '<span class="ptmd-muted small">' + (s.duration_sec || 5) + 's</span>'
                + '</div>'
                + '<p class="small mb-1"><strong>Narration:</strong></p>'
                + '<textarea class="form-control form-control-sm mb-2 sv-ai-narration" data-idx="' + i + '" rows="3">'
                +   escHtml(s.narration_text)
                + '</textarea>'
                + '<p class="small mb-1 ptmd-muted"><strong>Visual direction:</strong> ' + escHtml(s.visual_prompt || '—') + '</p>'
                + '</div>';
        }).join('');

        // Sync edits back to aiScenes on change
        container.querySelectorAll('.sv-ai-narration').forEach(function (ta) {
            ta.addEventListener('input', function () {
                const idx = parseInt(this.dataset.idx, 10);
                if (aiScenes[idx]) { aiScenes[idx].narration_text = this.value; }
            });
        });
    }

    function setSpinner(on, label) {
        document.getElementById('svSpinner').classList.toggle('d-none', !on);
        if (on && label) { document.getElementById('svSpinner').textContent = label; }
        document.getElementById('svSubmitBtn').disabled = on;
        document.getElementById('svAiGenerateBtn').disabled = on;
    }

    function showAlert(msg, type) {
        const el = document.getElementById('svFormAlert');
        el.className = 'alert ptmd-alert alert-' + type + ' mt-3';
        el.textContent = msg;
        el.classList.remove('d-none');
    }

    function clearAlert() {
        document.getElementById('svFormAlert').classList.add('d-none');
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
}());
</script>
SCRIPTS;
?>

<?php include __DIR__ . '/_admin_footer.php'; ?>
