<?php
/**
 * PTMD — Episodes listing page
 */

$episodes = get_latest_episodes(24);

// Viewer favorites (for heart state on cards)
$viewer       = current_viewer();
$viewerFavIds = $viewer ? get_viewer_favorites((int) $viewer['id']) : [];
$csrfToken    = csrf_token();
?>

<section class="container py-5">

    <div class="mb-5" data-animate>
        <span class="ptmd-badge-teal mb-3 d-inline-block">
            <i class="fa-solid fa-film me-1"></i> All Episodes
        </span>
        <h1 class="mb-2">Episodes</h1>
        <p class="ptmd-text-muted" style="max-width:55ch">
            Database-driven documentary drops and investigations.
            Every episode has receipts.
        </p>
    </div>

    <div class="row g-4">
        <?php if (!$episodes): ?>
            <div class="col-12">
                <div class="ptmd-panel p-lg ptmd-text-muted">
                    <i class="fa-solid fa-circle-info me-2"></i>
                    No published episodes yet. Run <code>database/seed.sql</code> to load samples.
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($episodes as $i => $ep): ?>
            <?php
                $epId       = (int) $ep['id'];
                $favorited  = in_array($epId, $viewerFavIds, true);
                $loginHref  = '/index.php?page=login&return=episode&slug=' . urlencode($ep['slug']);
            ?>
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
                            <!-- Favorite overlay -->
                            <?php if ($viewer): ?>
                                <button
                                    class="ptmd-favorite-btn <?php echo $favorited ? 'is-favorited' : ''; ?> position-absolute"
                                    style="top:0.5rem;right:0.5rem"
                                    data-favorite-episode="<?php ee((string) $epId); ?>"
                                    data-csrf="<?php ee($csrfToken); ?>"
                                    aria-label="<?php echo $favorited ? 'Remove from favorites' : 'Add to favorites'; ?>"
                                    aria-pressed="<?php echo $favorited ? 'true' : 'false'; ?>"
                                >
                                    <i class="<?php echo $favorited ? 'fa-solid' : 'fa-regular'; ?> fa-heart"></i>
                                </button>
                            <?php else: ?>
                                <a
                                    href="<?php ee($loginHref); ?>"
                                    class="ptmd-favorite-btn position-absolute"
                                    style="top:0.5rem;right:0.5rem"
                                    aria-label="Sign in to save"
                                >
                                    <i class="fa-regular fa-heart"></i>
                                </a>
                            <?php endif; ?>
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
