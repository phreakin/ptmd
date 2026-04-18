<?php
/**
 * PTMD — cases listing page
 */

$cases = get_latest_cases(24);
?>

<section class="container py-5">

    <div class="mb-5" data-animate>
        <span class="ptmd-badge-teal mb-3 d-inline-block">
            <i class="fa-solid fa-film me-1"></i> All cases
        </span>
        <h1 class="mb-2">cases</h1>
        <p class="ptmd-text-muted" style="max-width:55ch">
            Database-driven documentary drops and investigations.
            Every case has receipts.
        </p>
    </div>

    <div class="row g-4">
        <?php if (!$cases): ?>
            <div class="col-12">
                <div class="ptmd-panel p-lg ptmd-text-muted">
                    <i class="fa-solid fa-circle-info me-2"></i>
                    No published cases yet. Run <code>database/seed.sql</code> to load samples.
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($cases as $i => $ep): ?>
            <div class="col-md-6 col-lg-4" data-animate data-animate-delay="<?php echo $i * 60; ?>">
                <article class="ptmd-card h-100 d-flex flex-column overflow-hidden">

                    <?php if ($ep['thumbnail_image']): ?>
                        <div class="ptmd-ep-thumb" style="border-radius:0">
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
                            href="<?php ee(route_case((string) $ep['slug'])); ?>"
                        >
                            Watch + Read <i class="fa-solid fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </div>

</section>
