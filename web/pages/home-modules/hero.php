<!-- ── Hero ──────────────────────────────────────────────────────────────── -->
<section class="ptmd-hero">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-7" data-animate data-animate-delay="0">

                <div class="ptmd-hero-eyebrow">
                    🧾 Forensic Storytelling
                </div>

                <h1>
                    <?php ee(site_setting('hero_headline', 'The Timeline Never Lies.')); ?>
                </h1>

                <p class="ptmd-hero-sub">
                    <?php ee(site_setting('hero_subheadline', 'Receipt-based narratives. Social + pop culture autopsies. Case Chat is live.')); ?>
                </p>

                <div class="d-flex flex-wrap gap-3">
                    <a class="btn btn-ptmd-primary btn-lg" href="/index.php?page=episodes">
                        <i class="fa-solid fa-play me-2"></i>
                        <?php ee(site_setting('hero_cta_text', 'Watch Latest Episode')); ?>
                    </a>
                    <a class="btn btn-ptmd-outline btn-lg" href="/index.php?page=contact">
                        <i class="fa-solid fa-paper-plane me-2"></i>
                        Drop a Tip
                    </a>
                </div>

            </div>
            <div class="col-lg-5" data-animate data-animate-delay="150">

                <!-- Content pillars panel -->
                <div class="ptmd-panel p-lg">
                    <h2 class="h5 mb-4 ptmd-gradient-text">
                        <i class="fa-solid fa-magnifying-glass me-2"></i>What We Cover
                    </h2>
                    <ul class="list-unstyled d-flex flex-column gap-3 mb-0">
                        <li class="d-flex align-items-start gap-3">
                            <span style="width:18px;flex-shrink:0" class="mt-1">🧾</span>
                            <span class="ptmd-text-muted">Receipts &amp; timelines — reconstructing what actually happened, step by step</span>
                        </li>
                        <li class="d-flex align-items-start gap-3">
                            <span style="width:18px;flex-shrink:0" class="mt-1">👀</span>
                            <span class="ptmd-text-muted">Social &amp; pop culture breakdowns — when the internet's receipts tell a better story</span>
                        </li>
                        <li class="d-flex align-items-start gap-3">
                            <span style="width:18px;flex-shrink:0" class="mt-1">🚨</span>
                            <span class="ptmd-text-muted">Crime &amp; medical docs — gritty, fact-first, occasionally very funny</span>
                        </li>
                    </ul>
                </div>

            </div>
        </div>
    </div>
</section>
