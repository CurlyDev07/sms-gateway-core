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

<section class="send-panel">
    <h2>Direct Runtime Send Test</h2>
    <p class="muted">
        Controlled verification path using <code>POST /dashboard/api/runtime/python/send-test</code>.
        This directly calls Python send and persists the result on one outbound row.
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

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Actions</th>
                <th>Runtime SIM ID (IMSI/device)</th>
                <th>Tenant SIM DB ID</th>
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
                <td colspan="11" class="muted">No modem rows loaded.</td>
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
        let latestDiscoveryRows = [];

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
            const probeError = modem && modem.probe_error !== null && modem.probe_error !== undefined
                ? String(modem.probe_error).trim()
                : '';

            if (probeError !== '') {
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

        const renderModems = (modems) => {
            latestDiscoveryRows = Array.isArray(modems) ? modems : [];

            if (latestDiscoveryRows.length === 0) {
                modemRowsEl.innerHTML = '<tr><td colspan="11" class="muted">No modem rows returned by discovery.</td></tr>';
                return;
            }

            modemRowsEl.innerHTML = latestDiscoveryRows.map((modem, index) => {
                const state = rowState(modem);
                const runtimeSimId = asText(modem.sim_id);
                const tenantSimDbId = asText(modem.tenant_sim_db_id);
                const sendability = runtimeRowSendability(modem, runtimeSimId, tenantSimDbId);
                const runtimeSimIdAttr = ` data-sim-id="${escapeHtml(runtimeSimId)}"`;
                const tenantSimDbIdAttr = ` data-tenant-sim-id="${escapeHtml(tenantSimDbId)}"`;
                const useDisabledAttr = sendability.can_use_in_send_test ? '' : ' disabled';
                const useTitleAttr = sendability.disabled_reason ? ` title="${escapeHtml(sendability.disabled_reason)}"` : '';
                const sendBadge = sendability.can_use_in_send_test
                    ? '<span class="send-ready-badge">send-ready</span>'
                    : '<span class="send-blocked-badge">not send-ready</span>';
                const sendDisabledReason = sendability.disabled_reason
                    ? `<div class="send-disabled-reason">${escapeHtml(sendability.disabled_reason)}</div>`
                    : '';

                return `
                <tr class="${rowClass(state)}">
                    <td>
                        <button type="button" class="mini-button copy-sim-id"${runtimeSimIdAttr}>Copy SIM ID</button>
                        <button type="button" class="mini-button use-sim-id"${runtimeSimIdAttr}${tenantSimDbIdAttr}${useDisabledAttr}${useTitleAttr}>Use in Send Test</button>
                        ${sendBadge}
                        ${sendDisabledReason}
                    </td>
                    <td>${escapeHtml(runtimeSimId)}</td>
                    <td>${escapeHtml(tenantSimDbId)}</td>
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
                renderModems(payload.discovery && payload.discovery.all_modems ? payload.discovery.all_modems : []);
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
                setSendStatus(
                    `Send test failed (${error}). message_id=${asText(result.message_id)} error=${asText(result.error)} error_layer=${asText(result.error_layer)}`,
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
