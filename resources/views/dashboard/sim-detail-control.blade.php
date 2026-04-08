<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SIM Detail / Control</title>
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
            margin-bottom: 12px;
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

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 12px 0;
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

        .panel {
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 14px;
            max-width: 920px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(260px, 1fr));
            gap: 8px 16px;
            font-size: 14px;
        }

        .field-label {
            color: #6b7280;
            margin-right: 6px;
        }
    </style>
</head>
<body>
<h1>SIM Detail / Control</h1>
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
    SIM ID: <strong id="simIdText">{{ $simId }}</strong>.
    Read/Control page powered by <code>GET /dashboard/api/sims</code> plus existing admin SIM control endpoints.
</p>

<div class="controls">
    <button id="loadButton" type="button" title="Load live SIM status, health, and queue depth.">Load SIM</button>
</div>

<div id="status" class="status muted">No SIM data loaded yet.</div>

<div class="panel">
    <div class="actions">
        <button id="setActiveButton" type="button" class="button-secondary" title="Allow normal intake and processing for this SIM.">Set Active</button>
        <button id="setPausedButton" type="button" class="button-secondary" title="Pause processing for this SIM while keeping data intact.">Set Paused</button>
        <button id="setBlockedButton" type="button" class="button-secondary" title="Block this SIM for intake. Use when SIM is unavailable or unsafe.">Set Blocked</button>
        <button id="enableAssignmentsButton" type="button" class="button-secondary" title="Allow new customer assignments to this SIM.">Enable Assignments</button>
        <button id="disableAssignmentsButton" type="button" class="button-secondary" title="Prevent new customer assignments to this SIM.">Disable Assignments</button>
        <button id="rebuildQueueButton" type="button" class="button-secondary" title="Rebuild this SIM's Redis queues from DB pending truth.">Rebuild Queue</button>
    </div>

    <div class="grid">
        <div><span class="field-label">ID:</span><span id="field-id">-</span></div>
        <div><span class="field-label">UUID:</span><span id="field-uuid">-</span></div>
        <div><span class="field-label">Phone Number:</span><span id="field-phone">-</span></div>
        <div><span class="field-label">Carrier:</span><span id="field-carrier">-</span></div>
        <div><span class="field-label">SIM Label:</span><span id="field-sim-label">-</span></div>
        <div><span class="field-label">Status:</span><span id="field-status">-</span></div>
        <div><span class="field-label" title="Operator-controlled send state: active, paused, or blocked.">Operator Status:</span><span id="field-operator-status">-</span></div>
        <div><span class="field-label" title="Current health based on last successful send timestamp.">Health Status:</span><span id="field-health-status">-</span></div>
        <div><span class="field-label" title="Why this SIM is healthy or unhealthy. Example: no_success_within_30_minutes.">Health Reason:</span><span id="field-health-reason">-</span></div>
        <div><span class="field-label" title="True means no successful send for 6+ hours.">Stuck 6h:</span><span id="field-stuck-6h">-</span></div>
        <div><span class="field-label" title="True means no successful send for 24+ hours.">Stuck 24h:</span><span id="field-stuck-24h">-</span></div>
        <div><span class="field-label" title="True means no successful send for 3+ days.">Stuck 3d:</span><span id="field-stuck-3d">-</span></div>
        <div><span class="field-label" title="Total queue depth across all message tiers for this SIM.">Queue Total:</span><span id="field-queue-total">-</span></div>
        <div><span class="field-label" title="High-priority queue depth (chat + auto-reply).">Queue Chat:</span><span id="field-queue-chat">-</span></div>
        <div><span class="field-label" title="Follow-up queue depth for this SIM.">Queue Followup:</span><span id="field-queue-followup">-</span></div>
        <div><span class="field-label" title="Blasting queue depth for this SIM.">Queue Blasting:</span><span id="field-queue-blasting">-</span></div>
        <div><span class="field-label" title="If true, this SIM can receive new customer assignments.">Accept New Assignments:</span><span id="field-accept-new">-</span></div>
        <div><span class="field-label" title="System safety flag that blocks new assignments despite acceptance setting.">Disabled For New Assignments:</span><span id="field-disabled-new">-</span></div>
        <div><span class="field-label">Last Success At:</span><span id="field-last-success">-</span></div>
    </div>
</div>

