<?php
/**
 * PTMD — Home page
 *
 * Sections: hero, featured episode, latest episodes grid, content pillars, CTA
 */

$featuredEpisode = get_featured_episode();
$latestEpisodes  = get_latest_episodes(6);
?>

<!-- ── Hero ──────────────────────────────────────────────────────────────── -->
<section class="ptmd-hero">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-7" data-animate data-animate-delay="0">

                <div class="ptmd-hero-eyebrow">
                    <i class="fa-solid fa-film"></i>
                    Documentary-First Media
                </div>

                <h1>
                    <?php ee(site_setting('hero_headline', 'Truth with Teeth.')); ?>
                </h1>

                <p class="ptmd-hero-sub">
                    <?php ee(site_setting('hero_subheadline', 'Investigative mini-docs with cinematic style and satirical precision.')); ?>
                </p>

                <div class="d-flex flex-wrap gap-3">
                    <a class="btn btn-ptmd-primary btn-lg" href="/index.php?page=episodes">
                        <i class="fa-solid fa-play me-2"></i>
                        <?php ee(site_setting('hero_cta_text', 'Watch Latest Episode')); ?>
                    </a>
                    <a class="btn btn-ptmd-outline btn-lg" href="/index.php?page=contact">
                        <i class="fa-solid fa-paper-plane me-2"></i>
                        Pitch a Story
                    </a>
                </div>

            </div>
            <div class="col-lg-5" data-animate data-animate-delay="150">

                <!-- Content pillars panel -->
                <div class="ptmd-panel p-lg">
                    <h2 class="h5 mb-4 ptmd-gradient-text">
                        <i class="fa-solid fa-layer-group me-2"></i>What We Cover
                    </h2>
                    <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                        <li class="d-flex align-items-start gap-3">
                            <i class="fa-solid fa-scale-balanced ptmd-text-teal mt-1" style="width:18px"></i>
                            <span class="ptmd-text-muted">Power &amp; accountability — who holds the levers and who paid for them</span>
                        </li>
                        <li class="d-flex align-items-start gap-3">
                            <i class="fa-solid fa-masks-theater ptmd-text-yellow mt-1" style="width:18px"></i>
                            <span class="ptmd-text-muted">Culture &amp; consequence — when the zeitgeist meets the receipts</span>
                        </li>
                        <li class="d-flex align-items-start gap-3">
                            <i class="fa-solid fa-landmark ptmd-text-gold mt-1" style="width:18px"></i>
                            <span class="ptmd-text-muted">Political absurdity — democracy's highlight reel, explained</span>
                        </li>
                    </ul>
                </div>

            </div>
        </div>
    </div>
</section>

<!-- ── Featured Episode ───────────────────────────────────────────────────── -->
<section class="container mb-5" data-animate>
    <div class="d-flex align-items-center gap-3 mb-4">
        <h2 class="mb-0">Featured Episode</h2>
        <span class="ptmd-badge-teal">Latest</span>
    </div>

    <?php if ($featuredEpisode): ?>
        <article class="ptmd-card-featured p-0 overflow-hidden">
            <div class="row g-0">
                <div class="col-lg-5">
                    <div class="ptmd-ep-thumb" style="border-radius:0;height:100%;min-height:220px">
                        <?php if ($featuredEpisode['thumbnail_image']): ?>
                            <img
                                src="<?php ee($featuredEpisode['thumbnail_image']); ?>"
                                alt="<?php ee($featuredEpisode['title']); ?>"
                                loading="lazy"
                            >
                        <?php endif; ?>
                        <div class="ptmd-ep-play">
                            <span><i class="fa-solid fa-play"></i></span>
                        </div>
                    </div>
                </div>
                <div class="col-lg-7 p-5 d-flex flex-column justify-content-center">
                    <small class="ptmd-muted mb-2">
                        <i class="fa-solid fa-calendar-days me-1"></i>
                        <?php echo e(date('F j, Y', strtotime($featuredEpisode['published_at']))); ?>
                        &nbsp;·&nbsp;
                        <i class="fa-solid fa-clock me-1"></i>
                        <?php ee($featuredEpisode['duration']); ?>
                    </small>
                    <h2 class="h3 mb-3">
                        <?php ee($featuredEpisode['title']); ?>
                    </h2>
                    <p class="ptmd-text-muted mb-4">
                        <?php ee($featuredEpisode['excerpt']); ?>
                    </p>
                    <div>
                        <a
                            class="btn btn-ptmd-primary"
                            href="/index.php?page=episode&amp;slug=<?php ee($featuredEpisode['slug']); ?>"
                        >
                            <i class="fa-solid fa-play me-2"></i>Watch + Read
                        </a>
                    </div>
                </div>
            </div>
        </article>

    <?php else: ?>
        <div class="ptmd-panel p-lg ptmd-text-muted">
            <i class="fa-solid fa-circle-info me-2"></i>
            No published episodes yet. Import <code>database/seed.sql</code> or add one via
            <a href="/admin/episodes.php">Admin → Episodes</a>.
        </div>
    <?php endif; ?>
