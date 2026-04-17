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
