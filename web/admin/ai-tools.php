<?php
/**
 * PTMD Admin — AI Content Studio
 *
 * Features:
 *  - Video Idea Generator
 *  - Title Generator
 *  - Keyword / Tag Generator
 *  - Video Description Generator
 *  - Social Caption Generator
 *  - Thumbnail Concept Suggester
 *  - Generation History log
 *
 * All AI calls are routed through /api/ai_generate.php (POST, AJAX).
 * The OpenAI API key is stored in site_settings as 'openai_api_key'.
 */

$pageTitle    = 'AI Content Studio | PTMD Admin';
$activePage   = 'ai-tools';
$pageHeading  = 'AI Content Studio';
$pageSubheading = 'Generate ideas, titles, keywords, and captions powered by OpenAI.';

include __DIR__ . '/_admin_head.php';

$pdo = get_db();
$apiKeySet = site_setting('openai_api_key', '') !== '';

// Generation history (last 30)
$history = [];
if ($pdo) {
    $history = $pdo->query(
        'SELECT g.*, e.title AS case_title
         FROM ai_generations g
         LEFT JOIN cases e ON e.id = g.case_id
         ORDER BY g.created_at DESC LIMIT 30'
    )->fetchAll();
}

// Stored structured video ideas (latest first)
$storedIdeas = [];
if ($pdo) {
    try {
        $storedIdeas = $pdo->query(
            'SELECT i.*, u.username AS created_by_username
             FROM ai_video_ideas i
             LEFT JOIN users u ON u.id = i.created_by
             ORDER BY i.created_at DESC
             LIMIT 50'
        )->fetchAll();
    } catch (Throwable $e) {
        $storedIdeas = [];
    }
}
$topIdeas = array_slice($storedIdeas, 0, 5);
$moreIdeas = array_slice($storedIdeas, 5);

// case list for the "context" dropdown
$cases = [];
if ($pdo) {
    $cases = $pdo->query(
        'SELECT id, title FROM cases ORDER BY published_at DESC, id DESC'
    )->fetchAll();
}
?>

<?php if (!$apiKeySet): ?>
    <div class="alert ptmd-alert alert-warning mb-5" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i>
        <strong>OpenAI API key not configured.</strong>
        Go to <a href="/admin/settings.php">Settings</a> and set the
        <strong>OpenAI API Key</strong> to enable AI features.
    </div>
<?php endif; ?>

