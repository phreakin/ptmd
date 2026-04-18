/**
 * PTMD Admin — Optimizer Engine JS
 * ES Module, vanilla JS, no jQuery
 */
'use strict';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

// ── DOM refs ──────────────────────────────────────────────────────────────────
const runForm         = document.getElementById('optimizerRunForm');
const runBtn          = document.getElementById('optimizerRunBtn');
const targetType      = document.getElementById('optimizerTargetType');
const targetId        = document.getElementById('optimizerTargetId');
const resultsPanel    = document.getElementById('optimizerResults');
const scoreEl         = document.getElementById('optimizerScore');
const resultBadges    = document.getElementById('optimizerResultBadges');
const metaBadges      = document.getElementById('optimizerMetaBadges');
const factorsTbody    = document.getElementById('optimizerFactorsTbody');
const variantsRow     = document.getElementById('optimizerVariantsRow');
const explainBtn      = document.getElementById('optimizerExplainBtn');
const explainBody     = document.getElementById('explainModalBody');

let currentRunId = null;

// ── Target type → load targets dynamically ────────────────────────────────────
if (targetType) {
    targetType.addEventListener('change', async () => {
        const type = targetType.value;
        targetId.disabled = true;
        targetId.innerHTML = '<option value="">Loading…</option>';
        if (!type) {
            targetId.innerHTML = '<option value="">— Select target type first —</option>';
            return;
        }
        try {
            const url  = type === 'case'
                ? '/api/v1/cases.php?action=list&status=all'
                : '/api/v1/clips.php?action=list';
            const res  = await fetch(url);
            const data = await res.json();
            const items = data.items ?? data.cases ?? data.clips ?? [];
            targetId.innerHTML = '<option value="">— Select Target —</option>';
            items.forEach(item => {
                const opt = document.createElement('option');
                opt.value       = item.id;
                opt.textContent = item.title ?? ('#' + item.id);
                targetId.appendChild(opt);
            });
            targetId.disabled = false;
        } catch (_err) {
            targetId.innerHTML = '<option value="">Failed to load — try again</option>';
        }
    });
}

// ── Run form submit ───────────────────────────────────────────────────────────
if (runForm) {
    runForm.addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(runForm);
        if (!fd.get('target_type') || !fd.get('target_id')) {
            Swal.fire({ icon: 'warning', title: 'Select Target', text: 'Please select a target type and target.' });
            return;
        }
        fd.set('action', 'run');
        fd.set('csrf_token', csrf());
        runBtn.disabled = true;
        runBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Running…';
        try {
            const res  = await fetch('/api/v1/optimizer.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok && data.run) {
                currentRunId = data.run.id;
                renderResults(data.run);
                resultsPanel.style.display = '';
                resultsPanel.scrollIntoView({ behavior: 'smooth' });
            } else {
                Swal.fire({ icon: 'error', title: 'Optimizer Failed', text: data.error ?? 'Unknown error.' });
            }
        } catch (err) {
            Swal.fire({ icon: 'error', title: 'Request Failed', text: err.message });
        } finally {
            runBtn.disabled = false;
            runBtn.innerHTML = '<i class="fa-solid fa-bolt me-2"></i>Run Optimizer';
        }
    });
}

// ── View run buttons (delegated) ──────────────────────────────────────────────
document.addEventListener('click', async e => {
    const btn = e.target.closest('.view-run-btn');
    if (!btn) return;
    const runId = btn.dataset.runId;
    btn.disabled = true;
    try {
        const res  = await fetch(`/api/v1/optimizer.php?action=get&run_id=${runId}`);
        const data = await res.json();
        if (data.ok && data.run) {
            currentRunId = data.run.id;
            renderResults(data.run);
            resultsPanel.style.display = '';
            resultsPanel.scrollIntoView({ behavior: 'smooth' });
        } else {
            Swal.fire({ icon: 'error', title: 'Load Failed', text: data.error ?? 'Unknown error.' });
        }
    } catch (err) {
        Swal.fire({ icon: 'error', title: 'Error', text: err.message });
    } finally {
        btn.disabled = false;
    }
});