</section>

<!-- ── Latest Episodes Grid ──────────────────────────────────────────────── -->
<section class="container mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4" data-animate>
        <h2 class="mb-0">Latest Episodes</h2>
        <a class="btn btn-ptmd-outline" href="/index.php?page=episodes">
            Browse All <i class="fa-solid fa-arrow-right ms-2"></i>
        </a>
    </div>

    <div class="row g-4">
        <?php foreach ($latestEpisodes as $i => $ep): ?>
            <div class="col-md-6 col-lg-4" data-animate data-animate-delay="<?php echo $i * 80; ?>">
                <article class="ptmd-card h-100 d-flex flex-column overflow-hidden">

                    <?php if ($ep['thumbnail_image']): ?>
                        <div class="ptmd-ep-thumb" style="border-radius:0;border-bottom-left-radius:0;border-bottom-right-radius:0">
                            <img
                                src="<?php ee($ep['thumbnail_image']); ?>"
                                alt="<?php ee($ep['title']); ?>"
                                loading="lazy"
                            >
                            <div class="ptmd-ep-play">
                                <span><i class="fa-solid fa-play"></i></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="p-4 d-flex flex-column flex-grow-1">
                        <div class="d-flex gap-2 mb-2">
                            <span class="ptmd-badge-teal"><?php ee($ep['duration']); ?></span>
                        </div>
                        <h3 class="h5 mb-2">
                            <?php ee($ep['title']); ?>
                        </h3>
                        <p class="ptmd-text-muted small mb-3 flex-grow-1">
                            <?php ee($ep['excerpt']); ?>
                        </p>
                        <div class="d-flex justify-content-between align-items-center mt-auto">
                            <small class="ptmd-muted">
                                <?php echo e(date('M j, Y', strtotime($ep['published_at']))); ?>
                            </small>
                            <a
                                class="btn btn-ptmd-outline btn-sm"
                                href="/index.php?page=episode&amp;slug=<?php ee($ep['slug']); ?>"
                            >
                                Open <i class="fa-solid fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>

        <?php if (!$latestEpisodes): ?>
            <div class="col-12">
                <div class="ptmd-panel p-lg ptmd-text-muted">No episodes yet.</div>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ── Social links CTA ───────────────────────────────────────────────────── -->
<section class="container mb-5" data-animate>
    <div class="ptmd-panel p-xl text-center">
        <h2 class="mb-3">Follow the Paper Trail</h2>
        <p class="ptmd-text-muted mb-4" style="max-width:50ch;margin-inline:auto">
            New episodes every week. Receipts always included.
        </p>
        <div class="d-flex flex-wrap gap-3 justify-content-center">
            <?php if (site_setting('social_youtube')): ?>
                <a href="<?php ee(site_setting('social_youtube')); ?>" target="_blank" rel="noopener"
                   class="btn btn-ptmd-outline">
                    <i class="fa-brands fa-youtube me-2" style="color:var(--ptmd-red)"></i>YouTube
                </a>
            <?php endif; ?>
            <?php if (site_setting('social_x')): ?>
                <a href="<?php ee(site_setting('social_x')); ?>" target="_blank" rel="noopener"
                   class="btn btn-ptmd-outline">
                    <i class="fa-brands fa-x-twitter me-2"></i>X / Twitter
                </a>
            <?php endif; ?>
            <?php if (site_setting('social_instagram')): ?>
                <a href="<?php ee(site_setting('social_instagram')); ?>" target="_blank" rel="noopener"
                   class="btn btn-ptmd-outline">
                    <i class="fa-brands fa-instagram me-2" style="color:var(--ptmd-pink)"></i>Instagram
                </a>
            <?php endif; ?>
            <?php if (site_setting('social_tiktok')): ?>
                <a href="<?php ee(site_setting('social_tiktok')); ?>" target="_blank" rel="noopener"
                   class="btn btn-ptmd-outline">
                    <i class="fa-brands fa-tiktok me-2"></i>TikTok
                </a>
            <?php endif; ?>
        </div>
    </div>
</section>
