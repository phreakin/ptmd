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