<div class="ptmd-screen-hook-lab">
<!-- Tool cards grid -->
<div class="row g-4 mb-5">

    <!-- Video Ideas -->
    <div class="col-md-6 col-xl-4">
        <div class="ptmd-ai-card h-100">
            <div class="ai-card-icon"><i class="fa-solid fa-lightbulb"></i></div>
            <h2 class="h5 mb-2">Video Idea Generator</h2>
            <p class="ptmd-muted small mb-4">Generate future documentary topic ideas based on the PTMD brand voice.</p>
            <div class="mb-3">
                <label class="form-label" for="ideas_theme">Optional theme or keyword</label>
                <input class="form-control" id="ideas_theme" placeholder="e.g. housing policy, social media manipulation…">
            </div>
            <div class="mb-3">
                <label class="form-label" for="ideas_scope">Social context scope</label>
                <select class="form-select" id="ideas_scope">
                    <option value="both" selected>U.S. + World</option>
                    <option value="us">U.S. only</option>
                    <option value="world">World only</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="ideas_context">Current issue context (optional)</label>
                <textarea class="form-control" id="ideas_context" rows="2"
                    placeholder="e.g. rising housing costs, election misinformation, labor strikes…"></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label" for="ideas_count">How many ideas?</label>
                <select class="form-select" id="ideas_count">
                    <option value="5" selected>5 ideas</option>
                    <option value="10">10 ideas</option>
                    <option value="15">15 ideas</option>
                </select>
            </div>
            <button
                class="btn btn-ptmd-outline w-100 mb-3"
                data-ai-feature="video_ideas"
                data-inputs='["ideas_theme","ideas_scope","ideas_context","ideas_count"]'
                <?php if (!$apiKeySet) echo 'disabled'; ?>
            >
                <i class="fa-solid fa-wand-magic-sparkles me-2"></i>Generate Ideas
            </button>
            <div class="ptmd-ai-result-box" id="result_video_ideas" style="display:none"></div>
        </div>
    </div>

    <!-- Title Generator -->
    <div class="col-md-6 col-xl-4">
        <div class="ptmd-ai-card h-100">
            <div class="ai-card-icon"><i class="fa-solid fa-heading"></i></div>
            <h2 class="h5 mb-2">Title Generator</h2>
            <p class="ptmd-muted small mb-4">Get compelling, click-worthy case titles matched to the PTMD style.</p>
            <div class="mb-3">
                <label class="form-label" for="title_topic">What's the case about?</label>
                <textarea class="form-control" id="title_topic" rows="3"
                    placeholder="Describe the case premise briefly…"></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label" for="title_case">Or link to an case</label>
                <select class="form-select" id="title_case">
                    <option value="">— None —</option>
                    <?php foreach ($cases as $ep): ?>
                        <option value="<?php ee((string) $ep['id']); ?>"><?php ee($ep['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button
                class="btn btn-ptmd-outline w-100 mb-3"
                data-ai-feature="title"
                data-inputs='["title_topic","title_case"]'
                <?php if (!$apiKeySet) echo 'disabled'; ?>
            >
                <i class="fa-solid fa-wand-magic-sparkles me-2"></i>Generate Titles
            </button>
            <div class="ptmd-ai-result-box" id="result_title" style="display:none"></div>
        </div>
    </div>

    <!-- Keywords -->
    <div class="col-md-6 col-xl-4">
        <div class="ptmd-ai-card h-100">
            <div class="ai-card-icon"><i class="fa-solid fa-tags"></i></div>
            <h2 class="h5 mb-2">Keyword &amp; Tag Generator</h2>
            <p class="ptmd-muted small mb-4">SEO keywords, hashtags, and YouTube tags to maximise discoverability.</p>
            <div class="mb-3">
                <label class="form-label" for="kw_topic">case topic or title</label>
                <input class="form-control" id="kw_topic" placeholder="Topic, title, or short description…">
            </div>
            <div class="mb-3">
                <label class="form-label" for="kw_platform">Target platform</label>
                <select class="form-select" id="kw_platform">
                    <option value="YouTube">YouTube</option>
                    <option value="TikTok">TikTok</option>
                    <option value="Instagram">Instagram</option>
                    <option value="all">All Platforms</option>
                </select>
            </div>
            <button
                class="btn btn-ptmd-outline w-100 mb-3"
                data-ai-feature="keywords"
                data-inputs='["kw_topic","kw_platform"]'
                <?php if (!$apiKeySet) echo 'disabled'; ?>
            >
                <i class="fa-solid fa-wand-magic-sparkles me-2"></i>Generate Keywords
            </button>
            <div class="ptmd-ai-result-box" id="result_keywords" style="display:none"></div>
        </div>
    </div>

    <!-- Description -->
    <div class="col-md-6 col-xl-4">
        <div class="ptmd-ai-card h-100">
            <div class="ai-card-icon"><i class="fa-solid fa-align-left"></i></div>
            <h2 class="h5 mb-2">Description Generator</h2>
            <p class="ptmd-muted small mb-4">Full YouTube / video platform description with hooks, links, and hashtags.</p>
            <div class="mb-3">
                <label class="form-label" for="desc_case">case</label>
                <select class="form-select" id="desc_case">
                    <option value="">— Select case —</option>
                    <?php foreach ($cases as $ep): ?>
                        <option value="<?php ee((string) $ep['id']); ?>"><?php ee($ep['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="desc_notes">Additional notes</label>
                <textarea class="form-control" id="desc_notes" rows="2"
                    placeholder="Any key points, guests, or callouts to include…"></textarea>
            </div>
            <button
                class="btn btn-ptmd-outline w-100 mb-3"
                data-ai-feature="description"
                data-inputs='["desc_case","desc_notes"]'
                <?php if (!$apiKeySet) echo 'disabled'; ?>
            >
                <i class="fa-solid fa-wand-magic-sparkles me-2"></i>Generate Description
            </button>
            <div class="ptmd-ai-result-box" id="result_description" style="display:none"></div>
        </div>
    </div>

    <!-- Social Caption -->
    <div class="col-md-6 col-xl-4">
        <div class="ptmd-ai-card h-100">
            <div class="ai-card-icon"><i class="fa-brands fa-instagram"></i></div>
            <h2 class="h5 mb-2">Social Caption Generator</h2>
            <p class="ptmd-muted small mb-4">Platform-specific captions for YouTube Shorts, TikTok, Instagram, X, and Facebook.</p>
            <div class="mb-3">
                <label class="form-label" for="cap_case">case</label>
                <select class="form-select" id="cap_case">
                    <option value="">— Select case —</option>
                    <?php foreach ($cases as $ep): ?>
                        <option value="<?php ee((string) $ep['id']); ?>"><?php ee($ep['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="cap_platform">Platform</label>
                <select class="form-select" id="cap_platform">
                    <option value="all">All Platforms</option>
                    <option value="YouTube Shorts">YouTube Shorts</option>
                    <option value="TikTok">TikTok</option>
                    <option value="Instagram Reels">Instagram Reels</option>
                    <option value="X">X / Twitter</option>
                    <option value="Facebook Reels">Facebook Reels</option>
                </select>
            </div>
            <button
                class="btn btn-ptmd-outline w-100 mb-3"
                data-ai-feature="caption"
                data-inputs='["cap_case","cap_platform"]'
                <?php if (!$apiKeySet) echo 'disabled'; ?>
            >
                <i class="fa-solid fa-wand-magic-sparkles me-2"></i>Generate Captions
            </button>
            <div class="ptmd-ai-result-box" id="result_caption" style="display:none"></div>
        </div>
    </div>

    <!-- Thumbnail Concept -->
    <div class="col-md-6 col-xl-4">
        <div class="ptmd-ai-card h-100">
            <div class="ai-card-icon"><i class="fa-solid fa-image"></i></div>
            <h2 class="h5 mb-2">Thumbnail Concept</h2>
            <p class="ptmd-muted small mb-4">Detailed visual direction and text overlay suggestions for case thumbnails.</p>
            <div class="mb-3">
                <label class="form-label" for="thumb_case">case</label>
                <select class="form-select" id="thumb_case">
                    <option value="">— Select case —</option>
                    <?php foreach ($cases as $ep): ?>
                        <option value="<?php ee((string) $ep['id']); ?>"><?php ee($ep['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="thumb_style">Visual style</label>
                <select class="form-select" id="thumb_style">
                    <option value="PTMD cinematic dark">PTMD Cinematic Dark (default)</option>
                    <option value="investigative documentary">Investigative / documentary</option>
                    <option value="bold text yellow on black">Bold Yellow-on-Black</option>
                    <option value="evidence board newspaper">Evidence Board / Newspaper</option>
                </select>
            </div>
            <button
                class="btn btn-ptmd-outline w-100 mb-3"
                data-ai-feature="thumbnail_concept"
                data-inputs='["thumb_case","thumb_style"]'
                <?php if (!$apiKeySet) echo 'disabled'; ?>
            >
                <i class="fa-solid fa-wand-magic-sparkles me-2"></i>Generate Concept
            </button>
            <div class="ptmd-ai-result-box" id="result_thumbnail_concept" style="display:none"></div>
        </div>
    </div>

</div>

<!-- Stored AI video ideas -->
<div class="ptmd-panel p-lg mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h6 mb-0">
            <i class="fa-solid fa-lightbulb me-2 ptmd-text-yellow"></i>Stored Video Ideas
        </h2>
        <span class="ptmd-muted small"><?php echo count($storedIdeas); ?> stored</span>
    </div>

    <?php if ($topIdeas): ?>
        <div class="d-flex flex-column gap-3">
            <?php foreach ($topIdeas as $idea): ?>
                <div class="p-3 rounded" style="border:1px solid var(--ptmd-border);background:rgba(255,255,255,0.015)">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div class="fw-600"><?php ee($idea['idea_title']); ?></div>
                        <span class="ptmd-badge-muted"><?php ee(strtoupper($idea['scope'])); ?></span>
                    </div>
                    <p class="ptmd-muted small mb-2 mt-2"><?php ee($idea['premise']); ?></p>
                    <div class="small"><strong>Angle:</strong> <?php ee($idea['suggested_angle']); ?></div>
                    <div class="ptmd-muted" style="font-size:var(--text-xs)">
                        <?php echo e(date('M j, Y g:ia', strtotime($idea['created_at']))); ?>
                        <?php if (!empty($idea['created_by_username'])): ?>
                            · by <?php ee($idea['created_by_username']); ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($moreIdeas): ?>
            <div class="mt-3">
                <button class="btn btn-ptmd-ghost btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#moreStoredIdeas" aria-expanded="false" aria-controls="moreStoredIdeas">
                    <i class="fa-solid fa-chevron-down me-1"></i>Show More Ideas
                </button>
            </div>
            <div class="collapse mt-3" id="moreStoredIdeas">
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($moreIdeas as $idea): ?>
                        <div class="p-3 rounded" style="border:1px solid var(--ptmd-border);background:rgba(255,255,255,0.015)">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div class="fw-600"><?php ee($idea['idea_title']); ?></div>
                                <span class="ptmd-badge-muted"><?php ee(strtoupper($idea['scope'])); ?></span>
                            </div>
                            <p class="ptmd-muted small mb-2 mt-2"><?php ee($idea['premise']); ?></p>
                            <div class="small"><strong>Angle:</strong> <?php ee($idea['suggested_angle']); ?></div>
                            <div class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php echo e(date('M j, Y g:ia', strtotime($idea['created_at']))); ?>
                                <?php if (!empty($idea['created_by_username'])): ?>
                                    · by <?php ee($idea['created_by_username']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <p class="ptmd-muted small mb-0">No stored video ideas yet. Generate ideas above to save and display them here.</p>
    <?php endif; ?>
</div>

<!-- Generation History -->
<div class="ptmd-panel p-lg">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h6 mb-0">
            <i class="fa-solid fa-clock-rotate-left me-2 ptmd-text-teal"></i>Generation History
        </h2>
        <span class="ptmd-muted small"><?php echo count($history); ?> entries</span>
    </div>

    <?php if ($history): ?>
        <div class="table-responsive">
            <table class="ptmd-table">
                <thead>
                    <tr>
                        <th>Feature</th>
                        <th>case</th>
                        <th>Model</th>
                        <th>Tokens</th>
                        <th>Date</th>
                        <th>Output</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $gen): ?>
                        <tr>
                            <td>
                                <span class="ptmd-badge-muted">
                                    <?php ee($gen['feature']); ?>
                                </span>
                            </td>
                            <td class="ptmd-muted small">
                                <?php ee($gen['case_title'] ?? '—'); ?>
                            </td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php ee($gen['model']); ?>
                            </td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php echo e($gen['prompt_tokens'] + $gen['response_tokens']); ?>
                            </td>
                            <td class="ptmd-muted" style="font-size:var(--text-xs)">
                                <?php echo e(date('M j, Y g:ia', strtotime($gen['created_at']))); ?>
                            </td>
                            <td style="max-width:280px">
                                <div class="ptmd-muted small"
                                     style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:260px"
                                     data-tippy-content="<?php ee(mb_substr($gen['output_text'], 0, 400)); ?>">
                                    <?php ee(mb_substr($gen['output_text'], 0, 80)); ?>…
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="ptmd-muted small">No AI generations yet. Use the tools above to get started.</p>
    <?php endif; ?>
</div>
</div>

<!-- Inject CSRF for JS -->
<meta name="csrf-token" content="<?php ee(csrf_token()); ?>">

<?php
$extraScripts = '<script src="/assets/js/admin/ai-tools.js"></script>';
include __DIR__ . '/_admin_footer.php';
?>
