/**
 * PTMD — overlay-tool.js
 * Canvas-based live overlay compositor, clip multi-select, position/opacity
 * controls, and batch queue submission.
 *
 * Dependencies: app.js (PTMDToast), fetch API, Canvas 2D
 */
'use strict';

(function initOverlayTool() {

    // ── DOM refs ─────────────────────────────────────────────────────────────
    const canvas          = document.getElementById('overlayPreviewCanvas');
    const ctx             = canvas?.getContext('2d');
    const positionBtns    = document.querySelectorAll('[data-position]');
    const opacitySlider   = document.getElementById('overlayOpacity');
    const scaleSlider     = document.getElementById('overlayScale');
    const opacityLabel    = document.getElementById('opacityLabel');
    const scaleLabel      = document.getElementById('scaleLabel');
    const submitBtn       = document.getElementById('submitBatchBtn');
    const batchForm       = document.getElementById('batchForm');
    const progressSection = document.getElementById('batchProgressSection');

    if (!canvas || !ctx) return;

    // ── State ─────────────────────────────────────────────────────────────────
    const state = {
        selectedClips:    new Set(),
        selectedOverlay:  null,  // { path, img }
        position:         'bottom-right',
        opacity:          1.0,
        scale:            30,    // % of video width
        previewClipPath:  null,
        previewImg:       new Image(),
        overlayImg:       new Image(),
    };

    // ── Canvas render ─────────────────────────────────────────────────────────
    function drawPreview() {
        const W = canvas.width  = 640;
        const H = canvas.height = 360;

        ctx.clearRect(0, 0, W, H);

        // Background
        ctx.fillStyle = '#0B0C10';
        ctx.fillRect(0, 0, W, H);

        // Clip preview frame
        if (state.previewImg.complete && state.previewImg.naturalWidth > 0) {
            ctx.drawImage(state.previewImg, 0, 0, W, H);
        } else {
            // Placeholder gradient
            const grad = ctx.createLinearGradient(0, 0, W, H);
            grad.addColorStop(0, '#1C2A39');
            grad.addColorStop(1, '#0B0C10');
            ctx.fillStyle = grad;
            ctx.fillRect(0, 0, W, H);

            ctx.fillStyle = 'rgba(245,245,243,0.2)';
            ctx.font = '14px Inter, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('Select a clip to preview', W / 2, H / 2);
            ctx.textAlign = 'left';
        }

        // Overlay
        if (state.selectedOverlay && state.overlayImg.complete && state.overlayImg.naturalWidth > 0) {
            const overlayW = W * (state.scale / 100);
            const aspectR  = state.overlayImg.naturalWidth / state.overlayImg.naturalHeight;
            const overlayH = overlayW / aspectR;

            const positions = {
                'top-left':     { x: 10,              y: 10 },
                'top-right':    { x: W - overlayW - 10, y: 10 },
                'bottom-left':  { x: 10,              y: H - overlayH - 10 },
                'bottom-right': { x: W - overlayW - 10, y: H - overlayH - 10 },
                'center':       { x: (W - overlayW) / 2, y: (H - overlayH) / 2 },
                'full':         { x: 0, y: 0, w: W, h: H },
            };

            const pos = positions[state.position] ?? positions['bottom-right'];
            const drawW = pos.w ?? overlayW;
            const drawH = pos.h ?? overlayH;

            ctx.globalAlpha = state.opacity;
            ctx.drawImage(state.overlayImg, pos.x, pos.y, drawW, drawH);
            ctx.globalAlpha = 1.0;
        }
    }

    // ── Overlay swatch selection ──────────────────────────────────────────────
    document.querySelectorAll('.overlay-swatch').forEach(swatch => {
        swatch.addEventListener('click', () => {
            document.querySelectorAll('.overlay-swatch').forEach(s => s.classList.remove('selected'));
            swatch.classList.add('selected');

            const path = swatch.dataset.overlayPath;
            state.selectedOverlay = { path };

            state.overlayImg = new Image();
            state.overlayImg.crossOrigin = 'anonymous';
            state.overlayImg.onload  = drawPreview;
            state.overlayImg.onerror = () => {
                window.PTMDToast?.warning('Could not load overlay image preview.');
                drawPreview();
            };
            state.overlayImg.src = path;
        });
    });

    // ── Clip thumbnail selection (multi-select) ────────────────────────────────
    document.querySelectorAll('.clip-thumbnail-item').forEach(item => {
        item.addEventListener('click', (e) => {
            const path = item.dataset.clipPath;

            // Ctrl/Cmd = multi-select, otherwise single-select
            if (!e.ctrlKey && !e.metaKey) {
                document.querySelectorAll('.clip-thumbnail-item').forEach(i => i.classList.remove('selected'));
                state.selectedClips.clear();
            }

            if (state.selectedClips.has(path)) {
                state.selectedClips.delete(path);
                item.classList.remove('selected');
            } else {
                state.selectedClips.add(path);
                item.classList.add('selected');
                // Load first selected clip into preview
                if (!state.previewClipPath || state.selectedClips.size === 1) {
                    state.previewClipPath = path;

                    // Try to get thumbnail from a sibling img or poster attribute
                    const thumb = item.querySelector('img, video');
                    const src   = thumb?.src ?? thumb?.poster ?? '';

                    if (src) {
                        state.previewImg = new Image();
                        state.previewImg.crossOrigin = 'anonymous';
                        state.previewImg.onload  = drawPreview;
                        state.previewImg.src = src;
                    } else {
                        state.previewImg = new Image(); // blank
                        drawPreview();
                    }
                }
            }

            updateSelectedCount();
        });
    });

    function updateSelectedCount() {
        const countEl = document.getElementById('selectedClipsCount');
        if (countEl) {
            countEl.textContent = state.selectedClips.size === 0
                ? 'No clips selected'
                : `${state.selectedClips.size} clip${state.selectedClips.size !== 1 ? 's' : ''} selected`;
        }
    }

    // ── Position buttons ──────────────────────────────────────────────────────
    positionBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            positionBtns.forEach(b => b.classList.remove('active', 'btn-ptmd-teal'));
            btn.classList.add('active', 'btn-ptmd-teal');
            state.position = btn.dataset.position;
            drawPreview();
        });
    });

    // ── Sliders ───────────────────────────────────────────────────────────────
    opacitySlider?.addEventListener('input', () => {
        state.opacity = parseFloat(opacitySlider.value);
        if (opacityLabel) opacityLabel.textContent = Math.round(state.opacity * 100) + '%';
        drawPreview();
    });

    scaleSlider?.addEventListener('input', () => {
        state.scale = parseInt(scaleSlider.value, 10);
        if (scaleLabel) scaleLabel.textContent = state.scale + '%';
        drawPreview();
    });

    // ── Batch submission ──────────────────────────────────────────────────────
    batchForm?.addEventListener('submit', async (e) => {
        e.preventDefault();

        if (!state.selectedOverlay) {
            window.PTMDToast?.warning('Please select an overlay first.');
            return;
        }

        if (state.selectedClips.size === 0) {
            window.PTMDToast?.warning('Please select at least one clip.');
            return;
        }

        const fd = new FormData(batchForm);
        fd.set('overlay_path', state.selectedOverlay.path);
        fd.set('position',     state.position);
        fd.set('opacity',      state.opacity.toFixed(2));
        fd.set('scale',        state.scale);

        state.selectedClips.forEach(path => fd.append('clip_paths[]', path));

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Submitting…';

        try {
            const res  = await fetch('/api/apply_overlays.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: fd,
            });
            const data = await res.json();

            if (data.ok) {
                window.PTMDToast?.success(`Batch job #${data.job_id} queued (${data.item_count} clips).`);
                if (progressSection) {
                    progressSection.style.display = 'block';
                    pollBatchJob(data.job_id);
                }
            } else {
                window.PTMDToast?.error(data.error ?? 'Batch submission failed.');
            }
        } catch (err) {
            window.PTMDToast?.error('Network error submitting batch.');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fa-solid fa-layer-group me-2"></i>Apply Overlays to Selected Clips';
        }
    });

    // ── Batch progress polling ────────────────────────────────────────────────
    function pollBatchJob(jobId) {
        const bar        = document.getElementById('batchProgressBar');
        const statusText = document.getElementById('batchStatusText');
        let   timer      = null;

        async function check() {
            try {
                const res  = await fetch(`/api/apply_overlays.php?job_id=${jobId}`, { credentials: 'same-origin' });
                const data = await res.json();

                if (!data.ok) return;

                const pct = data.total > 0 ? Math.round((data.done / data.total) * 100) : 0;

                if (bar) {
                    bar.style.width = pct + '%';
                    bar.setAttribute('aria-valuenow', pct);
                }

                if (statusText) {
                    statusText.textContent = `${data.done} of ${data.total} clips processed (${pct}%) — Status: ${data.status}`;
                }

                if (data.status === 'completed' || data.status === 'failed') {
                    clearInterval(timer);
                    const isOk = data.status === 'completed';
                    window.PTMDToast?.[isOk ? 'success' : 'error'](
                        isOk ? 'All clips processed successfully!' : 'Batch completed with errors.'
                    );
                }
            } catch {
                clearInterval(timer);
            }
        }

        check();
        timer = setInterval(check, 3000);
    }

    // ── Refresh batch table on load ───────────────────────────────────────────
    document.querySelectorAll('[data-poll-job]').forEach(row => {
        const jobId = row.dataset.pollJob;
        if (row.dataset.status === 'processing' || row.dataset.status === 'pending') {
            pollBatchJob(parseInt(jobId, 10));
        }
    });

    // Initial canvas draw
    drawPreview();

})();
