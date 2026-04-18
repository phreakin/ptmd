<!-- ── Latest cases Grid ──────────────────────────────────────────────── -->
<section class="container mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4" data-animate>
        <h2 class="mb-0">Latest cases</h2>
        <a class="btn btn-ptmd-outline" href="<?php ee(route_cases()); ?>">
            Browse All <i class="fa-solid fa-arrow-right ms-2"></i>
        </a>
    </div>

    <div class="row g-4">
        <?php foreach ($latestcases as $i => $ep): ?>
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
                                href="<?php ee(route_case((string) $ep['slug'])); ?>"
                            >
                                Open <i class="fa-solid fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>

        <?php if (!$latestcases): ?>
            <div class="col-12">
                <div class="ptmd-panel p-lg ptmd-text-muted">No cases yet.</div>
            </div>
        <?php endif; ?>
    </div>
</section>
