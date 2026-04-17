<?php
/**
 * PTMD — Single Episode page
 *
 * $currentEpisode is resolved by index.php before this file is included.
 */

$tags       = get_episode_tags((int) $currentEpisode['id']);
$shareUrl   = 'https://' . site_setting('site_domain', 'papertrailmd.com')
            . '/index.php?page=episode&slug=' . urlencode($currentEpisode['slug']);

// Viewer favorite state
$viewer        = current_viewer();
$viewerFavIds  = $viewer ? get_viewer_favorites((int) $viewer['id']) : [];
$isFavorited   = in_array((int) $currentEpisode['id'], $viewerFavIds, true);
$loginReturn   = '/index.php?page=login&return=episode&slug=' . urlencode($currentEpisode['slug']);
?>

<section class="container py-5">

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb" style="font-size:var(--text-sm)">
            <li class="breadcrumb-item">
                <a href="/index.php">Home</a>
            </li>
            <li class="breadcrumb-item">
                <a href="/index.php?page=episodes">Episodes</a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">
                <?php ee($currentEpisode['title']); ?>
            </li>
        </ol>
    </nav>

    <article>
        <header class="mb-5" data-animate>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <span class="ptmd-badge-teal">
                    <i class="fa-solid fa-clock me-1"></i>
                    <?php ee($currentEpisode['duration']); ?>
                </span>
                <?php foreach ($tags as $tag): ?>
                    <span class="ptmd-badge-muted"><?php ee($tag); ?></span>
                <?php endforeach; ?>
            </div>

            <h1 class="mb-3">
                <?php ee($currentEpisode['title']); ?>
            </h1>

            <p class="ptmd-hero-sub" style="font-size:var(--text-xl)">
                <?php ee($currentEpisode['excerpt']); ?>
            </p>

            <small class="ptmd-muted">
                <i class="fa-solid fa-calendar-days me-1"></i>
                Published <?php echo e(date('F j, Y', strtotime($currentEpisode['published_at']))); ?>
            </small>

            <!-- Share row + Favorite -->
            <div class="d-flex gap-2 mt-4 flex-wrap align-items-center">
                <button
                    class="btn btn-ptmd-outline btn-sm"
                    data-clipboard-text="<?php ee($shareUrl); ?>"
                    data-tippy-content="Copy link"
                >
                    <i class="fa-solid fa-link me-1"></i> Copy Link
                </button>
                <?php if (site_setting('social_x')): ?>
                    <a
                        href="https://x.com/intent/tweet?text=<?php echo urlencode('"' . $currentEpisode['title'] . '" — ' . $shareUrl); ?>"
                        target="_blank" rel="noopener"
                        class="btn btn-ptmd-ghost btn-sm"
                    >
                        <i class="fa-brands fa-x-twitter me-1"></i> Share on X
                    </a>
                <?php endif; ?>
                <?php if (site_setting('social_facebook')): ?>
                    <a
                        href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($shareUrl); ?>"
                        target="_blank" rel="noopener"
                        class="btn btn-ptmd-ghost btn-sm"
                    >
                        <i class="fa-brands fa-facebook me-1"></i> Facebook
                    </a>
                <?php endif; ?>
                <a
                    href="https://www.linkedin.com/sharing/share-offsite/?url=<?php echo urlencode($shareUrl); ?>"
                    target="_blank" rel="noopener"
                    class="btn btn-ptmd-ghost btn-sm"
                >
                    <i class="fa-brands fa-linkedin me-1"></i> LinkedIn
                </a>
                <button
                    id="btnNativeShare"
                    class="btn btn-ptmd-ghost btn-sm"
                    style="display:none"
                    data-share-title="<?php ee($currentEpisode['title']); ?>"
                    data-share-url="<?php ee($shareUrl); ?>"
                >
                    <i class="fa-solid fa-share-nodes me-1"></i> Share
                </button>

                <!-- Favorite button -->
                <?php if ($viewer): ?>
                    <button
                        class="ptmd-favorite-btn <?php echo $isFavorited ? 'is-favorited' : ''; ?> ms-auto"
                        data-favorite-episode="<?php ee((string) $currentEpisode['id']); ?>"
                        data-csrf="<?php ee(csrf_token()); ?>"
                        aria-label="<?php echo $isFavorited ? 'Remove from favorites' : 'Add to favorites'; ?>"
                        aria-pressed="<?php echo $isFavorited ? 'true' : 'false'; ?>"
                        data-tippy-content="<?php echo $isFavorited ? 'Remove from favorites' : 'Add to favorites'; ?>"
                    >
                        <i class="<?php echo $isFavorited ? 'fa-solid' : 'fa-regular'; ?> fa-heart"></i>
                    </button>
                <?php else: ?>
                    <a
                        href="<?php ee($loginReturn); ?>"
                        class="ptmd-favorite-btn ms-auto"
                        aria-label="Sign in to save"
                        data-tippy-content="Sign in to save"
                    >
                        <i class="fa-regular fa-heart"></i>
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <!-- Video embed -->
        <?php if (!empty($currentEpisode['video_url'])): ?>
            <div class="ratio ratio-16x9 mb-5 rounded overflow-hidden" style="border:1px solid var(--ptmd-border)" data-animate>
                <iframe
                    src="<?php ee($currentEpisode['video_url']); ?>"
                    title="<?php ee($currentEpisode['title']); ?>"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen
                ></iframe>
            </div>
        <?php elseif (!empty($currentEpisode['featured_image'])): ?>
            <figure class="mb-5" data-animate>
                <img
                    src="<?php ee($currentEpisode['featured_image']); ?>"
                    alt="<?php ee($currentEpisode['title']); ?>"
                    class="w-100 rounded"
                    style="max-height:480px;object-fit:cover;border:1px solid var(--ptmd-border)"
                    loading="lazy"
                >
            </figure>
        <?php endif; ?>

        <!-- Article body -->
        <?php if (!empty($currentEpisode['body'])): ?>
            <div class="ptmd-panel p-xl mb-5" data-animate>
                <div class="ptmd-article-body" style="font-size:var(--text-md);line-height:1.85;max-width:72ch;color:var(--ptmd-muted-strong)">
                    <?php echo nl2br(e($currentEpisode['body'])); ?>
                </div>
            </div>
        <?php endif; ?>
    </article>

    <!-- Back -->
    <div class="mt-4" data-animate>
        <a href="/index.php?page=episodes" class="btn btn-ptmd-outline">
            <i class="fa-solid fa-arrow-left me-2"></i>Back to Episodes
        </a>
    </div>

</section>
