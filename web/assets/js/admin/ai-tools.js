/**
 * PTMD Admin — AI Content Studio interactive buttons
 *
 * Loaded only on admin/ai-tools.php via $extraScripts.
 * Requires: a <meta name="csrf-token"> to be present before this script.
 */
'use strict';

function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/** Button label map for restoring after async call. */
const BUTTON_LABELS = {
    video_ideas:       'Generate Ideas',
    title:             'Generate Titles',
    keywords:          'Generate Keywords',
    description:       'Generate Description',
    caption:           'Generate Captions',
    thumbnail_concept: 'Generate Concept',
};

// ── AI tool buttons ────────────────────────────────────────────────────────────
document.querySelectorAll('[data-ai-feature]').forEach(btn => {
    btn.addEventListener('click', async () => {
        const feature    = btn.dataset.aiFeature;
        const inputIds   = JSON.parse(btn.dataset.inputs ?? '[]');
        const resultBox  = document.getElementById('result_' + feature);

        if (!resultBox) return;

        // Collect input values
        const inputs = {};
        inputIds.forEach(id => {
            const el = document.getElementById(id);
            inputs[id] = el?.value ?? '';
        });

        // Show loading
        resultBox.style.display = 'block';
        resultBox.classList.add('loading');
        resultBox.textContent = 'Generating…';
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Generating…';

        try {
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            const fd = new FormData();
            fd.append('csrf_token', csrfMeta?.content ?? '');
            fd.append('feature', feature);
            Object.entries(inputs).forEach(([k, v]) => fd.append(k, v));

            const res  = await fetch('/api/ai_generate.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: fd,
            });
            const data = await res.json();

            resultBox.classList.remove('loading');

            if (data.ok) {
                if (feature === 'video_ideas' && Array.isArray(data.ideas) && data.ideas.length) {
                    resultBox.innerHTML = '<ol class="mb-0">' + data.ideas.map(item => {
                        return '<li class="mb-2"><strong>' + escapeHtml(item.title) + '</strong><br>'
                            + '<span class="ptmd-muted">' + escapeHtml(item.premise) + '</span><br>'
                            + '<em>Angle:</em> ' + escapeHtml(item.angle) + '</li>';
                    }).join('') + '</ol>'
                        + '<div class="ptmd-muted mt-2 text-xs">Saved to database. Refresh to update the stored list.</div>';
                } else {
                    resultBox.textContent = data.text ?? '';
                }
                window.PTMDToast?.success('Generated successfully.');
            } else {
                resultBox.textContent = '⚠ ' + (data.error ?? 'Generation failed.');
                window.PTMDToast?.error(data.error ?? 'Generation failed.');
            }
        } catch {
            resultBox.classList.remove('loading');
            resultBox.textContent = '⚠ Network error. Please try again.';
            window.PTMDToast?.error('Network error.');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles me-2"></i>'
                + (BUTTON_LABELS[feature] ?? 'Generate');
        }
    });
});
