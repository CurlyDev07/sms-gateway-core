<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SIM Fleet Status</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 24px;
            color: #1f2937;
        }

        h1 {
            margin: 0 0 12px 0;
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
            min-width: 260px;
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
            min-width: 1500px;
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
<h1>SIM Fleet Status</h1>
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
    Read-only fleet visibility page powered by <code>GET /dashboard/api/sims</code> using your authenticated dashboard session.
</p>

<div class="controls">
    <button id="loadButton" type="button" title="Fetch the latest SIM status list for your tenant.">Load SIMs</button>
</div>

<div id="status" class="status muted">No data loaded yet.</div>

<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>SIM ID</th>
            <th>Phone Number</th>
            <th>Carrier</th>
            <th>SIM Label</th>
            <th title="Operator-controlled send state: active, paused, or blocked.">Operator Status</th>
            <th title="Current health based on last successful send timestamp.">Health Status</th>
            <th title="Why this SIM is marked healthy or unhealthy. Example: no_success_within_30_minutes.">Health Reason</th>
            <th title="True means this SIM has not reported a successful send for 6+ hours.">Stuck 6h</th>
            <th title="True means this SIM has not reported a successful send for 24+ hours.">Stuck 24h</th>
            <th title="True means this SIM has not reported a successful send for 3+ days.">Stuck 3d</th>
            <th title="Total pending queued workload across all queue tiers for this SIM.">Queue Total</th>
            <th title="High-priority queue depth (chat + auto-reply traffic).">Queue Chat</th>
            <th title="Follow-up queue depth for this SIM.">Queue Followup</th>
            <th title="Blasting queue depth for this SIM.">Queue Blasting</th>
            <th title="If true, new customer stickies may be placed on this SIM.">Accept New Assignments</th>
            <th title="Health/system safety flag that blocks new assignments even if accept_new_assignments is true.">Disabled For New Assignments</th>
            <th>Last Success At</th>
        </tr>
        </thead>
        <tbody id="simRows">
        <tr>
            <td colspan="17" class="muted">No SIM rows loaded.</td>
        </tr>
        </tbody>
    </table>
</div>

<script>
    (() => {
        const apiPath = '/dashboard/api/sims';
        const loadButton = document.getElementById('loadButton');
        const statusEl = document.getElementById('status');
        const rowsEl = document.getElementById('simRows');

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

        const renderRows = (sims) => {
            if (!Array.isArray(sims) || sims.length === 0) {
                rowsEl.innerHTML = '<tr><td colspan="17" class="muted">No SIM rows found for this tenant.</td></tr>';
                return;
            }

            rowsEl.innerHTML = sims.map((sim) => {
                const health = sim.health || {};
                const stuck = sim.stuck || {};
                const queueDepth = sim.queue_depth || {};
                const simId = Number(sim.id);
                const simLink = Number.isFinite(simId)
                    ? `<a href="/dashboard/sims/${simId}" title="Open SIM detail/control page for SIM ${simId}.">${simId}</a>`
                    : '-';

                return `
                    <tr>
                        <td>${simLink}</td>
                        <td>${escapeHtml(sim.phone_number ?? '')}</td>
                        <td>${escapeHtml(sim.carrier ?? '')}</td>
                        <td>${escapeHtml(sim.sim_label ?? '')}</td>
                        <td>${escapeHtml(sim.operator_status ?? '')}</td>
                        <td>${escapeHtml(health.status ?? '')}</td>
                        <td>${escapeHtml(health.reason ?? '')}</td>
                        <td>${escapeHtml(boolText(stuck.stuck_6h ?? false))}</td>
                        <td>${escapeHtml(boolText(stuck.stuck_24h ?? false))}</td>
                        <td>${escapeHtml(boolText(stuck.stuck_3d ?? false))}</td>
                        <td>${escapeHtml(queueDepth.total ?? 0)}</td>
                        <td>${escapeHtml(queueDepth.chat ?? 0)}</td>
                        <td>${escapeHtml(queueDepth.followup ?? 0)}</td>
                        <td>${escapeHtml(queueDepth.blasting ?? 0)}</td>
                        <td>${escapeHtml(boolText(sim.accept_new_assignments ?? false))}</td>
                        <td>${escapeHtml(boolText(sim.disabled_for_new_assignments ?? false))}</td>
                        <td>${escapeHtml(sim.last_success_at ?? '')}</td>
                    </tr>
                `;
            }).join('');
        };

        loadButton.addEventListener('click', async () => {
            setStatus('Loading SIM fleet...', 'muted');

            try {
                const response = await fetch(apiPath, {
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
                    setStatus(`Load failed: ${error}`, 'error');
                    renderRows([]);
                    return;
                }

                renderRows(payload.sims || []);
                setStatus(`Loaded ${payload.sims.length} SIM(s).`, 'ok');
            } catch (error) {
                setStatus(`Load failed: ${error.message}`, 'error');
                renderRows([]);
            }
        });
    })();
</script>
</body>
</html>
