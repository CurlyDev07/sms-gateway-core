<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Gateway Ops Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        body { font-family: "Space Grotesk", sans-serif; }
        .mono { font-family: "IBM Plex Mono", monospace; }
        .glass {
            background: rgba(15, 23, 42, 0.65);
            border: 1px solid rgba(148, 163, 184, 0.2);
            backdrop-filter: blur(10px);
        }
        .table-wrap { max-height: 24rem; overflow: auto; }
        .table-wrap thead th { position: sticky; top: 0; z-index: 10; }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <div class="fixed inset-0 -z-10 bg-[radial-gradient(circle_at_20%_20%,rgba(14,165,233,0.20),transparent_45%),radial-gradient(circle_at_80%_0%,rgba(249,115,22,0.15),transparent_40%),radial-gradient(circle_at_50%_100%,rgba(16,185,129,0.15),transparent_45%)]"></div>

    <main class="mx-auto max-w-[1500px] p-4 md:p-8">
        <header class="glass rounded-2xl p-5 md:p-7">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="mono text-xs uppercase tracking-[0.24em] text-sky-300">SMS Gateway Core</p>
                    <h1 class="mt-2 text-2xl font-bold md:text-4xl">Ops Pipeline Monitor</h1>
                    <p class="mt-2 max-w-2xl text-sm text-slate-300 md:text-base">
                        No-auth visual telemetry for Python Runtime -> Gateway -> ChatApp flow. If events stop here, this panel tells you exactly which layer is failing.
                    </p>
                </div>
                <div class="flex flex-col items-end gap-2">
                    <button id="refreshBtn" class="rounded-xl bg-sky-500 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-sky-400">
                        Refresh Now
                    </button>
                    <label class="inline-flex cursor-pointer items-center gap-2 text-xs text-slate-300">
                        <input id="autoRefreshToggle" type="checkbox" class="h-4 w-4 rounded border-slate-500 bg-slate-800" checked>
                        Auto refresh (8s)
                    </label>
                    <p id="lastUpdated" class="mono text-[11px] text-slate-400">Last updated: -</p>
                </div>
            </div>
        </header>

        <section class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
            <article class="glass rounded-xl p-4">
                <p class="text-xs text-slate-300">Runtime Modems</p>
                <p id="kpiModems" class="mt-2 text-2xl font-bold">-</p>
            </article>
            <article class="glass rounded-xl p-4">
                <p class="text-xs text-slate-300">Runtime Send-Ready</p>
                <p id="kpiSendReady" class="mt-2 text-2xl font-bold">-</p>
            </article>
            <article class="glass rounded-xl p-4">
                <p class="text-xs text-slate-300">Inbound (1h)</p>
                <p id="kpiInbound1h" class="mt-2 text-2xl font-bold">-</p>
            </article>
            <article class="glass rounded-xl p-4">
                <p class="text-xs text-slate-300">Outbound (1h)</p>
                <p id="kpiOutbound1h" class="mt-2 text-2xl font-bold">-</p>
            </article>
            <article class="glass rounded-xl p-4">
                <p class="text-xs text-slate-300">Relay Pending/Failed</p>
                <p id="kpiRelayIssue" class="mt-2 text-2xl font-bold">-</p>
            </article>
            <article class="glass rounded-xl p-4">
                <p class="text-xs text-slate-300">Queue Depth</p>
                <p id="kpiQueueDepth" class="mt-2 text-2xl font-bold">-</p>
            </article>
        </section>

        <section id="pipelineHint" class="mt-4 rounded-xl border px-4 py-3 text-sm">
            Loading pipeline hint...
        </section>

        <section class="mt-4">
            <article class="glass rounded-xl p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">Health Policy Settings</h2>
                        <p class="mt-1 text-xs text-slate-400">Tune SIM auto-disable/auto-enable thresholds without code changes.</p>
                    </div>
                    <button id="healthPolicySaveBtn" class="rounded-xl bg-emerald-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-emerald-300">
                        Save Policy
                    </button>
                </div>

                <p id="healthPolicyStatus" class="mt-2 text-xs text-slate-400">Policy loaded from defaults or database.</p>

                <div class="mt-3 grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                    <label class="rounded-lg border border-slate-700 bg-slate-900/60 p-3 text-xs">
                        <span class="block text-slate-300">Unhealthy Threshold Minutes</span>
                        <input id="hp_sim_health_unhealthy_threshold_minutes" type="number" min="5" max="1440" class="mono mt-2 w-full rounded-md border border-slate-600 bg-slate-950 px-2 py-1 text-sm text-slate-100">
                    </label>
                    <label class="rounded-lg border border-slate-700 bg-slate-900/60 p-3 text-xs">
                        <span class="block text-slate-300">Runtime Failure Window (min)</span>
                        <input id="hp_sim_health_runtime_failure_window_minutes" type="number" min="1" max="240" class="mono mt-2 w-full rounded-md border border-slate-600 bg-slate-950 px-2 py-1 text-sm text-slate-100">
                    </label>
                    <label class="rounded-lg border border-slate-700 bg-slate-900/60 p-3 text-xs">
                        <span class="block text-slate-300">Runtime Failure Threshold</span>
                        <input id="hp_sim_health_runtime_failure_threshold" type="number" min="1" max="20" class="mono mt-2 w-full rounded-md border border-slate-600 bg-slate-950 px-2 py-1 text-sm text-slate-100">
                    </label>
                    <label class="rounded-lg border border-slate-700 bg-slate-900/60 p-3 text-xs">
                        <span class="block text-slate-300">Runtime Suppression Minutes</span>
                        <input id="hp_sim_health_runtime_suppression_minutes" type="number" min="1" max="240" class="mono mt-2 w-full rounded-md border border-slate-600 bg-slate-950 px-2 py-1 text-sm text-slate-100">
                    </label>
                    <label class="rounded-lg border border-slate-700 bg-slate-900/60 p-3 text-xs">
                        <span class="block text-slate-300">Disable After Not-Ready Checks</span>
                        <input id="hp_runtime_sync_disable_after_not_ready_checks" type="number" min="1" max="10" class="mono mt-2 w-full rounded-md border border-slate-600 bg-slate-950 px-2 py-1 text-sm text-slate-100">
                    </label>
                    <label class="rounded-lg border border-slate-700 bg-slate-900/60 p-3 text-xs">
                        <span class="block text-slate-300">Enable After Ready Checks</span>
                        <input id="hp_runtime_sync_enable_after_ready_checks" type="number" min="1" max="10" class="mono mt-2 w-full rounded-md border border-slate-600 bg-slate-950 px-2 py-1 text-sm text-slate-100">
                    </label>
                </div>
            </article>
        </section>

        <section class="mt-4 grid gap-4 lg:grid-cols-2">
            <article class="glass rounded-xl p-4">
                <h2 class="text-lg font-semibold">Runtime Modems</h2>
                <p class="mt-1 text-xs text-slate-400">Live modem readiness from Python discovery.</p>
                <div class="table-wrap mt-3 rounded-lg border border-slate-700">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-900 text-slate-300">
                            <tr>
                                <th class="px-3 py-2">SIM ID</th>
                                <th class="px-3 py-2">Port</th>
                                <th class="px-3 py-2">Ready</th>
                                <th class="px-3 py-2">AT/SIM/CREG</th>
                                <th class="px-3 py-2">Reason</th>
                                <th class="px-3 py-2">Seen</th>
                            </tr>
                        </thead>
                        <tbody id="runtimeRows" class="divide-y divide-slate-800"></tbody>
                    </table>
                </div>
            </article>

            <article class="glass rounded-xl p-4">
                <h2 class="text-lg font-semibold">SIM Mapping + Queue</h2>
                <p class="mt-1 text-xs text-slate-400">Tenant SIM rows, assignment flags, runtime match, queue depths.</p>
                <div class="table-wrap mt-3 rounded-lg border border-slate-700">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-900 text-slate-300">
                            <tr>
                                <th class="px-3 py-2">SIM</th>
                                <th class="px-3 py-2">Company</th>
                                <th class="px-3 py-2">IMSI</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="px-3 py-2">Assignable</th>
                                <th class="px-3 py-2">Queue</th>
                                <th class="px-3 py-2">Runtime</th>
                            </tr>
                        </thead>
                        <tbody id="simRows" class="divide-y divide-slate-800"></tbody>
                    </table>
                </div>
            </article>
        </section>

        <section class="mt-4 grid gap-4 lg:grid-cols-2">
            <article class="glass rounded-xl p-4">
                <h2 class="text-lg font-semibold">Inbound + Webhook Relay</h2>
                <p class="mt-1 text-xs text-slate-400">Python inbound ingestion and ChatApp relay outcome.</p>
                <div class="table-wrap mt-3 rounded-lg border border-slate-700">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-900 text-slate-300">
                            <tr>
                                <th class="px-3 py-2">ID</th>
                                <th class="px-3 py-2">SIM</th>
                                <th class="px-3 py-2">Phone</th>
                                <th class="px-3 py-2">Relay</th>
                                <th class="px-3 py-2">Retry</th>
                                <th class="px-3 py-2">Error</th>
                                <th class="px-3 py-2">Time</th>
                            </tr>
                        </thead>
                        <tbody id="inboundRows" class="divide-y divide-slate-800"></tbody>
                    </table>
                </div>
            </article>

            <article class="glass rounded-xl p-4">
                <h2 class="text-lg font-semibold">Outbound Flow</h2>
                <p class="mt-1 text-xs text-slate-400">ChatApp-triggered outbound acceptance and send progression.</p>
                <div class="table-wrap mt-3 rounded-lg border border-slate-700">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-900 text-slate-300">
                            <tr>
                                <th class="px-3 py-2">ID</th>
                                <th class="px-3 py-2">SIM</th>
                                <th class="px-3 py-2">Phone</th>
                                <th class="px-3 py-2">Type</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="px-3 py-2">Retry</th>
                                <th class="px-3 py-2">Error</th>
                                <th class="px-3 py-2">Updated</th>
                            </tr>
                        </thead>
                        <tbody id="outboundRows" class="divide-y divide-slate-800"></tbody>
                    </table>
                </div>
            </article>
        </section>

        <section class="mt-4 grid gap-4 lg:grid-cols-2">
            <article class="glass rounded-xl p-4">
                <h2 class="text-lg font-semibold">API Client Mapping</h2>
                <p class="mt-1 text-xs text-slate-400">Token to company mapping used by ChatApp fast-path.</p>
                <div class="table-wrap mt-3 rounded-lg border border-slate-700">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-900 text-slate-300">
                            <tr>
                                <th class="px-3 py-2">ID</th>
                                <th class="px-3 py-2">Company</th>
                                <th class="px-3 py-2">Name</th>
                                <th class="px-3 py-2">API Key</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="px-3 py-2">Updated</th>
                            </tr>
                        </thead>
                        <tbody id="apiClientRows" class="divide-y divide-slate-800"></tbody>
                    </table>
                </div>
            </article>

            <article class="glass rounded-xl p-4">
                <h2 class="text-lg font-semibold">Gateway Snapshot</h2>
                <p class="mt-1 text-xs text-slate-400">Quick telemetry numbers for triage.</p>
                <div id="snapshotBox" class="mono mt-3 space-y-1 rounded-lg border border-slate-700 bg-slate-900/70 p-3 text-[11px] text-slate-300"></div>
            </article>
        </section>
    </main>

    <script>
        const dataUrl = '/ops/data';
        const healthPolicyUrl = '/ops/settings/health-policy';
        const refreshBtn = document.getElementById('refreshBtn');
        const autoRefreshToggle = document.getElementById('autoRefreshToggle');
        const lastUpdated = document.getElementById('lastUpdated');
        const pipelineHint = document.getElementById('pipelineHint');
        const snapshotBox = document.getElementById('snapshotBox');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const healthPolicySaveBtn = document.getElementById('healthPolicySaveBtn');
        const healthPolicyStatus = document.getElementById('healthPolicyStatus');
        let timer = null;

        const healthPolicyInputIds = {
            sim_health_unhealthy_threshold_minutes: 'hp_sim_health_unhealthy_threshold_minutes',
            sim_health_runtime_failure_window_minutes: 'hp_sim_health_runtime_failure_window_minutes',
            sim_health_runtime_failure_threshold: 'hp_sim_health_runtime_failure_threshold',
            sim_health_runtime_suppression_minutes: 'hp_sim_health_runtime_suppression_minutes',
            runtime_sync_disable_after_not_ready_checks: 'hp_runtime_sync_disable_after_not_ready_checks',
            runtime_sync_enable_after_ready_checks: 'hp_runtime_sync_enable_after_ready_checks',
        };

        const esc = (value) => {
            const str = value === null || value === undefined ? '' : String(value);
            return str
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        };

        const fmtDt = (iso) => {
            if (!iso) return '-';
            try { return new Date(iso).toLocaleString(); } catch (_) { return iso; }
        };

        const badge = (value) => {
            const raw = String(value || '-').toLowerCase();
            let tone = 'bg-slate-700 text-slate-100';
            if (['success', 'sent', 'ok', 'healthy', 'true'].includes(raw)) tone = 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40';
            if (['pending', 'queued', 'sending', 'warning'].includes(raw)) tone = 'bg-amber-500/20 text-amber-300 border border-amber-500/40';
            if (['failed', 'critical', 'false'].includes(raw)) tone = 'bg-rose-500/20 text-rose-300 border border-rose-500/40';
            return `<span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold ${tone}">${esc(value ?? '-')}</span>`;
        };

        const renderRows = (elId, rows, cols) => {
            const el = document.getElementById(elId);
            if (!rows || rows.length === 0) {
                el.innerHTML = `<tr><td colspan="${cols}" class="px-3 py-4 text-center text-slate-400">No rows</td></tr>`;
                return;
            }
            el.innerHTML = rows.join('');
        };

        const setPipelineHint = (hint) => {
            const severity = (hint?.severity || 'warning').toLowerCase();
            let cls = 'border-amber-500/40 bg-amber-500/10 text-amber-200';
            if (severity === 'ok') cls = 'border-emerald-500/40 bg-emerald-500/10 text-emerald-200';
            if (severity === 'critical') cls = 'border-rose-500/40 bg-rose-500/10 text-rose-200';
            pipelineHint.className = `mt-4 rounded-xl border px-4 py-3 text-sm ${cls}`;
            pipelineHint.innerHTML = `<span class="mono mr-2 text-xs uppercase">${esc(hint?.layer || 'unknown')}</span>${esc(hint?.message || 'No hint')}`;
        };

        const applyHealthPolicy = (policy) => {
            if (!policy || typeof policy !== 'object') return;

            Object.entries(healthPolicyInputIds).forEach(([key, elementId]) => {
                const el = document.getElementById(elementId);
                if (!el) return;

                if (policy[key] !== undefined && policy[key] !== null) {
                    el.value = String(policy[key]);
                }
            });
        };

        const collectHealthPolicy = () => {
            const payload = {};

            Object.entries(healthPolicyInputIds).forEach(([key, elementId]) => {
                const el = document.getElementById(elementId);
                const raw = el ? String(el.value || '').trim() : '';
                const parsed = Number.parseInt(raw, 10);
                payload[key] = Number.isFinite(parsed) ? parsed : null;
            });

            return payload;
        };

        const saveHealthPolicy = async () => {
            healthPolicySaveBtn.disabled = true;
            healthPolicyStatus.textContent = 'Saving...';
            healthPolicyStatus.className = 'mt-2 text-xs text-amber-300';

            try {
                const payload = collectHealthPolicy();
                const res = await fetch(healthPolicyUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify(payload),
                });

                const data = await res.json();

                if (!res.ok || data?.ok !== true) {
                    throw new Error(data?.error || `HTTP ${res.status}`);
                }

                applyHealthPolicy(data.health_policy || {});
                healthPolicyStatus.textContent = 'Saved. New thresholds apply on next scheduler/command run.';
                healthPolicyStatus.className = 'mt-2 text-xs text-emerald-300';
                await refreshData();
            } catch (error) {
                healthPolicyStatus.textContent = `Save failed: ${error.message}`;
                healthPolicyStatus.className = 'mt-2 text-xs text-rose-300';
            } finally {
                healthPolicySaveBtn.disabled = false;
            }
        };

        const refreshData = async () => {
            refreshBtn.disabled = true;
            try {
                const res = await fetch(dataUrl, { headers: { 'Accept': 'application/json' } });
                const payload = await res.json();
                if (!res.ok || payload.ok !== true) {
                    throw new Error(payload?.error || `HTTP ${res.status}`);
                }

                const summary = payload.summary || {};
                document.getElementById('kpiModems').textContent = summary.runtime?.modems_total ?? '-';
                document.getElementById('kpiSendReady').textContent = summary.runtime?.send_ready_total ?? '-';
                document.getElementById('kpiInbound1h').textContent = summary.inbound_1h?.total ?? '-';
                document.getElementById('kpiOutbound1h').textContent = summary.outbound_1h?.total ?? '-';
                document.getElementById('kpiRelayIssue').textContent = `${summary.relay?.pending_total ?? 0}/${summary.relay?.failed_total ?? 0}`;
                document.getElementById('kpiQueueDepth').textContent = summary.queues?.per_sim_total_depth ?? '-';

                setPipelineHint(payload.pipeline_hint || null);
                applyHealthPolicy(payload.settings?.health_policy || {});

                const runtimeRows = (payload.runtime?.discovery?.modems || []).slice(0, 120).map((row) => {
                    const ready = row.effective_send_ready ?? row.realtime_probe_ready ?? row.send_ready ?? false;
                    return `<tr>
                        <td class="mono px-3 py-2">${esc(row.sim_id || '-')}</td>
                        <td class="mono px-3 py-2">${esc(row.port || '-')}</td>
                        <td class="px-3 py-2">${badge(String(Boolean(ready)))}</td>
                        <td class="px-3 py-2">${esc([row.at_ok, row.sim_ready, row.creg_registered].map(v => v === null || v === undefined ? '-' : (v ? '1' : '0')).join('/'))}</td>
                        <td class="px-3 py-2">${esc(row.readiness_reason_code || row.probe_error || '-')}</td>
                        <td class="mono px-3 py-2">${esc(fmtDt(row.last_seen_at))}</td>
                    </tr>`;
                });
                renderRows('runtimeRows', runtimeRows, 6);

                const simRows = (payload.tables?.sims || []).slice(0, 150).map((row) => `<tr>
                    <td class="mono px-3 py-2">#${esc(row.sim_id)}</td>
                    <td class="px-3 py-2">${esc(row.company_code || row.company_id)}</td>
                    <td class="mono px-3 py-2">${esc(row.imsi || '-')}</td>
                    <td class="px-3 py-2">${badge(row.operator_status)}</td>
                    <td class="px-3 py-2">${badge(String(Boolean(row.accept_new_assignments && !row.disabled_for_new_assignments)))}</td>
                    <td class="mono px-3 py-2">${esc(`${row.queue_depth?.total ?? 0} (${row.queue_depth?.chat ?? 0}/${row.queue_depth?.followup ?? 0}/${row.queue_depth?.blasting ?? 0})`)}</td>
                    <td class="px-3 py-2">${badge(String(Boolean(row.runtime?.effective_send_ready ?? row.runtime?.realtime_probe_ready ?? false)))}</td>
                </tr>`);
                renderRows('simRows', simRows, 7);

                const inboundRows = (payload.tables?.inbound_recent || []).slice(0, 120).map((row) => `<tr>
                    <td class="mono px-3 py-2">#${esc(row.id)}</td>
                    <td class="mono px-3 py-2">${esc(row.runtime_sim_id || row.sim_id)}</td>
                    <td class="mono px-3 py-2">${esc(row.customer_phone)}</td>
                    <td class="px-3 py-2">${badge(row.relay_status)}</td>
                    <td class="mono px-3 py-2">${esc(row.relay_retry_count)}</td>
                    <td class="px-3 py-2 max-w-[16rem] truncate" title="${esc(row.relay_error || '')}">${esc(row.relay_error || '-')}</td>
                    <td class="mono px-3 py-2">${esc(fmtDt(row.created_at))}</td>
                </tr>`);
                renderRows('inboundRows', inboundRows, 7);

                const outboundRows = (payload.tables?.outbound_recent || []).slice(0, 120).map((row) => `<tr>
                    <td class="mono px-3 py-2">#${esc(row.id)}</td>
                    <td class="mono px-3 py-2">${esc(row.sim_id)}</td>
                    <td class="mono px-3 py-2">${esc(row.customer_phone)}</td>
                    <td class="px-3 py-2">${esc(row.message_type)}</td>
                    <td class="px-3 py-2">${badge(row.status)}</td>
                    <td class="mono px-3 py-2">${esc(row.retry_count)}</td>
                    <td class="px-3 py-2 max-w-[14rem] truncate" title="${esc(row.failure_reason || '')}">${esc(row.failure_reason || '-')}</td>
                    <td class="mono px-3 py-2">${esc(fmtDt(row.updated_at))}</td>
                </tr>`);
                renderRows('outboundRows', outboundRows, 8);

                const apiRows = (payload.tables?.api_clients || []).slice(0, 100).map((row) => `<tr>
                    <td class="mono px-3 py-2">#${esc(row.id)}</td>
                    <td class="px-3 py-2">${esc(row.company_code || row.company_id || '-')}</td>
                    <td class="px-3 py-2">${esc(row.name)}</td>
                    <td class="mono px-3 py-2">${esc(row.api_key)}</td>
                    <td class="px-3 py-2">${badge(row.status)}</td>
                    <td class="mono px-3 py-2">${esc(fmtDt(row.updated_at))}</td>
                </tr>`);
                renderRows('apiClientRows', apiRows, 6);

                snapshotBox.innerHTML = [
                    `generated_at=${esc(payload.generated_at)}`,
                    `runtime_health_ok=${esc(summary.runtime?.health_ok)}`,
                    `runtime_discover_ok=${esc(summary.runtime?.discover_ok)}`,
                    `runtime_error=${esc(summary.runtime?.discover_error || summary.runtime?.health_error || '-')}`,
                    `inbound_1h_success/pending/failed=${esc(summary.inbound_1h?.success ?? 0)}/${esc(summary.inbound_1h?.pending ?? 0)}/${esc(summary.inbound_1h?.failed ?? 0)}`,
                    `outbound_1h_sent/queued/sending/pending/failed=${esc(summary.outbound_1h?.sent ?? 0)}/${esc(summary.outbound_1h?.queued ?? 0)}/${esc(summary.outbound_1h?.sending ?? 0)}/${esc(summary.outbound_1h?.pending ?? 0)}/${esc(summary.outbound_1h?.failed ?? 0)}`,
                    `queue_default=${esc(summary.queues?.default_depth ?? 0)}`,
                    `queue_per_sim_total=${esc(summary.queues?.per_sim_total_depth ?? 0)}`,
                    `sending_stale=${esc(summary.queues?.sending_stale_count ?? 0)}`,
                    `queued_old=${esc(summary.queues?.queued_old_count ?? 0)}`
                ].map((line) => `<div>${line}</div>`).join('');

                lastUpdated.textContent = `Last updated: ${new Date().toLocaleString()}`;
            } catch (error) {
                setPipelineHint({
                    layer: 'ops_panel',
                    severity: 'critical',
                    message: `Failed to load ops data: ${error.message}`
                });
            } finally {
                refreshBtn.disabled = false;
            }
        };

        const startAutoRefresh = () => {
            if (timer) clearInterval(timer);
            if (autoRefreshToggle.checked) {
                timer = setInterval(refreshData, 8000);
            }
        };

        refreshBtn.addEventListener('click', refreshData);
        autoRefreshToggle.addEventListener('change', startAutoRefresh);
        healthPolicySaveBtn.addEventListener('click', saveHealthPolicy);

        refreshData();
        startAutoRefresh();
    </script>
</body>
</html>
