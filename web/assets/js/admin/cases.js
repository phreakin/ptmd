/**
 * PTMD Admin — Cases editor: AI field suggestion / optimize / apply
 *
 * Loaded only on admin/cases.php (add/edit view) via $extraScripts.
 * Requires: a <input name="csrf_token"> form field to be present.
 */
'use strict';

const fieldToElementIdMap = {
    title:           'ep_title',
    slug:            'ep_slug',
    excerpt:         'ep_excerpt',
    body:            'ep_body',
    keywords:        'ep_keywords',
    video_url:       'ep_video_url',
    duration:        'ep_duration',
    thumbnail_image: 'ep_thumb_url',
};

const suggestBtn   = document.getElementById('ai_suggest_btn');
const fieldSelect  = document.getElementById('ai_suggest_field');
const guidanceInput = document.getElementById('ai_suggest_guidance');
const resultWrap   = document.getElementById('ai_suggest_result_wrap');
const resultInput  = document.getElementById('ai_suggest_result');
const applyBtn     = document.getElementById('ai_apply_suggestion_btn');
const optimizeBtn  = document.getElementById('ai_optimize_btn');
const csrfInput    = document.querySelector('input[name="csrf_token"]');
const caseIdInput  = document.getElementById('ep_id');

async function runFieldAiAction(button, actionLabel, idleLabel, feature, extraPayload = {}) {
    if (!button || !fieldSelect || !resultWrap || !resultInput || !csrfInput) return;

    const selectedField = fieldSelect.value;
    button.disabled = true;
    button.innerHTML = `<i class="fa-solid fa-spinner fa-spin me-2"></i>${actionLabel}`;
    resultWrap.style.display = 'block';
    resultInput.value = `${actionLabel}...`;

    try {
        const fd = new FormData();
        fd.set('csrf_token',           csrfInput.value);
        fd.set('feature',              feature);
        fd.set('suggest_field',        selectedField);
        fd.set('suggest_guidance',     guidanceInput?.value ?? '');
        fd.set('suggest_case',         caseIdInput?.value ?? '');
        fd.set('context_title',        document.getElementById('ep_title')?.value ?? '');
        fd.set('context_excerpt',      document.getElementById('ep_excerpt')?.value ?? '');
        fd.set('context_body',         document.getElementById('ep_body')?.value ?? '');
        fd.set('context_keywords',     document.getElementById('ep_keywords')?.value ?? '');
        fd.set('context_video_url',    document.getElementById('ep_video_url')?.value ?? '');
        fd.set('context_duration',     document.getElementById('ep_duration')?.value ?? '');
        fd.set('context_thumbnail_image', document.getElementById('ep_thumb_url')?.value ?? '');
        Object.entries(extraPayload).forEach(([k, v]) => fd.set(k, v));

        const res  = await fetch('/api/ai_generate.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: fd,
        });
        const data = await res.json();

        if (data.ok) {
            resultInput.value = data.text ?? '';
            window.PTMDToast?.success(idleLabel + ' generated.');
        } else {
            resultInput.value = '⚠ ' + (data.error ?? idleLabel + ' failed.');
            window.PTMDToast?.error(data.error ?? idleLabel + ' failed.');
        }
    } catch {
        resultInput.value = '⚠ Network error. Please try again.';
        window.PTMDToast?.error('Network error.');
    } finally {
        button.disabled = false;
        button.innerHTML = `<i class="fa-solid fa-wand-magic-sparkles me-2"></i>${idleLabel}`;
    }
}

if (suggestBtn && fieldSelect && resultWrap && resultInput && applyBtn && csrfInput) {
    suggestBtn.addEventListener('click', async () => {
        await runFieldAiAction(suggestBtn, 'Suggesting Field', 'Suggest Field', 'case_field_suggestion');
    });

    if (optimizeBtn) {
        optimizeBtn.addEventListener('click', async () => {
            const selectedField = fieldSelect.value;
            const targetId = fieldToElementIdMap[selectedField];
            const target   = targetId ? document.getElementById(targetId) : null;
            const sourceText = target?.value ?? '';
            if (!target) {
                window.PTMDToast?.error('Target field not found.');
                return;
            }
            if (sourceText.trim() === '') {
                window.PTMDToast?.error('Add field content before optimizing.');
                return;
            }
            await runFieldAiAction(
                optimizeBtn,
                'Optimizing Field',
                'Optimize Field',
                'case_field_optimize',
                { optimize_source: sourceText }
            );
        });
    }

    applyBtn.addEventListener('click', () => {
        const selectedField = fieldSelect.value;
        const targetId = fieldToElementIdMap[selectedField];
        const target   = targetId ? document.getElementById(targetId) : null;
        if (!target) {
            window.PTMDToast?.error('Target field not found.');
            return;
        }
        target.value = resultInput.value;
        target.dispatchEvent(new Event('input', { bubbles: true }));
        window.PTMDToast?.success('Suggestion applied.');
    });
}
