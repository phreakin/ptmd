<?php
/**
 * PTMD — Viewer account / dashboard page
 *
 * Requires a viewer session; redirects to login if absent.
 * Displays saved favorites in episode-card grid.
 */

if (!is_viewer_logged_in()) {
    redirect('/index.php?page=login&return=account');
}

$viewer    = current_viewer();
$viewerId  = (int) ($_SESSION['viewer_id'] ?? 0);

// Fetch favorited episodes
$pdo        = get_db();
$favorites  = [];

if ($pdo && $viewerId > 0) {
    $stmt = $pdo->prepare(
        'SELECT e.id, e.title, e.slug, e.excerpt, e.thumbnail_image, e.duration, e.published_at
         FROM episodes e
         INNER JOIN episode_favorites ef ON ef.episode_id = e.id
         WHERE ef.viewer_id = :v AND e.status = :status
         ORDER BY ef.created_at DESC'
    );
    $stmt->execute(['v' => $viewerId, 'status' => 'published']);
    $favorites = $stmt->fetchAll();
}

$displayName = ($viewer && $viewer['display_name'] !== null && $viewer['display_name'] !== '')
    ? $viewer['display_name']
    : ($viewer['username'] ?? 'Viewer');
$username    = $viewer['username'] ?? '';
?>

<section class="container py-5">

    <!-- Header row -->
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-4 mb-5" data-animate>
        <div>
            <span class="ptmd-badge-teal mb-3 d-inline-block">
                <i class="fa-solid fa-user me-1"></i> My Account
            </span>
            <h1 class="h3 mb-1"><?php ee($displayName); ?></h1>
            <?php if ($username !== '' && $username !== $displayName): ?>
                <p class="ptmd-muted small mb-0">@<?php ee($username); ?></p>
            <?php endif; ?>
        </div>

        <!-- Logout button -->
        <form method="post" action="/index.php?page=logout">
            <input type="hidden" name="csrf_token" value="<?php ee(csrf_token()); ?>">
            <button type="submit" class="btn btn-ptmd-ghost btn-sm">
                <i class="fa-solid fa-right-from-bracket me-1"></i>Sign Out
            </button>
        </form>
    </div>

    <!-- Saved episodes -->
    <div class="mb-4" data-animate>
        <h2 class="h5 mb-1">
            <i class="fa-solid fa-heart me-2" style="color:var(--ptmd-teal)"></i>Saved Episodes
        </h2>
        <p class="ptmd-muted small">Episodes you've marked as favorites.</p>
    </div>

    <div class="row g-4">
        <?php if (!$favorites): ?>
            <div class="col-12" data-animate>
                <div class="ptmd-panel p-lg ptmd-text-muted">
                    <i class="fa-regular fa-heart me-2"></i>
                    No saved episodes yet.
                    <a href="/index.php?page=episodes" class="ms-1">Browse episodes</a> and tap the
                    <i class="fa-solid fa-heart ms-1 me-1" style="color:var(--ptmd-teal)"></i> to save them here.
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($favorites as $i => $ep): ?>
            <div class="col-md-6 col-lg-4" data-animate data-animate-delay="<?php echo $i * 60; ?>">
                <article class="ptmd-card h-100 d-flex flex-column overflow-hidden">

                    <?php if ($ep['thumbnail_image']): ?>
                        <div class="ptmd-ep-thumb position-relative" style="border-radius:0">
                            <img
                                src="<?php ee($ep['thumbnail_image']); ?>"
                                alt="<?php ee($ep['title']); ?>"
                                loading="lazy"
                            >
                            <div class="ptmd-ep-play">
                                <span><i class="fa-solid fa-play"></i></span>
                            </div>
                            <!-- Remove from favorites -->
                            <button
                                class="ptmd-favorite-btn is-favorited position-absolute"
                                style="top:0.5rem;right:0.5rem"
                                data-favorite-episode="<?php ee((string) $ep['id']); ?>"
                                data-csrf="<?php ee(csrf_token()); ?>"
                                aria-label="Remove from favorites"
                                aria-pressed="true"
                            >
                                <i class="fa-solid fa-heart"></i>
                            </button>
                        </div>
                    <?php else: ?>
                        <!-- No thumbnail — still show favorite button -->
                        <div class="position-relative" style="height:0">
                            <button
                                class="ptmd-favorite-btn is-favorited position-absolute"
                                style="top:0.5rem;right:0.5rem;z-index:1"
                                data-favorite-episode="<?php ee((string) $ep['id']); ?>"
                                data-csrf="<?php ee(csrf_token()); ?>"
                                aria-label="Remove from favorites"
                                aria-pressed="true"
                            >
                                <i class="fa-solid fa-heart"></i>
                            </button>
                        </div>
                    <?php endif; ?>

                    <div class="p-4 d-flex flex-column flex-grow-1">
                        <div class="d-flex gap-2 mb-2 flex-wrap">
                            <span class="ptmd-badge-teal"><?php ee($ep['duration']); ?></span>
                            <span class="ptmd-badge-muted">
                                <?php echo e(date('M j, Y', strtotime($ep['published_at']))); ?>
                            </span>
                        </div>
                        <h2 class="h5 mb-2"><?php ee($ep['title']); ?></h2>
                        <p class="ptmd-text-muted small mb-4 flex-grow-1">
                            <?php ee($ep['excerpt']); ?>
                        </p>
                        <a
                            class="btn btn-ptmd-outline btn-sm align-self-start"
                            href="/index.php?page=episode&amp;slug=<?php ee($ep['slug']); ?>"
                        >
                            Watch + Read <i class="fa-solid fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>

</section>
