@extends('dashboard.layouts.app')

@section('title', 'Migration Tools')
@section('page_heading', 'Migration Tools')

@push('styles')
<style>
        body {
            font-family: Arial, sans-serif;
            margin: 24px;
            color: #1f2937;
        }

        h1 {
            margin: 0 0 8px 0;
            font-size: 24px;
        }

        h2 {
            font-size: 18px;
            margin: 18px 0 10px 0;
        }

        .links {
            margin-bottom: 12px;
            font-size: 14px;
        }

        .links a {
            color: #1d4ed8;
            text-decoration: none;
            margin-right: 12px;
        }

        .links a:hover {
            text-decoration: underline;
        }

        .links .logout-button {
            border: none;
            background: none;
            color: #1d4ed8;
            padding: 0;
            cursor: pointer;
            font-size: 14px;
        }

        .links .logout-button:hover {
            text-decoration: underline;
        }

        .muted {
            color: #6b7280;
            margin-bottom: 12px;
        }

        .panel {
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 14px;
            margin-bottom: 14px;
        }

        .controls {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: end;
        }

        label {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 14px;
        }

        input {
            min-width: 220px;
            padding: 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
        }

        button {
            padding: 9px 14px;
            border: 1px solid #111827;
            background: #111827;
            color: #ffffff;
            border-radius: 4px;
            cursor: pointer;
        }

        .button-secondary {
            background: #ffffff;
            color: #111827;
        }

        .status {
            margin: 8px 0 14px 0;
            min-height: 20px;
            font-size: 14px;
        }

        .error {
            color: #b91c1c;
        }

        .ok {
            color: #065f46;
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid #e5e7eb;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            min-width: 1700px;
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
    Operator workflow page using existing assignment and migration APIs only.
    Core lookup endpoint: <code>GET /dashboard/api/assignments</code>.
    Use this page to inspect assignments, mark safe, set assignment, migrate single customer, or bulk migrate.
</p>

<div class="panel">
    <h2>Lookup</h2>
    <div class="controls">
        <label title="Optional filter for assignment lookup by customer phone.">
            customer_phone (filter)
            <input id="filterCustomerPhone" type="text" placeholder="e.g. 09171234567">
        </label>
        <label title="Optional filter for assignment lookup by SIM ID.">
            sim_id (filter)
            <input id="filterSimId" type="number" min="1" step="1" placeholder="e.g. 1">
        </label>
        <button id="loadAssignmentsButton" type="button" title="Load assignments using optional filters above.">Load Assignments</button>
    </div>
</div>

<div id="status" class="status muted">No data loaded yet.</div>

<div class="panel">
    <h2>Actions</h2>

    <div class="controls" style="margin-bottom:12px;">
        <label title="Customer phone to mark as safe for migration.">
            customer_phone (mark-safe)
            <input id="markSafeCustomerPhone" type="text" placeholder="e.g. 09171234567">
        </label>
        <button id="markSafeButton" type="button" class="button-secondary" title="Set safe_to_migrate=true for this customer assignment.">Mark Safe</button>
    </div>

    <div class="controls" style="margin-bottom:12px;">
        <label title="Customer phone to force-assign to a SIM.">
            customer_phone (set assignment)
            <input id="setCustomerPhone" type="text" placeholder="e.g. 09171234567">
        </label>
        <label title="Target SIM ID for force assignment.">
            sim_id (set assignment)
            <input id="setSimId" type="number" min="1" step="1" placeholder="e.g. 2">
        </label>
        <button id="setAssignmentButton" type="button" class="button-secondary" title="Force this customer to use the target SIM assignment.">Set Assignment</button>
    </div>

    <div class="controls" style="margin-bottom:12px;">
        <label title="Customer phone to migrate from one SIM to another.">
            customer_phone (migrate single)
            <input id="singleCustomerPhone" type="text" placeholder="e.g. 09171234567">
        </label>
        <label title="Source SIM for the customer migration.">
            from_sim_id (single)
            <input id="singleFromSimId" type="number" min="1" step="1" placeholder="e.g. 2">
        </label>
        <label title="Destination SIM for the customer migration.">
            to_sim_id (single)
            <input id="singleToSimId" type="number" min="1" step="1" placeholder="e.g. 3">
        </label>
        <button id="migrateSingleButton" type="button" class="button-secondary" title="Move one customer assignment and eligible rows to destination SIM.">Migrate Single Customer</button>
    </div>

    <div class="controls">
        <label title="Source SIM for bulk migration.">
            from_sim_id (bulk)
            <input id="bulkFromSimId" type="number" min="1" step="1" placeholder="e.g. 2">
        </label>
        <label title="Destination SIM for bulk migration.">
            to_sim_id (bulk)
            <input id="bulkToSimId" type="number" min="1" step="1" placeholder="e.g. 3">
        </label>
        <button id="migrateBulkButton" type="button" class="button-secondary" title="Move all eligible assignments/messages from source SIM to destination SIM.">Bulk Migrate</button>
    </div>
</div>

<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Customer Phone</th>
            <th>SIM ID</th>
            <th>SIM Nested ID</th>
            <th>SIM Phone Number</th>
            <th title="Operator-controlled send state of the assigned SIM.">SIM Operator Status</th>
            <th>SIM Status</th>
            <th>Assignment Status</th>
            <th title="True means this customer has sent an inbound reply on this assignment.">Has Replied</th>
            <th title="True means operator marked this assignment safe for migration actions.">Safe To Migrate</th>
            <th>Assigned At</th>
            <th>Last Used At</th>
            <th>Last Inbound At</th>
            <th>Last Outbound At</th>
            <th>Created At</th>
            <th>Updated At</th>
        </tr>
        </thead>
        <tbody id="assignmentRows">
        <tr>
            <td colspan="15" class="muted">No assignment rows loaded.</td>
        </tr>
        </tbody>
    </table>
</div>

<script>
    (() => {
        const statusEl = document.getElementById('status');
        const rowsEl = document.getElementById('assignmentRows');
        const csrfToken = @json(csrf_token());

        const filterCustomerPhoneInput = document.getElementById('filterCustomerPhone');
        const filterSimIdInput = document.getElementById('filterSimId');

        const markSafeCustomerPhoneInput = document.getElementById('markSafeCustomerPhone');
        const setCustomerPhoneInput = document.getElementById('setCustomerPhone');
        const setSimIdInput = document.getElementById('setSimId');
        const singleCustomerPhoneInput = document.getElementById('singleCustomerPhone');
        const singleFromSimIdInput = document.getElementById('singleFromSimId');
        const singleToSimIdInput = document.getElementById('singleToSimId');
        const bulkFromSimIdInput = document.getElementById('bulkFromSimId');
        const bulkToSimIdInput = document.getElementById('bulkToSimId');

        const loadAssignmentsButton = document.getElementById('loadAssignmentsButton');
        const markSafeButton = document.getElementById('markSafeButton');
        const setAssignmentButton = document.getElementById('setAssignmentButton');
        const migrateSingleButton = document.getElementById('migrateSingleButton');
        const migrateBulkButton = document.getElementById('migrateBulkButton');

        const escapeHtml = (value) => {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        };

        const boolText = (value) => value ? 'true' : 'false';

        const setStatus = (text, type = 'muted') => {
            statusEl.className = `status ${type}`;
            statusEl.textContent = text;
        };

        const getHeaders = () => ({
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken
        });

        const renderAssignments = (assignments) => {
            if (!Array.isArray(assignments) || assignments.length === 0) {
                rowsEl.innerHTML = '<tr><td colspan="15" class="muted">No assignments found for this query.</td></tr>';
                return;
            }

            rowsEl.innerHTML = assignments.map((assignment) => {
                const sim = assignment.sim || {};
                const simId = Number(assignment.sim_id ?? sim.id);
                const simLink = Number.isFinite(simId)
                    ? `<a href="/dashboard/sims/${simId}" title="Open SIM detail/control page for SIM ${simId}.">${simId}</a>`
                    : escapeHtml(assignment.sim_id ?? '');

                return `
                    <tr>
                        <td>${escapeHtml(assignment.customer_phone ?? '')}</td>
                        <td>${simLink}</td>
                        <td>${escapeHtml(sim.id ?? '')}</td>
                        <td>${escapeHtml(sim.phone_number ?? '')}</td>
                        <td>${escapeHtml(sim.operator_status ?? '')}</td>
                        <td>${escapeHtml(sim.status ?? '')}</td>
                        <td>${escapeHtml(assignment.status ?? '')}</td>
                        <td>${escapeHtml(boolText(assignment.has_replied ?? false))}</td>
                        <td>${escapeHtml(boolText(assignment.safe_to_migrate ?? false))}</td>
                        <td>${escapeHtml(assignment.assigned_at ?? '')}</td>
                        <td>${escapeHtml(assignment.last_used_at ?? '')}</td>
                        <td>${escapeHtml(assignment.last_inbound_at ?? '')}</td>
                        <td>${escapeHtml(assignment.last_outbound_at ?? '')}</td>
                        <td>${escapeHtml(assignment.created_at ?? '')}</td>
                        <td>${escapeHtml(assignment.updated_at ?? '')}</td>
                    </tr>
                `;
            }).join('');
        };

        const loadAssignments = async ({silent = false} = {}) => {
            const customerPhone = filterCustomerPhoneInput.value.trim();
            const simId = filterSimIdInput.value.trim();
            const query = new URLSearchParams();

            if (customerPhone) {
                query.set('customer_phone', customerPhone);
            }

            if (simId) {
                query.set('sim_id', simId);
            }

            const url = query.toString() ? `/dashboard/api/assignments?${query.toString()}` : '/dashboard/api/assignments';

            if (!silent) {
                setStatus('Loading assignments...', 'muted');
            }

            try {
                const response = await fetch(url, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json'
                    }
                });

                const payload = await response.json();

                if (!response.ok || !payload || payload.ok !== true) {
                    const error = payload && payload.error ? payload.error : `HTTP ${response.status}`;
                    if (!silent) {
                        setStatus(`Load failed: ${error}`, 'error');
                    }
                    renderAssignments([]);
                    return false;
                }

                const assignments = Array.isArray(payload.assignments) ? payload.assignments : [];
                renderAssignments(assignments);
                if (!silent) {
                    setStatus(`Loaded ${assignments.length} assignment(s).`, 'ok');
                }
                return true;
            } catch (error) {
                if (!silent) {
                    setStatus(`Load failed: ${error.message}`, 'error');
                }
                renderAssignments([]);
                return false;
            }
        };

        const postAction = async (url, body, label) => {
            const headers = getHeaders();

            setStatus(`${label} in progress...`, 'muted');

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers,
                    body: JSON.stringify(body)
                });

                const payload = await response.json();

                if (!response.ok || !payload || payload.ok !== true) {
                    const error = payload && payload.error ? payload.error : `HTTP ${response.status}`;
                    setStatus(`${label} failed: ${error}`, 'error');
                    return false;
                }

                if (payload.result) {
                    const assignmentsMoved = payload.result.assignments_moved ?? 0;
                    const messagesMoved = payload.result.messages_moved ?? 0;
                    const refreshed = await loadAssignments({ silent: true });
                    const refreshNote = refreshed ? ' Assignment table refreshed.' : ' Action applied, but table refresh failed.';
                    setStatus(`${label} completed. assignments_moved=${assignmentsMoved}, messages_moved=${messagesMoved}.${refreshNote}`, 'ok');
                } else {
                    const refreshed = await loadAssignments({ silent: true });
                    const refreshNote = refreshed ? ' Assignment table refreshed.' : ' Action applied, but table refresh failed.';
                    setStatus(`${label} completed successfully.${refreshNote}`, 'ok');
                }
                return true;
            } catch (error) {
                setStatus(`${label} failed: ${error.message}`, 'error');
                return false;
            }
        };

        loadAssignmentsButton.addEventListener('click', loadAssignments);

        markSafeButton.addEventListener('click', async () => {
            const customerPhone = markSafeCustomerPhoneInput.value.trim();
            if (!customerPhone) {
                setStatus('customer_phone (mark-safe) is required.', 'error');
                return;
            }

            await postAction('/dashboard/api/assignments/mark-safe', { customer_phone: customerPhone }, 'Mark Safe');
        });

        setAssignmentButton.addEventListener('click', async () => {
            const customerPhone = setCustomerPhoneInput.value.trim();
            const simId = setSimIdInput.value.trim();

            if (!customerPhone || !simId) {
                setStatus('customer_phone and sim_id are required for Set Assignment.', 'error');
                return;
            }

            await postAction('/dashboard/api/assignments/set', {
                customer_phone: customerPhone,
                sim_id: Number(simId)
            }, 'Set Assignment');
        });

        migrateSingleButton.addEventListener('click', async () => {
            const customerPhone = singleCustomerPhoneInput.value.trim();
            const fromSimId = singleFromSimIdInput.value.trim();
            const toSimId = singleToSimIdInput.value.trim();

            if (!customerPhone || !fromSimId || !toSimId) {
                setStatus('customer_phone, from_sim_id, and to_sim_id are required for single migration.', 'error');
                return;
            }

            if (!window.confirm('Migrate this single customer now?')) {
                return;
            }

            await postAction('/dashboard/api/admin/migrate-single-customer', {
                customer_phone: customerPhone,
                from_sim_id: Number(fromSimId),
                to_sim_id: Number(toSimId)
            }, 'Migrate Single Customer');
        });

        migrateBulkButton.addEventListener('click', async () => {
            const fromSimId = bulkFromSimIdInput.value.trim();
            const toSimId = bulkToSimIdInput.value.trim();

            if (!fromSimId || !toSimId) {
                setStatus('from_sim_id and to_sim_id are required for bulk migration.', 'error');
                return;
            }

            if (!window.confirm('Bulk migrate all eligible assignments from source SIM to destination SIM now?')) {
                return;
            }

            await postAction('/dashboard/api/admin/migrate-bulk', {
                from_sim_id: Number(fromSimId),
                to_sim_id: Number(toSimId)
            }, 'Bulk Migrate');
        });
    })();
</script>
@endsection
