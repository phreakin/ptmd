/**
 * PTMD Admin — Trend Intake JS
 * ES Module, vanilla JS, no jQuery
 */
'use strict';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

// Live range value display
document.querySelectorAll('.trend-range').forEach(input => {
    const valEl = document.getElementById('val_' + input.name);
    if (valEl) {
        input.addEventListener('input', () => { valEl.textContent = input.value; });
    }
});

// Ingest Signal Form
const ingestForm  = document.getElementById('ingestSignalForm');
const ingestBtn   = document.getElementById('ingestSubmitBtn');

if (ingestForm) {
    ingestForm.addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(ingestForm);
        fd.set('action', 'ingest');
        fd.set('csrf_token', csrf());
        ingestBtn.disabled = true;
        ingestBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Ingesting…';
        try {
            const res  = await fetch('/api/v1/trends.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                Swal.fire({
                    icon: 'success',
                    title: 'Signal Ingested',
                    text: data.message ?? 'Signal has been processed.',
                    timer: 2000,
                    showConfirmButton: false,
                });
                ingestForm.reset();
                document.querySelectorAll('.trend-range').forEach(r => {
                    r.value = 50;
                    const v = document.getElementById('val_' + r.name);
                    if (v) v.textContent = '50';
                });
                await refreshClusters();
            } else {
                Swal.fire({ icon: 'error', title: 'Ingest Failed', text: data.error ?? 'Unknown error.' });
            }
        } catch (err) {
            Swal.fire({ icon: 'error', title: 'Request Failed', text: err.message });
        } finally {
            ingestBtn.disabled = false;
            ingestBtn.innerHTML = '<i class="fa-solid fa-satellite-dish me-2"></i>Ingest Signal';
        }
    });
}

// Promote to Case buttons (delegated)
document.addEventListener('click', async e => {
    const btn = e.target.closest('.promote-cluster-btn');
    if (!btn) return;
    const clusterId = btn.dataset.clusterId;
    const label     = btn.dataset.label || '#' + clusterId;
    const confirmed = await Swal.fire({
        title: 'Promote to Case?',
        html: `Promote cluster <strong>${Swal.escapeHtml(label)}</strong> to a new case?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Promote',
        cancelButtonText: 'Cancel',
    });
    if (!confirmed.isConfirmed) return;
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.set('action', 'promote');
        fd.set('cluster_id', clusterId);
        fd.set('csrf_token', csrf());
        const res  = await fetch('/api/v1/trends.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            Swal.fire({
                icon: 'success',
                title: 'Promoted!',
                text: data.message ?? 'Case created.',
                timer: 2000,
                showConfirmButton: false,
            });
            await refreshClusters();
        } else {
            Swal.fire({ icon: 'error', title: 'Failed', text: data.error ?? 'Unknown error.' });
            btn.disabled = false;
        }
    } catch (err) {
        Swal.fire({ icon: 'error', title: 'Error', text: err.message });
        btn.disabled = false;
    }
});

// Refresh clusters button
const refreshBtn = document.getElementById('refreshClustersBtn');
if (refreshBtn) {
    refreshBtn.addEventListener('click', () => refreshClusters());
}

async function refreshClusters() {
    const wrap = document.getElementById('clustersTableWrap');
    if (!wrap) return;
    try {
        const res  = await fetch('/api/v1/trends.php?action=list_clusters');
        const data = await res.json();
        if (data.ok && data.html) {
            wrap.innerHTML = data.html;
        }
    } catch (_err) {
        // silent fail — table remains as-is
    }
}
