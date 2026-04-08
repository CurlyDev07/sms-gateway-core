<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Assignments Status</title>
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

        .links {
            margin-bottom: 12px;
            font-size: 14px;
        }

        .links a {
            color: #1d4ed8;
            text-decoration: none;
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
            margin-left: 12px;
        }

        .links .logout-button:hover {
            text-decoration: underline;
        }

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
            min-width: 1900px;
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
</head>
<body>
<h1>Assignments Status</h1>
<div class="links">
    <a href="/dashboard">Dashboard Home</a>
    <a href="/dashboard/sims">SIM Fleet</a>
    <a href="/dashboard/assignments">Assignments</a>
    <a href="/dashboard/migration">Migration</a>
    <a href="/dashboard/messages/status">Message Status</a>
    <form method="POST" action="{{ route('logout') }}" style="display:inline;">
        @csrf
        <button type="submit" class="logout-button">Logout</button>
    </form>
</div>
<p class="muted">
    Read-only assignment visibility page powered by <code>GET /api/assignments</code>.
    Provide tenant API credentials (X-API-KEY / X-API-SECRET) and optional filters to load data.
</p>

<div class="controls">
    <label title="API key used to identify your tenant account. Example: key_live_xxx">
        X-API-KEY
        <input id="apiKey" type="text" placeholder="Enter API key">
    </label>
    <label title="API secret paired with your API key. Keep this private.">
        X-API-SECRET
        <input id="apiSecret" type="password" placeholder="Enter API secret">
    </label>
    <label title="Optional filter: show assignments for one customer phone only.">
        customer_phone (optional)
        <input id="customerPhone" type="text" placeholder="e.g. 09171234567">
    </label>
    <label title="Optional filter: show assignments linked to one SIM ID.">
        sim_id (optional)
        <input id="simId" type="number" min="1" step="1" placeholder="e.g. 1">
    </label>
    <button id="loadButton" type="button" title="Fetch assignment records using current optional filters.">Load Assignments</button>
    <button id="clearCredentialsButton" class="button-secondary" type="button" title="Remove saved API credentials from this browser only.">Clear Saved Credentials</button>
</div>

<div id="status" class="status muted">No data loaded yet.</div>

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

@include('dashboard.partials.credential-bootstrap')
<script>
    (() => {
        const apiPath = '/api/assignments';
        const loadButton = document.getElementById('loadButton');
        const clearCredentialsButton = document.getElementById('clearCredentialsButton');
        const apiKeyInput = document.getElementById('apiKey');
        const apiSecretInput = document.getElementById('apiSecret');
        const customerPhoneInput = document.getElementById('customerPhone');
        const simIdInput = document.getElementById('simId');
        const statusEl = document.getElementById('status');
        const rowsEl = document.getElementById('assignmentRows');
        const credentialsStorageKey = 'gateway_dashboard_credentials_v1';

        const escapeHtml = (value) => {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        };

        const boolText = (value) => value ? 'true' : 'false';

        const saveCredentials = () => {
            localStorage.setItem(credentialsStorageKey, JSON.stringify({
                api_key: apiKeyInput.value.trim(),
                api_secret: apiSecretInput.value.trim()
            }));
        };

        const hydrateCredentials = () => {
            const raw = localStorage.getItem(credentialsStorageKey);
            if (!raw) {
                return;
            }

            try {
                const parsed = JSON.parse(raw);
                if (typeof parsed.api_key === 'string') {
                    apiKeyInput.value = parsed.api_key;
                }
                if (typeof parsed.api_secret === 'string') {
                    apiSecretInput.value = parsed.api_secret;
                }
            } catch (_) {
                localStorage.removeItem(credentialsStorageKey);
            }
        };

        const setStatus = (text, type = 'muted') => {
            statusEl.className = `status ${type}`;
            statusEl.textContent = text;
        };

        const renderRows = (assignments) => {
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

        loadButton.addEventListener('click', async () => {
            const apiKey = apiKeyInput.value.trim();
            const apiSecret = apiSecretInput.value.trim();
            const customerPhone = customerPhoneInput.value.trim();
            const simId = simIdInput.value.trim();

            if (!apiKey || !apiSecret) {
                setStatus('Both X-API-KEY and X-API-SECRET are required.', 'error');
                return;
            }

            saveCredentials();
            const query = new URLSearchParams();
            if (customerPhone) {
                query.set('customer_phone', customerPhone);
            }
            if (simId) {
                query.set('sim_id', simId);
            }

            const url = query.toString() ? `${apiPath}?${query.toString()}` : apiPath;
            setStatus('Loading assignments...', 'muted');

            try {
                const response = await fetch(url, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-API-KEY': apiKey,
                        'X-API-SECRET': apiSecret
                    }
                });

                let payload = null;
                try {
                    payload = await response.json();
                } catch (_) {
                    payload = null;
                }

                if (!response.ok || !payload || payload.ok !== true) {
                    const error = payload && payload.error ? payload.error : `HTTP ${response.status}`;
                    setStatus(`Load failed: ${error}`, 'error');
                    renderRows([]);
                    return;
                }

                const assignments = Array.isArray(payload.assignments) ? payload.assignments : [];
                renderRows(assignments);
                setStatus(`Loaded ${assignments.length} assignment(s).`, 'ok');
            } catch (error) {
                setStatus(`Load failed: ${error.message}`, 'error');
                renderRows([]);
            }
        });

        clearCredentialsButton.addEventListener('click', () => {
            localStorage.removeItem(credentialsStorageKey);
            apiKeyInput.value = '';
            apiSecretInput.value = '';
            setStatus('Saved credentials cleared for this browser.', 'muted');
        });

        hydrateCredentials();
    })();
</script>
</body>
</html>