// ── Render results ────────────────────────────────────────────────────────────
function renderResults(run) {
    const score     = parseFloat(run.score ?? 0);
    const scoreColor = score >= 75 ? 'var(--bs-success)' : score >= 50 ? 'var(--bs-warning)' : 'var(--bs-danger)';
    scoreEl.textContent  = score.toFixed(1);
    scoreEl.style.color  = scoreColor;

    // Decision + confidence badges
    const dec    = run.decision ?? '';
    const decBg  = dec === 'accept' ? 'bg-success' : dec === 'reject' ? 'bg-danger' : 'bg-warning text-dark';
    const conf   = parseFloat(run.confidence_score ?? 0).toFixed(1);
    resultBadges.innerHTML = `
        <span class="badge ${esc(decBg)} me-1" style="font-size:0.85rem">${esc(dec)}</span>
        <span class="badge bg-info text-dark" style="font-size:0.85rem">${esc(conf)}% conf</span>
    `;

    // Meta badges
    metaBadges.innerHTML = `
        <span class="badge bg-secondary">${esc(run.target_type ?? '')}</span>
        <span class="badge bg-secondary">#${esc(String(run.target_id ?? ''))}</span>
        ${run.platform ? `<span class="badge bg-info text-dark">${esc(run.platform)}</span>` : ''}
    `;

    // Factors table
    const factors = run.factors ?? run.score_factors ?? [];
    if (factorsTbody) {
        if (!factors.length) {
            factorsTbody.innerHTML = '<tr><td colspan="3" class="ptmd-muted small">No factors available.</td></tr>';
        } else {
            factorsTbody.innerHTML = factors.map(f => {
                const val   = parseFloat(f.contribution ?? f.value ?? 0);
                const pct   = Math.min(100, Math.abs(val));
                const barBg = val >= 0 ? 'bg-success' : 'bg-danger';
                return `<tr>
                    <td class="ptmd-muted small">${esc(f.name ?? f.factor ?? '—')}</td>
                    <td>${esc(val.toFixed(2))}</td>
                    <td style="min-width:100px">
                        <div class="progress" style="height:6px">
                            <div class="progress-bar ${esc(barBg)}" style="width:${pct}%"></div>
                        </div>
                    </td>
                </tr>`;
            }).join('');
        }
    }

    // Variants
    const variants = run.variants ?? [];
    if (variantsRow) {
        if (!variants.length) {
            variantsRow.innerHTML = '<div class="col-12"><p class="ptmd-muted small">No variants generated.</p></div>';
        } else {
            variantsRow.innerHTML = variants.map(v => `
                <div class="col-md-6 col-lg-4">
                    <div class="ptmd-panel p-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="badge bg-secondary">${esc(v.variant_type ?? v.type ?? 'variant')}</span>
                            <span class="badge bg-info text-dark">${esc(String(v.score ?? ''))}</span>
                        </div>
                        <p class="small mb-3" style="line-height:1.6">${esc(v.content_text ?? v.text ?? '—')}</p>
                        <div class="d-flex gap-2">
                            <button class="btn btn-ptmd-primary btn-sm review-variant-btn flex-grow-1"
                                    data-variant-id="${esc(String(v.id ?? ''))}"
                                    data-decision="accept">
                                <i class="fa-solid fa-check me-1"></i>Accept
                            </button>
                            <button class="btn btn-ptmd-danger btn-sm review-variant-btn flex-grow-1"
                                    data-variant-id="${esc(String(v.id ?? ''))}"
                                    data-decision="reject">
                                <i class="fa-solid fa-xmark me-1"></i>Reject
                            </button>
                        </div>
                    </div>
                </div>
            `).join('');
        }
    }
}

// ── Review variant buttons (delegated) ───────────────────────────────────────
document.addEventListener('click', async e => {
    const btn = e.target.closest('.review-variant-btn');
    if (!btn) return;
    const variantId = btn.dataset.variantId;
    const decision  = btn.dataset.decision;
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.set('action', 'review_variant');
        fd.set('variant_id', variantId);
        fd.set('decision', decision);
        fd.set('run_id', String(currentRunId ?? ''));
        fd.set('csrf_token', csrf());
        const res  = await fetch('/api/v1/optimizer.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            Swal.fire({
                icon: 'success',
                title: decision === 'accept' ? 'Accepted!' : 'Rejected',
                timer: 1500,
                showConfirmButton: false,
            });
            btn.closest('.col-md-6, .col-lg-4')?.classList.add('opacity-50');
        } else {
            Swal.fire({ icon: 'error', title: 'Failed', text: data.error ?? 'Unknown error.' });
            btn.disabled = false;
        }
    } catch (err) {
        Swal.fire({ icon: 'error', title: 'Error', text: err.message });
        btn.disabled = false;
    }
});

// ── Explain Decision ──────────────────────────────────────────────────────────
if (explainBtn) {
    explainBtn.addEventListener('click', async () => {
        if (!currentRunId) return;
        explainBody.innerHTML = '<p class="ptmd-muted">Loading explanation…</p>';
        const modal = new bootstrap.Modal(document.getElementById('explainModal'));
        modal.show();
        try {
            const res  = await fetch(`/api/v1/optimizer.php?action=explain&run_id=${currentRunId}`);
            const data = await res.json();
            if (data.ok && data.explanation) {
                explainBody.innerHTML = `<p style="line-height:1.8;white-space:pre-wrap">${esc(data.explanation)}</p>`;
            } else {
                explainBody.innerHTML = `<p class="text-danger">${esc(data.error ?? 'No explanation available.')}</p>`;
            }
        } catch (err) {
            explainBody.innerHTML = `<p class="text-danger">${esc(err.message)}</p>`;
        }
    });
}
