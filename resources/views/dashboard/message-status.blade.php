<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Message Status Lookup</title>
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
</head>
<body>
<h1>Message Status Lookup</h1>
<div class="links">
    <a href="/dashboard">Dashboard Home</a>
    <a href="/dashboard/sims">SIM Fleet</a>
    <a href="/dashboard/assignments">Assignments</a>
    <a href="/dashboard/migration">Migration</a>
    <a href="/dashboard/messages/status">Message Status</a>
    <a href="/dashboard/operators">Operators</a>
    <form method="POST" action="{{ route('logout') }}" style="display:inline;">
        @csrf
        <button type="submit" class="logout-button">Logout</button>
    </form>
</div>
<p class="muted">
    Read-only lookup page powered by <code>GET /dashboard/api/messages/status</code>.
    Enter a required <code>client_message_id</code>.
</p>

<div class="controls">
    <label>
        client_message_id (required)
        <input id="clientMessageId" type="text" placeholder="e.g. ref-abc-001">
    </label>
    <label>
        sim_id (optional)
        <input id="simId" type="number" min="1" step="1" placeholder="e.g. 1">
    </label>
    <button id="lookupButton" type="button">Lookup Status</button>
</div>

<div id="status" class="status muted">No data loaded yet.</div>

<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>ID</th>
            <th>Client Message ID</th>
            <th>SIM ID</th>
            <th>Customer Phone</th>
            <th>Status</th>
            <th>Message Type</th>
            <th>Priority</th>
            <th>Sent At</th>
            <th>Failed At</th>
            <th>Failure Reason</th>
            <th>Retry Count</th>
            <th>Created At</th>
            <th>Updated At</th>
        </tr>
        </thead>
        <tbody id="messageRows">
        <tr>
            <td colspan="13" class="muted">No message rows loaded.</td>
        </tr>
        </tbody>
    </table>
</div>

<script>
    (() => {
        const apiPath = '/dashboard/api/messages/status';
        const lookupButton = document.getElementById('lookupButton');
        const clientMessageIdInput = document.getElementById('clientMessageId');
        const simIdInput = document.getElementById('simId');
        const statusEl = document.getElementById('status');
        const rowsEl = document.getElementById('messageRows');

        const escapeHtml = (value) => {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        };

        const setStatus = (text, type = 'muted') => {
            statusEl.className = `status ${type}`;
            statusEl.textContent = text;
        };

        const formatField = (value) => {
            return value === null || value === undefined || value === '' ? '-' : value;
        };

        const renderRows = (messages) => {
            if (!Array.isArray(messages) || messages.length === 0) {
                rowsEl.innerHTML = '<tr><td colspan="13" class="muted">No messages found for this query.</td></tr>';
                return;
            }

            rowsEl.innerHTML = messages.map((message) => `
                <tr>
                    <td>${escapeHtml(formatField(message.id))}</td>
                    <td>${escapeHtml(formatField(message.client_message_id))}</td>
                    <td>${escapeHtml(formatField(message.sim_id))}</td>
                    <td>${escapeHtml(formatField(message.customer_phone))}</td>
                    <td>${escapeHtml(formatField(message.status))}</td>
                    <td>${escapeHtml(formatField(message.message_type))}</td>
                    <td>${escapeHtml(formatField(message.priority))}</td>
                    <td>${escapeHtml(formatField(message.sent_at))}</td>
                    <td>${escapeHtml(formatField(message.failed_at))}</td>
                    <td>${escapeHtml(formatField(message.failure_reason))}</td>
                    <td>${escapeHtml(formatField(message.retry_count))}</td>
                    <td>${escapeHtml(formatField(message.created_at))}</td>
                    <td>${escapeHtml(formatField(message.updated_at))}</td>
                </tr>
            `).join('');
        };

        lookupButton.addEventListener('click', async () => {
            const clientMessageId = clientMessageIdInput.value.trim();
            const simId = simIdInput.value.trim();

            if (!clientMessageId) {
                setStatus('client_message_id is required.', 'error');
                return;
            }

            const query = new URLSearchParams();
            query.set('client_message_id', clientMessageId);
            if (simId) {
                query.set('sim_id', simId);
            }

            setStatus('Looking up message status...', 'muted');

            try {
                const response = await fetch(`${apiPath}?${query.toString()}`, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json'
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
                    setStatus(`Lookup failed: ${error}`, 'error');
                    renderRows([]);
                    return;
                }

                const messages = Array.isArray(payload.messages) ? payload.messages : [];
                renderRows(messages);
                setStatus(`Loaded ${messages.length} message row(s).`, 'ok');
            } catch (error) {
                setStatus(`Lookup failed: ${error.message}`, 'error');
                renderRows([]);
            }
        });
    })();
</script>
</body>
</html>
