<!-- ── Featured case ───────────────────────────────────────────────────── -->
<section class="container mb-5" data-animate>
    <div class="d-flex align-items-center gap-3 mb-4">
        <h2 class="mb-0">Featured case</h2>
        <span class="ptmd-badge-teal">Latest</span>
    </div>

    <?php if ($featuredcase): ?>
        <article class="ptmd-card-featured p-0 overflow-hidden">
            <div class="row g-0">
                <div class="col-lg-5">
                    <div class="ptmd-ep-thumb" style="border-radius:0;height:100%;min-height:220px">
                        <?php if ($featuredcase['thumbnail_image']): ?>
                            <img
                                src="<?php ee($featuredcase['thumbnail_image']); ?>"
                                alt="<?php ee($featuredcase['title']); ?>"
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
                        <?php echo e(date('F j, Y', strtotime($featuredcase['published_at']))); ?>
                        &nbsp;·&nbsp;
                        <i class="fa-solid fa-clock me-1"></i>
                        <?php ee($featuredcase['duration']); ?>
                    </small>
                    <h2 class="h3 mb-3">
                        <?php ee($featuredcase['title']); ?>
                    </h2>
                    <p class="ptmd-text-muted mb-4">
                        <?php ee($featuredcase['excerpt']); ?>
                    </p>
                    <div>
                        <a
                            class="btn btn-ptmd-primary"
                            href="<?php ee(route_case((string) $featuredcase['slug'])); ?>"
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
            No published cases yet. Import <code>database/seed.sql</code> or add one via
            <a href="<?php ee(route_admin('cases')); ?>">Admin → Cases</a>.
        </div>
    <?php endif; ?>
</section>
