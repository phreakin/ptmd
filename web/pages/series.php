<?php
/**
 * PTMD — Series page
 */

$latestcases = get_latest_cases(3);
?>

<section class="container py-5">

    <div class="mb-5" data-animate>
        <span class="ptmd-badge-teal mb-3 d-inline-block">
            <i class="fa-solid fa-layer-group me-1"></i> Series
        </span>
        <h1 class="mb-2">Editorial Series</h1>
        <p class="ptmd-hero-sub">
            The recurring lanes that shape Paper Trail MD: power, culture, and the receipts in between.
        </p>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-lg-4" data-animate>
            <div class="ptmd-panel p-xl h-100">
                <h2 class="h5 mb-3 ptmd-text-teal">
                    <i class="fa-solid fa-scale-balanced me-2"></i>Power &amp; Accountability
                </h2>
                <p class="ptmd-text-muted mb-0">
                    Stories about money, influence, institutions, and the people who profit when nobody is watching.
                </p>
            </div>
        </div>
        <div class="col-lg-4" data-animate data-animate-delay="80">
            <div class="ptmd-panel p-xl h-100">
                <h2 class="h5 mb-3 ptmd-text-yellow">
                    <i class="fa-solid fa-masks-theater me-2"></i>Culture &amp; Consequence
                </h2>
                <p class="ptmd-text-muted mb-0">
                    Media, spectacle, and viral moments — with the paperwork that explains what really happened.
                </p>
            </div>
        </div>
        <div class="col-lg-4" data-animate data-animate-delay="160">
            <div class="ptmd-panel p-xl h-100">
                <h2 class="h5 mb-3 ptmd-text-gold">
                    <i class="fa-solid fa-landmark me-2"></i>Political Absurdity
                </h2>
                <p class="ptmd-text-muted mb-0">
                    The strange, documented, very real side of civic life — explained with a little dry humor and a lot of sourcing.
                </p>
            </div>
        </div>
    </div>

    <div class="row g-5 align-items-start">
        <div class="col-lg-7" data-animate>
            <div class="ptmd-panel p-xl mb-4">
                <h2 class="h5 mb-3">What a series means here</h2>
                <p class="ptmd-text-muted">
                    On Paper Trail MD, a series is a recurring editorial lane rather than a separate show page.
                    Each investigation stands on its own, but the themes connect across episodes so viewers can follow
                    a larger paper trail over time.
                </p>
                <p class="ptmd-text-muted mb-0">
                    Use this page as a starting point for the brand’s recurring story buckets, then head to the
                    cases archive to watch individual investigations.
                </p>
            </div>

            <div class="ptmd-panel p-xl">
                <div class="d-flex justify-content-between align-items-center gap-3 mb-4">
                    <h2 class="h5 mb-0">Recent investigations</h2>
                    <a class="btn btn-ptmd-outline btn-sm" href="/index.php?page=cases">
                        Browse all cases <i class="fa-solid fa-arrow-right ms-2"></i>
                    </a>
                </div>

                <?php if ($latestcases): ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($latestcases as $ep): ?>
                            <article class="d-flex gap-3 align-items-start">
                                <?php if (!empty($ep['thumbnail_image'])): ?>
                                    <img
                                        src="<?php ee($ep['thumbnail_image']); ?>"
                                        alt="<?php ee($ep['title']); ?>"
                                        loading="lazy"
                                        style="width:96px;height:64px;object-fit:cover;border-radius:12px;border:1px solid var(--ptmd-border)"
                                    >
                                <?php endif; ?>
                                <div>
                                    <h3 class="h6 mb-1">
                                        <a href="/index.php?page=case&amp;slug=<?php ee($ep['slug']); ?>">
                                            <?php ee($ep['title']); ?>
                                        </a>
                                    </h3>
                                    <p class="ptmd-text-muted small mb-1"><?php ee($ep['excerpt']); ?></p>
                                    <small class="ptmd-muted"><?php echo e(date('M j, Y', strtotime($ep['published_at']))); ?></small>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="ptmd-text-muted">
                        No published cases yet. Add one in the admin panel to start building out the series archive.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-5" data-animate data-animate-delay="100">
            <div class="ptmd-panel p-xl mb-4">
                <h2 class="h5 mb-3">Brand promise</h2>
                <ul class="list-unstyled d-flex flex-column gap-2 ptmd-text-muted small mb-0">
                    <li><i class="fa-solid fa-check ptmd-text-teal me-2"></i>Every episode has a clear thesis</li>
                    <li><i class="fa-solid fa-check ptmd-text-teal me-2"></i>Receipts are the point, not the garnish</li>
                    <li><i class="fa-solid fa-check ptmd-text-teal me-2"></i>The tone stays sharp, modern, and shareable</li>
                    <li><i class="fa-solid fa-check ptmd-text-teal me-2"></i>Viewers can follow the trail across multiple stories</li>
                </ul>
            </div>

            <div class="ptmd-panel p-xl">
                <h2 class="h5 mb-3">Want a new series lane?</h2>
                <p class="ptmd-text-muted mb-3">
                    If you have a repeatable subject area or a story arc that deserves multiple chapters, pitch it as a series idea.
                </p>
                <a class="btn btn-ptmd-primary" href="/index.php?page=contact">
                    <i class="fa-solid fa-paper-plane me-2"></i>Pitch a series
                </a>
            </div>
        </div>
    </div>

</section>

