@extends('dashboard.layouts.app')

@section('title', 'Python Runtime')
@section('page_title', 'Python Runtime')
@section('page_heading', 'Python Runtime')

@push('styles')
<style>
    .muted {
        color: #6b7280;
        margin-bottom: 16px;
    }

    .controls {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 16px;
        align-items: end;
    }

    button {
        padding: 9px 14px;
        border: 1px solid #111827;
        background: #111827;
        color: #ffffff;
        border-radius: 4px;
        cursor: pointer;
    }

    .status {
        margin: 8px 0 14px 0;
        min-height: 20px;
        font-size: 14px;
    }

    .ok {
        color: #065f46;
    }

    .error {
        color: #b91c1c;
    }

    .cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 12px;
        margin-bottom: 12px;
    }

    .card {
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        padding: 12px;
        background: #ffffff;
    }

    .card h2 {
        margin: 0 0 8px 0;
        font-size: 16px;
    }

    .kv {
        display: grid;
        grid-template-columns: 140px 1fr;
        gap: 6px 10px;
        font-size: 13px;
    }

    .kv strong {
        color: #374151;
    }

    .table-wrap {
        overflow-x: auto;
        border: 1px solid #e5e7eb;
    }

    table {
        border-collapse: collapse;
        width: 100%;
        min-width: 1100px;
        font-size: 13px;
    }

    th, td {
        border-bottom: 1px solid #e5e7eb;
        text-align: left;
        padding: 8px 10px;
        vertical-align: top;
    }

    thead th {
        background: #f9fafb;
        position: sticky;
        top: 0;
        z-index: 1;
    }
</style>
@endpush

@section('content')
<p class="muted">
    Runtime integration visibility for Python health + modem discovery using
    <code>GET /dashboard/api/runtime/python</code>. Discovery rows are tenant-filtered by IMSI mapping.
</p>

<div class="controls">
    <button id="refreshButton" type="button">Check Python Runtime</button>
</div>

<div id="status" class="status muted">No runtime data loaded yet.</div>

<div class="cards">
    <section class="card">
        <h2>Health</h2>
        <div class="kv">
            <strong>Reachable</strong>
            <span id="healthReachable">-</span>
            <strong>HTTP Status</strong>
            <span id="healthHttpStatus">-</span>
            <strong>Error</strong>
            <span id="healthError">-</span>
            <strong>Payload</strong>
            <span id="healthPayload">-</span>
        </div>
    </section>

    <section class="card">
        <h2>Discovery</h2>
        <div class="kv">
            <strong>Reachable</strong>
            <span id="discoveryReachable">-</span>
            <strong>HTTP Status</strong>
            <span id="discoveryHttpStatus">-</span>
            <strong>Error</strong>
            <span id="discoveryError">-</span>
            <strong>Discovered Total</strong>
            <span id="discoveredTotal">-</span>
            <strong>Tenant Visible</strong>
            <span id="tenantVisibleTotal">-</span>
            <strong>Tenant IMSI Mapped</strong>
            <span id="tenantImsiMapped">-</span>
        </div>
    </section>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Device ID</th>
                <th>SIM ID / IMSI</th>
                <th>Port</th>
                <th>AT OK</th>
                <th>SIM Ready</th>
                <th>CREG Registered</th>
                <th>Signal</th>
                <th>Last Seen At</th>
            </tr>
        </thead>
        <tbody id="modemRows">
            <tr>
                <td colspan="8" class="muted">No modem rows loaded.</td>
            </tr>
        </tbody>
    </table>
</div>