<script>
    (() => {
        const simId = Number(@json($simId));
        const apiSimsPath = '/dashboard/api/sims';
        const csrfToken = @json(csrf_token());

        const loadButton = document.getElementById('loadButton');
        const setActiveButton = document.getElementById('setActiveButton');
        const setPausedButton = document.getElementById('setPausedButton');
        const setBlockedButton = document.getElementById('setBlockedButton');
        const enableAssignmentsButton = document.getElementById('enableAssignmentsButton');
        const disableAssignmentsButton = document.getElementById('disableAssignmentsButton');
        const rebuildQueueButton = document.getElementById('rebuildQueueButton');
        const statusEl = document.getElementById('status');

        const boolText = (value) => value ? 'true' : 'false';

        const setStatus = (text, type = 'muted') => {
            statusEl.className = `status ${type}`;
            statusEl.textContent = text;
        };

        const defaultHeaders = {
            'Accept': 'application/json'
        };

        const postHeaders = {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken
        };

        const setField = (id, value) => {
            const el = document.getElementById(id);
            if (!el) {
                return;
            }

            el.textContent = value === null || value === undefined || value === '' ? '-' : String(value);
        };

        const renderSim = (sim) => {
            const health = sim.health || {};
            const stuck = sim.stuck || {};
            const queueDepth = sim.queue_depth || {};

            setField('field-id', sim.id);
            setField('field-uuid', sim.uuid);
            setField('field-phone', sim.phone_number);
            setField('field-carrier', sim.carrier);
            setField('field-sim-label', sim.sim_label);
            setField('field-status', sim.status);
            setField('field-operator-status', sim.operator_status);
            setField('field-health-status', health.status);
            setField('field-health-reason', health.reason);
            setField('field-stuck-6h', boolText(stuck.stuck_6h ?? false));
            setField('field-stuck-24h', boolText(stuck.stuck_24h ?? false));
            setField('field-stuck-3d', boolText(stuck.stuck_3d ?? false));
            setField('field-queue-total', queueDepth.total ?? 0);
            setField('field-queue-chat', queueDepth.chat ?? 0);
            setField('field-queue-followup', queueDepth.followup ?? 0);
            setField('field-queue-blasting', queueDepth.blasting ?? 0);
            setField('field-accept-new', boolText(sim.accept_new_assignments ?? false));
            setField('field-disabled-new', boolText(sim.disabled_for_new_assignments ?? false));
            setField('field-last-success', sim.last_success_at);
        };

        const loadSim = async ({silent = false} = {}) => {
            if (!silent) {
                setStatus('Loading SIM details...', 'muted');
            }

            try {
                const response = await fetch(apiSimsPath, {
                    method: 'GET',
                    headers: defaultHeaders
                });

                const payload = await response.json();

                if (!response.ok || !payload || payload.ok !== true || !Array.isArray(payload.sims)) {
                    const error = payload && payload.error ? payload.error : `HTTP ${response.status}`;
                    if (!silent) {
                        setStatus(`Load failed: ${error}`, 'error');
                    }

                    return { ok: false, error };
                }

                const sim = payload.sims.find((row) => Number(row.id) === simId);

                if (!sim) {
                    const error = `SIM ${simId} not found in tenant scope.`;
                    if (!silent) {
                        setStatus(error, 'error');
                    }

                    return { ok: false, error };
                }

                renderSim(sim);
                if (!silent) {
                    setStatus(`Loaded SIM ${simId}.`, 'ok');
                }

                return { ok: true };
            } catch (error) {
                if (!silent) {
                    setStatus(`Load failed: ${error.message}`, 'error');
                }

                return { ok: false, error: error.message };
            }
        };

        const callAction = async (path, payload = null, actionLabel = 'Action') => {
            setStatus(`${actionLabel} in progress...`, 'muted');

            try {
                const response = await fetch(path, {
                    method: 'POST',
                    headers: postHeaders,
                    body: payload ? JSON.stringify(payload) : '{}'
                });

                const data = await response.json();

                if (!response.ok || !data || data.ok !== true) {
                    const error = data && data.error ? data.error : `HTTP ${response.status}`;
                    setStatus(`${actionLabel} failed: ${error}`, 'error');
                    return;
                }

                if (data.result) {
                    const rebuilt = data.result.rebuilt_count ?? 0;
                    const baseMessage = `${actionLabel} completed: rebuilt_count=${rebuilt}.`;
                    const refresh = await loadSim({ silent: true });
                    const suffix = refresh.ok
                        ? ' Latest SIM details refreshed.'
                        : ' Action applied, but detail refresh failed. Use Load SIM.';
                    setStatus(`${baseMessage}${suffix}`, 'ok');
                } else {
                    const baseMessage = `${actionLabel} completed successfully.`;
                    const refresh = await loadSim({ silent: true });
                    const suffix = refresh.ok
                        ? ' Latest SIM details refreshed.'
                        : ' Action applied, but detail refresh failed. Use Load SIM.';
                    setStatus(`${baseMessage}${suffix}`, 'ok');
                }
            } catch (error) {
                setStatus(`${actionLabel} failed: ${error.message}`, 'error');
            }
        };

        loadButton.addEventListener('click', loadSim);

        setActiveButton.addEventListener('click', () => {
            callAction(`/dashboard/api/admin/sim/${simId}/status`, { operator_status: 'active' }, 'Set Active');
        });

        setPausedButton.addEventListener('click', () => {
            callAction(`/dashboard/api/admin/sim/${simId}/status`, { operator_status: 'paused' }, 'Set Paused');
        });

        setBlockedButton.addEventListener('click', () => {
            if (!window.confirm('Set this SIM to blocked? Intake for this SIM will be rejected.')) {
                return;
            }

            callAction(`/dashboard/api/admin/sim/${simId}/status`, { operator_status: 'blocked' }, 'Set Blocked');
        });

        enableAssignmentsButton.addEventListener('click', () => {
            callAction(`/dashboard/api/admin/sim/${simId}/enable-assignments`, null, 'Enable Assignments');
        });

        disableAssignmentsButton.addEventListener('click', () => {
            callAction(`/dashboard/api/admin/sim/${simId}/disable-assignments`, null, 'Disable Assignments');
        });

        rebuildQueueButton.addEventListener('click', () => {
            if (!window.confirm('Rebuild Redis queue for this SIM from DB pending truth now?')) {
                return;
            }

            callAction(`/dashboard/api/admin/sim/${simId}/rebuild-queue`, null, 'Rebuild Queue');
        });
    })();
</script>
</body>
</html>
