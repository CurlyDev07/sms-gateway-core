<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Audit Log</title>
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
            align-items: end;
            margin-bottom: 16px;
        }

        label {
            display: flex;
            flex-direction: column;
            gap: 4px;
            font-size: 14px;
        }

        input {
            width: 160px;
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
            min-width: 1200px;
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

        .metadata {
            white-space: pre-wrap;
            word-break: break-word;
            max-width: 420px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        }
    </style>
</head>
<body>
<h1>Audit Log</h1>
<div class="links">
    <a href="/dashboard">Dashboard Home</a>
    <a href="/dashboard/sims">SIM Fleet</a>
    <a href="/dashboard/assignments">Assignments</a>
    <a href="/dashboard/migration">Migration</a>
    <a href="/dashboard/messages/status">Message Status</a>
    <a href="/dashboard/operators">Operators</a>
    <a href="/dashboard/audit">Audit Log</a>
    <form method="POST" action="{{ route('logout') }}" style="display:inline;">
        @csrf
        <button type="submit" class="logout-button">Logout</button>
    </form>
</div>
<p class="muted">
    Read-only tenant-local operator activity history powered by <code>GET /dashboard/api/audit-logs</code>.
</p>

<div class="controls">
    <label>
        limit
        <input id="limitInput" type="number" min="1" max="200" value="100">
    </label>
    <label>
        action
        <input id="actionInput" type="text" placeholder="e.g. sim.status_updated">
    </label>
    <label>
        actor_user_id
        <input id="actorUserIdInput" type="number" min="1" placeholder="e.g. 12">
    </label>
    <label>
        date_from
        <input id="dateFromInput" type="date">
    </label>
    <label>
        date_to
        <input id="dateToInput" type="date">
    </label>
    <button id="loadButton" type="button">Load Audit Logs</button>
</div>

<div id="status" class="status muted">No data loaded yet.</div>

<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>ID</th>
            <th>Company ID</th>
            <th>Actor User ID</th>
            <th>Action</th>
            <th>Target Type</th>
            <th>Target ID</th>
            <th>Created At</th>
            <th>Metadata</th>
        </tr>
        </thead>
        <tbody id="rows">
        <tr>
            <td colspan="8" class="muted">No audit rows loaded.</td>
        </tr>
        </tbody>
    </table>
</div>

<script>
    (() => {
        const path = '/dashboard/api/audit-logs';
        const loadButton = document.getElementById('loadButton');
        const limitInput = document.getElementById('limitInput');
        const actionInput = document.getElementById('actionInput');
        const actorUserIdInput = document.getElementById('actorUserIdInput');
        const dateFromInput = document.getElementById('dateFromInput');
        const dateToInput = document.getElementById('dateToInput');
        const statusEl = document.getElementById('status');
        const rowsEl = document.getElementById('rows');

        const setStatus = (text, type = 'muted') => {
            statusEl.className = `status ${type}`;
            statusEl.textContent = text;
        };

        const escapeHtml = (value) => {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        };

        const renderRows = (logs) => {
            if (!Array.isArray(logs) || logs.length === 0) {
                rowsEl.innerHTML = '<tr><td colspan="8" class="muted">No audit rows found for this tenant.</td></tr>';
                return;
            }

            rowsEl.innerHTML = logs.map((entry) => {
                const metadata = JSON.stringify(entry.metadata || {}, null, 2);
                return `
                    <tr>
                        <td>${escapeHtml(entry.id ?? '')}</td>
                        <td>${escapeHtml(entry.company_id ?? '')}</td>
                        <td>${escapeHtml(entry.actor_user_id ?? '')}</td>
                        <td>${escapeHtml(entry.action ?? '')}</td>
                        <td>${escapeHtml(entry.target_type ?? '')}</td>
                        <td>${escapeHtml(entry.target_id ?? '')}</td>
                        <td>${escapeHtml(entry.created_at ?? '')}</td>
                        <td class="metadata">${escapeHtml(metadata)}</td>
                    </tr>
                `;
            }).join('');
        };

        const loadLogs = async () => {
            const rawLimit = Number(limitInput.value || 100);
            if (!Number.isInteger(rawLimit) || rawLimit < 1 || rawLimit > 200) {
                setStatus('limit must be an integer between 1 and 200.', 'error');
                return;
            }

            const action = String(actionInput.value || '').trim();
            const actorUserIdRaw = String(actorUserIdInput.value || '').trim();
            const dateFrom = String(dateFromInput.value || '').trim();
            const dateTo = String(dateToInput.value || '').trim();

            if (actorUserIdRaw !== '') {
                const actorUserId = Number(actorUserIdRaw);
                if (!Number.isInteger(actorUserId) || actorUserId < 1) {
                    setStatus('actor_user_id must be a positive integer.', 'error');
                    return;
                }
            }

            if (dateFrom !== '' && dateTo !== '' && dateFrom > dateTo) {
                setStatus('date_to must be on or after date_from.', 'error');
                return;
            }

            const query = new URLSearchParams();
            query.set('limit', String(rawLimit));
            if (action !== '') {
                query.set('action', action);
            }
            if (actorUserIdRaw !== '') {
                query.set('actor_user_id', actorUserIdRaw);
            }
            if (dateFrom !== '') {
                query.set('date_from', dateFrom);
            }
            if (dateTo !== '') {
                query.set('date_to', dateTo);
            }

            setStatus('Loading audit logs...', 'muted');

            try {
                const response = await fetch(`${path}?${query.toString()}`, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                    },
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
                    rowsEl.innerHTML = '<tr><td colspan="8" class="muted">No audit rows loaded.</td></tr>';
                    return;
                }

                const logs = Array.isArray(payload.logs) ? payload.logs : [];
                renderRows(logs);
                setStatus(`Loaded ${logs.length} audit row(s).`, 'ok');
            } catch (error) {
                setStatus(`Load failed: ${error.message}`, 'error');
                rowsEl.innerHTML = '<tr><td colspan="8" class="muted">No audit rows loaded.</td></tr>';
            }
        };

        loadButton.addEventListener('click', loadLogs);
    })();
</script>
</body>
</html>
