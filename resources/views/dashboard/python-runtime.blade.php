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

    .warn {
        color: #b45309;
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
        margin-top: 12px;
    }

    .runtime-row-healthy {
        background: #ecfdf5;
    }

    .runtime-row-warning {
        background: #fffbeb;
    }

    .runtime-row-error {
        background: #fef2f2;
    }

    .mini-button {
        padding: 5px 8px;
        font-size: 12px;
        border-radius: 4px;
        margin-right: 6px;
        border: 1px solid #374151;
        background: #ffffff;
        color: #111827;
        cursor: pointer;
    }

    .mini-button:disabled {
        cursor: not-allowed;
        opacity: 0.5;
    }

    .send-ready-badge {
        display: inline-block;
        margin-top: 6px;
        padding: 2px 6px;
        border-radius: 999px;
        font-size: 11px;
        background: #dcfce7;
        color: #166534;
    }

    .send-blocked-badge {
        display: inline-block;
        margin-top: 6px;
        padding: 2px 6px;
        border-radius: 999px;
        font-size: 11px;
        background: #fee2e2;
        color: #991b1b;
    }

    .send-disabled-reason {
        margin-top: 6px;
        font-size: 11px;
        color: #7f1d1d;
    }

    .snapshot-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 8px;
        margin-top: 8px;
    }

    .snapshot-item {
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        padding: 8px;
        background: #f9fafb;
    }

    .snapshot-item strong {
        display: block;
        color: #374151;
        font-size: 12px;
        margin-bottom: 3px;
    }

    .snapshot-item span {
        font-size: 18px;
        font-weight: 600;
    }

    .state-badge {
        display: inline-block;
        border-radius: 999px;
        padding: 2px 6px;
        font-size: 11px;
    }

    .state-good {
        background: #dcfce7;
        color: #166534;
    }

    .state-warn {
        background: #ffedd5;
        color: #9a3412;
    }

    .state-danger {
        background: #fee2e2;
        color: #991b1b;
    }

    .safety-badge {
        display: inline-block;
        border-radius: 999px;
        padding: 2px 8px;
        font-size: 11px;
        margin-bottom: 4px;
    }

    .safety-strong {
        background: #dcfce7;
        color: #166534;
    }

    .safety-caution {
        background: #ffedd5;
        color: #9a3412;
    }

    .safety-visible {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .safety-degraded {
        background: #fee2e2;
        color: #991b1b;
    }

    .safety-note {
        font-size: 11px;
        color: #374151;
        margin-top: 3px;
    }

    .row-filters {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
        margin-top: 10px;
        margin-bottom: 8px;
    }

    .row-filters strong {
        font-size: 12px;
        color: #374151;
    }

    button.filter-chip {
        padding: 4px 8px;
        border: 1px solid #d1d5db;
        background: #ffffff;
        color: #111827;
        font-size: 12px;
    }

    button.filter-chip.active {
        border-color: #111827;
        background: #111827;
        color: #ffffff;
    }

    .diagnostics-panel {
        margin-bottom: 10px;
    }

    .diagnostics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 8px;
        margin-top: 8px;
    }

    .diagnostics-item {
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        background: #f9fafb;
        padding: 8px;
    }

    .diagnostics-item strong {
        display: block;
        font-size: 12px;
        color: #374151;
        margin-bottom: 4px;
    }

    .diagnostics-item span {
        font-size: 13px;
        color: #111827;
        word-break: break-word;
    }

    .diagnostics-raw {
        margin-top: 10px;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        background: #f9fafb;
        padding: 8px;
    }

    .diagnostics-raw pre {
        margin: 6px 0 0 0;
        white-space: pre-wrap;
        word-break: break-word;
        font-size: 12px;
    }

    .send-panel {
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        padding: 12px;
        margin-top: 12px;
    }

    .send-panel h2 {
        margin: 0 0 10px 0;
        font-size: 16px;
    }

    .send-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 10px;
    }

    .send-grid label {
        display: flex;
        flex-direction: column;
        gap: 4px;
        font-size: 13px;
    }

    .send-grid input,
    .send-grid textarea {
        padding: 8px;
        border: 1px solid #d1d5db;
        border-radius: 4px;
        font-family: inherit;
        font-size: 13px;
    }

    .send-grid textarea {
        min-height: 80px;
        resize: vertical;
    }

    .send-actions {
        margin-top: 10px;
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .send-result {
        margin-top: 8px;
        font-size: 13px;
        min-height: 18px;
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
    <code>GET /dashboard/api/runtime/python</code>. The table below shows the full Python discovery list.
</p>
<p class="muted">
    Note: Current <code>sim_id</code> may be a fallback device identifier, not a confirmed telecom SIM ID.
</p>
<p class="muted">
    Send test uses <strong>Tenant SIM DB ID</strong> (Laravel <code>sims.id</code>), not runtime SIM ID.
</p>
<p class="muted">
    Safety guide: <strong>Strongest usable</strong> means mapped + IMSI-backed + send-ready; fallback IDs are lower-trust; unmapped rows are runtime-visible only; probe/runtime issues are marked degraded.
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

    <section class="card">
        <h2>Fleet Snapshot</h2>
        <div class="snapshot-grid">
            <div class="snapshot-item">
                <strong>Total Discovered</strong>
                <span id="fleetTotal">-</span>
            </div>
            <div class="snapshot-item">
                <strong>Mapped</strong>
                <span id="fleetMapped">-</span>
            </div>
            <div class="snapshot-item">
                <strong>Unmapped</strong>
                <span id="fleetUnmapped">-</span>
            </div>
            <div class="snapshot-item">
                <strong>Send-Ready</strong>
                <span id="fleetSendReady">-</span>
            </div>
            <div class="snapshot-item">
                <strong>Probe Errors</strong>
                <span id="fleetProbeError">-</span>
            </div>
            <div class="snapshot-item">
                <strong>Fallback IDs</strong>
                <span id="fleetFallback">-</span>
            </div>
        </div>
    </section>
</div>

<section class="send-panel">
    <h2>Direct Runtime Send Test</h2>
    <p class="muted">
        Controlled verification path using <code>POST /dashboard/api/runtime/python/send-test</code>.
        This directly calls Python send and persists the result on one outbound row.
    </p>
    <p class="muted">
        Tip: If send test fails with <code>error_layer=network</code> and <code>error=SEND_FAILED</code>,
        check SIM load/balance and carrier service state.
    </p>

    <div class="send-grid">
        <label>
            Tenant SIM DB ID
            <input id="sendSimId" type="number" min="1" placeholder="e.g. 1">
        </label>
        <label>
            Customer Phone
            <input id="sendCustomerPhone" type="text" placeholder="e.g. 09171234567">
        </label>
        <label>
            Client Message ID (optional)
            <input id="sendClientMessageId" type="text" placeholder="e.g. runtime-test-001">
        </label>
        <label style="grid-column: 1 / -1;">
            Message
            <textarea id="sendMessage" placeholder="Type a test SMS message"></textarea>
        </label>
    </div>

    <div class="send-actions">
        <button id="sendTestButton" type="button">Send Runtime Test SMS</button>
    </div>

    <div id="sendStatus" class="send-result muted">No send test executed yet.</div>
</section>

<div class="row-filters" id="runtimeRowFilters">
    <strong>Quick Filters</strong>
    <button type="button" class="filter-chip active" data-filter="all" aria-pressed="true">All</button>
    <button type="button" class="filter-chip" data-filter="mapped" aria-pressed="false">Mapped</button>
    <button type="button" class="filter-chip" data-filter="unmapped" aria-pressed="false">Unmapped</button>
    <button type="button" class="filter-chip" data-filter="send_ready" aria-pressed="false">Send Ready</button>
    <button type="button" class="filter-chip" data-filter="probe_error" aria-pressed="false">Probe Error</button>
    <button type="button" class="filter-chip" data-filter="fallback" aria-pressed="false">Fallback</button>
</div>

<div id="runtimeFilterSummary" class="muted">Showing all rows.</div>

<section class="card diagnostics-panel">
    <h2>Row Diagnostics</h2>
    <p class="muted">
        Select a row and click <strong>View Details</strong> to inspect deeper runtime facts without changing send-test behavior.
    </p>
    <div id="runtimeDetailStatus" class="muted">No row selected yet.</div>
    <div class="diagnostics-grid">
        <div class="diagnostics-item">
            <strong>Safety Classification</strong>
            <span id="diagSafety">-</span>
        </div>
        <div class="diagnostics-item">
            <strong>Safety Reason</strong>
            <span id="diagSafetyReason">-</span>
        </div>
        <div class="diagnostics-item">
            <strong>Send-Test Actionability</strong>
            <span id="diagActionability">-</span>
        </div>
        <div class="diagnostics-item">
            <strong>Runtime SIM ID</strong>
            <span id="diagRuntimeSimId">-</span>
        </div>
        <div class="diagnostics-item">
            <strong>Tenant SIM DB ID</strong>
            <span id="diagTenantSimDbId">-</span>
        </div>
        <div class="diagnostics-item">
            <strong>Mapping Status</strong>
            <span id="diagMappingStatus">-</span>
        </div>
        <div class="diagnostics-item">
            <strong>Identifier Source</strong>
            <span id="diagIdentifierSource">-</span>
        </div>
        <div class="diagnostics-item">
            <strong>Runtime Send-Ready</strong>
            <span id="diagRuntimeSendReady">-</span>
        </div>
        <div class="diagnostics-item">
            <strong>Probe Error</strong>
            <span id="diagProbeError">-</span>
        </div>
        <div class="diagnostics-item">
            <strong>AT OK</strong>
            <span id="diagAtOk">-</span>
        </div>
        <div class="diagnostics-item">
            <strong>SIM Ready</strong>
            <span id="diagSimReady">-</span>
        </div>
        <div class="diagnostics-item">
            <strong>CREG Registered</strong>
            <span id="diagCregRegistered">-</span>
        </div>
        <div class="diagnostics-item">
            <strong>Signal</strong>
            <span id="diagSignal">-</span>
        </div>
        <div class="diagnostics-item">
            <strong>Device ID</strong>
            <span id="diagDeviceId">-</span>
        </div>
        <div class="diagnostics-item">
            <strong>Port</strong>
            <span id="diagPort">-</span>
        </div>
        <div class="diagnostics-item">
            <strong>Last Seen At</strong>
            <span id="diagLastSeenAt">-</span>
        </div>
    </div>
    <details class="diagnostics-raw">
        <summary>Raw Row JSON</summary>
        <pre id="diagRawJson">-</pre>
    </details>
</section>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Actions</th>
                <th>Row Safety</th>
                <th>Runtime SIM ID (IMSI/device)</th>
                <th>Tenant SIM DB ID</th>
                <th>Mapping Status</th>
                <th>Identifier Source</th>
                <th>Runtime Send-Ready</th>
                <th>Device ID</th>
                <th>Port</th>
                <th>AT OK</th>
                <th>SIM Ready</th>
                <th>CREG Registered</th>
                <th>Signal</th>
                <th>Probe Error</th>
                <th>Last Seen At</th>
            </tr>
        </thead>
        <tbody id="modemRows">
            <tr>
                <td colspan="15" class="muted">No modem rows loaded.</td>
            </tr>
        </tbody>
    </table>
</div>

@push('scripts')
<script>
    (() => {
        const apiPath = '/dashboard/api/runtime/python';
        const sendApiPath = '/dashboard/api/runtime/python/send-test';
        const csrfToken = @json(csrf_token());
        const refreshButton = document.getElementById('refreshButton');
        const sendTestButton = document.getElementById('sendTestButton');
        const statusEl = document.getElementById('status');
        const sendStatusEl = document.getElementById('sendStatus');
        const modemRowsEl = document.getElementById('modemRows');
        const runtimeRowFiltersEl = document.getElementById('runtimeRowFilters');
        const runtimeFilterSummaryEl = document.getElementById('runtimeFilterSummary');
        const runtimeDetailStatusEl = document.getElementById('runtimeDetailStatus');
        const diagSafetyEl = document.getElementById('diagSafety');
        const diagSafetyReasonEl = document.getElementById('diagSafetyReason');
        const diagActionabilityEl = document.getElementById('diagActionability');
        const diagRuntimeSimIdEl = document.getElementById('diagRuntimeSimId');
        const diagTenantSimDbIdEl = document.getElementById('diagTenantSimDbId');
        const diagMappingStatusEl = document.getElementById('diagMappingStatus');
        const diagIdentifierSourceEl = document.getElementById('diagIdentifierSource');
        const diagRuntimeSendReadyEl = document.getElementById('diagRuntimeSendReady');
        const diagProbeErrorEl = document.getElementById('diagProbeError');
        const diagAtOkEl = document.getElementById('diagAtOk');
        const diagSimReadyEl = document.getElementById('diagSimReady');
        const diagCregRegisteredEl = document.getElementById('diagCregRegistered');
        const diagSignalEl = document.getElementById('diagSignal');
        const diagDeviceIdEl = document.getElementById('diagDeviceId');
        const diagPortEl = document.getElementById('diagPort');
        const diagLastSeenAtEl = document.getElementById('diagLastSeenAt');
        const diagRawJsonEl = document.getElementById('diagRawJson');

        const sendSimIdEl = document.getElementById('sendSimId');
        const sendCustomerPhoneEl = document.getElementById('sendCustomerPhone');
        const sendClientMessageIdEl = document.getElementById('sendClientMessageId');
        const sendMessageEl = document.getElementById('sendMessage');

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
        const fleetTotalEl = document.getElementById('fleetTotal');
        const fleetMappedEl = document.getElementById('fleetMapped');
        const fleetUnmappedEl = document.getElementById('fleetUnmapped');
        const fleetSendReadyEl = document.getElementById('fleetSendReady');
        const fleetProbeErrorEl = document.getElementById('fleetProbeError');
        const fleetFallbackEl = document.getElementById('fleetFallback');
        let latestDiscoveryRows = [];
        let currentRenderedRows = [];
        let activeRowFilter = 'all';

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

        const hasProbeError = (modem) => {
            const probeError = modem && modem.probe_error !== null && modem.probe_error !== undefined
                ? String(modem.probe_error).trim()
                : '';

            return probeError !== '';
        };

        const isMappedTenantSim = (modem) => {
            const tenantSimDbId = String(modem && modem.tenant_sim_db_id !== undefined ? modem.tenant_sim_db_id : '').trim();

            return /^[1-9]\d*$/.test(tenantSimDbId);
        };

        const runtimeSendReady = (modem) => {
            if (typeof modem.send_ready === 'boolean') {
                return modem.send_ready;
            }

            return !hasProbeError(modem)
                && modem.at_ok === true
                && modem.sim_ready === true
                && modem.creg_registered === true;
        };

        const normalizedIdentifierSource = (modem) => {
            return String(modem && modem.identifier_source !== undefined ? modem.identifier_source : '')
                .trim()
                .toLowerCase();
        };

        const isFallbackIdentifier = (modem) => {
            return normalizedIdentifierSource(modem) === 'fallback_device_id';
        };

        const setStatus = (text, type = 'muted') => {
            statusEl.className = `status ${type}`;
            statusEl.textContent = text;
        };

        const setSendStatus = (text, type = 'muted') => {
            sendStatusEl.className = `send-result ${type}`;
            sendStatusEl.textContent = text;
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

        const rowState = (modem) => {
            if (hasProbeError(modem)) {
                return 'error';
            }

            if (modem.at_ok === true && modem.sim_ready === true && modem.creg_registered === true) {
                return 'healthy';
            }

            return 'warning';
        };

        const rowClass = (state) => {
            if (state === 'healthy') {
                return 'runtime-row-healthy';
            }

            if (state === 'error') {
                return 'runtime-row-error';
            }

            return 'runtime-row-warning';
        };

        const runtimeRowSendability = (modem, runtimeSimId, tenantSimDbId) => {
            const probeError = modem && modem.probe_error !== null && modem.probe_error !== undefined
                ? String(modem.probe_error).trim()
                : '';

            if (probeError !== '') {
                return {
                    can_use_in_send_test: false,
                    disabled_reason: 'Probe did not complete; send test disabled.'
                };
            }

            if (modem.at_ok === false) {
                return {
                    can_use_in_send_test: false,
                    disabled_reason: 'AT not ready; send test disabled.'
                };
            }

            if (modem.sim_ready === false) {
                return {
                    can_use_in_send_test: false,
                    disabled_reason: 'SIM not ready; send test disabled.'
                };
            }

            if (modem.creg_registered === false) {
                return {
                    can_use_in_send_test: false,
                    disabled_reason: 'Modem not registered; send test disabled.'
                };
            }

            if (modem.at_ok !== true) {
                return {
                    can_use_in_send_test: false,
                    disabled_reason: 'AT not ready; send test disabled.'
                };
            }

            if (modem.sim_ready !== true) {
                return {
                    can_use_in_send_test: false,
                    disabled_reason: 'SIM not ready; send test disabled.'
                };
            }

            if (modem.creg_registered !== true) {
                return {
                    can_use_in_send_test: false,
                    disabled_reason: 'Modem not registered; send test disabled.'
                };
            }

            const normalizedTenantSimId = String(tenantSimDbId ?? '').trim();
            if (normalizedTenantSimId === '' || normalizedTenantSimId === '-' || !/^[1-9]\d*$/.test(normalizedTenantSimId)) {
                return {
                    can_use_in_send_test: false,
                    disabled_reason: 'No tenant SIM mapping for this runtime SIM ID; send test disabled.'
                };
            }

            if (runtimeSimId === '-' || runtimeSimId === '') {
                return {
                    can_use_in_send_test: false,
                    disabled_reason: 'No runtime SIM ID available; send test disabled.'
                };
            }

            return {
                can_use_in_send_test: true,
                disabled_reason: null
            };
        };

        const rowSafetyMeta = (modem) => {
            const mapped = isMappedTenantSim(modem);
            const ready = runtimeSendReady(modem);
            const probeError = hasProbeError(modem);
            const identifierSource = normalizedIdentifierSource(modem);
            const sendability = runtimeRowSendability(modem, asText(modem.sim_id), asText(modem.tenant_sim_db_id));

            if (probeError || !ready) {
                return {
                    badgeClass: 'safety-degraded',
                    label: 'Degraded',
                    description: sendability.disabled_reason || 'Runtime readiness checks are incomplete; treat as degraded.'
                };
            }

            if (!mapped) {
                return {
                    badgeClass: 'safety-visible',
                    label: 'Visible only / not mapped',
                    description: 'No tenant SIM DB mapping; visible for runtime truth and debugging only.'
                };
            }

            if (identifierSource === 'imsi') {
                return {
                    badgeClass: 'safety-strong',
                    label: 'Strongest usable',
                    description: 'Mapped IMSI-backed row with runtime send-ready state.'
                };
            }

            return {
                badgeClass: 'safety-caution',
                label: 'Usable with caution',
                description: 'Mapped and send-ready, but identifier/runtime signal quality is weaker than IMSI-backed rows.'
            };
        };

        const filterLabel = (filter) => {
            if (filter === 'mapped') {
                return 'Mapped';
            }

            if (filter === 'unmapped') {
                return 'Unmapped';
            }

            if (filter === 'send_ready') {
                return 'Send Ready';
            }

            if (filter === 'probe_error') {
                return 'Probe Error';
            }

            if (filter === 'fallback') {
                return 'Fallback';
            }

            return 'All';
        };

        const applyRuntimeRowFilter = (rows) => {
            const list = Array.isArray(rows) ? rows : [];

            if (activeRowFilter === 'mapped') {
                return list.filter((modem) => isMappedTenantSim(modem));
            }

            if (activeRowFilter === 'unmapped') {
                return list.filter((modem) => !isMappedTenantSim(modem));
            }

            if (activeRowFilter === 'send_ready') {
                return list.filter((modem) => runtimeSendReady(modem));
            }

            if (activeRowFilter === 'probe_error') {
                return list.filter((modem) => hasProbeError(modem));
            }

            if (activeRowFilter === 'fallback') {
                return list.filter((modem) => isFallbackIdentifier(modem));
            }

            return list;
        };

        const updateFilterChips = () => {
            const chips = runtimeRowFiltersEl.querySelectorAll('button.filter-chip[data-filter]');
            chips.forEach((chip) => {
                const filter = String(chip.dataset.filter || 'all');
                const isActive = filter === activeRowFilter;
                chip.classList.toggle('active', isActive);
                chip.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
        };

        const setDiagnosticsField = (element, value) => {
            element.textContent = asText(value);
        };

        const clearDiagnostics = (message = 'No row selected yet.') => {
            runtimeDetailStatusEl.textContent = message;
            setDiagnosticsField(diagSafetyEl, '-');
            setDiagnosticsField(diagSafetyReasonEl, '-');
            setDiagnosticsField(diagActionabilityEl, '-');
            setDiagnosticsField(diagRuntimeSimIdEl, '-');
            setDiagnosticsField(diagTenantSimDbIdEl, '-');
            setDiagnosticsField(diagMappingStatusEl, '-');
            setDiagnosticsField(diagIdentifierSourceEl, '-');
            setDiagnosticsField(diagRuntimeSendReadyEl, '-');
            setDiagnosticsField(diagProbeErrorEl, '-');
            setDiagnosticsField(diagAtOkEl, '-');
            setDiagnosticsField(diagSimReadyEl, '-');
            setDiagnosticsField(diagCregRegisteredEl, '-');
            setDiagnosticsField(diagSignalEl, '-');
            setDiagnosticsField(diagDeviceIdEl, '-');
            setDiagnosticsField(diagPortEl, '-');
            setDiagnosticsField(diagLastSeenAtEl, '-');
            diagRawJsonEl.textContent = '-';
        };

        const showRowDiagnostics = (modem) => {
            if (!modem || typeof modem !== 'object') {
                clearDiagnostics();
                return;
            }

            const safety = rowSafetyMeta(modem);
            const runtimeSimId = asText(modem.sim_id);
            const tenantSimDbId = asText(modem.tenant_sim_db_id);
            const sendability = runtimeRowSendability(modem, runtimeSimId, tenantSimDbId);
            const actionability = sendability.can_use_in_send_test
                ? 'Send test enabled via Tenant SIM DB ID mapping.'
                : sendability.disabled_reason;

            runtimeDetailStatusEl.textContent = `Showing diagnostics for runtime SIM ID: ${runtimeSimId}`;
            setDiagnosticsField(diagSafetyEl, safety.label);
            setDiagnosticsField(diagSafetyReasonEl, safety.description);
            setDiagnosticsField(diagActionabilityEl, actionability);
            setDiagnosticsField(diagRuntimeSimIdEl, runtimeSimId);
            setDiagnosticsField(diagTenantSimDbIdEl, tenantSimDbId);
            setDiagnosticsField(diagMappingStatusEl, isMappedTenantSim(modem) ? 'mapped' : 'unmapped');
            setDiagnosticsField(diagIdentifierSourceEl, modem.identifier_source);
            setDiagnosticsField(diagRuntimeSendReadyEl, boolText(runtimeSendReady(modem)));
            setDiagnosticsField(diagProbeErrorEl, modem.probe_error);
            setDiagnosticsField(diagAtOkEl, boolText(modem.at_ok));
            setDiagnosticsField(diagSimReadyEl, boolText(modem.sim_ready));
            setDiagnosticsField(diagCregRegisteredEl, boolText(modem.creg_registered));
            setDiagnosticsField(diagSignalEl, modem.signal);
            setDiagnosticsField(diagDeviceIdEl, modem.device_id);
            setDiagnosticsField(diagPortEl, modem.port);
            setDiagnosticsField(diagLastSeenAtEl, modem.last_seen_at);
            diagRawJsonEl.textContent = JSON.stringify(modem, null, 2);
        };

        const renderModems = (modems) => {
            latestDiscoveryRows = Array.isArray(modems) ? modems : [];
            const filteredRows = applyRuntimeRowFilter(latestDiscoveryRows);
            currentRenderedRows = filteredRows;
            runtimeFilterSummaryEl.textContent = `Showing ${filteredRows.length} of ${latestDiscoveryRows.length} rows (${filterLabel(activeRowFilter)}).`;
            updateFilterChips();

            if (filteredRows.length === 0) {
                modemRowsEl.innerHTML = '<tr><td colspan="15" class="muted">No modem rows match the selected filter.</td></tr>';
                clearDiagnostics('No row selected. Load runtime rows and click View Details.');
                return;
            }

            modemRowsEl.innerHTML = filteredRows.map((modem, index) => {
                const state = rowState(modem);
                const runtimeSimId = asText(modem.sim_id);
                const tenantSimDbId = asText(modem.tenant_sim_db_id);
                const sendability = runtimeRowSendability(modem, runtimeSimId, tenantSimDbId);
                const isMapped = isMappedTenantSim(modem);
                const identifierSource = asText(modem.identifier_source);
                const fallbackIdentifier = isFallbackIdentifier(modem);
                const runtimeReady = runtimeSendReady(modem);
                const safety = rowSafetyMeta(modem);
                const runtimeSimIdAttr = ` data-sim-id="${escapeHtml(runtimeSimId)}"`;
                const tenantSimDbIdAttr = ` data-tenant-sim-id="${escapeHtml(tenantSimDbId)}"`;
                const rowIndexAttr = ` data-row-index="${index}"`;
                const useDisabledAttr = sendability.can_use_in_send_test ? '' : ' disabled';
                const useTitleAttr = sendability.disabled_reason ? ` title="${escapeHtml(sendability.disabled_reason)}"` : '';
                const sendBadge = sendability.can_use_in_send_test
                    ? '<span class="send-ready-badge">send-ready</span>'
                    : '<span class="send-blocked-badge">not send-ready</span>';
                const sendDisabledReason = sendability.disabled_reason
                    ? `<div class="send-disabled-reason">${escapeHtml(sendability.disabled_reason)}</div>`
                    : '';
                const mappingBadge = isMapped
                    ? '<span class="state-badge state-good">mapped</span>'
                    : '<span class="state-badge state-danger">unmapped</span>';
                const identifierBadge = fallbackIdentifier
                    ? '<span class="state-badge state-warn">fallback_device_id</span>'
                    : `<span class="state-badge state-good">${escapeHtml(identifierSource)}</span>`;
                const runtimeReadyBadge = runtimeReady
                    ? '<span class="state-badge state-good">true</span>'
                    : '<span class="state-badge state-warn">false</span>';
                const safetyBadge = `<span class="safety-badge ${escapeHtml(safety.badgeClass)}">${escapeHtml(safety.label)}</span>`;
                const safetyNote = `<div class="safety-note">${escapeHtml(safety.description)}</div>`;

                return `
                <tr class="${rowClass(state)}">
                    <td>
                        <button type="button" class="mini-button view-details"${rowIndexAttr}>View Details</button>
                        <button type="button" class="mini-button copy-sim-id"${runtimeSimIdAttr}>Copy SIM ID</button>
                        <button type="button" class="mini-button use-sim-id"${runtimeSimIdAttr}${tenantSimDbIdAttr}${useDisabledAttr}${useTitleAttr}>Use in Send Test</button>
                        ${sendBadge}
                        ${sendDisabledReason}
                    </td>
                    <td>
                        ${safetyBadge}
                        ${safetyNote}
                    </td>
                    <td>${escapeHtml(runtimeSimId)}</td>
                    <td>${escapeHtml(tenantSimDbId)}</td>
                    <td>${mappingBadge}</td>
                    <td>${identifierBadge}</td>
                    <td>${runtimeReadyBadge}</td>
                    <td>${escapeHtml(asText(modem.device_id))}</td>
                    <td>${escapeHtml(asText(modem.port))}</td>
                    <td>${escapeHtml(boolText(modem.at_ok))}</td>
                    <td>${escapeHtml(boolText(modem.sim_ready))}</td>
                    <td>${escapeHtml(boolText(modem.creg_registered))}</td>
                    <td>${escapeHtml(asText(modem.signal))}</td>
                    <td>${escapeHtml(asText(modem.probe_error))}</td>
                    <td>${escapeHtml(asText(modem.last_seen_at))}</td>
                </tr>
            `;
            }).join('');
        };

        const renderFleetSnapshot = (modems) => {
            const rows = Array.isArray(modems) ? modems : [];
            const total = rows.length;
            let mapped = 0;
            let sendReady = 0;
            let probeError = 0;
            let fallbackIdentifiers = 0;

            rows.forEach((modem) => {
                if (isMappedTenantSim(modem)) {
                    mapped += 1;
                }

                if (runtimeSendReady(modem)) {
                    sendReady += 1;
                }

                if (hasProbeError(modem)) {
                    probeError += 1;
                }

                if (String(modem && modem.identifier_source !== undefined ? modem.identifier_source : '') === 'fallback_device_id') {
                    fallbackIdentifiers += 1;
                }
            });

            const unmapped = Math.max(total - mapped, 0);

            fleetTotalEl.textContent = String(total);
            fleetMappedEl.textContent = String(mapped);
            fleetUnmappedEl.textContent = String(unmapped);
            fleetSendReadyEl.textContent = String(sendReady);
            fleetProbeErrorEl.textContent = String(probeError);
            fleetFallbackEl.textContent = String(fallbackIdentifiers);
        };

        const runtimeStatusMeta = (payload) => {
            const health = payload && payload.health ? payload.health : {};
            const discovery = payload && payload.discovery ? payload.discovery : {};
            const modems = Array.isArray(discovery.all_modems) ? discovery.all_modems : [];
            const fallbackTotal = Number(discovery.discovered_total || 0);
            const totalCount = modems.length > 0 ? modems.length : (Number.isFinite(fallbackTotal) ? fallbackTotal : 0);
            const probeErrorCount = modems.filter((modem) => {
                const probeError = modem && typeof modem === 'object' ? modem.probe_error : null;
                return probeError !== null && probeError !== undefined && String(probeError).trim() !== '';
            }).length;
            const healthyCount = Math.max(totalCount - probeErrorCount, 0);

            if (health.ok === false) {
                return {
                    category: 'runtime_unreachable',
                    type: 'error',
                    message: 'Python runtime is unreachable. Check runtime service/network/token configuration.'
                };
            }

            if (health.ok === true && discovery.ok === false) {
                return {
                    category: 'discovery_failed',
                    type: 'error',
                    message: 'Python runtime is reachable, but modem discovery failed. Check discovery endpoint/runtime logs.'
                };
            }

            if (health.ok === true && discovery.ok === true && probeErrorCount > 0) {
                return {
                    category: 'discovery_partial',
                    type: 'warn',
                    message: `Python runtime reachable. Discovery completed with warnings: ${healthyCount}/${totalCount} modems healthy, ${probeErrorCount} with probe errors.`
                };
            }

            return {
                category: 'discovery_success',
                type: 'ok',
                message: `Python runtime reachable. Discovery completed successfully: ${healthyCount}/${totalCount} modems healthy.`
            };
        };

        clearDiagnostics();

        runtimeRowFiltersEl.addEventListener('click', (event) => {
            const target = event.target;

            if (!(target instanceof HTMLButtonElement)) {
                return;
            }

            const filter = String(target.dataset.filter || '').trim();
            const allowedFilters = ['all', 'mapped', 'unmapped', 'send_ready', 'probe_error', 'fallback'];

            if (!allowedFilters.includes(filter)) {
                return;
            }

            activeRowFilter = filter;
            renderModems(latestDiscoveryRows);
        });

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
                const modemRows = payload.discovery && payload.discovery.all_modems ? payload.discovery.all_modems : [];
                renderFleetSnapshot(modemRows);
                renderModems(modemRows);
                const runtimeStatus = runtimeStatusMeta(payload);
                setStatus(runtimeStatus.message, runtimeStatus.type);
            } catch (error) {
                setStatus(`Runtime check failed: ${error.message}`, 'error');
            }
        });

        modemRowsEl.addEventListener('click', async (event) => {
            const target = event.target;

            if (!(target instanceof HTMLButtonElement)) {
                return;
            }

            if (target.classList.contains('view-details')) {
                const rowIndex = Number(target.dataset.rowIndex || -1);

                if (!Number.isInteger(rowIndex) || rowIndex < 0 || rowIndex >= currentRenderedRows.length) {
                    setStatus('Could not load diagnostics for this row.', 'warn');
                    return;
                }

                showRowDiagnostics(currentRenderedRows[rowIndex]);
                return;
            }

            const simId = (target.dataset.simId || '').trim();
            const tenantSimId = (target.dataset.tenantSimId || '').trim();

            if (simId === '') {
                setStatus('No runtime SIM ID available for this modem row.', 'warn');
                return;
            }

            if (target.classList.contains('use-sim-id')) {
                if (!/^[1-9]\d*$/.test(tenantSimId)) {
                    setStatus('No tenant SIM DB ID mapping for this runtime SIM ID.', 'warn');
                    return;
                }

                sendSimIdEl.value = tenantSimId;
                setStatus(`Tenant SIM DB ID ${tenantSimId} loaded into send test form (runtime SIM ID: ${simId}).`, 'ok');
                return;
            }

            if (target.classList.contains('copy-sim-id')) {
                try {
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        await navigator.clipboard.writeText(simId);
                    } else {
                        const helper = document.createElement('textarea');
                        helper.value = simId;
                        helper.setAttribute('readonly', '');
                        helper.style.position = 'absolute';
                        helper.style.left = '-9999px';
                        document.body.appendChild(helper);
                        helper.select();
                        document.execCommand('copy');
                        document.body.removeChild(helper);
                    }

                    setStatus(`SIM ID copied: ${simId}`, 'ok');
                } catch (_) {
                    setStatus(`Could not copy SIM ID automatically. Use this value manually: ${simId}`, 'warn');
                }
            }
        });

        sendTestButton.addEventListener('click', async () => {
            const simId = Number(sendSimIdEl.value || 0);
            const customerPhone = (sendCustomerPhoneEl.value || '').trim();
            const clientMessageId = (sendClientMessageIdEl.value || '').trim();
            const message = (sendMessageEl.value || '').trim();

            if (!Number.isInteger(simId) || simId < 1) {
                setSendStatus('Send test failed: SIM ID is required.', 'error');
                return;
            }

            if (customerPhone === '') {
                setSendStatus('Send test failed: Customer Phone is required.', 'error');
                return;
            }

            if (message === '') {
                setSendStatus('Send test failed: Message is required.', 'error');
                return;
            }

            setSendStatus('Executing runtime send test...', 'muted');

            try {
                const response = await fetch(sendApiPath, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({
                        sim_id: simId,
                        customer_phone: customerPhone,
                        client_message_id: clientMessageId === '' ? null : clientMessageId,
                        message
                    })
                });

                let payload = null;
                try {
                    payload = await response.json();
                } catch (_) {
                    payload = null;
                }

                if (!payload) {
                    setSendStatus(`Send test failed: HTTP ${response.status}`, 'error');
                    return;
                }

                if (payload.ok === true) {
                    const result = payload.result || {};
                    setSendStatus(
                        `Send test success. message_id=${asText(result.message_id)} status=${asText(result.status)} provider_message_id=${asText(result.provider_message_id)}`,
                        'ok'
                    );
                    return;
                }

                const result = payload.result || {};
                const error = payload.error || 'runtime_send_failed';
                const failureError = asText(result.error);
                const failureLayer = asText(result.error_layer);
                const likelyLoadIssue = failureError === 'SEND_FAILED' && failureLayer === 'network';
                const operatorHint = likelyLoadIssue
                    ? ' Likely SIM load/balance or carrier-side issue.'
                    : '';
                setSendStatus(
                    `Send test failed (${error}). message_id=${asText(result.message_id)} error=${failureError} error_layer=${failureLayer}.${operatorHint}`,
                    'error'
                );
            } catch (error) {
                setSendStatus(`Send test failed: ${error.message}`, 'error');
            }
        });
    })();
</script>
@endpush
@endsection