@push('scripts')
<script>
    (() => {
        const apiPath = '/dashboard/api/runtime/python';
        const refreshButton = document.getElementById('refreshButton');
        const statusEl = document.getElementById('status');
        const modemRowsEl = document.getElementById('modemRows');

        const healthReachableEl = document.getElementById('healthReachable');
        const healthHttpStatusEl = document.getElementById('healthHttpStatus');
        const healthErrorEl = document.getElementById('healthError');
        const healthPayloadEl = document.getElementById('healthPayload');

        const discoveryReachableEl = document.getElementById('discoveryReachable');
        const discoveryHttpStatusEl = document.getElementById('discoveryHttpStatus');
        const discoveryErrorEl = document.getElementById('discoveryError');
        const discoveredTotalEl = document.getElementById('discoveredTotal');
        const tenantVisibleTotalEl = document.getElementById('tenantVisibleTotal');
        const tenantImsiMappedEl = document.getElementById('tenantImsiMapped');

        const escapeHtml = (value) => {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        };

        const asText = (value) => {
            if (value === null || value === undefined || value === '') {
                return '-';
            }

            if (typeof value === 'object') {
                return JSON.stringify(value);
            }

            return String(value);
        };

        const boolText = (value) => {
            if (value === null || value === undefined) {
                return '-';
            }

            return value ? 'true' : 'false';
        };

        const setStatus = (text, type = 'muted') => {
            statusEl.className = `status ${type}`;
            statusEl.textContent = text;
        };

        const renderSummary = (payload) => {
            const health = payload.health || {};
            const discovery = payload.discovery || {};

            healthReachableEl.textContent = boolText(health.ok);
            healthHttpStatusEl.textContent = asText(health.status);
            healthErrorEl.textContent = asText(health.error);
            healthPayloadEl.textContent = asText(health.data);

            discoveryReachableEl.textContent = boolText(discovery.ok);
            discoveryHttpStatusEl.textContent = asText(discovery.status);
            discoveryErrorEl.textContent = asText(discovery.error);
            discoveredTotalEl.textContent = asText(discovery.discovered_total);
            tenantVisibleTotalEl.textContent = asText(discovery.tenant_visible_total);
            tenantImsiMappedEl.textContent = asText(discovery.tenant_imsi_mapped);
        };

        const renderModems = (modems) => {
            if (!Array.isArray(modems) || modems.length === 0) {
                modemRowsEl.innerHTML = '<tr><td colspan="8" class="muted">No tenant-visible modem rows found.</td></tr>';
                return;
            }

            modemRowsEl.innerHTML = modems.map((modem) => `
                <tr>
                    <td>${escapeHtml(asText(modem.device_id))}</td>
                    <td>${escapeHtml(asText(modem.sim_id))}</td>
                    <td>${escapeHtml(asText(modem.port))}</td>
                    <td>${escapeHtml(boolText(modem.at_ok))}</td>
                    <td>${escapeHtml(boolText(modem.sim_ready))}</td>
                    <td>${escapeHtml(boolText(modem.creg_registered))}</td>
                    <td>${escapeHtml(asText(modem.signal))}</td>
                    <td>${escapeHtml(asText(modem.last_seen_at))}</td>
                </tr>
            `).join('');
        };

        refreshButton.addEventListener('click', async () => {
            setStatus('Checking Python runtime...', 'muted');

            try {
                const response = await fetch(apiPath, {
                    method: 'GET',
                    headers: {
                        Accept: 'application/json'
                    }
                });

                let payload = null;
                try {
                    payload = await response.json();
                } catch (_) {
                    payload = null;
                }

                if (!response.ok || payload === null) {
                    setStatus(`Runtime check failed: HTTP ${response.status}`, 'error');
                    return;
                }

                renderSummary(payload);
                renderModems(payload.discovery && payload.discovery.modems ? payload.discovery.modems : []);

                if (payload.ok === true) {
                    setStatus('Python runtime reachable; discovery loaded successfully.', 'ok');
                } else {
                    setStatus('Python runtime check completed with partial/failed result. See details below.', 'error');
                }
            } catch (error) {
                setStatus(`Runtime check failed: ${error.message}`, 'error');
            }
        });
    })();
</script>
@endpush
@endsection
