<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Operator Management</title>
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
            min-width: 900px;
            font-size: 13px;
        }

        th, td {
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            padding: 8px 10px;
            vertical-align: middle;
        }

        thead th {
            background: #f9fafb;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .role-select {
            min-width: 130px;
            padding: 6px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 13px;
        }

        .action-note {
            font-size: 12px;
            color: #6b7280;
        }
    </style>
</head>
<body>
<h1>Operator Management</h1>
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
    Tenant-local operator list powered by <code>GET /dashboard/api/operators</code>.
    Role updates are owner-only in this first RBAC management slice.
</p>

<div class="controls">
    <button id="loadButton" type="button">Load Operators</button>
</div>

<div id="status" class="status muted">No data loaded yet.</div>

<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Email</th>
            <th>Company ID</th>
            <th>Operator Role</th>
            <th>Action</th>
        </tr>
        </thead>
        <tbody id="operatorRows">
        <tr>
            <td colspan="6" class="muted">No operator rows loaded.</td>
        </tr>
        </tbody>
    </table>
</div>

<script>
    (() => {
        const listPath = '/dashboard/api/operators';
        const rolePathBase = '/dashboard/api/operators';
        const csrfToken = @json(csrf_token());

        const loadButton = document.getElementById('loadButton');
        const statusEl = document.getElementById('status');
        const rowsEl = document.getElementById('operatorRows');

        const state = {
            operators: [],
            currentUserId: null,
            currentUserRole: null,
            canManageRoles: false,
        };

        const roleOptions = ['owner', 'admin', 'support'];

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

        const renderRows = () => {
            const operators = state.operators;

            if (!Array.isArray(operators) || operators.length === 0) {
                rowsEl.innerHTML = '<tr><td colspan="6" class="muted">No operators found for this tenant.</td></tr>';
                return;
            }

            rowsEl.innerHTML = operators.map((operator) => {
                const operatorId = Number(operator.id);
                const isSelf = Number.isFinite(operatorId) && Number(state.currentUserId) === operatorId;

                if (!state.canManageRoles) {
                    return `
                        <tr>
                            <td>${escapeHtml(operator.id ?? '')}</td>
                            <td>${escapeHtml(operator.name ?? '')}</td>
                            <td>${escapeHtml(operator.email ?? '')}</td>
                            <td>${escapeHtml(operator.company_id ?? '')}</td>
                            <td>${escapeHtml(operator.operator_role ?? '')}</td>
                            <td><span class="action-note">Read-only</span></td>
                        </tr>
                    `;
                }

                const options = roleOptions.map((role) => {
                    const selected = role === operator.operator_role ? 'selected' : '';
                    return `<option value="${role}" ${selected}>${role}</option>`;
                }).join('');

                const disabled = isSelf ? 'disabled' : '';
                const actionContent = isSelf
                    ? '<span class="action-note">You cannot change your own role.</span>'
                    : `<button type="button" class="button-secondary save-role" data-operator-id="${operatorId}">Save Role</button>`;

                return `
                    <tr>
                        <td>${escapeHtml(operator.id ?? '')}</td>
                        <td>${escapeHtml(operator.name ?? '')}</td>
                        <td>${escapeHtml(operator.email ?? '')}</td>
                        <td>${escapeHtml(operator.company_id ?? '')}</td>
                        <td>
                            <select class="role-select" data-role-input-id="${operatorId}" ${disabled}>
                                ${options}
                            </select>
                        </td>
                        <td>${actionContent}</td>
                    </tr>
                `;
            }).join('');

            rowsEl.querySelectorAll('.save-role').forEach((button) => {
                button.addEventListener('click', async () => {
                    const operatorId = Number(button.getAttribute('data-operator-id'));
                    const selectEl = rowsEl.querySelector(`select[data-role-input-id="${operatorId}"]`);
                    if (!selectEl) {
                        setStatus('Role input not found.', 'error');
                        return;
                    }

                    const operatorRole = String(selectEl.value || '').trim();
                    await updateRole(operatorId, operatorRole);
                });
            });
        };

        const loadOperators = async ({silent = false} = {}) => {
            if (!silent) {
                setStatus('Loading operators...', 'muted');
            }

            try {
                const response = await fetch(listPath, {
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
                    if (!silent) {
                        setStatus(`Load failed: ${error}`, 'error');
                    }
                    state.operators = [];
                    renderRows();
                    return false;
                }

                const operators = Array.isArray(payload.operators) ? payload.operators : [];
                const meta = payload.meta || {};

                state.operators = operators;
                state.currentUserId = meta.current_user_id ?? null;
                state.currentUserRole = meta.current_user_role ?? null;
                state.canManageRoles = Boolean(meta.can_manage_roles);

                renderRows();

                if (!silent) {
                    const suffix = state.canManageRoles
                        ? ' Owner role detected: role updates enabled.'
                        : ' Read-only mode: owner role required for updates.';
                    setStatus(`Loaded ${operators.length} operator(s).${suffix}`, 'ok');
                }

                return true;
            } catch (error) {
                if (!silent) {
                    setStatus(`Load failed: ${error.message}`, 'error');
                }
                state.operators = [];
                renderRows();
                return false;
            }
        };

        const updateRole = async (operatorId, operatorRole) => {
            if (!state.canManageRoles) {
                setStatus('Role update requires owner role.', 'error');
                return;
            }

            setStatus(`Updating role for operator ${operatorId}...`, 'muted');

            try {
                const response = await fetch(`${rolePathBase}/${operatorId}/role`, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        operator_role: operatorRole,
                    }),
                });

                let payload = null;
                try {
                    payload = await response.json();
                } catch (_) {
                    payload = null;
                }

                if (!response.ok || !payload || payload.ok !== true) {
                    const error = payload && payload.error ? payload.error : `HTTP ${response.status}`;
                    setStatus(`Role update failed: ${error}`, 'error');
                    return;
                }

                const noChange = Boolean(payload.no_change);
                const refreshed = await loadOperators({silent: true});
                const refreshSuffix = refreshed ? ' Operator list refreshed.' : ' Update applied but list refresh failed.';
                const successText = noChange ? 'No role change needed.' : 'Role updated successfully.';

                setStatus(`${successText}${refreshSuffix}`, 'ok');
            } catch (error) {
                setStatus(`Role update failed: ${error.message}`, 'error');
            }
        };

        loadButton.addEventListener('click', () => {
            loadOperators();
        });
    })();
</script>
</body>
</html>
