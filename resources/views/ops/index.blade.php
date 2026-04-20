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
                    <div class="flex items-center gap-2">
                        <button id="refreshBtn" class="rounded-xl bg-sky-500 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-sky-400">
                            Refresh Now
                        </button>
                        <button id="refreshDiscoverBtn" class="rounded-xl bg-amber-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-amber-300">
                            Refresh Discover
                        </button>
                    </div>
                    <div class="flex items-center gap-2">
                        <button id="retryInboundBtn" class="rounded-xl bg-emerald-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-emerald-300">
                            Retry All Inbound
                        </button>
                        <button id="retryOutboundBtn" class="rounded-xl bg-orange-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-orange-300">
                            Retry All Outbound
                        </button>
                    </div>
                    <label class="inline-flex cursor-pointer items-center gap-2 text-xs text-slate-300">
                        <input id="autoRefreshToggle" type="checkbox" class="h-4 w-4 rounded border-slate-500 bg-slate-800" checked>
                        Auto refresh (8s)
                    </label>
                    <p id="actionStatus" class="mono text-[11px] text-slate-400">Actions: idle</p>
                    <p id="lastUpdated" class="mono text-[11px] text-slate-400">Last updated: -</p>
                    <p id="discoverMeta" class="mono text-[11px] text-slate-400">Discover: -</p>
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

        <section class="mt-4 grid gap-4 lg:grid-cols-2">
            <article class="glass rounded-xl p-4">
                <h2 class="text-lg font-semibold">Runtime Modems</h2>
                <p class="mt-1 text-xs text-slate-400">Live modem readiness from Python watchdog health.</p>
                <div class="table-wrap mt-3 rounded-lg border border-slate-700">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-900 text-slate-300">
                            <tr>
                                <th class="px-3 py-2">SIM ID</th>
                                <th class="px-3 py-2">Port</th>
                                <th class="px-3 py-2">Ready</th>
                                <th class="px-3 py-2">Alive/Ping/Fails</th>
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
                                <th class="px-3 py-2">Cooldown</th>
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

        <section class="mt-4">
            <article class="glass rounded-xl p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="text-lg font-semibold">Redis Queue Diagnostics</h2>
                        <p class="mt-1 text-xs text-slate-400">Per-SIM Redis queue keys, depths, and head message IDs for stuck-queue triage.</p>
                    </div>
                    <p id="redisMeta" class="mono text-[11px] text-slate-300">Redis: -</p>
                </div>
                <div class="table-wrap mt-3 rounded-lg border border-slate-700">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-900 text-slate-300">
                            <tr>
                                <th class="px-3 py-2">SIM</th>
                                <th class="px-3 py-2">Company</th>
                                <th class="px-3 py-2">IMSI</th>
                                <th class="px-3 py-2">Depth (C/F/B/T)</th>
                                <th class="px-3 py-2">Head IDs (C/F/B)</th>
                                <th class="px-3 py-2">Keys (C/F/B)</th>
                            </tr>
                        </thead>
                        <tbody id="redisQueueRows" class="divide-y divide-slate-800"></tbody>
                    </table>
                </div>
            </article>
        </section>
    </main>

    <script>
        const dataUrl = '/ops/data';
        const retryInboundUrl = '/ops/retry-all-inbound';
        const retryOutboundUrl = '/ops/retry-all-outbound';
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const refreshBtn = document.getElementById('refreshBtn');
        const refreshDiscoverBtn = document.getElementById('refreshDiscoverBtn');
        const retryInboundBtn = document.getElementById('retryInboundBtn');
        const retryOutboundBtn = document.getElementById('retryOutboundBtn');
        const autoRefreshToggle = document.getElementById('autoRefreshToggle');
        const actionStatus = document.getElementById('actionStatus');
        const lastUpdated = document.getElementById('lastUpdated');
        const discoverMeta = document.getElementById('discoverMeta');
        const redisMeta = document.getElementById('redisMeta');
        const pipelineHint = document.getElementById('pipelineHint');
        const snapshotBox = document.getElementById('snapshotBox');
        let timer = null;

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

        const setActionStatus = (message, tone = 'muted') => {
            let cls = 'mono text-[11px] text-slate-400';
            if (tone === 'ok') cls = 'mono text-[11px] text-emerald-300';
            if (tone === 'error') cls = 'mono text-[11px] text-rose-300';
            actionStatus.className = cls;
            actionStatus.textContent = `Actions: ${message}`;
        };

        const runRetryAction = async (url, button, payload, label) => {
            button.disabled = true;
            setActionStatus(`${label} requested...`);

            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify(payload || {}),
                });
                const body = await res.json();

                if (!res.ok || body?.ok !== true) {
                    const error = body?.error || body?.message || `HTTP ${res.status}`;
                    throw new Error(error);
                }

                const summaryParts = [];
                if (typeof body.reset_count === 'number') summaryParts.push(`reset=${body.reset_count}`);
                if (typeof body.dispatched === 'number') summaryParts.push(`dispatched=${body.dispatched}`);
                if (typeof body.enqueued === 'number') summaryParts.push(`enqueued=${body.enqueued}`);
                const suffix = summaryParts.length > 0 ? ` (${summaryParts.join(', ')})` : '';
                setActionStatus(`${label} done${suffix}`, 'ok');
                await refreshData();
            } catch (error) {
                setActionStatus(`${label} failed: ${error.message}`, 'error');
            } finally {
                button.disabled = false;
            }
        };

        const refreshData = async (options = {}) => {
            const forceDiscover = Boolean(options.refreshDiscover);
            refreshBtn.disabled = true;
            refreshDiscoverBtn.disabled = true;
            try {
                const url = forceDiscover ? `${dataUrl}?refresh_discover=1` : dataUrl;
                const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
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
                const runtimeMeta = payload.runtime || {};
                discoverMeta.textContent = `Discover: ${runtimeMeta.discover_refreshed ? 'fresh' : 'cached'} | cached_at=${fmtDt(runtimeMeta.discover_cached_at)}`;
                const redisSummary = payload.redis || {};
                redisMeta.textContent = `Redis: ping_ok=${redisSummary.ping_ok === true ? 'true' : 'false'} | default=${redisSummary.default_queue_depth ?? 0} | sim_total=${redisSummary.per_sim_total_depth ?? 0} | non_empty_sims=${redisSummary.non_empty_sim_queues ?? 0}`;

                const runtimeRows = (payload.runtime?.health?.modems || []).slice(0, 120).map((row) => {
                    const ready = row.send_ready ?? row.effective_send_ready ?? row.realtime_probe_ready ?? false;
                    const watchdogCell = (row.alive !== null && row.alive !== undefined)
                        ? [row.alive, row.last_ping_ok, row.consecutive_failures].map(v => {
                            if (v === null || v === undefined) return '-';
                            if (typeof v === 'boolean') return v ? '1' : '0';
                            return String(v);
                        }).join('/')
                        : [row.at_ok, row.sim_ready, row.creg_registered].map(v => v === null || v === undefined ? '-' : (v ? '1' : '0')).join('/');
                    return `<tr>
                        <td class="mono px-3 py-2">${esc(row.sim_id || '-')}</td>
                        <td class="mono px-3 py-2">${esc(row.port || '-')}</td>
                        <td class="px-3 py-2">${badge(String(Boolean(ready)))}</td>
                        <td class="px-3 py-2">${esc(watchdogCell)}</td>
                        <td class="px-3 py-2">${esc(row.readiness_reason_code || row.probe_error || '-')}</td>
                        <td class="mono px-3 py-2">${esc(fmtDt(row.last_seen_at || row.last_ping_at))}</td>
                    </tr>`;
                });
                renderRows('runtimeRows', runtimeRows, 6);

                const simRows = (payload.tables?.sims || []).slice(0, 150).map((row) => `<tr>
                    <td class="mono px-3 py-2">#${esc(row.sim_id)}</td>
                    <td class="px-3 py-2">${esc(row.company_code || row.company_id)}</td>
                    <td class="mono px-3 py-2">${esc(row.imsi || '-')}</td>
                    <td class="px-3 py-2">${badge(row.operator_status)}</td>
                    <td class="px-3 py-2">${row.cooldown_active
                        ? `<span class="inline-flex rounded-full border border-amber-500/40 bg-amber-500/20 px-2 py-0.5 text-[11px] font-semibold text-amber-300">ON (${Math.ceil((row.cooldown_remaining_seconds || 0)/60)}m)</span>`
                        : `<span class="inline-flex rounded-full border border-emerald-500/40 bg-emerald-500/20 px-2 py-0.5 text-[11px] font-semibold text-emerald-300">OFF</span>`}
                    </td>
                    <td class="px-3 py-2">${badge(String(Boolean(row.accept_new_assignments && !row.disabled_for_new_assignments)))}</td>
                    <td class="mono px-3 py-2">${esc(`${row.queue_depth?.total ?? 0} (${row.queue_depth?.chat ?? 0}/${row.queue_depth?.followup ?? 0}/${row.queue_depth?.blasting ?? 0})`)}</td>
                    <td class="px-3 py-2">${badge(String(Boolean(row.runtime?.send_ready ?? row.runtime?.effective_send_ready ?? row.runtime?.realtime_probe_ready ?? false)))}</td>
                </tr>`);
                renderRows('simRows', simRows, 8);

                const redisRows = (payload.redis?.sim_queue_rows || payload.tables?.redis_sim_queues || []).slice(0, 200).map((row) => {
                    const chatDepth = row.depth?.chat ?? 0;
                    const followDepth = row.depth?.followup ?? 0;
                    const blastDepth = row.depth?.blasting ?? 0;
                    const totalDepth = row.depth?.total ?? 0;
                    const keyChat = row.keys?.chat || '-';
                    const keyFollow = row.keys?.followup || '-';
                    const keyBlast = row.keys?.blasting || '-';
                    const shortKey = (v) => String(v).replace(/^sms:queue:sim:/, '...:');

                    return `<tr>
                        <td class="mono px-3 py-2">#${esc(row.sim_id)}</td>
                        <td class="px-3 py-2">${esc(row.company_code || row.company_id || '-')}</td>
                        <td class="mono px-3 py-2">${esc(row.imsi || '-')}</td>
                        <td class="mono px-3 py-2">${esc(`${chatDepth}/${followDepth}/${blastDepth}/${totalDepth}`)}</td>
                        <td class="mono px-3 py-2">${esc(`${row.head?.chat || '-'} / ${row.head?.followup || '-'} / ${row.head?.blasting || '-'}`)}</td>
                        <td class="mono px-3 py-2" title="${esc(`${keyChat}\n${keyFollow}\n${keyBlast}`)}">${esc(`${shortKey(keyChat)} | ${shortKey(keyFollow)} | ${shortKey(keyBlast)}`)}</td>
                    </tr>`;
                });
                renderRows('redisQueueRows', redisRows, 6);

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
                    `queue_non_empty_sims=${esc(summary.queues?.non_empty_sim_queues ?? 0)}`,
                    `queue_cooldown_active_sims=${esc(summary.queues?.cooldown_active_sims ?? 0)}`,
                    `sending_stale=${esc(summary.queues?.sending_stale_count ?? 0)}`,
                    `queued_old=${esc(summary.queues?.queued_old_count ?? 0)}`,
                    `redis_ping_ok=${esc(payload.redis?.ping_ok ?? false)}`,
                    `redis_ping_reply=${esc(payload.redis?.ping_reply || '-')}`,
                    `redis_error=${esc(payload.redis?.error || '-')}`
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
                refreshDiscoverBtn.disabled = false;
            }
        };

        const startAutoRefresh = () => {
            if (timer) clearInterval(timer);
            if (autoRefreshToggle.checked) {
                timer = setInterval(refreshData, 8000);
            }
        };

        refreshBtn.addEventListener('click', refreshData);
        refreshDiscoverBtn.addEventListener('click', () => refreshData({ refreshDiscover: true }));
        retryInboundBtn.addEventListener('click', () => runRetryAction(retryInboundUrl, retryInboundBtn, { limit: 2000 }, 'Retry inbound'));
        retryOutboundBtn.addEventListener('click', () => runRetryAction(retryOutboundUrl, retryOutboundBtn, { limit: 5000 }, 'Retry outbound'));
        autoRefreshToggle.addEventListener('change', startAutoRefresh);

        refreshData();
        startAutoRefresh();
    </script>
</body>
</html>
